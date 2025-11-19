<?php

namespace App\Service\Impl;

use App\Lib\CommonResult;
use App\Lib\ConfigKey;
use App\Lib\CpApi;
use App\Lib\ErrorCode;
use App\Lib\Facade\Cache;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Redis;
use App\Lib\Facade\Validator;
use App\Lib\Helper;
use App\Lib\NoSqlKey;
use App\Lib\RStream;
use App\Lib\Scene;
use App\Lib\SysConst;
use App\Model\ChatRecord;
use App\Model\GroupQuotaLog;
use App\Model\GroupUser;
use App\Model\LuckyMoney;
use App\Model\SysChatContent;
use App\Repository\Impl\AvatarRepository;
use App\Repository\Impl\ConfigRepository;
use App\Repository\Impl\GroupQuotaLogRepository;
use App\Repository\Impl\GroupRepository;
use App\Repository\Impl\GroupUserRepository;
use App\Repository\Impl\LuckyMoneyPackRepository;
use App\Repository\Impl\LuckyMoneyRecordRepository;
use App\Repository\Impl\LuckyMoneyRepository;
use App\Repository\Impl\SessionRepository;
use App\Repository\Impl\SysChatRepository;
use App\Repository\Impl\SysLmUserRepository;
use App\Repository\Impl\UserRepository;
use Hyperf\DbConnection\Db;

use function Hyperf\Config\config;

/**
 * 红包Service
 */
class LuckyMoneyService
{

    /**
     * 建立运气红包.
     *
     * @param  array  $user
     * @param  array  $param
     *
     * @return CommonResult
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function createLuckyMoney(array $user, array $param): CommonResult
    {
        $vResult = Validator::validate($param, [
            'amount_type'     => 'required|integer|in:1,2',
            'count'           => 'required|integer|min:1',
            'bonus_type'      => 'required|integer|in:1,2',
            'title'           => 'string|max:30',
            'require_multi'   => 'numeric|min:0',
            'unit_amount'     => 'numeric|',
            'total_amount'    => 'numeric|',
            'game_company_id' => 'integer|min:1',
        ]);
        if ( ! $vResult->success) {
            return CommonResult::invalidParam('参数验证错误:'.$vResult->msg);
        }

        /** @var SessionRepository $sessionRepo */
        $sessionRepo = Container::get(SessionRepository::class);

        $wsSession = $sessionRepo->rGetSessionByUid($user['id']);
        if (empty($wsSession)) {
            Log::error('创建红包失败, 用户已离线, session丢失', 'createLuckyMoney_err');

            return CommonResult::make(ErrorCode::ErrSessionLost, '用户已离线');
        }

        if (empty(Scene::fetchGroupId($wsSession))) {
            Log::error('创建红包失败, 用户不在群内', 'createLuckyMoney_err');

            return CommonResult::make(ErrorCode::ErrWrongOperateScene, '发起人目前不在群内');
        }

        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $groupId   = Scene::fetchGroupId($wsSession);
        $group     = $groupRepo->getById($groupId);
        if (empty($group)) {
            return CommonResult::make(ErrorCode::ErrGroupNotExists, '未找到该群组');
        }

        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        $groupUser     = $groupUserRepo->getGroupUser($user['id'], $groupId);
        if (empty($groupUser)) {
            return CommonResult::make(ErrorCode::ErrNotBelongsToGroup, '红包发起人不属于该群组');
        }

        $createType = (int)$groupUser['role_type'] <= GroupUser::RoleUser ? LuckyMoney::CreateTypeUser : LuckyMoney::CreateTypePlatform;
        if ($createType === LuckyMoney::CreateTypeUser) {//用户没有权限开游戏币红包或调整打码要求.
            $vResult->validated['bonus_type']    = LuckyMoney::BonusTypeLuckyMoney;
            $vResult->validated['require_multi'] = 1;
            $titleMenu                           = config('bonus.lm_title_menu');
            if ( ! empty($titleMenu)) {
                $titleMap = array_column($titleMenu, 'title', 'id');
            }
            if ( ! isset($param['title_id'])) {
                return CommonResult::invalidParam('标题编号错误, 请选择标题!');
            }
            $param['title_id'] = (int)$param['title_id'];
            if (empty($param['title_id']) || ! isset($titleMap[$param['title_id']])) {
                return CommonResult::invalidParam('标题编号错误, 未匹配的标题!');
            }

            $title = $titleMap[$param['title_id']];
        } else {
            if (isset($vResult->validated['require_multi']) && bccomp((string)$vResult->validated['require_multi'], '0', SysConst::MoneyDecimal) <= 0) {
                return CommonResult::invalidParam('打码倍数必须大于0');
            }
            $vResult->validated['require_multi'] = round($vResult->validated['require_multi'], 1);

            $title = trim(Helper::secureStr($vResult->validated['title']));
        }

        $totalCost                         = 0;
        $vResult->validated['bonus_type']  = (int)$vResult->validated['bonus_type'];
        $vResult->validated['amount_type'] = (int)$vResult->validated['amount_type'];

        if ($vResult->validated['amount_type'] === LuckyMoney::AmountTypeFixed && ! isset($vResult->validated['unit_amount'])) {
            return CommonResult::invalidParam('请填入单个金额');
        }

        if ($vResult->validated['amount_type'] === LuckyMoney::AmountTypeRand && ! isset($vResult->validated['total_amount'])) {
            return CommonResult::invalidParam('请填入总金额');
        }

        switch ($vResult->validated['amount_type']):
            case LuckyMoney::AmountTypeFixed:
                if ($vResult->validated['unit_amount'] < 0.001) {
                    return CommonResult::invalidParam('每包金额至少0.001元');
                }
                $totalCost = bcmul(
                    (string)$vResult->validated['count'],
                    (string)$vResult->validated['unit_amount'],
                    SysConst::MoneyDecimal,
                );
                break;
            case LuckyMoney::AmountTypeRand:
                if ($vResult->validated['total_amount'] < 1) {
                    return CommonResult::invalidParam('手气红包总金额至少一元.');
                }
                $totalCost = $vResult->validated['total_amount'];
                break;
        endswitch;

        if ($vResult->validated['bonus_type'] === LuckyMoney::BonusTypeLuckyMoney) {
            if ($createType === LuckyMoney::CreateTypePlatform && bccomp($group['lucky_money_quota'], $totalCost) < 0) {
                return CommonResult::make(ErrorCode::ErrGroupLuckyMoneyQuotaNotEnough, '群组当前平台彩金红包额度不足');
            }
        } else {
            if ($createType === LuckyMoney::CreateTypeUser) {//用户不能发游戏彩金红包
                return CommonResult::make(ErrorCode::ErrInvalidOperate, '用户无权限发放此类型红包');
            }

            if (bccomp($group['game_coin_quota'], $totalCost) < 0) {
                return CommonResult::make(ErrorCode::ErrGroupLuckyMoneyQuotaNotEnough, '群组当前游戏彩金红包额度不足');
            }

            $gameCompanyMap = array_column((array)config('bonus.game_company_menu'), 'name', 'id');
            if ( ! isset($gameCompanyMap[$vResult->validated['game_company_id']])) {
                return CommonResult::make(ErrorCode::ErrCreateLuckyMoneyFailed, '未定义的游戏厂商!');
            }
        }

