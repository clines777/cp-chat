<?php

namespace App\Repository\Impl;

use App\Model\Group;
use App\Model\GroupQuotaLog;
use App\Model\GroupUser;
use Hyperf\DbConnection\Db;

class AdminGroupRepository
{

    public string $table = 'group';

    /**
     * @param  int    $page
     * @param  int    $pageSize
     * @param  array  $params
     *
     * @return array
     */
    public function getAdminGroupList(int $page, int $pageSize, array $params): array
    {
        $base = Db::table('group as g')->join('group_user as gu', 'g.id', '=', 'gu.group_id')->where('g.site_bid', $params['site_bid']);

        if ( ! empty($params['code'])) {
            $base->where('g.code', trim($params['code']));
        }
        if (isset($params['is_dismiss']) && in_array($params['is_dismiss'], [Group::DismissYes, Group::DismissNo], false)) {
            $base->where('g.is_dismiss', (int)$params['is_dismiss']);
        }
        if (isset($params['open_join']) && is_numeric($params['open_join'])) {
            $base->where('g.open_join', (int)$params['open_join']);
        }
        if (isset($params['visible']) && is_numeric($params['visible'])) {
            $base->where('g.visible', (int)$params['visible']);
        }

        $total = (clone $base)->distinct()->count('g.id');

        $list = (clone $base)
            ->selectRaw('g.*, COUNT(gu.id) AS user_count')->groupBy('g.id')->limit($pageSize)->offset(($page - 1) * $pageSize)->get()->toArray();

        return [
            'total'     => $total,
            'page'      => $page,
            'page_size' => $pageSize,
            'model'     => $list,
        ];
    }

    /**
     * 创建新群.
     *
     * @param  array  $param
     *
     * @return int
     */
    public function insertGetId(array $param): int
    {
        return Db::table($this->table)->insertGetId($param);
    }

    /**
     * 更新群信息
     *
     * @param  int    $id
     * @param  array  $updateParam
     *
     * @return bool
     */
    public function updateGroup(int $id, array $updateParam): bool
    {
        Db::table($this->table)->where('id', $id)->update($updateParam);

        return true;
    }

    /**
     * 取后台群组选单
     *
     * @param  string  $siteBid
     * @param  array   $cols
     *
     * @return array
     */
    public function getAdminGroupMenu(string $siteBid, array $cols = ['*']): array
    {
        $list = Db::table($this->table)->where('site_bid', $siteBid)->select($cols)->get();

        return $list->toArray();
    }

    /**
     * @param  int    $page
     * @param  int    $pageSize
     * @param  array  $param
     *
     * @return array
     */
    public function getAdminGroupQuotaLog(int $page, int $pageSize, array $param): array
    {
        $query = Db::table('group_quota_log')->where('site_bid', $param['site_bid']);

        if ( ! empty($param['group_id'])) {
            $query->where('group_id', (int)$param['group_id']);
        }

        if ( ! empty($param['create_date_start']) && ! empty($param['create_date_end'])) {
            $s = strtotime($param['create_date_start']);
            $e = strtotime($param['create_date_end']);
            if ($s && $e) {
                $query->whereBetween('create_time', [$s, $e]);
            }
        }

        if ( ! empty($param['quota_type']) && in_array($param['quota_type'], [GroupQuotaLog::QuotaTypeLuckyMoney, GroupQuotaLog::QuotaTypeGameCoin])) {
            $query->where('quota_type', (int)$param['quota_type']);
        }

        if ( ! empty($param['action_type'])
            && in_array($param['action_type'], [
                GroupQuotaLog::ActionTypeAdminSub,
                GroupQuotaLog::ActionTypeAdminAdd,
                GroupQuotaLog::ActionTypeSendCost,
                GroupQuotaLog::ActionTypeReturnBalance,
            ])
        ) {
            $query->where('action_type', (int)$param['action_type']);
        }

        $total             = $query->count();
        $list              = $query->limit($pageSize)->offset(($page - 1) * $pageSize)->get()->toArray();
        $data              = [];
        $data['total']     = $total;
        $data['page']      = $page;
        $data['page_size'] = $pageSize;
        $data['model']     = $list;

        return $data;
    }

    /**
     * @param  int  $groupId
     *
     * @return array
     */
    public function getOwnerModInfo(int $groupId): array
    {
        return Db::table('group_user as gu')->join('user as u', 'gu.user_id', '=', 'u.id')->where('gu.group_id', $groupId)->whereIn(
            'gu.role_type',
            [GroupUser::RoleOwner, GroupUser::RoleMod],
        )->select(['u.id', 'u.ext_member_id', 'u.ext_username', 'gu.role_type'])->get()->toArray();
    }

}