<?php

namespace App\Repository\Impl;

use App\Lib\Facade\Log;
use App\Lib\UserLogBuilder;
use Hyperf\DbConnection\Db;

/**
 *  site_bid               varchar(10)      default ''  not null comment '站点编号',
 *  log_type               tinyint unsigned default '0' not null comment '类型',
 *  ext_member_id          bigint unsigned              not null comment '被操作彩票用户ID',
 *  ext_username           varchar(32)      default ''  not null comment '被操作彩票用户名',
 *  user_id                bigint unsigned              not null comment '被操作用户ID',
 *  device                 tinyint unsigned default '0' not null comment '装置代号, 用彩票的, 0:未知 1:PC 2:WAP 3:Android 4:IOS',
 *  create_time            int unsigned     default '0' not null comment '创建时间',
 *  operate_scene          tinyint unsigned default '0' not null comment '操作来源 0:此笔纪录不适用 1:群内 2:彩票后台',
 *  admin_id               int unsigned     default '0' not null comment '彩票后台管理者ID',
 *  operator_ext_member_id bigint unsigned  default '0' not null comment '群内操作人彩票用户ID',
 *  operator_username      varchar(32)                  not null comment '群内操作人彩票用户名',
 *  operator_user_id       bigint unsigned  default '0' not null comment '群内操作人用户ID'
 *
 * 用户日志.
 */
class UserLogRepository
{

    public string $table = 'user_log';

    public function addWithBuilder(UserLogBuilder $builder): bool
    {
        $log = $builder->toArray();
        $ok  = false;
        try {
            $ok = DB::table($this->table)->insert($log);
        } catch (\Throwable $e) {
            Log::error(sprintf('日誌寫入失敗! 日誌內容: %s', json_encode($log, 256)), 'addWithLogger_err');
        }

        return $ok;
    }

    /**
     * 直接用阵列参数添加.
     *
     * @param  array  $logs
     *
     * @return bool
     */
    public function addWithParam(array $logs): bool
    {
        return DB::table($this->table)->insert($logs);
    }

    /**
     * 后台查询用户日志.
     *
     * @param  int    $page
     * @param  int    $pageSize
     * @param  array  $params
     *
     * @return array
     */
    public function getAdminIndex(int $page, int $pageSize, array $params): array
    {
        $query = DB::table($this->table)->where('site_bid', $params['site_bid']);

        if ( ! empty($params['user_id'])) {
            $query->where('user_id', (int)$params['user_id']);
        }

        if ( ! empty($param['create_date_start']) && ! empty($param['create_date_end'])) {
            $s = strtotime($param['create_date_start']);
            $e = strtotime($param['create_date_end']);
            if ($s && $e) {
                $query->whereBetween('create_time', [$s, $e]);
            }
        }

        if ( ! empty($params['type']) && $params['type'] > 0) {
            $query->where('type', (int)$params['type']);
        }

        $total = $query->count();

        $list              = $query->limit($pageSize)->offset(($page - 1) * $pageSize)->orderBy('create_time', 'DESC')->get()->toArray();
        $data              = [];
        $data['total']     = $total;
        $data['page']      = $page;
        $data['page_size'] = $pageSize;
        $data['model']     = $list;

        return $data;
    }

}