        if ($vResult->validated['count'] > $group['user_limit']) {
            return CommonResult::make(
                ErrorCode::ErrInvalidOperate,
                sprintf('紅包數量%d已超過群上限人數%d人', $vResult->validated['count'], $group['user_limit']),
            );
        }

        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);
        $onlineInfo = $groupUserRepo->rGetGroupOnlineUser($groupUser['group_id'], $user['id']);
        if (empty($onlineInfo)) {
            return CommonResult::make(ErrorCode::ErrWrongOperateScene, '发起人目前不在群内');
        }

        $now                         = time();
        $lmTime                      = $now - ($now % 60);//配合cron檢查過期, 秒數歸零.
        $insertLm                    = [];
        $insertLm['group_id']        = $groupUser['group_id'];
        $insertLm['title']           = $title;
        $insertLm['bonus_type']      = $vResult->validated['bonus_type'];//彩金或游戏币
        $insertLm['amount_type']     = $vResult->validated['amount_type'];//金額分配方式
        $insertLm['create_time']     = $now;
        $insertLm['update_time']     = $now;
        $insertLm['scene_type']      = LuckyMoney::SceneTypeGroup;
        $insertLm['count']           = $vResult->validated['count'];
        $insertLm['user_id']         = $user['id'];
        $insertLm['ext_member_id']   = $user['ext_member_id'];
        $insertLm['ext_username']    = $user['ext_username'];
        $insertLm['site_bid']        = $group['site_bid'];
        $insertLm['create_type']     = $createType;
        $insertLm['game_company_id'] = $vResult->validated['game_company_id'] ?? 0;
        $insertLm['require_multi']   = $vResult->validated['require_multi'] ?? 0.1;
        if ($insertLm['amount_type'] === LuckyMoney::AmountTypeFixed) {
            $insertLm['unit_amount']  = $vResult->validated['unit_amount'];
            $insertLm['total_amount'] = 0;
        } else {
            $insertLm['unit_amount']  = 0;
            $insertLm['total_amount'] = $vResult->validated['total_amount'];
        }

        $activeHours            = (int)$configRepo->getByKey(ConfigKey::LmHours);
        $insertLm['start_time'] = $lmTime;//红包有效时间
        $insertLm['end_time']   = $lmTime + (3600 * $activeHours);

        Db::beginTransaction();
        try {
            //1. 写入红包配置
            /** @var LuckyMoneyRepository $luckyMoneyRepo */
            $luckyMoneyRepo = Container::get(LuckyMoneyRepository::class);
            $lm             = $luckyMoneyRepo->insertAndGet($insertLm);
            if (empty($lm)) {
                throw new \PDOException('紅包創建失敗');
            }

            //2. 生成红包拆包数据.
            $genPacks = $this->generateLmPacks($lm);
            if (empty($genPacks)) {
                throw new \PDOException('紅包拆包創建失敗');
            }
            /** @var LuckyMoneyPackRepository $luckyMoneyPackRepo */
            $luckyMoneyPackRepo = Container::get(LuckyMoneyPackRepository::class);
            $count              = $luckyMoneyPackRepo->rSaveLmPackages($lm, $genPacks);
            Log::info(sprintf('红包初始成功! 红包配置: %s, 数量: %d', json_encode($lm, 256), $count), 'lucky_money_create_info');
            $luckyMoneyRepo->rInitLuckyMoneyFlag($lm);//初始化抢红包的flag map, 用于判断是否已抢过.

            //3. 写入群内红包广播讯息
            $extra = [
                'lm_id'       => $lm['id'],
                'amount_type' => $lm['amount_type'],
                'bonus_type'  => $lm['bonus_type'],
                'start_time'  => $lm['start_time'],
                'end_time'    => $lm['end_time'],
                'creator_id'  => $lm['user_id'],
                'close_state' => LuckyMoney::CloseTypeNone,
            ];
            /** @var \App\Service\Impl\ChatService $chatService */
            $chatService   = Container::get(ChatService::class);
            $addChatResult = $chatService->addChatMessage(
                $lm['user_id'],
                $lm['site_bid'],
                $lm['group_id'],
                ['content' => $lm['title']],
                ChatRecord::TypeLmMsg,
                true,
                $extra,
                $lm['id'],
            );
            if ( ! $addChatResult->isSuccess()) {
                Log::error(
                    sprintf('写入红包消息失败! 原始数据: %s, 讯息: %s', json_encode($lm, 256), $addChatResult->msg),
                    'lm_insert_chat_failed',
                );

                throw new \PDOException('创建失败');
            }

            //4. 将红包配置同步到彩票端, 游戏彩金会需要用到
            $syncLm = [
                'lm_id'           => $lm['id'],
                'title'           => $lm['title'],
                'game_company_id' => $lm['game_company_id'],
                'site_bid'        => $lm['site_bid'],
            ];

            /** @var CpApi $cpApi */
            $cpApi = Container::make(CpApi::class, ['siteBid' => $group['site_bid']]);
            $resp  = $cpApi->syncLm($syncLm);
            if ( ! $resp) {
                throw new \PDOException('呼叫彩票端同步红包配置失败! 请求资讯: '.json_encode($cpApi->getReqInfo(), 256));
            }

            //5. 计算红包总金额并处理额度变更, 一般群内红包有额度限制或是拿用户的钱来发红包
            if ($lm['amount_type'] === LuckyMoney::AmountTypeFixed) {
                $totalCost = bcmul($lm['unit_amount'], $lm['count'], SysConst::MoneyDecimal);
            } else {
                $totalCost = $lm['total_amount'];
            }

            if ((int)$lm['bonus_type'] === LuckyMoney::BonusTypeLuckyMoney) {//平台奖金.
                if ($createType === LuckyMoney::CreateTypeUser) {
                    [$subOk, $cpSubErrMsg] = $this->subCpMemberBalance($user, $lm, $totalCost);
                    if ( ! $subOk) {
                        throw new \PDOException($cpSubErrMsg, ErrorCode::ErrLmFailDueToUserRemainBet);
                    }
                } else {
                    $subOk = $groupRepo->subLmQuota($groupUser['group_id'], $totalCost);
                    if ( ! $subOk) {
                        throw new \PDOException('红包额度变动处理失败');
                    }

                    /** @var GroupQuotaLogRepository $quotaLogRepo */
                    $quotaLogRepo = Container::get(GroupQuotaLogRepository::class);
                    $quotaLogRepo->groupAdminUsed(
                        $lm['site_bid'],
                        $lm['group_id'],
                        $user['ext_username'],
                        GroupQuotaLog::QuotaTypeLuckyMoney,
                        $totalCost,
                        GroupQuotaLog::ActionTypeSendCost,
                    );
                }
            } else { //游戏币
                $subOk = $groupRepo->subGameCoinQuota($groupUser['group_id'], $totalCost);
                if ( ! $subOk) {
                    throw new \PDOException('红包额度变动处理失败');
                }

                /** @var GroupQuotaLogRepository $quotaLogRepo */
                $quotaLogRepo = Container::get(GroupQuotaLogRepository::class);
                $quotaLogRepo->groupAdminUsed(
                    $lm['site_bid'],
                    $lm['group_id'],
                    $user['ext_username'],
                    GroupQuotaLog::QuotaTypeGameCoin,
                    $totalCost,
                    GroupQuotaLog::ActionTypeSendCost,
                );
            }

            //6. 推进管道即时广播
            $this->queueLmNotify($addChatResult->data);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error(sprintf('红包创建失败! 讯息: %s', Helper::getExpDetails($e)), 'createLuckyMoney_exception');
            if ($e->getCode() === ErrorCode::ErrLmFailDueToUserRemainBet) {//一般用户发红包需要跟彩票端确认其余额是否充足
                return CommonResult::make(ErrorCode::ErrLmFailDueToUserRemainBet, $e->getMessage());
            } else {
                return CommonResult::make(ErrorCode::ErrCreateLuckyMoneyFailed, '创建失败');
            }
        }
        $luckyMoneyRepo->getOne($lm['id']);//直接生成配置的cache

        Cache::del(NoSqlKey::allGroup($group['site_bid']));

        return CommonResult::success('紅包創建成功', ['id' => $lm['id']]);
    }

    /**
     * 创建系统红包(游戏币)
     *
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function createSysLuckyMoney(array $param): CommonResult
    {
        $vResult = Validator::validate($param, [
            'title'           => 'required|string|min:1|max:30',
            'unit_amount'     => 'required|numeric',
            'require_multi'   => 'required|numeric',
            'admin_id'        => 'required|integer',
            'game_company_id' => 'required|integer',
            'member_id'       => 'array',
        ]);

        if ( ! $vResult->success) {
            return CommonResult::invalidParam($vResult->msg);
        }

        if (empty($vResult->validated['member_id'])) {
            return CommonResult::invalidParam('导入类型未提供对象用户清单');
        }

        if (isset($vResult->validated['require_multi']) && bccomp((string)$vResult->validated['require_multi'], '0', SysConst::MoneyDecimal) <= 0) {
            return CommonResult::invalidParam('打码倍数必须大于0');
        }
        $vResult->validated['require_multi'] = round($vResult->validated['require_multi'], 1);

        /** @var UserRepository $userRepo */
        $userRepo    = Container::get(UserRepository::class);
        $importUsers = $userRepo->getUsersByCondition(['ext_member_id' => $vResult->validated['member_id'], 'site_bid' => $param['site_bid']],
            ['id', 'ext_member_id', 'ext_username']);

        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);
        $now        = time();
        $startTime  = strtotime(date('Y-m-d H:i:00', $now));
        $endTime    = $startTime + ((int)$configRepo->getByKey(ConfigKey::LmHours) * 3600);
        /** @var LuckyMoneyRepository $lmRepo */
        $lmRepo   = Container::get(LuckyMoneyRepository::class);
        $insertLm = [
            'title'           => $vResult->validated['title'],
            'site_bid'        => strtoupper($param['site_bid']),
            'unit_amount'     => $vResult->validated['unit_amount'],
            'require_multi'   => $vResult->validated['require_multi'],
            'admin_id'        => (int)$param['admin_id'],
            'bonus_type'      => LuckyMoney::BonusTypeGameCoin,
            'scene_type'      => LuckyMoney::SceneTypeSys,
            'amount_type'     => LuckyMoney::AmountTypeFixed,
            'game_company_id' => $vResult->validated['game_company_id'],
            'create_time'     => $now,
            'update_time'     => $now,
            'start_time'      => $startTime,
            'end_time'        => $endTime,
            'count'           => count($importUsers),
        ];

        DB::beginTransaction();
        try {
            //1. 写入红包配置
            $lm = $lmRepo->insertAndGet($insertLm);
            if (empty($lm)) {
                throw new \PDOException('系统红包写入失败: '.json_encode($insertLm, 256));
            }

            //2. 将红包配置同步到彩票端
            /** @var CpApi $cpApi */
            $cpApi  = Container::make(CpApi::class, ['siteBid' => $param['site_bid']]);
            $syncLm = [
                'lm_id'           => $lm['id'],
                'title'           => $lm['title'],
                'game_company_id' => $lm['game_company_id'],
                'site_bid'        => $lm['site_bid'],
            ];
            $resp   = $cpApi->syncLm($syncLm);
            if ( ! $resp) {
                Log::error('呼叫彩票端同步红包配置失败! 请求资讯: '.json_encode($cpApi->getReqInfo(), 256), 'sync_lm_req_failed');

                return CommonResult::make(ErrorCode::ErrSyncLmFailed, '创建失败');
            }

            //3. 生成红包拆包数据.
            $genPacks = $this->generateLmPacks($lm);
            if (empty($genPacks)) {
                throw new \PDOException('紅包拆包創建失敗');
            }
            /** @var LuckyMoneyPackRepository $luckyMoneyPackRepo */
            $luckyMoneyPackRepo = Container::get(LuckyMoneyPackRepository::class);
            $luckyMoneyPackRepo->rSaveLmPackages($lm, $genPacks);
            Log::info(sprintf('系统红包初始成功! 红包ID: %d, 数量: %d', json_encode($lm['id'], 256), $lm['count']), 'sys_lucky_money_create_info');

            //4. 写入系统群消息
            $result = $this->createSysLmChat($lm);
            if ( ! $result->isSuccess()) {
                throw new \PDOException('写入系统红包聊天讯息失败! 红包数据: '.json_encode($insertLm, 256));
            }
            $lmRepo->rInitLuckyMoneyFlag($lm);//初始化抢红包的flag map, 用于判断是否已抢过.
            $lmRepo->getOne($lm['id']);//直接生成配置的cache

            //5. (1)批次写入红包导入用户名单 (2)批次写入系统消息收接用户数据, 这步做完会顺便即时推送消息
            $newChatId      = $result->data['chat_id'];
            $sysChatUserTpl = ['chat_id' => $newChatId, 'create_time' => $now];
            $sysLmUserTpl   = ['lm_id' => $lm['id'], 'create_time' => $now, 'site_bid' => $lm['site_bid']];
            $batch          = array_chunk($importUsers, 500);
            foreach ($batch as $batchUsers) {
                $lmUserList = [];
                $chatList   = [];
                foreach ($batchUsers as $user) {
                    $rowLmUser                  = $sysLmUserTpl;
                    $rowLmUser['user_id']       = $user->id;
                    $rowLmUser['ext_member_id'] = $user->ext_member_id;
                    $rowLmUser['ext_username']  = $user->ext_username;
                    $lmUserList[]               = $rowLmUser;

                    $rowChat                  = $sysChatUserTpl;
                    $rowChat['user_id']       = $user->id;
                    $rowChat['ext_member_id'] = $user->ext_member_id;
                    $rowChat['ext_username']  = $user->ext_username;
                    $chatList[]               = $rowChat;
                }

                RStream::push(RStream::TypeBatchSysLmUser, ['list' => json_encode($lmUserList), 'lm_id' => $lm['id'], 'retry' => 0], RStream::BatchStream);
                RStream::push(RStream::TypeBatchSysChat, ['list' => json_encode($chatList), 'chat_id' => $newChatId, 'retry' => 0], RStream::BatchStream);
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            Log::error(sprintf('创建系统红包异常! 红包数据: %s, 讯息: %s', json_encode($insertLm, 256), Helper::getExpDetails($e)), 'createSysLuckyMoney_err');

            return CommonResult::error('创建失败');
        }

        return CommonResult::success('ok', ['lm_id' => $lm['id']]);
    }

    /**
     * 生成红包数据.
     *
     * @param  array  $luckyMoney
     *
     * @return array
     */
    protected function generateLmPacks(array $luckyMoney): array
    {
        if (empty($luckyMoney)) {
            Log::error('红包分包生成失败, 红包为空', 'generateLmPacks_err');

            return [];
        }

        $generatedPacks = match ((int)$luckyMoney['amount_type']) {
            LuckyMoney::AmountTypeFixed => $this->genFixedAmountPacks($luckyMoney),
            LuckyMoney::AmountTypeRand => $this->genRandAmountPacks($luckyMoney),
            default => [],
        };
        if (empty($generatedPacks)) {
            Log::error('红包分包生成失败', 'generateLmPacks_err');

            return [];
        }

        /** @var LuckyMoneyPackRepository $luckyMoneyPackRepo */
        $luckyMoneyPackRepo = Container::get(LuckyMoneyPackRepository::class);
        $insertPacks        = $luckyMoneyPackRepo->insertPacksAndGet($luckyMoney['id'], $generatedPacks);
        if (empty($insertPacks)) {
            Log::error('红包分包写入失败', 'generateLmPacks_err');

            return [];
        }

        return $insertPacks;
    }

    /**
     * 生成固定金額紅包.
     *
     * @param  array  $luckyMoney
     *
     * @return array
     */
    protected function genFixedAmountPacks(array $luckyMoney): array
    {
        $packs = [];
        for ($i = 1; $i <= $luckyMoney['count']; $i++) {
            $packs[] = ['amount' => (string)$luckyMoney['unit_amount'], 'no' => $i];
        }

        return $packs;
    }

    /**
     * 生成隨機金額紅包.
     *
     * @param  array  $luckyMoney
     *
     * @return array
     */
    protected function genRandAmountPacks(array $luckyMoney): array
    {
        $amountBase    = 1000;
        $multiplyTotal = (int)round($luckyMoney['total_amount'] * $amountBase);

        $luckyMoney['count'] = (int)$luckyMoney['count'];
        if ($luckyMoney['count'] === 1) {
            $remain = $multiplyTotal;
        } else {
            //每包區間
            $avg = (int)floor($multiplyTotal / $luckyMoney['count']);
            $min = (int)floor($avg * 0.8);
            $max = (int)ceil($avg * 1.2);

            $rawAmounts = [];
            $remain     = $multiplyTotal;
            for ($i = 1; $i <= $luckyMoney['count']; $i++) {
                $maxCanUse    = min($max, $remain - ($min * ($luckyMoney['count'] - $i)));
                $rand         = rand($min, $maxCanUse);
                $rawAmounts[] = $rand;
                $remain       -= $rand;
            }
        }

        $rawAmounts[] = $remain;

        $packs = [];
        foreach ($rawAmounts as $i => $amt) {
            $amount = number_format($amt / $amountBase, SysConst::MoneyDecimal, '.', '');
            if (bccomp($amount, '0', SysConst::MoneyDecimal) === 0) {
                continue;
            }
            $packs[] = [
                'amount' => $amount,
                'no'     => $i + 1,
            ];
        }

        return $packs;
    }

    /**
     *
     * @param  array  $user
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function grabLuckyMoney(array $user, array $param): CommonResult
    {
        if (empty($param['lm_id'])) {
            return CommonResult::invalidParam('参数错误');
        }

        /** @var LuckyMoneyRepository $lmRepo */
        $lmRepo = Container::get(LuckyMoneyRepository::class);
        $lm     = $lmRepo->getOne($param['lm_id']);
        if (empty($lm)) {
            return CommonResult::make(ErrorCode::ErrGrabLmNotExists, '红包不存在');
        }
        $lm['scene_type'] = (int)$lm['scene_type'];

        /** @var \App\Service\Impl\UserService $userService */
        $userService = Container::get(UserService::class);
        $creatorInfo = $userService->getLmCreatorInfo($lm, $user['id'], $lm['scene_type'] === LuckyMoney::SceneTypeSys);
        if (empty($creatorInfo)) {
            Log::error(sprintf('搶紅包錯誤, 查詢發紅包用戶失敗! 配置: %s', json_encode($lm, 256)), 'grabLuckyMoney_err');

            return CommonResult::error('領取失敗');
        }

        $validateResult = $this->validatePermission($user, $lm, $creatorInfo);
        if ( ! $validateResult->isSuccess()) {
            return $validateResult;
        }

        if ((int)$lm['close_type'] !== LuckyMoney::CloseTypeNone) {
            return CommonResult::make(ErrorCode::ErrGrabLmExpired, '手慢了，红包派完了', $creatorInfo);
        }

        $takenFlagExists = $lmRepo->rHasUserTakenLuckyMoney($lm['id'], $user['id']);
        if ($takenFlagExists) {
            return CommonResult::make(ErrorCode::ErrAlreadyTakenLm, '已領取过红包', $creatorInfo);
        }

        $lmPackKey = NoSqlKey::luckyMoneyPackages($lm['id']);
        $redis     = Redis::instance(Redis::PoolBonus);
        $packJson  = $redis->rpop($lmPackKey);
        if (empty($packJson)) {
            return CommonResult::make(ErrorCode::ErrGrabLmNoStock, '手慢了，红包派完了');
        }

        $pack = json_decode($packJson, true);
        if (bccomp("0", (string)$pack['amount'], SysConst::MoneyDecimal) >= 0) {
            Log::error(
                sprintf('抢红包金额错误! amount ≤ 0, 红包: %s, 拆包: %s', json_encode($lm, 256), $packJson),
                'grab_lm_amount_err',
            );

            return CommonResult::make(ErrorCode::ErrGrabLmFailed, '領取失败', $creatorInfo);
        }

        Db::beginTransaction();
        try {
            /** @var LuckyMoneyPackRepository $luckyMoneyPackRepo */
            $luckyMoneyPackRepo = Container::get(LuckyMoneyPackRepository::class);
            $ok                 = $luckyMoneyPackRepo->updateAsTaken($user['id'], $pack['id']);
            if ( ! $ok) {
                $luckyMoneyPackRepo->rAddLmPackBack($lm['id'], $pack);

                return CommonResult::make(ErrorCode::ErrGrabLmFailed, '領取失败', $creatorInfo);
            }
            $pack = $luckyMoneyPackRepo->getOne($pack['id']);

            $now            = time();
            $insertLmRecord = [
                'lm_id'             => $lm['id'],
                'pack_id'           => $pack['id'],
                'site_bid'          => $lm['site_bid'],
                'amount'            => $pack['amount'],
                'require_amount'    => $lm['require_multi'] <= 0
                    ? '0'
                    : bcmul(
                        (string)$pack['amount'],
                        (string)$lm['require_multi'],
                        SysConst::MoneyDecimal,
                    ),
                'user_id'           => $user['id'],
                'group_id'          => $lm['group_id'],
                'create_time'       => $now,
                'ext_platform_type' => $user['ext_platform_type'],
                'ext_member_id'     => $user['ext_member_id'],
                'ext_username'      => $user['ext_username'],
            ];

            if ($lm['scene_type'] === LuckyMoney::SceneTypeSys) {
                /** @var SysLmUserRepository $sysLmUserRepo */
                $sysLmUserRepo = Container::get(SysLmUserRepository::class);
                $sysLmUserRepo->updateTaken($lm['id'], $user['id']);
            }

            /** @var LuckyMoneyRecordRepository $recordRepo */
            $recordRepo = Container::get(LuckyMoneyRecordRepository::class);
            $record     = $recordRepo->insertAndGet($insertLmRecord);
            if (empty($record)) {
                throw new \PDOException('紅包紀錄寫入失敗!');
            }
            $lmRepo->rSetUserTakenFlag($lm['id'], $user['id']);

            //推进queue后打给彩票派奖.
            $fundingData = ['lm' => json_encode($lm), 'record' => json_encode($record)];
            $ok          = RStream::push(RStream::TypeLmSendCpFunding, $fundingData);
            if ( ! $ok) {
                throw new \PDOException('搶紅包成功但推送至彩票端派獎失敗! 应推送數據: '.json_encode($fundingData, 256));
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            Log::error(
                sprintf(
                    '搶紅包異常! 用戶: %s, 紅包配置: %s, 紅包: %s 訊息: %s',
                    json_encode($user, 256),
                    json_encode($lm, 256),
                    $packJson,
                    Helper::getExpDetails($e),
                ),
                'grab_lm_err',
            );

            return CommonResult::make(ErrorCode::ErrGrabLmFailed, '領取失败', $creatorInfo);
        }

        //检查剩余数量看需不需要结算
        $remainPackCount = (int)$redis->lLen($lmPackKey);
        if ($remainPackCount <= 0) {//自动侦测抽完关闭, 已抽完的情况不需要结算红包余额.
            $settleResult = $this->simpleSettleLm($lm, LuckyMoney::CloseTypeNoStock);
            if ($settleResult->isSuccess()) {
                try {
                    if ($lm['scene_type'] === LuckyMoney::SceneTypeGroup) {
                        $streamData = [
                            'lm_id'      => $lm['id'],
                            'group_id'   => $lm['group_id'],
                            'user_id'    => $lm['user_id'],
                            'site_id'    => $lm['site_bid'],
                            'close_type' => LuckyMoney::CloseTypeNoStock,
                        ];
                        RStream::push(RStream::TypeNotifyGroupLmClose, $streamData);
                    } else {
                        $streamData = [
                            'lm_id'      => $lm['id'],
                            'site_bid'   => $lm['site_bid'],
                            'close_type' => LuckyMoney::CloseTypeNoStock,
                        ];
                        RStream::push(RStream::TypeNotifySysLmClose, $streamData);
                    }
                } catch (\Throwable $e) {
                    Log::error(sprintf('红包抽完自动结算时推送通知异常! 红包: %s, 讯息: %s', json_encode($lm, 256), Helper::getExpDetails($e)), 'no_stock_lm_push_notify_err');
                }
            } else {
                Log::error(sprintf('红包抽完自动结算失败! 红包: %s, 讯息: %s', json_encode($lm, 256), $settleResult->msg), 'auto_settle_no_stock_lm_err');
            }
        }

        $info             = $creatorInfo;
        $info['congrats'] = '恭喜获得';
        $info['bonus']    = $record['amount'] + 0 .' 元';

        return CommonResult::success('领取成功', $info);
    }

    /**
     * 將紅包訊息推送給群成員.
     *
     * @param  array  $lmChat
     *
     * @return bool
     */
    public function queueLmNotify(array $lmChat): bool
    {
        if (empty($lmChat)) {
            return false;
        }

        return RStream::push(RStream::TypeLuckyMoneyStart, $lmChat);
    }

    /**
     * 送给彩票端派奖.
     *
     * @param  array  $msg
     *
     * @return void
     */
    public function sendCpFunding(array $msg): void
    {
        $lm     = ! empty($msg['lm']) && json_validate($msg['lm']) ? json_decode($msg['lm'], true) : [];
        $record = ! empty($msg['record']) && json_validate($msg['record']) ? json_decode($msg['record'], true) : [];
        if ( ! $lm || ! $record) {
            Log::error('将红包送至彩票端派奖处理错误! 收到的msg缺少栏位, 原始数据:'.json_encode($msg, 256), __METHOD__);

            return;
        }

        try {
            /** @var \App\Lib\CpApi $cpApi */
            $cpApi = Container::make(CpApi::class, ['siteBid' => $lm['site_bid']]);
            $resp  = $cpApi->sendCpFunding($lm, $record);
            if (empty($resp) || (isset($resp['errcode']) && $resp['errcode'] !== 0)) {
                $respMsg = $resp['errmsg'] ?? '';
                Log::error(sprintf('紅包紀錄派獎委託失敗, 數據: %s, 回傳訊息: %s', json_encode($msg, 256), $respMsg), 'sendCpFunding_err');
            }

            $sendLog = sprintf(
                '紅包紀錄派獎已委託, 红包ID: %s, 纪录ID: %s, 站点: %s, 用户: %s, %s',
                $lm['id'],
                $record['id'],
                $lm['site_bid'],
                $record['ext_member_id'],
                $record['ext_username'],
            );
            Log::info($sendLog, 'send_cp_funding_info');
        } catch (\Throwable $e) {
            Log::error('呼叫彩票端派奖接口异常! 讯息:'.Helper::getExpDetails($e), 'sendCpFunding_exception');
        }
    }

    /**
     * 同步派奖状态.
     *
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function syncFundingRecord(array $param): CommonResult
    {
        $vResult = Validator::validate(
            $param,
            [
                'lm_id'         => 'required|integer',
                'record_id'     => 'required|integer',
                'send_state'    => 'required|integer',
                'fund_time'     => 'required|integer',
                'chat_user_id'  => 'required|integer',
                'failed_reason' => 'string',
            ],
        );

        if ( ! $vResult->success) {
            return CommonResult::invalidParam("参数错误:".$vResult->msg);
        }

        /** @var LuckyMoneyRecordRepository $recordRepo */
        $recordRepo = Container::get(LuckyMoneyRecordRepository::class);
        $ok         = $recordRepo->updateSendState(
            $param['record_id'],
            ['fund_time' => (int)$param['fund_time'], 'send_state' => (int)$param['send_state']],
        );

        if ( ! $ok) {
            return CommonResult::error('同步失败! 数据:'.json_encode($param, 256));
        }

        return CommonResult::success();
    }

    /**
     * 取红包纪录
     *
     * @param  mixed  $user
     * @param  array  $params
     *
     * @return array
     */
    public function getRecordInfo(mixed $user, array $params): array
    {
        if (empty($params['lm_id'])) {
            return CommonResult::invalidParam('参数错误')->toArray();
        }

        $page     = isset($params['page']) && $params['page'] > 0 ? (int)$params['page'] : SysConst::DefaultPage;
        $pageSize = isset($params['page_size']) && $params['page_size'] > 0 ? (int)$params['page_size'] : SysConst::LmRecordPageSize;

        /** @var LuckyMoneyRepository $lmRepo */
        $lmRepo = Container::get(LuckyMoneyRepository::class);
        $lm     = $lmRepo->getOne((int)$params['lm_id']);
        if (empty($lm)) {
            return CommonResult::invalidParam('红包不存在')->toArray();
        }

        if ((int)$user['id'] !== (int)$lm['user_id']) {
            return CommonResult::make(ErrorCode::ErrInvalidOperate, '当前用户无观看权限')->toArray();
        }

        $info = $this->buildRecordLmInfo($lm);
        /** @var LuckyMoneyRecordRepository $recordRepo */
        $recordRepo = Container::get(LuckyMoneyRecordRepository::class);
        [$records, $pagination] = $recordRepo->getRecords($lm['id'], $page, $pageSize);
        $filterList = [];
        foreach ($records as $record) {
            $each         = [
                'create_time'     => $record->create_time,
                'create_time_str' => date('Y-m-d H:i:s', $record->create_time),
                'username'        => Helper::maskUsername($record->ext_username),
                'bonus'           => ($record->amount + 0).'元',
            ];
            $filterList[] = $each;
        }

        $info['lm']['record'] = $filterList;

        $data               = CommonResult::success('ok', $info)->toArray();
        $data['pagination'] = $pagination->get();

        return $data;
    }

    /**
     * 组建派奖纪录红包资讯.
     *
     * @param  array  $lm
     *
     * @return array
     */
    protected function buildRecordLmInfo(array $lm): array
    {
        $userRepo    = Container::get(UserRepository::class);
        $user        = $userRepo->getOne($lm['user_id']);
        $maskName    = Helper::maskUsername($user['ext_username']);
        $avatarId    = $user['avatar_id'];
        $requireText = sprintf('平台打码：打码 %s 倍后可提现', $lm['require_multi'] + 0);

        /** @var AvatarRepository $avatarRepo */
        $avatarRepo = Container::get(AvatarRepository::class);
        $avatarMap  = $avatarRepo->getMap();
        $avatarPath = isset($avatarMap[$avatarId]) ? trim($avatarMap[$avatarId]) : '';

        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);
        $avatarUrl  = $configRepo->getByKey(ConfigKey::CdnUrl).$avatarPath;

        /** @var LuckyMoneyPackRepository $packRepo */
        $packRepo = Container::get(LuckyMoneyPackRepository::class);
        [$totalCount, $takenCount, $totalAmount, $takenAmount] = $packRepo->getLmState($lm['id']);

        $remainStateText  = $takenAmount > 0 ? sprintf('剩余 %s 元', $takenCount) : '红包已被抢光';
        $remainPackText   = sprintf('已领取 %d / %d 个', $takenAmount, $totalCount);
        $remainAmountText = sprintf('共 %s / %s 元', ($takenCount + 0), ($totalAmount + 0));

        return [
            'creator' => ['name' => $maskName, 'avatar_url' => $avatarUrl],
            'lm'      => [
                'amount_type'        => (int)$lm['amount_type'],
                'title'              => $lm['title'],
                'remain_state_text'  => $remainStateText,
                'require_text'       => $requireText,
                'remain_pack_text'   => $remainPackText,
                'remain_amount_text' => $remainAmountText,
            ],
        ];
    }

    /**
     * 扣除彩票端用户余额
     *
     * @param  array   $user
     * @param  array   $lmInfo
     * @param  string  $totalCost
     *
     * @return array
     */
    protected function subCpMemberBalance(array $user, array $lmInfo, string $totalCost): array
    {
        /** @var CpApi $cpApi */
        $cpApi = Container::make(CpApi::class, ['siteBid' => $user['site_bid']]);

        return $cpApi->subCpMemberBalance($user, $lmInfo, $totalCost);
    }

    /**
     * 关闭并结算红包
     *
     * @param  mixed  $user
     * @param  array  $params
     *
     * @return \App\Lib\CommonResult
     */
    public function closeLuckyMoney(array $user, array $params): CommonResult
    {
        if (empty($params['lm_id'])) {
            return CommonResult::invalidParam('参数错误');
        }

        /** @var LuckyMoneyRepository $lmRepo */
        $lmRepo = Container::get(LuckyMoneyRepository::class);
        $lm     = $lmRepo->getOne($params['lm_id']);
        if (empty($lm)) {
            return CommonResult::error('红包不存在');
        }

        $lm['close_type']  = (int)$lm['close_type'];
        $lm['create_type'] = (int)$lm['create_type'];
        $lm['bonus_type']  = (int)$lm['bonus_type'];
        $lm['scene_type']  = (int)$lm['scene_type'];

        if ((int)$user['id'] !== (int)$lm['user_id']) {
            return CommonResult::error('非法操作, 用户非红包创建者');
        }

        if ($lm['close_type'] !== LuckyMoney::CloseTypeNone) {
            return CommonResult::error('紅包已結束');
        }

        Db::beginTransaction();
        try {
            /** @var LuckyMoneyPackRepository $packRepo */
            $packRepo  = Container::get(LuckyMoneyPackRepository::class);
            $lmBalance = $packRepo->sumLmBalance($lm['id']);

            //修改红包状态为已关闭
            $updated = $lmRepo->update($lm['id'], ['close_type' => LuckyMoney::CloseTypeManual]);
            if ( ! $updated) {
                throw new \PDOException('修改红包关闭状态失败');
            }

            if ($lmBalance > 0 && $lm['scene_type'] !== LuckyMoney::SceneTypeSys) {
                if ($lm['create_type'] === LuckyMoney::CreateTypePlatform) {
                    /** @var GroupRepository $groupRepo */
                    $groupRepo = Container::get(GroupRepository::class);
                    $group     = $groupRepo->getById($lm['group_id']);
                    if (empty($group)) {
                        throw new \PDOException('收回失败, 未找到红包群组:'.json_encode($lm, 256));
                    }

                    $ok = $groupRepo->addBackQuota($group['id'], $lm['bonus_type'], $lmBalance);
                    if ( ! $ok) {
                        throw new \PDOException('返还群组红包额度失败: '.json_encode(['group' => $group, 'lm' => $lm], 256));
                    }
                } else {
                    /** @var CpApi $cpApi */
                    $cpApi = Container::make(CpApi::class, ['siteBid' => $user['site_bid']]);
                    [$settleOk, $errMsg] = $cpApi->addBackUserBalance($user, $lm, $lmBalance, LuckyMoney::CloseTypeManual);
                    if ( ! $settleOk) {
                        throw new \PDOException("退回用户红包余额失败!: ".$errMsg);
                    }
                }
            }

            Cache::del(NoSqlKey::luckyMoneyInfo($lm['id']));

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            Log::error(sprintf('收回红包异常! 红包数据: %s, 讯息: %s', json_encode($lm, 256), Helper::getExpDetails($e)));

            return CommonResult::error('操作失败');
        }

        //广播到群内通知让前端修改显示
        $streamData = [
            'lm_id'      => $lm['id'],
            'site_bid'   => $lm['site_bid'],
            'group_id'   => $lm['group_id'],
            'user_id'    => $lm['user_id'],
            'close_type' => LuckyMoney::CloseTypeManual,
        ];
        RStream::push(RStream::TypeNotifyGroupLmClose, $streamData);

        return CommonResult::success('成功收回');
    }

    /**
     * 取搶紅包頁資訊.
     *
     * @param  array  $user
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function getGrabPageInfo(array $user, array $param): CommonResult
    {
        if (empty($param['lm_id'])) {
            return CommonResult::invalidParam('參數錯誤');
        }

        /** @var LuckyMoneyRepository $lmRepo */
        $lmRepo = Container::get(LuckyMoneyRepository::class);
        $lm     = $lmRepo->getOne($param['lm_id']);
        if (empty($lm)) {
            return CommonResult::error('紅包不存在');
        }

        /** @var \App\Service\Impl\UserService $userService */
        $userService = Container::get(UserService::class);
        $creatorInfo = $userService->getLmCreatorInfo($lm, $user['id'], (int)$lm['scene_type'] === LuckyMoney::SceneTypeSys);
        if (empty($creatorInfo)) {
            return CommonResult::error('查無資訊');
        }

        $info = ['creator' => $creatorInfo, 'lm' => ['id' => $lm['id'], 'title' => $lm['title'],]];

        return CommonResult::success('ok', $info);
    }

    /**
     * 结算超时红包
     *
     * @return void
     */
    public function settleExpireLuckyMoney(): void
    {
        /** @var LuckyMoneyRepository $lmRepo */
        $lmRepo = Container::get(LuckyMoneyRepository::class);

        $lmList = $lmRepo->getOpenLmList([
            'id',
            'create_type',
            'title',
            'end_time',
            'site_bid',
            'bonus_type',
            'group_id',
            'admin_id',
            'scene_type',
            'user_id',
        ]);
        if (empty($lmList)) {
            return;
        }

        /** @var LuckyMoneyPackRepository $packRepo */
        $packRepo = Container::get(LuckyMoneyPackRepository::class);

        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);

        /** @var GroupQuotaLogRepository $quotaLogRepo */
        $quotaLogRepo = Container::get(GroupQuotaLogRepository::class);

        $apiMap = [];
        $now    = time();
        foreach ($lmList as $lm) {
            if ($lm->end_time > $now) {
                continue;
            }

            //取出site_bid
            $siteBid = isset($lm->site_bid) ? strtoupper($lm->site_bid) : '';
            if ($siteBid === '') {
                Log::cli('site_bid为空!:'.json_encode($lm, 256));
                continue;
            }

            /** @var CpApi $api */
            $api = $apiMap[$siteBid] ?? null;
            if ($api === null) {
                $api = Container::make(CpApi::class, ['siteBid' => $siteBid]);
                if ($api) {
                    $apiMap[$siteBid] = $api;
                }
            }

            Db::beginTransaction();
            try {
                //更新红包关闭状态
                $updated = $lmRepo->update($lm->id, ['close_type' => LuckyMoney::CloseTypeExpire]);
                if ( ! $updated) {
                    throw new \PDOException('更新红包关闭状态失败:'.json_encode($lm, 256));
                }

                $lmRepo->rDelTakenFlag($lm->id);
                $packRepo->rDelLmPackages($lm->id);

                //检查余额
                $lmBalance      = $packRepo->sumLmBalance($lm->id);
                $lm->balance    = $lmBalance;
                $balanceUpdated = true;
                if ($lmBalance > 0 && (int)$lm->scene_type !== LuckyMoney::SceneTypeSys) {//群内红包才需要结算额度.
                    if ((int)$lm->create_type === LuckyMoney::CreateTypeUser && (int)$lm->bonus_type === LuckyMoney::BonusTypeLuckyMoney) {
                        /** @var UserRepository $userRepo */
                        $userRepo       = Container::get(UserRepository::class);
                        $createUserInfo = $userRepo->getOne($lm->user_id, ['ext_member_id', 'ext_username', 'ext_platform_type']);
                        [$balanceUpdated, $addBalanceErr] = $api->addBackUserBalance($createUserInfo, (array)$lm, $lmBalance, LuckyMoney::CloseTypeExpire);
                    } else {
                        $balanceUpdated = $groupRepo->addBackQuota($lm->group_id, $lm->bonus_type, $lmBalance);
                        $quotaLogRepo->sysAdd($lm->site_bid, $lm->group_id, $lm->bonus_type, $lmBalance);
                    }
                }

                if ( ! $balanceUpdated) {
                    if (isset($addBalanceErr) && $addBalanceErr !== 'ok') {
                        $uInfo = $createUserInfo ?? [];
                        $msg   = sprintf(
                            '补回用户红包余额失败! 参数: %s ,讯息: %s',
                            json_encode(['user' => $uInfo, 'lm' => $lm, 'balance' => $lmBalance], 256),
                            $addBalanceErr,
                        );
                    } else {
                        $msg = sprintf('补回群组活动额度失败! 参数: %s', json_encode($lm, 256));
                    }

                    throw new \PDOException('红包排程结算失败! 讯息: '.$msg);
                }

                if ((int)$lm->scene_type === LuckyMoney::SceneTypeGroup) {
                    RStream::push(
                        RStream::TypeNotifyGroupLmClose,
                        [
                            'lm_id'      => $lm->id,
                            'site_bid'   => $lm->site_bid,
                            'group_id'   => $lm->group_id,
                            'user_id'    => $lm->user_id,
                            'close_type' => LuckyMoney::CloseTypeExpire,
                        ],
                    );
                } else {
                    RStream::push(
                        RStream::TypeNotifySysLmClose,
                        [
                            'lm_id'      => $lm->id,
                            'site_bid'   => $lm->site_bid,
                            'close_type' => LuckyMoney::CloseTypeExpire,
                        ],
                    );
                }

                Cache::del(NoSqlKey::luckyMoneyInfo($lm->id));

                Db::commit();
            } catch (\Throwable $e) {
                Db::rollBack();
                Log::error(sprintf('排程红包结算异常! 红包: %s, 讯息: %s', json_encode($lm, 256), Helper::getExpDetails($e)));
            }
        }
    }

    /**
     * 建立系统红包聊天讯息.
     *
     * @param  array  $lm
     *
     * @return CommonResult
     */
    protected function createSysLmChat(array $lm): CommonResult
    {
        $now  = time();
        $chat = [
            'content'       => Helper::secureStr($lm['title']),
            'admin_id'      => $lm['admin_id'],
            'audience_type' => SysChatContent::AudienceByUsers,
            'content_type'  => SysChatContent::ContentTypeLuckyMoney,
            'site_bid'      => $lm['site_bid'],
            'create_time'   => $now,
            'update_time'   => $now,
            'custom_id'     => $lm['id'],
            'extra'         => json_encode([
                'lm_id'       => $lm['id'],
                'amount_type' => $lm['amount_type'],
                'bonus_type'  => $lm['bonus_type'],
                'start_time'  => $lm['start_time'],
                'end_time'    => $lm['end_time'],
                'creator_id'  => 0,
                'close_state' => LuckyMoney::CloseTypeNone,
            ]),
        ];

        /** @var SysChatRepository $sysChatRepo */
        $sysChatRepo = Container::get(SysChatRepository::class);
        $newChatId   = $sysChatRepo->add($chat);
        if (empty($newChatId)) {
            return CommonResult::error('添加失败');
        }

        return CommonResult::success('ok', ['chat_id' => $newChatId]);
    }

    /**
     * 取发红包页面展示资讯.
     *
     * @param  array  $user
     *
     * @return \App\Lib\CommonResult
     */
    public function getCreateLuckyMoneyInfo(array $user): CommonResult
    {
        /** @var SessionRepository $sessionRepo */
        $sessionRepo = Container::get(SessionRepository::class);
        $session     = $sessionRepo->rGetSessionByUid($user['id']);
        if (empty($session)) {
            return CommonResult::make(ErrorCode::ErrWrongOperateScene, '用户已离线');
        }

        $groupId = Scene::fetchGroupId($session);
        if (empty($groupId)) {
            return CommonResult::make(ErrorCode::ErrWrongOperateScene, '用户未处在群组中');
        }

        /** @var GroupRepository $groupRepo */
        $groupRepo = Container::get(GroupRepository::class);
        $group     = $groupRepo->getById($groupId);
        if (empty($group)) {
            return CommonResult::make(ErrorCode::ErrGroupNotExists, '未知的用户群组');
        }

        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        $groupUser     = $groupUserRepo->getGroupUser($user['id'], $group['id']);
        if (empty($groupUser)) {
            return CommonResult::make(ErrorCode::ErrNotBelongsToGroup, '用户不属于该群组');
        }

        $bonusConfig                     = config('bonus');
        $displayInfo                     = [];
        $displayInfo['amount_type_menu'] = $bonusConfig['amount_types'];
        if ($groupUser['role_type'] <= GroupUser::RoleUser) {
            /** @var CpApi $cpApi */
            $cpApi = Container::make(CpApi::class, ['siteBid' => $user['site_bid']]);
            [$cpInfo, $errMsg] = $cpApi->getCreateLmInfo($user['ext_member_id']);
            if (empty($cpInfo)) {
                return CommonResult::make(ErrorCode::ErrInvalidOperate, $errMsg);
            }
            $balanceText       = isset($cpInfo['usable_balance']) ? (string)($cpInfo['usable_balance'] + 0).'元' : '余额获取失败';
            $remainRequireText = isset($cpInfo['remain_require']) ? (string)($cpInfo['remain_require'] + 0).'元' : '剩余打码要求获取失败';

            $displayInfo['page_title']          = '发红包';
            $displayInfo['online_user_count']   = '';
            $displayInfo['is_admin']            = 0;
            $displayInfo['title_menu']          = $bonusConfig['lm_title_menu'];
            $displayInfo['bonus_type_menu']     = $bonusConfig['bonus_type_menu_user'];
            $displayInfo['quota']               = ['money' => sprintf('可提现余额：%s', $balanceText)];
            $displayInfo['require_text']        = '红包默认1倍打码后提现';
            $displayInfo['game_company_menu']   = [];
            $displayInfo['remain_require_text'] = sprintf('剩余打码要求：%s', $remainRequireText);
        } else {
            $displayInfo['page_title']        = '群管理发红包';
            $displayInfo['online_user_count'] = sprintf('在线人数： %d', count($groupUserRepo->rGetGroupOnlineUsers($group['id'])));
            $displayInfo['is_admin']          = 1;
            $displayInfo['title_menu']        = [];
            $displayInfo['bonus_type_menu']   = $bonusConfig['bonus_type_menu_admin'];
            $displayInfo['quota']             = [
                'money' => sprintf('剩余额度：%s元', ($group['lucky_money_quota'] + 0)),
                'game'  => sprintf('剩余额度：%s元', ($group['game_coin_quota'] + 0)),
            ];;
            $displayInfo['require_text']        = '说明：0倍则不要求，最小支持小数点后1位，例如0.1倍';
            $displayInfo['game_company_menu']   = $bonusConfig['game_company_menu'];
            $displayInfo['remain_require_text'] = '';
        }

        return CommonResult::success('ok', $displayInfo);
    }

    /**
     * 验证用户资格.
     *
     * @param  array  $user
     * @param  array  $lm
     * @param  array  $creatorInfo
     *
     * @return \App\Lib\CommonResult
     */
    protected function validatePermission(array $user, array $lm, array $creatorInfo): CommonResult
    {
        $now = time();
        if ($now < $lm['start_time'] || $now > $lm['end_time']) {
            return CommonResult::make(ErrorCode::ErrGrabLmFailed, '红包已过期', $creatorInfo);
        }

        if (empty($user['site_bid']) || strtoupper($user['site_bid']) !== strtoupper($lm['site_bid'])) {
            Log::info(sprintf('抢红包site_bid比对错误! 用户: %s, 红包: %s'.json_encode($user, 256), json_encode($lm, 256)), 'grab_lm_site_bid_err');

            return CommonResult::make(ErrorCode::ErrGrabLmFailed, '红包不存在', $creatorInfo);
        }

        if ((int)$lm['close_type'] !== LuckyMoney::CloseTypeNone) {
            return CommonResult::make(ErrorCode::ErrGrabLmFailed, '红包已关闭', $creatorInfo);
        }

        if ((int)$lm['scene_type'] === LuckyMoney::SceneTypeGroup) {
            $result = $this->validateGroupLm($user, $lm);
        } else {
            $result = $this->validateSysLm($user, $lm);
        }

        return $result;
    }

    /**
     * 验证群红包.
     *
     * @param  array  $user
     * @param  array  $lm
     *
     * @return \App\Lib\CommonResult
     */
    protected function validateGroupLm(array $user, array $lm): CommonResult
    {
        /** @var GroupUserRepository $groupUserRepo */
        $groupUserRepo = Container::get(GroupUserRepository::class);
        $groupUser     = $groupUserRepo->getGroupUser($user['id'], $lm['group_id'], ['id']);
        if (empty($groupUser)) {
            return CommonResult::make(ErrorCode::ErrGrabLmFailed, '您不属于此红包群组');
        }

        /** @var SessionRepository $sessionRepo */
        $sessionRepo = Container::get(SessionRepository::class);
        $wsSession   = $sessionRepo->rGetSessionByUid($user['id']);
        if (empty($wsSession)) {
            return CommonResult::make(ErrorCode::ErrGrabLmFailed, '您的连线状态异常');
        }

        $inGroupId = Scene::fetchGroupId($wsSession);
        if ($inGroupId <= 0) {
            return CommonResult::make(ErrorCode::ErrGrabLmFailed, '您不在群组内');
        }

        if ($inGroupId !== (int)$lm['group_id']) {
            return CommonResult::make(ErrorCode::ErrGrabLmFailed, '您不属于此红包群组');
        }

        return CommonResult::success();
    }

    /**
     * 验证系统红包.
     *
     * @param  array  $user
     * @param  array  $lm
     *
     * @return \App\Lib\CommonResult
     */
    protected function validateSysLm(array $user, array $lm): CommonResult
    {
        /** @var SysLmUserRepository $sysLmUserRepo */
        $sysLmUserRepo = Container::get(SysLmUserRepository::class);
        $userMap       = $sysLmUserRepo->getUserMap($lm);
        $match         = $userMap[$user['id']] ?? [];
        if (empty($match)) {
            Log::info('系统导入用户红包, 当前用户不在导入名单中!', 'SysLm_user_not_on_list');

            return CommonResult::make(ErrorCode::ErrGrabLmFailed, '您的资格不符');
        }

        /** @var SessionRepository $sessionRepo */
        $sessionRepo = Container::get(SessionRepository::class);
        $wsSession   = $sessionRepo->rGetSessionByUid($user['id']);
        if (empty($wsSession)) {
            return CommonResult::make(ErrorCode::ErrGrabLmFailed, '您的连线状态异常');
        }
        $scene = Scene::getSceneFromSession($wsSession);
        if (empty($scene['name']) || $scene['name'] !== Scene::SysGroup) {
            return CommonResult::make(ErrorCode::ErrGrabLmFailed, '用户未处于系统群内');
        }

        return CommonResult::success();
    }

    /**
     * 结算红包.
     *
     * @param  array  $lm         红包配置
     * @param  int    $closeType  关闭类型
     *
     * @return \App\Lib\CommonResult
     */
    protected function simpleSettleLm(array $lm, int $closeType): CommonResult
    {
        Db::beginTransaction();
        try {
            /** @var LuckyMoneyRepository $luckyMoneyRepo */
            $luckyMoneyRepo = Container::get(LuckyMoneyRepository::class);
            $luckyMoneyRepo->update($lm['id'], ['close_type' => $closeType]);
            $luckyMoneyRepo->rDelTakenFlag($lm['id']);

            /** @var LuckyMoneyPackRepository $packRepo */
            $packRepo = Container::get(LuckyMoneyPackRepository::class);
            $packRepo->rDelLmPackages($lm['id']);

            Cache::del(NoSqlKey::luckyMoneyInfo($lm['id']));
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            $msg = sprintf('红包结算异常! 红包: %s, 讯息: %s', json_encode($lm, 256), Helper::getExpDetails($e));

            return CommonResult::make(ErrorCode::ErrSettleLmFailed, $msg);
        }

        return CommonResult::success();
    }

    /**
     * 关闭系统红包.
     *
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function closeSysLuckyMoney(array $param): CommonResult
    {
        $vResult = Validator::validate($param, [
            'lm_id' => 'required|int',
        ]);

        if ( ! $vResult->success) {
            return CommonResult::invalidParam('参数错误');
        }

        /** @var LuckyMoneyRepository $lmRepo */
        $lmRepo = Container::get(LuckyMoneyRepository::class);
        $lm     = $lmRepo->getOne($vResult->validated['lm_id']);
        if (empty($lm)) {
            return CommonResult::make(ErrorCode::ErrCloseSysLmFailed, '红包不存在');
        }

        $settleResult = $this->simpleSettleLm($lm, LuckyMoney::CloseTypeManual);
        if ( ! $settleResult->isSuccess()) {
            return CommonResult::make(ErrorCode::ErrCloseSysLmFailed, '关闭失败');
        }

        RStream::push(RStream::TypeNotifySysLmClose, ['site_bid' => $lm['site_bid'], 'lm_id' => $lm['id'], 'close_type' => LuckyMoney::CloseTypeManual]);

        return CommonResult::success();
    }

    /**
     * 取用户红包纪录.
     *
     * @param  mixed  $user
     * @param  array  $param
     *
     * @return array
     */
    public function getUserLmRecord(mixed $user, array $param): array
    {
        /** @var LuckyMoneyRecordRepository $lmRecordRepo */
        $lmRecordRepo = Container::get(LuckyMoneyRecordRepository::class);

        $records = [];
        [$rawRecords, $pagination] = $lmRecordRepo->getUserRecord($user['id'], $param);
        if ( ! empty($rawRecords)) {
            /** @var GroupRepository $groupRepo */
            $groupRepo      = Container::get(GroupRepository::class);
            $allGroup       = $groupRepo->getAll($user['site_bid'], ['id', 'title']);
            $groupMap       = array_column($allGroup, null, 'id');
            $gameCompanyMap = array_column((array)config('bonus.game_company_menu'), 'name', 'id');
            foreach ($rawRecords as $each) {
                $each->lm_id      = (int)$each->lm_id;
                $each->id         = (int)$each->id;
                $each->scene_type = (int)$each->scene_type;
                $each->bonus_type = (int)$each->bonus_type;
                $isBonusMoney     = $each->bonus_type === LuckyMoney::BonusTypeLuckyMoney;
                $gameCompanyName  = $gameCompanyMap[$each->game_company_id] ?? '';
                if ($each->scene_type === LuckyMoney::SceneTypeSys) {
                    $groupTitle = '系统消息';
                } else {
                    $groupTitle = $groupMap[$each->group_id]->title ?? '';
                }

                $records[] = [
                    'lm_id'             => $each->lm_id,
                    'record_id'         => $each->id,
                    'lm_title'          => $each->title,
                    'create_time'       => date('Y-m-d H:i:s', $each->create_time),
                    'amount'            => (string)$each->amount,
                    'scene_name'        => $each->scene_type === LuckyMoney::SceneTypeGroup ? '群红包' : '系统红包',
                    'group_title'       => $groupTitle,
                    'require_type_name' => $isBonusMoney ? '平台打码' : '游戏专属',
                    'require_multi'     => (string)$each->require_multi,
                    'game_company'      => $isBonusMoney ? '' : $gameCompanyName,
                ];
            }
        }

        $data               = CommonResult::success('ok', ['record' => $records])->toArray();
        $data['pagination'] = $pagination->get();

        return $data;
    }

}