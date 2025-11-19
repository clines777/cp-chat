<?php

namespace App\WebSocket\Handler;

use App\Lib\ErrorCode;
use App\Lib\Facade\Cache;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\Interface\MessageHandlerInterface;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Lib\NoSqlKey;
use App\Lib\RStream;
use App\Lib\Scene;
use App\Model\GroupUser;
use App\Repository\Impl\GroupRepository;
use App\Repository\Impl\GroupUserRepository;
use Hyperf\Context\Context;
use Swoole\WebSocket\Server;

class UnpinChatHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::UnpinChat;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        try {
            if (empty($payload->data['chat_id'])) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidParam, $payload));

                return;
            }
            $payload->data['chat_id'] = (int)$payload->data['chat_id'];

            $session = Context::get('session');
            $groupId = Scene::fetchGroupId($session);
            if (empty($groupId)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrWrongOperateScene, $payload));

                return;
            }

            /** @var GroupUserRepository $groupUserRepo */
            $groupUserRepo = Container::get(GroupUserRepository::class);
            $groupUser     = $groupUserRepo->getGroupUser($session['id'], $groupId);
            if (empty($groupUser)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrNotBelongsToGroup, $payload, '操作者不属于该群'));

                return;
            }

            if ((int)$groupUser['role_type'] === GroupUser::RoleUser) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidOperate, $payload, '没有操作权限'));

                return;
            }

            /** @var GroupRepository $groupRepo */
            $groupRepo = Container::get(GroupRepository::class);
            $group     = $groupRepo->getById($groupUser['group_id'], ['id', 'pin_chat_id', 'site_bid']);
            if (empty($group)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrGroupNotExists, $payload));

                return;
            }
            if ($group['pin_chat_id'] > 0) {
                $ok = $groupRepo->setPinChat($groupUser['group_id'], 0);
                if ( ! $ok) {
                    Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrUnpinChatFailed, $payload, '操作失败'));

                    return;
                }
                Cache::del(NoSqlKey::allGroup($group['site_bid']));
            }

            Helper::push($server, $fd, MsgPayload::make(MsgType::UnpinChatOK, ['chat_id' => $payload->data['chat_id']], $payload->getMeta()));
            RStream::push(RStream::TypeNotifyUnpinChat, ['group_id' => $group['id'], 'chat_id' => $payload->data['chat_id']]);
        } catch (\Throwable $e) {
            Log::error(sprintf('讯息置顶异常! payload: %s, 讯息: %s', $payload->jsonSerialize(), Helper::getExpDetails($e)));
        }
    }

}