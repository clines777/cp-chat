<?php

namespace App\WebSocket\Handler;

use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Helper;
use App\Lib\Interface\MessageHandlerInterface;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Lib\NoSqlKey;
use App\Repository\Impl\MarqueeRepository;
use App\Repository\Impl\SessionRepository;
use App\Service\Impl\GroupUserService;
use App\Service\Impl\SysChatService;
use App\Service\Impl\UserService;
use Swoole\WebSocket\Server;

class ResumeHandler implements MessageHandlerInterface
{

    public function getMsgType(): string
    {
        return MsgType::Resume;
    }

    public function handle(Server $server, int $fd, MsgPayload $payload): void
    {
        try {
            $resumeToken = isset($payload->data['token']) ? trim($payload->data['token']) : '';
            if (empty($resumeToken)) {
                Helper::disconnect($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidParam, $payload));

                return;
            }

            /** @var SessionRepository $sessionRepo */
            $sessionRepo = Container::get(SessionRepository::class);
            $resumeInfo  = $sessionRepo->rGetResumeTokenInfo($resumeToken);
            if (empty($resumeInfo)) {
                $sessionRepo->rDel(NoSqlKey::resumeTokenKey($resumeToken));
                Helper::disconnect($server, $fd, MsgPayload::error(ErrorCode::ErrInvalidOrExpiredResumeToken, $payload));

                return;
            }

            /** @var UserService $userService */
            $userService  = Container::get(UserService::class);
            $resumeResult = $userService->resume($server, $fd, $resumeInfo);
            if (empty($resumeResult->data['user']) || ! $resumeResult->isSuccess()) {
                Log::error('用户重连失败! 讯息:'.$resumeResult->msg, 'login_by_resume_token_err');
                Helper::disconnect($server, $fd, MsgPayload::error(ErrorCode::ErrResumeFailed, $payload, $resumeResult->msg));

                return;
            }

            /** @var SysChatService $sysChatService */
            $sysChatService = Container::get(SysChatService::class);
            $sysGroupInfo   = $sysChatService->getSysChatPreview($resumeResult->data['user']['id']);

            /** @var GroupUserService $groupUserService */
            $groupUserService = Container::get(GroupUserService::class);
            $myGroups         = $groupUserService->getUserGroupList($resumeResult->data['user']['id']);

            $info = ['user' => $resumeResult->data['user'], 'my_groups' => $myGroups, 'sys_group' => $sysGroupInfo, 'marquee' => []];

            /** @var \App\Repository\Impl\MarqueeRepository $marqueeRepo */
            $marqueeRepo = Container::get(MarqueeRepository::class);
            $marquee     = $marqueeRepo->rGetMarquee();
            if ( ! empty($marquee)) {
                $info['marquee'] = $marquee;
            }

            Helper::push($server, $fd, MsgPayload::make(MsgType::ResumeOK, $info, $payload->getMeta()));
        } catch (\Throwable $e) {
            Log::error(sprintf('断线重连异常! 参数: %s, 讯息: %s', $payload->jsonSerialize(), Helper::getExpDetails($e)), 'resume_err');
            Helper::push($server, $fd, MsgPayload::error(ErrorCode::ErrResumeFailed, $payload, '恢复连线失败'));
        }
    }

}