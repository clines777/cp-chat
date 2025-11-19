<?php

namespace App\Repository\Impl;

use App\Lib\Facade\Container;
use App\Lib\SysConst;
use App\Model\LuckyMoney;
use Hyperf\DbConnection\Db;

class AdminLmRepository
{

    /**
     * 取后台红包列表.
     *
     * @param  int    $page
     * @param  int    $pageSize
     * @param  array  $params
     *
     * @return array
     */
    public function getAdminLmIndex(int $page, int $pageSize, array $params = []): array
    {
        $query = Db::table('lucky_money as lm')->leftJoin('group as g', 'lm.group_id', '=', 'g.id')->where(['lm.site_bid' => $params['site_bid']]);

        if ( ! empty($params['group_title'])) {
            $group = Db::table('group')->where('title', 'like', trim($params['group_title']).'%')->limit(1)->first();
            if ( ! empty($group)) {
                $query->where('lm.group_id', $group->id);
            }
        }

        if ( ! empty($params['scene_type'])) {
            if ( ! empty($params['username']) && $params['scene_type'] == LuckyMoney::SceneTypeGroup) {
                /** @var \App\Repository\Impl\UserRepository $userRepo */
                $userRepo = Container::get(UserRepository::class);
                $user     = $userRepo->getOneUserByCondition(['ext_username' => trim($params['username']), 'site_bid' => $params['site_bid']], ['id']);
                if (empty($user)) {
                    return [];
                }
                $query->where('user_id', (int)$user['id']);
            } else {
                if ( ! empty($params['query_admin_id']) && $params['scene_type'] == LuckyMoney::SceneTypeSys) {
                    $query->where('admin_id', (int)$params['query_admin_id']);
                }
            }
        }

        if ( ! empty($params['amount_type']) && in_array($params['amount_type'], [LuckyMoney::AmountTypeFixed, LuckyMoney::AmountTypeRand])) {
            $query->where('lm.amount_type', (int)$params['amount_type']);
        }

        if ( ! empty($params['bonus_type']) && in_array($params['bonus_type'], [LuckyMoney::BonusTypeLuckyMoney, LuckyMoney::BonusTypeGameCoin])) {
            $query->where('lm.bonus_type', (int)$params['bonus_type']);
        }

        if ( ! empty($params['create_type']) && in_array($params['create_type'], [LuckyMoney::CreateTypeUser, LuckyMoney::CreateTypePlatform])) {
            $query->where('lm.create_type', (int)$params['create_type']);
        }

        if ( ! empty($params['scene_type']) && is_numeric($params['scene_type'])) {
            $query->where('lm.scene_type', (int)$params['scene_type']);
        }

        if (isset($params['close_type']) && is_numeric($params['close_type'])) {
            $query->where('lm.close_type', (int)$params['close_type']);
        }

        if ( ! empty($params['create_date_start']) && ! empty($params['create_date_end'])) {
            $s = strtotime($params['create_date_start']);
            $e = strtotime($params['create_date_end']);
            if ($s && $e) {
                $query->whereBetween('lm.create_time', [$s, $e]);
            }
        }

        if ( ! empty($params['start_time']) && ! empty($params['end_time'])) {
            $startTime = strtotime($params['start_time']);
            $endTime   = strtotime($params['end_time']);
            if ($startTime && $endTime) {
                $query->whereBetween('lm.create_time', [$startTime, $endTime]);
            }
        }

        $query->select(
            [
                'lm.id',
                'lm.title as lm_title',
                'lm.bonus_type',
                'lm.amount_type',
                'lm.scene_type',
                'lm.create_type',
                'lm.create_time',
                'lm.start_time',
                'lm.end_time',
                'lm.ext_username',
                'lm.admin_id',
                'lm.close_type',
                'lm.require_multi',
                'g.title as group_title',
                'g.code',
                'g.id as group_id',
            ],
        );
        $total = $query->count();
        $list  = $query->limit($pageSize)->offset(($page - 1) * $pageSize)->orderBy('lm.create_time', 'desc')->get()->toArray();

        $data              = [];
        $data['total']     = $total;
        $data['page']      = $page;
        $data['page_size'] = $pageSize;
        $data['model']     = $list;

        return $data;
    }

