<?php

namespace App\Service\Impl;

use App\Lib\CommonResult;
use App\Lib\ConfigKey;
use App\Lib\ErrorCode;
use App\Lib\Facade\Cache;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Validator;
use App\Lib\Helper;
use App\Lib\MsgPayload;
use App\Lib\MsgType;
use App\Lib\NoSqlKey;
use App\Lib\RStream;
use App\Lib\Scene;
use App\Lib\SysConst;
use App\Model\SysChatContent;
use App\Repository\Impl\AvatarRepository;
use App\Repository\Impl\ConfigRepository;
use App\Repository\Impl\SessionRepository;
use App\Repository\Impl\SysChatRepository;
use App\Repository\Impl\SysLmUserRepository;
use App\Repository\Impl\UserRepository;
use Swoole\WebSocket\Server as WebSocketServer;

class SysChatService
{

    /**
     * 批量写入重试次数.
     */
    protected const BatchRetryLimit = 3;

    protected SysChatRepository $sysChatRepo;

    public function __construct(SysChatRepository $repo)
    {
        $this->sysChatRepo = $repo;
    }

    /**
     * 取得進系統群初始化數據.
     *
     * @param array $session
     *
     * @return array
     */
    public function getEnterSysGroupInitInfo(array $session): array
    {
        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);
        $lastChats = $this->sysChatRepo->getLastChat(
            $session['id'],
            $session['site_bid'],
            (int)$configRepo->getByKey(ConfigKey::SysLastChatCount)
        );
        if (empty($lastChats)) {
            return [];
        }

