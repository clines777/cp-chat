<?php

namespace App\Repository\Impl;

use App\Model\GroupQuotaLog;
use Hyperf\DbConnection\Db;

/**
 * 群额度纪录
 */
class GroupQuotaLogRepository
{

    public string $table = 'group_quota_log';

    /**
     * 组建管理管理员增加数据
     *
     * @param  string  $siteBid
     * @param  int     $groupId
     * @param  int     $adminId
     * @param  int     $quotaType
     * @param  string  $amount
     * @param  int     $actionType
     *
     * @return array
     */
    public function buildAdminAdd(string $siteBid, int $groupId, int $adminId, int $quotaType, string $amount, int $actionType): array
    {
        $data                  = [
            'site_bid'    => $siteBid,
            'group_id'    => $groupId,
            'admin_id'    => $adminId,
            'quota_type'  => $quotaType,
            'amount'      => $amount,
            'action_type' => $actionType,
        ];
        $data['alter_type']    = GroupQuotaLog::AlterTypeAdd;
        $data['operator_role'] = GroupQuotaLog::OperateTypeRoleAdmin;
        $data['create_time']   = time();

        return $data;
    }

    /**
     * 组建管理员扣除.
     *
     * @param  string  $siteBid
     * @param  int     $groupId
     * @param  int     $adminId
     * @param  int     $quotaType
     * @param  string  $amount
     * @param  int     $actionType
     *
     * @return array
     */
    public function buildAdminSub(string $siteBid, int $groupId, int $adminId, int $quotaType, string $amount, int $actionType): array
    {
        $data                  = [
            'site_bid'    => $siteBid,
            'group_id'    => $groupId,
            'admin_id'    => $adminId,
            'quota_type'  => $quotaType,
            'amount'      => $amount,
            'action_type' => $actionType,
        ];
        $data['alter_type']    = GroupQuotaLog::AlterTypeSub;
        $data['operator_role'] = GroupQuotaLog::OperateTypeRoleAdmin;
        $data['create_time']   = time();

        return $data;
    }

    /**
     * 组建群管理发红包扣除.
     *
     * @param  string  $siteBid
     * @param  int     $groupId
     * @param  string  $extUsername
     * @param  int     $quotaType
     * @param  string  $amount
     *
     * @return bool
     */
    public function groupAdminUsed(string $siteBid, int $groupId, string $extUsername, int $quotaType, string $amount): bool
    {
        $data = [
            'site_bid'     => $siteBid,
            'group_id'     => $groupId,
            'ext_username' => $extUsername,
            'quota_type'   => $quotaType,
            'amount'       => $amount,
        ];

        $data['action_type']   = GroupQuotaLog::ActionTypeSendCost;
        $data['alter_type']    = GroupQuotaLog::AlterTypeSub;
        $data['operator_role'] = GroupQuotaLog::OperateTypeGroupAdmin;
        $data['create_time']   = time();

        return Db::table($this->table)->insert($data);
    }

    /**
     * 写入(批次.).
     *
     * @param  array  $logs
     *
     * @return bool
     */
    public function add(array $logs): bool
    {
        if ( ! empty($logs)) {
            return Db::table($this->table)->insert($logs);
        }

        return false;
    }

    /**
     * 系统添加.
     *
     * @param  string  $siteBid
     * @param  int     $groupId
     * @param  int     $quotaType
     * @param  string  $amount
     *
     * @return bool
     */
    public function sysAdd(string $siteBid, int $groupId, int $quotaType, string $amount): bool
    {
        $data                  = [
            'site_bid'    => $siteBid,
            'group_id'    => $groupId,
            'quota_type'  => $quotaType,
            'amount'      => $amount,
            'action_type' => GroupQuotaLog::ActionTypeReturnBalance,
        ];
        $data['alter_type']    = GroupQuotaLog::AlterTypeAdd;
        $data['operator_role'] = GroupQuotaLog::OperateTypeRoleSys;
        $data['create_time']   = time();

        return Db::table($this->table)->insert($data);
    }

}