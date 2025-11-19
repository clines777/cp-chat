<?php

namespace App\Service\Impl;

use App\Lib\CommonResult;
use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\SysConst;
use App\Repository\Impl\AdminGroupRepository;
use App\Repository\Impl\AdminLmRepository;
use App\Repository\Impl\LuckyMoneyPackRepository;
use App\Repository\Impl\UserRepository;

class AdminBonusService
{

    protected AdminLmRepository $repo;

    public function __construct(AdminLmRepository $repository)
    {
        $this->repo = $repository;
    }

    /**
     * 取后台红包列表.
     *
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function getAdminLmIndex(array $param): CommonResult
    {
        $page     = isset($param['page']) ? (int)($param['page']) : 1;
        $pageSize = isset($param['page_size']) ? (int)($param['page_size']) : SysConst::AdminIndexPageSize;
        $list     = $this->repo->getAdminLmIndex($page, $pageSize, $param);

        return CommonResult::success('ok', $list);
    }

    /**
     * 取后台红包派奖纪录列表
     *
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function getAdminLmRecordIndex(array $param): CommonResult
    {
        $page     = isset($param['page']) ? (int)($param['page']) : 1;
        $pageSize = isset($param['page_size']) ? (int)($param['page_size']) : SysConst::AdminIndexPageSize;

        /** @var AdminGroupRepository $groupRepo */
        $adminGroupRepo = Container::get(AdminGroupRepository::class);
        $groupMenu      = $adminGroupRepo->getAdminGroupMenu($param['site_bid'], ['id', 'title', 'code']);
        /** @var AdminLmRepository $adminLmRepo */
        $adminLmRepo = Container::get(AdminLmRepository::class);
        $lmMenu      = $adminLmRepo->getAdminLmMenu($param['site_bid'], ['id', 'title']);

        if ( ! empty($param['username'])) {
            /** @var UserRepository $userRepo */
            $userRepo = Container::get(UserRepository::class);
            $user     = $userRepo->getOneUserByCondition(['ext_username' => trim($param['username'])], ['id']);
            if (empty($user)) {
                return CommonResult::make(ErrorCode::ErrNotFound, '查无数据', ['group_menu' => $groupMenu, 'lm_menu' => $lmMenu]);
            }
            $param['user_id'] = $user['id'];
        }
        $data               = $this->repo->getAdminLmRecord($page, $pageSize, $param);
        $data['group_menu'] = $groupMenu;
        $data['lm_menu']    = $lmMenu;

        return CommonResult::success('ok', $data);
    }

    /**
     * 统计红包状态
     *
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function getLmState(array $param): CommonResult
    {
        if (empty($param['lm_id'])) {
            return CommonResult::invalidParam('参数错误!, 缺少lm_id');
        }

        /** @var LuckyMoneyPackRepository $packRepo */
        $packRepo = Container::get(LuckyMoneyPackRepository::class);
        [$totalCount, $takenCount, $totalAmount, $takenAmount] = $packRepo->getLmState($param['lm_id']);

        return CommonResult::success('ok', ['total_count' => $totalCount, 'taken_count' => $takenCount, 'total_amount' => $totalAmount, 'taken_amount' => $takenAmount]);
    }

    /**
     * 取系統紅包導入用戶
     *
     * @param  array  $param
     *
     * @return \App\Lib\CommonResult
     */
    public function getAdminSysLmUsers(array $param): CommonResult
    {
        if (empty($param['lm_id'])) {
            return CommonResult::invalidParam('參數錯誤');
        }

        /** @var AdminLmRepository $lmRepo */
        $lmRepo = Container::get(AdminLmRepository::class);
        $lm     = $lmRepo->getOne($param['lm_id'], ['id', 'site_bid']);
        if (empty($lm) || $lm['site_bid'] !== $param['site_bid']) {
            return CommonResult::invalidParam('紅包不存在');
        }

        /** @var AdminLmRepository $adminLmRepo */
        $adminLmRepo = Container::get(AdminLmRepository::class);
        $users       = $adminLmRepo->adminGetSysLmUsers($param['lm_id'], $lm['site_bid'], $param);

        return CommonResult::success('ok', $users);
    }

}