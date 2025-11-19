<?php

namespace App\Repository\Impl;

use App\Model\ChatRecord;
use Hyperf\DbConnection\Db;
use stdClass;

class ChatRepository
{

    /**
     * @var string
     */
    public string $table = 'chat_record';

    /**
     * 新增聊天纪录
     *
     * @param  int     $type
     * @param  int     $userId
     * @param  string  $siteBid
     * @param  int     $groupId
     * @param  string  $content
     * @param  array   $extra
     * @param  string  $badWords
     * @param  int     $customId
     *
     * @return int
     */
    public function addChat(int $type, int $userId, string $siteBid, int $groupId, string $content, array $extra = [], string $badWords = '', int $customId = 0): int
    {
        $time = time();
        $data = [
            'user_id'     => $userId,
            'site_bid'    => $siteBid,
            'group_id'    => $groupId,
            'content'     => $content,
            'create_time' => $time,
            'update_time' => $time,
            'type'        => $type,
            'custom_id'   => $customId,
        ];
        if ( ! empty($badWords)) {
            $data['bad_words'] = $badWords;
        }

        if ( ! empty($extra)) {
            $data['extra'] = json_encode($extra);
        }

        $newId = DB::table($this->table)->insertGetId($data);
        if ( ! $newId) {
            return 0;
        }

        return $newId;
    }

    /**
     * 取一笔聊天室内展示用的消息.
     *
     * @param  int  $chatId
     *
     * @return array
     */
    public function getOneForDisplay(int $chatId): array
    {
        return (array)DB::table($this->table)->where('chat_record.id', $chatId)->join('group_user', 'group_user.user_id', '=', 'chat_record.user_id')->join(
            'user',
            'chat_record.user_id',
            '=',
            'user.id',
        )->select([
            'chat_record.id',
            'chat_record.content',
            'chat_record.create_time',
            'group_user.role_type',
            'group_user.is_ban',
            'user.user_level',
            'user.ext_username',
            'user.avatar_id',
            'chat_record.group_id',
            'chat_record.user_id',
            'chat_record.type',
            'chat_record.extra',
        ])->first();
    }

    /**
     * 取该群最新$count则消息
     *
     * @param  int  $groupId
     * @param  int  $lastVisibleChatId
     * @param  int  $count
     *
     * @return list<stdClass>
     */
    public function getLastChats(int $groupId, int $lastVisibleChatId, int $count): array
    {
        $list = Db::table('chat_record')->where(['group_id' => $groupId, 'deleted' => 0])->where('id', '>', $lastVisibleChatId)->orderBy(
            'create_time',
            'desc',
        )->limit($count)->get()->toArray();

        if ( ! empty($list)) {
            usort($list, static function ($a, $b) {
                return $a->create_time <=> $b->create_time;
            });
        }

        return $list;
    }

    /**
     * @param  int  $id
     *
     * @return array
     */
    public function getById(int $id): array
    {
        return (array)Db::table($this->table)->where('id', $id)->where('deleted', 0)->first();
    }

    /**
     * 取指定群组最新一笔消息信息.
     *
     * @param  int    $userId
     * @param  array  $groupIds
     *
     * @return list<stdClass>
     */
    public function getLastChatOfGroups(int $userId, array $groupIds): array
    {
        $latest = Db::table('group_user as gu')->join('chat_record as cr', function ($join) {
            $join
                ->on('cr.group_id', '=', 'gu.group_id')->where('cr.deleted', 0)->whereRaw('cr.id > COALESCE(gu.last_visible_chat_id, 0)');
        })->where('gu.user_id', $userId)->whereIn('gu.group_id', $groupIds)->groupBy('gu.group_id')->selectRaw('gu.group_id, MAX(cr.id) as last_id');

        return Db::table('chat_record as cr')->joinSub($latest, 'latest', function ($join) {
            $join->on('cr.id', '=', 'latest.last_id');
        })->select(['cr.content', 'cr.create_time', 'cr.user_id', 'cr.group_id', 'cr.type'])->get()->toArray();
    }

    /**
     * 取指定群组最新一笔消息ID.
     *
     * @param  int  $groupId
     *
     * @return int
     */
    public function getLastChatIdOfGroup(int $groupId): int
    {
        return (int)Db::table($this->table)->where('group_id', $groupId)->where('deleted', 0)->selectRaw('IFNULL(MAX(id), 0) as id')->limit(1)->value(
            'id',
        );
    }

    /**
     * 软删讯息
     *
     * @param  int  $chatId
     *
     * @return bool
     */
    public function softDeleteChat(int $chatId): bool
    {
        return Db::table($this->table)->where('id', $chatId)->update(['deleted' => ChatRecord::DeletedYes]) > 0;
    }

    /**
     * 取群历史讯息.
     *
     * @param  int  $groupId
     * @param  int  $beforeChatId
     * @param  int  $lastVisibleChatId
     * @param  int  $count
     *
     * @return array
     */
    public function getChatsBefore(int $groupId, int $beforeChatId, int $lastVisibleChatId, int $count): array
    {
        //主要的chat record
        $list = Db::table('chat_record')->where(['group_id' => $groupId, 'deleted' => 0])->where('id', '>', $lastVisibleChatId)->where(
            'id',
            '<',
            $beforeChatId,
        )->orderBy(
            'create_time',
            'desc',
        )->limit($count)->get()->toArray();

        if ( ! empty($list)) {
            usort($list, static function ($a, $b) {
                return $a->create_time <=> $b->create_time;
            });
        }

        return $list;
    }

    /**
     * @param  int  $type
     * @param  int  $lmId
     *
     * @return array
     */
    public function getByLmId(int $type, int $lmId): array
    {
        return (array)Db::table($this->table)->where('type', $type)->where('custom_id', $lmId)->where('deleted', 0)->first();
    }

}