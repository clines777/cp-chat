<?php

namespace App\WebSocket\Handler;

use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\Interface\MessageHandlerInterface;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Lib\RStream;
use App\Lib\Scene;
use App\Lib\UserLogBuilder;
use App\Model\GroupUser;
use App\Repository\Impl\GroupRepository;
use App\Repository\Impl\GroupUserRepository;
use App\Repository\Impl\UserLogRepository;
use App\Repository\Impl\UserRepository;
use Hyperf\Context\Context;
use Swoole\WebSocket\Server;

/**
 * 管理者在群内踢人退群
 */
class KickHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::KickUser;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        try {
            if (empty($payload->data['user_id'])) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidParam, $payload, '缺少对象user_id参数'));

                return;
            }
            $payload->data['user_id'] = (int)$payload->data['user_id'];

            $session = Context::get('session');

            /** @var UserRepository $userRepo */
            $userRepo = Container::get(UserRepository::class);
            $users    = $userRepo->getUsersByCondition(['id' => [$session['id'], $payload->data['user_id']]], ['id', 'ext_member_id', 'ext_username', 'site_bid']);
            $userMap  = array_column($users, null, 'id');
            $user     = $userMap[$payload->data['user_id']] ?? [];
            if (empty($user)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrUserNotFound, $payload, '未找到該目標用戶'));

                return;
            }
            $admin = $userMap[$session['id']] ?? [];

            $groupId = Scene::fetchGroupId($session);
            if (empty($groupId)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrWrongOperateScene, $payload, '用户当前不在群内'));

                return;
            }

            /** @var GroupRepository $groupRepo */
            $groupRepo = Container::get(GroupRepository::class);
            $group     = $groupRepo->getById($groupId, ['id', 'title', 'code']);
            if (empty($group)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrGroupNotExists, $payload, '群組ID錯誤'));

                return;
            }

            /** @var GroupUserRepository $groupUserRepo */
            $groupUserRepo = Container::get(GroupUserRepository::class);
            $groupUsers    = $groupUserRepo->getGroupUsers($groupId, [$payload->data['user_id'], $session['id']]);
            if (empty($groupUsers)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrNotBelongsToGroup, $payload, '群用户信息不存在'));

                return;
            }
            $groupUserMap = array_column($groupUsers, null, 'user_id');
            $guAdmin      = $groupUserMap[$session['id']] ?? [];
            $guUser       = $groupUserMap[$payload->data['user_id']] ?? [];
            if (empty($guAdmin)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrNotBelongsToGroup, $payload, '管理者不属于该群'));

                return;
            }

            if (empty($guUser)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrNotBelongsToGroup, $payload, '用户不属于该群'));

                return;
            }

            if ((int)$guAdmin->role_type === GroupUser::RoleUser) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidOperate, $payload, '发起人无操作权限'));

                return;
            }

            if ((int)$guUser->role_type !== GroupUser::RoleUser) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidOperate, $payload, '踢群对象不是一般用户'));

                return;
            }

            $ok = $groupUserRepo->delGroupUser($guUser->id);
            if ( ! $ok) {
                Log::error('踢群处理失败, 删除group_user失败', 'del_group_user_err');
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrKickUserFailed, $payload, '踢群失败'));

                return;
            }

            /** @var UserLogBuilder $userLogger */
            $userLogger = Container::make(UserLogBuilder::class, ['siteBid' => $user->site_bid]);
            $userLogger->setParam($user->id, $user->ext_member_id, $user->ext_username)->sceneInApp()->setLogType(UserLogBuilder::TypeGroupBan)->byGroupAdmin(
                $admin->id,
                $admin->ext_member_id,
                $admin->ext_username,
            )->withRemark(sprintf('踢出群組，群號: %s', $group['code']));

            /** @var UserLogRepository $userLogRepo */
            $userLogRepo = Container::get(UserLogRepository::class);
            $userLogRepo->addWithBuilder($userLogger);

            Helper::push($server, $fd, MsgPayload::make(MsgType::KickUserOK, ['user_id' => $guUser->user_id], $payload->getMeta()));
            RStream::push(RStream::TypeKickUser, ['group_id' => $guUser->group_id, 'user_id' => $guUser->user_id]);
        } catch (\Throwable $e) {
            Log::error(sprintf('踢群处理异常! payload: %s, 讯息: %s', $payload->jsonSerialize(), Helper::getExpDetails($e)), 'in_group_kick_user_err');
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrKickUserFailed, $payload));
        }
    }

}