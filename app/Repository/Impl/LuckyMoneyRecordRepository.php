<?php

namespace App\Repository\Impl;

use App\Lib\Facade\Container;
use App\Lib\Paginator;
use App\Lib\SysConst;
use App\Model\LuckyMoney;
use Hyperf\DbConnection\Db;

/**
 * 红包派奖纪录, lucky_money_pack是拆包纪录, 因此另外建一个record表纪录派奖纪录.
 */
class LuckyMoneyRecordRepository
{

    public string $table = 'lucky_money_record';

    /**
     * 红包讯息状态 - 超时.
     */
    public const ChatLmStateExpire = 2;

    /**
     * 红包讯息状态 - 未领取.
     */
    public const ChatLmStateNormal = 0;

    /**
     * 红包讯息状态 - 已领取.
     */
    public const ChatLmStateTaken = 1;

    /**
     * 写入并回传
     *
     * @param  array  $data
     *
     * @return array
     */
    public function insertAndGet(array $data): array
    {
        $id = Db::table($this->table)->insertGetId($data);
        if ( ! $id) {
            return [];
        }

        return (array)Db::table($this->table)->where('id', $id)->first();
    }

    /**
     * 取红包纪录
     *
     * @param  int  $lmId  红包ID
     * @param  int  $page
     * @param  int  $pageSize
     *
     * @return array
     */
    public function getRecords(int $lmId, int $page, int $pageSize): array
    {
        $query = Db::table($this->table)->where('lm_id', $lmId);
        $total = $query->count();
        /** @var Paginator $pagination */
        $pagination = Container::make(Paginator::class, ['total' => $total, 'page' => $page, 'page_size' => $pageSize]);

        $records = $query
            ->select(['ext_username', 'amount', 'create_time'])->orderBy('create_time', 'desc')->limit($pageSize)->offset(
                ($page - 1) * $pageSize,
            )->get()->toArray();

        return [$records, $pagination];
    }

    /**
     * 取单笔.
     *
     * @param  int  $id
     *
     * @return array
     */
    public function getOne(int $id): array
    {
        return (array)Db::table($this->table)->where('id', $id)->first();
    }

    /**
     * 同步派奖状态.
     *
     * @param  int    $recordId
     * @param  array  $updateParam
     *
     * @return bool
     */
    public function updateSendState(int $recordId, array $updateParam): bool
    {
        $record = $this->getOne($recordId);
        if (empty($record)) {
            return false;
        }

        return Db::table($this->table)->where('id', $recordId)->update($updateParam) > 0;
    }

    /**
     * 取用户红包纪录.
     *
     * @param  int    $userId
     * @param  array  $param
     *
     * @return array [$records, $pagination]
     */
    public function getUserRecord(int $userId, array $param): array
    {
        $param['page']       = isset($param['page']) ? (int)$param['page'] : 1;
        $param['page_size']  = isset($param['count']) ? (int)$param['count'] : SysConst::LmRecordPageSize;
        $param['scene_type'] = isset($param['scene_type']) && in_array($param['scene_type'], [LuckyMoney::SceneTypeGroup, LuckyMoney::SceneTypeSys]) ? (int)$param['scene_type']
            : 0;

        $query = Db::table($this->table.' as r')->join('lucky_money as l', 'r.lm_id', '=', 'l.id')->where('r.user_id', $userId);

        if ($param['scene_type'] !== 0) {
            $query->where('l.scene_type', $param['scene_type']);
        }

        $total = $query->count();
        /** @var Paginator $pagination */
        $pagination = Container::make(Paginator::class, ['total' => $total, 'page' => $param['page'], 'page_size' => $param['page_size']]);

        $query->select(['r.id', 'r.lm_id', 'r.amount', 'r.create_time', 'l.title', 'l.scene_type', 'l.group_id', 'l.require_multi', 'l.game_company_id', 'l.bonus_type']);
        $list = $query->limit($param['page_size'])->offset(($param['page'] - 1) * $param['page_size'])->orderBy('r.id', 'desc')->get()->toArray();

        return [$list, $pagination];
    }

}