    /**
     * 取红包纪录.
     *
     * @param  int    $page
     * @param  int    $pageSize
     * @param  array  $params
     *
     * @return array
     */
    public function getAdminLmRecord(int $page, int $pageSize, array $params): array
    {
        $query = Db::table('lucky_money_record as r')->join('lucky_money as lm', 'r.lm_id', '=', 'lm.id')->where(['lm.site_bid' => $params['site_bid']]);

        if ( ! empty($params['user_id'])) {
            $query->where('r.user_id', (int)$params['user_id']);
        }

        if ( ! empty($params['create_date_start']) && ! empty($params['create_date_end'])) {
            $s = strtotime($params['create_date_start']);
            $e = strtotime($params['create_date_end']);
            if ($s && $e) {
                $query->whereBetween('lm.create_time', [$s, $e]);
            }
        }

        if ( ! empty($params['lm_id'])) {
            $query->where('lm.id', (int)$params['lm_id']);
        }

        if ( ! empty($params['group_id'])) {
            $query->where('lm.group_id', (int)$params['group_id']);
        }

        $query->select(['lm.title as lm_title', 'r.ext_username', 'r.id as record_id', 'lm.id as lm_id', 'r.create_time', 'lm.group_id', 'r.require_amount', 'r.amount']);

        $total = $query->count();
        $list  = $query->limit($pageSize)->offset(($page - 1) * $pageSize)->orderBy('r.id', 'DESC')->get();
        if ( ! $list->isEmpty()) {
            /** @var \App\Repository\Impl\GroupRepository $groupRepo */
            $groupRepo = Container::get(GroupRepository::class);
            $groupMap  = array_column($groupRepo->getAll($params['site_bid'], ['id', 'title', 'code']), null, 'id');
            foreach ($list as $item) {
                $group = $groupMap[$item->group_id] ?? [];
                if (empty($group)) {
                    $item->group_title = '-';
                    $item->group_code  = '-';
                } else {
                    $item->group_title = $group->title ?? '';
                    $item->group_code  = $group->code ?? '';
                }
            }
        }

        $data              = [];
        $data['total']     = $total;
        $data['page']      = $page;
        $data['page_size'] = $pageSize;
        $data['model']     = $list->toArray();

        return $data;
    }

    /**
     * @param  string  $siteBid
     * @param  array   $cols
     *
     * @return array
     */
    public function getAdminLmMenu(string $siteBid, array $cols = ['*']): array
    {
        $list = Db::table('lucky_money')->where(['site_bid' => $siteBid])->select($cols)->get();

        return $list->toArray();
    }

    /**
     * 取單筆.
     *
     * @param  int    $lmId
     * @param  array  $cols
     *
     * @return array
     */
    public function getOne(int $lmId, array $cols = ['*']): array
    {
        return (array)Db::table('lucky_money')->where(['id' => $lmId])->select($cols)->first();
    }

    /**
     * 后台取系统红包用户.
     *
     * @param  int     $lmId
     * @param  string  $siteBid
     * @param  array   $param
     *
     * @return array
     */
    public function adminGetSysLmUsers(int $lmId, string $siteBid, array $param): array
    {
        $query = Db::table('sys_lm_user')->where(['lm_id' => $lmId])->where(['site_bid' => $siteBid]);

        $query->select(['user_id', 'ext_username', 'create_time', 'is_taken', 'lm_id']);

        if ( ! empty($param['username'])) {
            $query->where('ext_username', trim($param['username']));
        }

        $page     = isset($param['page']) ? (int)($param['page']) : 1;
        $pageSize = isset($param['page_size']) ? (int)($param['page_size']) : SysConst::AdminIndexPageSize;

        $total = $query->count();
        $list  = $query->limit($pageSize)->offset(($page - 1) * $pageSize)->orderBy('id', 'DESC')->get();

        $data              = [];
        $data['total']     = $total;
        $data['page']      = $page;
        $data['page_size'] = $pageSize;
        $data['model']     = $list->toArray();

        return $data;
    }

}