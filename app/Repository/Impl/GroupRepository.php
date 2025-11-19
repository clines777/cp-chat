<?php

namespace App\Repository\Impl;

use App\Lib\Facade\Cache;
use App\Lib\Helper;
use App\Lib\NoSqlKey;
use App\Lib\Paginator;
use App\Lib\SysConst;
use App\Model\Group;
use App\Model\LuckyMoney;
use Hyperf\DbConnection\Db;
use stdClass;

class GroupRepository
{

    public string $table = 'group';

    /**
     * 大厅群列表查询 - 全部.
     */
    public const LobbyGroupFilterNone = 0;

    /**
     * 大厅群列表查询 - 未加入.
     */
    public const LobbyGroupFilterNoJoinOnly = 2;

    /**
     * 大厅群列表查询 - 已加入.
     */
    public const LobbyGroupFilterJoinedOnly = 1;

    /**
     * 以群号查询聊天群.
     *
     * @param  string  $siteBid
     * @param  string  $code
     * @param  array   $cols
     *
     * @return array
     */
    public function getByCode(string $siteBid, string $code, array $cols = ['*']): array
    {
        return (array)Db::table($this->table)->where("code", $code)->where('site_bid', $siteBid)->select($cols)->limit(1)->first();
    }

    /**
     * 取大厅展示群列表.
     *
     * @param  string  $siteBid
     * @param  array   $user
     * @param  int     $page
     * @param  int     $pageSize
     * @param  array   $param
     *
     * @return array
     */
    public function getLobbyGroups(string $siteBid, array $user, int $page, int $pageSize, array $param): array
    {
        $query = Db::table('group as g')->where([
            'g.visible'    => Group::VisibleYes,
            'g.site_bid'   => $siteBid,
            'g.open_join'  => Group::OpenJoinYes,
            'g.is_dismiss' => Group::DismissNo,
        ])->where(
            'g.join_user_level',
            '<=',
            (int)$user['user_level'],
        );

        $query->leftJoin('group_user as ga', 'ga.group_id', '=', 'g.id');

        $query->leftJoin('group_user as gu', function ($join) use ($user) {
            $join->on('gu.group_id', '=', 'g.id')->where('gu.user_id', '=', $user['id']);
        });

        $joinState = isset($param['join_state']) ? (int)$param['join_state'] : self::LobbyGroupFilterNone;
        if ($joinState === self::LobbyGroupFilterJoinedOnly) {
            $query->whereNotNull('gu.user_id');
        } elseif ($joinState === self::LobbyGroupFilterNoJoinOnly) {
            $query->whereNull('gu.user_id');
        }

        $page     = $page <= 0 ? SysConst::DefaultPage : $page;
        $pageSize = $pageSize <= 0 ? SysConst::DefaultPageSize : $pageSize;

        $offset = ($page - 1) * $pageSize;
        $limit  = $pageSize;
        $query->select(
            [
                'g.id',
                'g.title',
                'g.code',
                'g.join_user_level',
                'g.lobby_cover_pic_url',
                Db::raw('COUNT(DISTINCT ga.id) as user_count'),
                Db::raw('CASE WHEN gu.user_id IS NULL THEN 2 ELSE 1 END AS join_state'),
            ],
        )->groupBy('g.id')->orderBy("sort");

        $total      = $query->count();
        $list       = $query->offset($offset)->limit($limit)->get()->toArray();
        $pagination = new Paginator($total, $page, $pageSize);

        return [$list, $pagination];
    }

    /**
     * 取站点全部群(缓存).
     *
     * @return stdClass[]
     */
    public function getAll(string $siteBid, array $cols = ['*'], bool $useCache = true): array
    {
        $key = NoSqlKey::allGroup($siteBid).':'.Helper::array_hash($cols);
        if ($useCache && Cache::has($key)) {
            return Cache::get($key);
        }

        $list = $this->_getAll($siteBid, $cols);
        Cache::set($key, $list, Cache::randTTL());

        return $list;
    }

    /**
     * @param  string  $siteBid
     * @param  array   $cols
     *
     * @return array
     */
    public function _getAll(string $siteBid, array $cols): array
    {
        return Db::table($this->table)->select($cols)->where('site_bid', $siteBid)->get()->toArray();
    }

    /**
     * 取群by ID
     *
     * @param  int    $groupId
     * @param  array  $cols
     *
     * @return array
     */
    public function getById(int $groupId, array $cols = ['*']): array
    {
        return (array)Db::table($this->table)->where("id", $groupId)->select($cols)->first();
    }

    /**
     * @param  int  $groupId
     * @param  int  $chatId
     *
     * @return bool
     */
    public function setPinChat(int $groupId, int $chatId): bool
    {
        return Db::table($this->table)->where("id", $groupId)->update(['pin_chat_id' => $chatId]) > 0;
    }

    /**
     * 删掉聊天群组
     *
     * @param  int  $groupId
     *
     * @return bool
     */
    public function updateAsDismissed(int $groupId): bool
    {
        return Db::table($this->table)->where('id', $groupId)->update(
                ['is_dismiss' => Group::DismissYes, 'open_join' => Group::OpenJoinNo, 'visible' => Group::VisibleNo],
            ) > 0;
    }

    /**
     * 扣除群组红包额度
     *
     * @param  int     $groupId
     * @param  string  $amount
     *
     * @return bool
     */
    public function subLmQuota(int $groupId, string $amount): bool
    {
        return Db::table($this->table)->where('id', $groupId)->decrement('lucky_money_quota', $amount) > 0;
    }

    /**
     * 扣除群游戏币额度
     *
     * @param  int     $groupId
     * @param  string  $amount
     *
     * @return bool
     */
    public function subGameCoinQuota(int $groupId, string $amount): bool
    {
        return Db::table($this->table)->where('id', $groupId)->update(['game_coin_quota' => Db::raw('game_coin_quota - '.$amount)]) > 0;
    }

    /**
     * 返回群组活动额度
     *
     * @param  int     $groupId    群ID
     * @param  int     $bonusType  额度类型 lucky_money.bonus_type
     * @param  string  $amount     补回余额.
     *
     * @return bool
     */
    public function addBackQuota(int $groupId, int $bonusType, string $amount): bool
    {
        $field = $bonusType === LuckyMoney::BonusTypeLuckyMoney ? 'lucky_money_quota' : 'game_coin_quota';

        return Db::table($this->table)->where('id', $groupId)->increment($field, $amount) > 0;
    }

    public function increase(int $groupId, string $field, int $amount = 1): bool
    {
        return Db::table($this->table)->where('id', $groupId)->increment($field, $amount) > 0;
    }

    /**
     * 更新群组.
     *
     * @param  int    $groupId
     * @param  array  $data
     *
     * @return bool
     */
    public function update(int $groupId, array $data): bool
    {
        return Db::table($this->table)->where('id', $groupId)->update($data) > 0;
    }

    public function getGroupInfo(int $groupId): array
    {
        return (array)DB::table('group as g')->join('group_user as gu', 'g.id', '=', 'gu.group_id')->where('g.id', $groupId)->selectRaw('COUNT(gu.id) AS user_count')->addSelect(
            ['g.id', 'g.code', 'g.title', 'g.pin_chat_id', 'g.speak_user_level'],
        )->groupBy('g.id')->first();
    }

}