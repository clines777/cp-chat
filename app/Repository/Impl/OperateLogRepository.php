<?php

namespace App\Repository\Impl;

use Hyperf\DbConnection\Db;

class OperateLogRepository
{

    public string $table = 'operate_log';

    public function insert(int $type, string $siteBid, string $operatorName, string $operateeUserId, string $remark): bool
    {
        $insert = ['type' => $type, 'site_bid' => $siteBid, 'operator_name' => $operatorName, 'operatee_id' => $operateeUserId, 'remark' => $remark, 'create_time' => time()];

        return Db::table($this->table)->insert($insert);
    }

}