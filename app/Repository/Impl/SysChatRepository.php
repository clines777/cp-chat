<?php

namespace App\Repository\Impl;

use App\Model\SysChatContent;
use Hyperf\DbConnection\Db;

/**
 * 系統頻道消息.
 */
class SysChatRepository
{

    /**
     * 内容表
     *
     * @var string
     */
    public string $contentTable = 'sys_chat_content';

    /**
     * 对象表.
     *
     * @var string
     */
    public string $audienceTable = 'sys_chat_audience';

    /**
     * 取最新count筆系統消息
     *
     * @param  int     $userId
     * @param  string  $siteBid
     * @param  int     $count
     * @param  int     $beforeChatId
     *
     * @return list<\stdClass>
     */
    public function getLastChat(int $userId, string $siteBid, int $count, int $beforeChatId = 0): array
    {
        $query = Db::table('sys_chat_content as cnt')->leftJoin('sys_chat_audience as aud', function ($join) {
            $join->on('cnt.id', '=', 'aud.chat_id')->where('cnt.audience_type', SysChatContent::AudienceByUsers); // and cnt.audience_type=1
        })->where(
            function ($q) use ($userId, $siteBid) {
                $q->where(function ($q) use ($userId) {
                    $q->where('cnt.audience_type', SysChatContent::AudienceByUsers)->where('aud.user_id', $userId);
                })->orWhere(function ($q) use ($siteBid) {
                    $q->where('cnt.audience_type', SysChatContent::AudienceBySite)->where('cnt.site_bid', $siteBid);
                });
            },
        )->where('cnt.deleted', SysChatContent::DeleteNot)->orderBy('cnt.create_time', 'DESC')->limit($count)->select([
            'cnt.content',
            'cnt.extra',
            'cnt.create_time',
            'aud.user_id',
            'cnt.content_type',
            'cnt.id',
        ]);

        if ($beforeChatId > 0) {
            $query->where('cnt.id', '<', $beforeChatId);
        }

        $list = $query->get()->toArray();
        if ( ! empty($list)) {
            usort($list, static function ($a, $b) {
                return $a->create_time <=> $b->create_time;
            });
        }

        return $list;
    }

    /**
     * 简单取用户最新一笔.
     *
     * @param  int  $userId
     *
     * @return array
     */
    public function getSimpleLastChat(int $userId): array
    {
        return (array)Db::table($this->audienceTable.' as aud')->leftJoin($this->contentTable.' as cnt', function ($join, $userId) {
            $join->on('aud.chat_id', '=', 'cnt.id')->where('aud.user_id', $userId);
        })->orderBy('aud.id', 'DESC')->select(['cnt.content', 'cnt.extra', 'cnt.create_time', 'cnt.id'])->limit(1)->first();
    }

    /**
     * 写入单笔.
     *
     * @param  array  $chat
     *
     * @return int
     */
    public function add(array $chat): int
    {
        $newChatId = Db::table($this->contentTable)->insertGetId($chat);
        if ( ! $newChatId) {
            throw new \PDOException('系统消息内容写入失败!');
        }

        return $newChatId;
    }

    /**
     * 取单笔.
     *
     * @param  int    $chatId
     * @param  array  $cols  指定栏位.
     *
     * @return array
     */
    public function getOne(int $chatId, array $cols = ['*']): array
    {
        return (array)Db::table($this->contentTable)->where('id', $chatId)->where('deleted', SysChatContent::DeleteNot)->select($cols)->first();
    }

    /**
     * 写入消息用户.
     *
     * @param  array  $sysChatUsers
     *
     * @return bool
     */
    public function addContentUsers(array $sysChatUsers): bool
    {
        return Db::table($this->audienceTable)->insert($sysChatUsers);
    }

    /**
     * 取后台系统消息列表.
     *
     * @param  int    $page
     * @param  int    $pageSize
     * @param  array  $params
     *
     * @return array
     */
    public function getAdminIndex(int $page, int $pageSize, array $params): array
    {
        $query = Db::table($this->contentTable)->where('site_bid', $params['site_bid'])->where(
            'deleted',
            SysChatContent::DeleteNot,
        );

        if (isset($params['audience_type']) && in_array($params['audience_type'], [SysChatContent::AudienceByUsers, SysChatContent::AudienceBySite])) {
            $query->where('audience_type', (int)$params['audience_type']);
        } else {
            $query->whereIn('audience_type', [SysChatContent::AudienceByUsers, SysChatContent::AudienceBySite]);
        }

        if ( ! empty($params['admin_id']) && is_numeric($params['admin_id'])) {
            $query->where('admin_id', (int)$params['admin_id']);
        }

        if ( ! empty($params['create_date_start']) && ! empty($params['create_date_end'])) {
            $s = strtotime($params['create_date_start']);
            $e = strtotime($params['create_date_end']);
            if ($s && $e) {
                $query->whereBetween('create_time', [$s, $e]);
            }
        } else {
            $query->where('create_time', '>', 0);
        }

        $total = $query->count();
        $query->select(['id', 'admin_id', 'audience_type', 'content', 'create_time', 'content_type']);
        $list = $query->limit($pageSize)->offset(($page - 1) * $pageSize)->orderBy('id', 'desc')->get();

        $data              = [];
        $data['total']     = $total;
        $data['page']      = $page;
        $data['page_size'] = $pageSize;
        $data['model']     = $list->toArray();

        return $data;
    }

    /**
     * 后台取系统消息的接收用户列表.
     *
     * @param  int    $chatId
     * @param  int    $page
     * @param  int    $pageSize
     * @param  array  $params
     *
     * @return array
     */
    public function getUsersOfChat(int $chatId, int $page, int $pageSize, array $params): array
    {
        $query = Db::table($this->audienceTable)->where('chat_id', $chatId)->select(['ext_username', 'ext_member_id', 'user_id']);

        $total = $query->count();

        $list = $query->limit($pageSize)->offset(($page - 1) * $pageSize)->get()->toArray();

        $data              = [];
        $data['total']     = $total;
        $data['page']      = $page;
        $data['page_size'] = $pageSize;
        $data['model']     = $list;

        return $data;
    }

    /**
     * 取未读数.
     *
     * @param  int     $userId
     * @param  int     $lastReadId
     * @param  string  $siteBid
     *
     * @return int
     */
    public function getUnreadCount(int $userId, int $lastReadId, string $siteBid): int
    {
        return Db::table('sys_chat_content as cnt')->where('cnt.deleted', 0)->where('cnt.id', '>', $lastReadId)->where(function ($q) use ($siteBid, $userId) {
            $q
                ->where(function ($q2) use ($siteBid) {
                    $q2
                        ->where('cnt.audience_type', 0)->where('cnt.site_bid', $siteBid);
                })->orWhere(function ($q2) use ($userId) {
                    $q2
                        ->where('cnt.audience_type', 1)->whereExists(function ($sub) use ($userId) {
                            $sub
                                ->select(Db::raw(1))->from('sys_chat_audience as aud')->whereColumn('aud.chat_id', 'cnt.id')->where('aud.user_id', $userId);
                        });
                });
        })->count();
    }

    /**
     * 取系统红包讯息.
     *
     * @param  int  $lm_id
     *
     * @return array
     */
    public function getByLmId(int $lm_id): array
    {
        return (array)Db::table($this->contentTable)->where('content_type', SysChatContent::ContentTypeLuckyMoney)->where('custom_id', $lm_id)->where(
            'deleted',
            SysChatContent::DeleteNot,
        )->first();
    }

}