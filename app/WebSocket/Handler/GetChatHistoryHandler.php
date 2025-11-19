<?php

namespace App\WebSocket\Handler;

use App\Lib\ConfigKey;
use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Validator;
use App\Lib\Helper;
use App\Lib\Interface\MessageHandlerInterface;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Lib\Scene;
use App\Repository\Impl\ConfigRepository;
use App\Repository\Impl\GroupUserRepository;
use App\Service\Impl\ChatService;
use Hyperf\Context\Context;
use Swoole\WebSocket\Server;

class GetChatHistoryHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::GetChatHistory;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        $session = Context::get('session');
        try {
            $vResult = Validator::validate(
                $payload->data,
                [
                    'before_chat_id' => 'required|integer',
                ],
            );
            if ( ! $vResult->success) {
                Log::error('拉取群消息历史失败! 参数错误! 原始数据:'.$payload->jsonSerialize(), 'get_chat_history_err');
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidParam, $payload, '参数错误'));

                return;
            }

            $groupId = Scene::fetchGroupId($session);
            if (empty($groupId)) {
                Log::error('取群历史消息失败! 用户不在该群内! user: '.json_encode($session, JSON_UNESCAPED_UNICODE), 'get_chat_history_err');
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidOperate, $payload, '用户不在该群内'));

                return;
            }

            /** @var GroupUserRepository $groupUserRepo */
            $groupUserRepo = Container::get(GroupUserRepository::class);
            $groupUser     = $groupUserRepo->getGroupUser($session['id'], $groupId);
            if (empty($groupUser)) {
                Log::error('拉取群消息历史失败! 用户不属于该群组', 'get_chat_history_err');
                Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrNotBelongsToGroup, $payload, '用户不属于该群组'));

                return;
            }

            /** @var ConfigRepository $configRepo */
            $configRepo = Container::get(ConfigRepository::class);

            /** @var \App\Service\Impl\ChatService $chatService */
            $chatService = Container::get(ChatService::class);
            $count       = ! empty($payload->data['count']) && $payload->data['count'] > 0 && $payload->data['count'] <= 100 ? (int)$payload->data['count']
                : (int)$configRepo->getByKey(ConfigKey::GroupLastChatCount);
            $chatHistory = $chatService->getHistory($session['id'], $groupId, $payload->data['before_chat_id'], $groupUser['last_visible_chat_id'], $count);
            if ( ! empty($chatHistory)) {
                Log::cli("history".json_encode($chatHistory, 256));
            }
            Helper::push($server, $fd, MsgPayload::make(MsgType::GetChatHistoryOK, $chatHistory, $payload->getMeta()));
        } catch (\Throwable $e) {
            Log::error(
                sprintf('取得历史讯息异常! 参数: %s, 讯息: %s', $payload->jsonSerialize(), Helper::getExpDetails($e)),
                'get_chat_history_exp',
            );
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrGetChatHistoryFailed, $payload, '获取失败'));
        }
    }

}