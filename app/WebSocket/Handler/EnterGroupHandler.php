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
use App\Repository\Impl\GroupRepository;
use App\Repository\Impl\SceneRepository;
use App\Service\Impl\GroupUserService;
use Hyperf\Context\Context;
use Swoole\WebSocket\Server;

/**
 * 进入聊天群.
 */
class EnterGroupHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::EnterGroup;
    }

    /**
     * @param  \Swoole\WebSocket\Server  $server
     * @param  int                       $fd
     * @param  \App\Lib\MsgPayload       $payload
     *
     * @return void
     */
    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        try {
            $session = Context::get('session');

            if (empty($payload->data['group_id'])) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidParam, $payload));

                return;
            }

            /** @var GroupRepository $groupRepo */
            $groupRepo = Container::get(GroupRepository::class);
            $group     = $groupRepo->getById($payload->data['group_id']);
            if (empty($group)) {
                Helper::disconnect($server, $fd, MsgPayload::error(ErrorCode::ErrGroupNotExists, $payload, '群组不存在'));

                return;
            }

            /** @var GroupUserService $groupUserService */
            $groupUserService = Container::get(GroupUserService::class);
            $result           = $groupUserService->enterGroup($fd, $session, $group);
            if ( ! $result->isSuccess()) {
                Log::error("进群失败! 讯息:".$result->msg, 'enter_group_err');
                Helper::disconnect($server, $fd, MsgPayload::error(ErrorCode::ErrEnterGroupFailed, $payload, $result->msg));

                return;
            }

            $groupUser = $result->data['group_user'] ?? [];
            if (empty($groupUser)) {
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrNotBelongsToGroup, $payload, '用户不属于该群组'));

                return;
            }

            /** @var SceneRepository $sceneRepo */
            $sceneRepo = Container::get(SceneRepository::class);
            if ($sceneRepo->existsAtLobby($session['id'])) {
                $sceneRepo->removeAtLobby($session['id']);
            }

            if ($sceneRepo->existsAtMyGroup($session['id'])) {
                $sceneRepo->removeAtMyGroup($session['id']);
            }

            $enterInfo = $groupUserService->buildEnterGroupInfo($session, $group, $groupUser);
            Helper::push($server, $fd, MsgPayload::make(MsgType::EnterGroupOK, $enterInfo, $payload->getMeta()));
            $userCount = $enterInfo['group']['user_count'] ?? 0;
            RStream::push(RStream::TypeNotifyGroupState, ['group_id' => $group['id'], 'user_count' => $userCount]);//通知更新群内状态.
        } catch (\Throwable $e) {
            Log::error(sprintf('进群处理异常! payload: %s, 讯息: %s', $payload->jsonSerialize(), Helper::getExpDetails($e)), 'enter_group_err');
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrEnterGroupFailed, $payload));
        }
    }

}