        return $this->buildSysChatContent($lastChats);
    }

    /**
     * 填充系統消息內容.
     *
     * @param array $rawChats
     *
     * @return array
     */
    protected function buildSysChatContent(array $rawChats): array
    {
        if (empty($rawChats)) {
            return [];
        }

        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);
        /** @var AvatarRepository $avatarRepo */
        $avatarRepo = Container::get(AvatarRepository::class);
        $sysAvatarUrl = $configRepo->getByKey(ConfigKey::CdnUrl) . $avatarRepo->getSysAvatarPath();
        $title = '系統';
        $name = '系統消息';
        $chatList = [];
        $now = time();
        foreach ($rawChats as $each) {
            $dateTime = date('m-d H:i', $each->create_time);

            $extra = new \stdClass();
            if ((int)$each->content_type === SysChatContent::ContentTypeLuckyMoney) {
                $extra = ChatService::buildChatLmExtra($each, $now);
            }
            $chatList[] = [
                'type' => (int)$each->content_type,
                'id' => (int)$each->id,
                'user' => ['name' => $name, 'title' => $title, 'avatar_url' => $sysAvatarUrl],
                'content' => htmlspecialchars($each->content),
                'datetime' => ['text' => $dateTime, 'time' => (int)$each->create_time],
                'extra' => $extra,
                'is_client' => 0,
            ];
        }

        return $chatList;
    }

    /**
     * 推送系统消息.
     *
     * @param mixed $server
     * @param array $msg
     *
     * @return void
     */
    public function notifySysChat(WebSocketServer $server, array $msg): void
    {
        if (!isset($msg['audience_type']) || empty($msg['format_chat']) || empty($msg['site_bid'])) {
            Log::error('转发系统消息失败! 必要参数错误! 参数: ' . json_encode($msg), 'sendSysChat_err');

            return;
        }

        if ((int)$msg['audience_type'] === SysChatContent::AudienceByUsers && empty($msg['user_ids'])) {
            Log::error('系统消息推送失败, 指定用户消息未附带用户ID: ' . json_encode($msg), 'sendSysChat_err');

            return;
        }

        $myGroupData = [
            'type' => (int)$msg['content_type'],
            'chat_id' => (int)$msg['id'],
            'content' => $msg['content'],
            'create_time' => (int)$msg['create_time'],
            'time' => date('m-d H:i', $msg['create_time']),
            'last_read_add' => 1,
        ];
        $msg['format_chat'] = json_decode($msg['format_chat'], true);
        if ((int)$msg['audience_type'] === SysChatContent::AudienceByUsers) {
            $userIds = json_decode($msg['user_ids'], true);
            $this->pushTargetUsers($server, $msg['format_chat'], $myGroupData, $msg['site_bid'], $userIds);
        } else {
            $this->pushSiteAllUser($server, $msg['format_chat'], $myGroupData, $msg['site_bid']);
        }
    }

    /**
     * 推送指定用户.
     *
     * @param mixed $server
     * @param array $inSysGroupData
     * @param array $myGroupData
     * @param string $siteBid
     * @param array $userIds
     *
     * @return void
     */
    protected function pushTargetUsers(
        mixed $server,
        array $inSysGroupData,
        array $myGroupData,
        string $siteBid,
        array $userIds
    ): void {
        if (empty($userIds)) {
            return;
        }

        $uidMap = array_flip($userIds);
        /** @var SessionRepository $sessionRepo */
        $sessionRepo = Container::get(SessionRepository::class);
        $siteOnlineUsers = $sessionRepo->rGetSiteOnlineAll($siteBid);
        foreach ($siteOnlineUsers as $info) {
            if (empty($info['user_id']) || !isset($uidMap[$info['user_id']])) {
                continue;
            }

            $eachFd = $sessionRepo->rGetExistsUserFd($info['user_id']);
            if ($eachFd > 0) {
                $session = $sessionRepo->rGetSession($eachFd);
                if (empty($session['scene'])) {
                    continue;
                }

                if ($session['scene']['name'] === Scene::MyGroup) {
                    Helper::push($server, $eachFd, MsgPayload::make(MsgType::SysGroupLastChat, $myGroupData));
                } elseif ($session['scene']['name'] === Scene::SysGroup) {
                    Helper::push($server, $eachFd, MsgPayload::make(MsgType::SysGroupChatAck, $inSysGroupData));
                }
            }
        }
    }

    /**
     * 推送指定站台全部
     *
     * @param mixed $server
     * @param array $inSysGroupData
     * @param array $myGroupData
     * @param string $siteBid
     *
     * @return void
     */
    protected function pushSiteAllUser(mixed $server, array $inSysGroupData, array $myGroupData, string $siteBid): void
    {
        if (empty($inSysGroupData)) {
            Log::error('站点推送全用户系统消息失败, format chat为空', 'update_sys_last_read_err');

            return;
        }
        /** @var SessionRepository $sessionRepo */
        $sessionRepo = Container::get(SessionRepository::class);
        $siteOnlineUsers = $sessionRepo->rGetSiteOnlineAll($siteBid);
        foreach ($siteOnlineUsers as $info) {
            if (empty($info['user_id'])) {
                continue;
            }

            $eachFd = $sessionRepo->rGetExistsUserFd($info['user_id']);
            if ($eachFd > 0) {
                $session = $sessionRepo->rGetSession($eachFd);
                if (empty($session)) {
                    continue;
                }
                if (empty($session['scene'])) {
                    continue;
                }

                if ($session['scene']['name'] === Scene::MyGroup) {
                    Helper::push($server, $eachFd, MsgPayload::make(MsgType::SysGroupLastChat, $myGroupData));
                } elseif ($session['scene']['name'] === Scene::SysGroup) {
                    Helper::push($server, $eachFd, MsgPayload::make(MsgType::SysGroupChatAck, $inSysGroupData));
                }
            }
        }
    }

    /**
     * 更新系统消息最新已读.
     *
     * @param array $session
     * @param int $fd
     * @param array $data
     *
     * @return \App\Lib\CommonResult
     */
    public function setLastRead(array $session, int $fd, array $data): CommonResult
    {
        if (empty($data['chat_id'])) {
            Log::error('更新系统消息已读失败, 参数错误!', 'update_sys_last_read_err');

            return CommonResult::invalidParam('参数错误');
        }

        $chat = $this->sysChatRepo->getOne($data['chat_id']);
        if (empty($chat)) {
            Log::error('更新系统消息已读失败, 该消息不存在', 'update_sys_last_read_err');

            return CommonResult::make(ErrorCode::ErrInvalidOperate, '该消息不存在');
        }

        /** @var UserRepository $userRepo */
        $userRepo = Container::get(UserRepository::class);
        $user = $userRepo->getOneUserByCondition(['id' => $session['id']],
            ['sys_chat_last_read_id', 'id', 'site_bid', 'ext_member_id']);
        if (empty($user)) {
            return CommonResult::make(ErrorCode::ErrUserNotFound, '未查找到用户数据');
        }

        if ((int)$chat['id'] === (int)$user['sys_chat_last_read_id']) {
            return CommonResult::success();
        }

        $ok = $userRepo->updateSysLastRead($user['id'], $chat['id']);
        if (!$ok) {
            Log::error('更新系统消息已读失败, DB更新失败', 'update_sys_last_read_err');

            return CommonResult::error('更新失败');
        }

        Cache::del(NoSqlKey::extUnread($user['site_bid'], $user['ext_member_id']));

        return CommonResult::success();
    }

    /**
     * 获取系统群历史讯息.
     *
     * @param array $user
     * @param array $queryParam
     *
     * @return \App\Lib\CommonResult
     */
    public function getHistory(array $user, array $queryParam): CommonResult
    {
        /** @var UserRepository $userRepo */
        $userRepo = Container::get(UserRepository::class);
        $user = $userRepo->getOneUserByCondition(['id' => $user['id']]);
        if (empty($user)) {
            Log::error(
                sprintf('拉取系统群讯息, 未查找到用户! user : %s', json_encode($user, 256)),
                'sysGetHistory_err'
            );

            return CommonResult::make(ErrorCode::ErrUserNotFound, '未查找到该用户');
        }

        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);
        $beforeChatId = isset($queryParam['before_chat_id']) ? (int)$queryParam['before_chat_id'] : 0;
        $chatList = $this->sysChatRepo->getLastChat(
            $user['id'],
            $user['site_bid'],
            (int)$configRepo->getByKey(ConfigKey::SysLastChatCount),
            $beforeChatId
        );
        $chats = $this->buildSysChatContent($chatList);

        return CommonResult::success('ok', $chats);
    }

    /**
     * 取后台消息列表.
     *
     * @param array $params
     *
     * @return \App\Lib\CommonResult
     */
    public function getAdminIndex(array $params): CommonResult
    {
        $page = isset($params['page']) ? (int)$params['page'] : 1;
        $pageSize = isset($params['page_size']) ? (int)$params['page_size'] : SysConst::AdminIndexPageSize;
        $list = $this->sysChatRepo->getAdminIndex($page, $pageSize, $params);

        return CommonResult::success('OK', $list);
    }

    /**
     * 后台取系统消息的接收者列表.
     *
     * @param array $params
     *
     * @return \App\Lib\CommonResult
     */
    public function getAdminSysChatUser(array $params): CommonResult
    {
        if (empty($params['chat_id']) || !is_numeric($params['chat_id'])) {
            return CommonResult::invalidParam('缺少chat_id');
        }

        $chat = $this->sysChatRepo->getOne($params['chat_id']);
        if (empty($chat) || strtoupper($chat['site_bid']) !== strtoupper($params['site_bid'])) {
            return CommonResult::invalidParam('未找到对应讯息');
        }

        $page = isset($params['page']) ? (int)$params['page'] : 1;
        $pageSize = isset($params['page_size']) ? (int)$params['page_size'] : SysConst::AdminIndexPageSize;
        $list = $this->sysChatRepo->getUsersOfChat($chat['id'], $page, $pageSize, $params);

        return CommonResult::success('OK', $list);
    }

    /**
     * 储存系统频道讯息
     *
     * @param array $params
     *
     * @return \App\Lib\CommonResult
     */
    public function addNormalSysChat(array $params): CommonResult
    {
        $vResult = Validator::validate($params, [
            'content' => 'required|string|max:1000',
            'admin_id' => 'required|integer',
            'audience_type' => 'required|integer|in:0,1',
            'member_id' => 'array',
        ]);

        if (!$vResult->success) {
            return CommonResult::invalidParam('参数错误:' . $vResult->msg);
        }
        $vResult->validated['audience_type'] = (int)$vResult->validated['audience_type'];

        $users = [];
        if ($vResult->validated['audience_type'] === SysChatContent::AudienceByUsers) {
            /** @var UserRepository $userRepo */
            $userRepo = Container::get(UserRepository::class);
            $users = $userRepo->getUsersByCondition(
                ['ext_member_id' => $params['member_id'], 'site_bid' => $params['site_bid']],
                ['id', 'ext_member_id', 'ext_username']
            );
            if (empty($users)) {
                return CommonResult::error('指定用户皆尚未使用过群聊');
            }
        }

        try {
            $insertChat = [
                'content' => Helper::secureStr($params['content']),
                'site_bid' => $params['site_bid'],
                'admin_id' => $params['admin_id'],
                'content_type' => SysChatContent::ContentTypeNormal,
                'audience_type' => $vResult->validated['audience_type'],
                'create_time' => time(),
            ];
            $newChatId = $this->sysChatRepo->add($insertChat);
            if (!$newChatId) {
                throw new \PDOException('系统消息内容写入失败! 参数:' . json_encode($params));
            }
        } catch (\Throwable $e) {
            Log::error(sprintf("系统消息写入异常! 讯息: %s", Helper::getExpDetails($e)), 'addNormalSysChat_err');

            return CommonResult::error('操作失败');
        }

        $now = time();
        if ($vResult->validated['audience_type'] === SysChatContent::AudienceByUsers) {
            $sysChatUserTpl = ['chat_id' => $newChatId, 'create_time' => $now];
            $batch = array_chunk($users, 500);
            foreach ($batch as $batchUsers) {
                $list = [];
                foreach ($batchUsers as $user) {
                    $row = $sysChatUserTpl;
                    $row['user_id'] = $user->id;
                    $row['ext_member_id'] = $user->ext_member_id;
                    $row['ext_username'] = $user->ext_username;
                    $list[] = $row;
                }
                //为确保消息有被建立, 会在批量新增后才推送
                RStream::push(
                    RStream::TypeBatchSysChat,
                    ['list' => json_encode($list), 'chat_id' => $newChatId, 'retry' => 0],
                    RStream::BatchStream
                );
            }
        } else {
            $sysChat = $this->sysChatRepo->getOne(
                $newChatId,
                ['id', 'audience_type', 'site_bid', 'content_type', 'create_time', 'content', 'extra', 'custom_id']
            );
            $formatChat = $this->buildSysChatContent([(object)$sysChat]);
            if (!empty($formatChat[0])) {
                $sysChat['format_chat'] = json_encode($formatChat[0]);
                RStream::push(RStream::TypeSendSysChat, $sysChat);
            } else {
                Log::error(
                    sprintf('系统消息推送失败, 消息格式化失败! 原始消息: %s', json_encode($sysChat, 256)),
                    'addNormalSysChat_notify_err'
                );
            }
        }

        return CommonResult::success();
    }

    /**
     * @param array $msg
     *
     * @return bool
     */
    public function batchAddSysChat(array $msg): bool
    {
        $retry = isset($msg['retry']) ? (int)$msg['retry'] : 0;
        if ($retry > self::BatchRetryLimit) {
            Log::error('批量写入系统消息终止! 重试次数超过!: ' . json_encode($msg, 256), 'batchAddSysChat_err');

            return false;
        }
        $chatId = isset($msg['chat_id']) ? (int)$msg['chat_id'] : 0;
        $list = !empty($msg['list']) && json_validate($msg['list']) ? json_decode($msg['list'], true) : [];
        if (empty($chatId) || empty($list)) {
            Log::error(
                '批量写入系统消息失败, 缺少chat_id或list为空! 数据:' . json_encode($msg, 256),
                'batchAddSysChat_err'
            );

            return false;
        }

        $ok = $this->sysChatRepo->addContentUsers($list);
        if (!$ok) {
            Log::error('批量写入系统消息失败! ');
            $msg['retry'] = $retry + 1;
            RStream::push(RStream::TypeBatchSysChat, $msg, RStream::BatchStream);

            return false;
        }

        //发到notify stream处理推送
        $userIds = array_column($list, 'user_id');
        //等待批量写入时再推送.
        $sysChat = $this->sysChatRepo->getOne(
            $chatId,
            ['id', 'audience_type', 'site_bid', 'content_type', 'create_time', 'content', 'extra', 'custom_id']
        );
        $builtChats = $this->buildSysChatContent([(object)$sysChat]);
        if (!empty($builtChats)) {
            $sysChat['format_chat'] = json_encode($builtChats[0]);
            $sysChat['user_ids'] = json_encode($userIds);

            RStream::push(RStream::TypeSendSysChat, $sysChat);
        }

        return true;
    }

    /**
     * 批次写入系统红包导入用户
     *
     * @param $msg
     *
     * @return void
     */
    public function batchAddSysLmUser($msg): void
    {
        if (empty($msg['list']) || empty($msg['lm_id'])) {
            Log::error(
                sprintf('批次写入系统红包导入用户失败 推送参数缺失! 参数: %s', json_encode($msg, 256)),
                'batchAddSysLmUser_err'
            );

            return;
        }

        $list = json_validate($msg['list']) ? json_decode($msg['list'], true) : [];
        if (empty($list)) {
            Log::error(
                sprintf('批次写入系统红包导入用户失败! list参数decode失败: %s', json_encode($msg['list'], 256)),
                'batchAddSysLmUser_decode_list_err'
            );

            return;
        }

        /** @var SysLmUserRepository $sysLmUserRepo */
        $sysLmUserRepo = Container::get(SysLmUserRepository::class);
        $sysLmUserRepo->add($list);
    }

    /**
     * 取系统群预览资讯(标题, 最后一笔内容跟时间, 未读数, 封面)
     *
     * @param int $userId
     *
     * @return array
     */
    public function getSysChatPreview(int $userId): array
    {
        /** @var UserRepository $userRepo */
        $userRepo = Container::get(UserRepository::class);
        $user = $userRepo->getOne($userId, ['id', 'site_bid', 'sys_chat_last_read_id']);
        $lastChats = $this->sysChatRepo->getLastChat($user['id'], $user['site_bid'], 1);
        $lastChat = new \stdClass();
        if (!empty($lastChats[0])) {
            $lastChat->type = (int)$lastChats[0]->content_type;
            $lastChat->content = $lastChats[0]->content;
            $lastChat->time = date('m-d H:i', $lastChats[0]->create_time);
            $lastChat->create_time = (int)$lastChats[0]->create_time;
        }
        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);
        $sysGroupCover = $configRepo->getSysGroupCoverPath();
        $unreadCount = $this->sysChatRepo->getUnreadCount(
            $user['id'],
            $user['sys_chat_last_read_id'],
            $user['site_bid']
        );

        return [
            'title' => '系统消息',
            'cover_pic_url' => $sysGroupCover,
            'unread_count' => $unreadCount,
            'last_chat' => $lastChat,
        ];
    }

}