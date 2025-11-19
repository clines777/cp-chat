<?php

namespace App\Lib;

/**
 * 用户日志.
 */
class UserLogBuilder
{

    /**
     * 第一次登入群聊(自动建立帐号).
     */
    public const TypeCreateUser = 1;

    /**
     * 登入
     */
    public const TypeLogin = 2;

    /**
     * 加群
     */
    public const TypeJoinGroup = 3;

    /**
     * 退群
     */
    public const TypeQuitGroup = 4;

    /**
     * 被踢群
     */
    public const TypeKickGroup = 5;

    /**
     * 踢出全部群组.
     */
    public const TypeKickGroupAll = 6;

    /**
     * 群内禁言.
     */
    public const TypeGroupBan = 7;

    /**
     * 群内解禁.
     */
    public const TypeGroupUnban = 8;

    /**
     * 成为群管理.
     */
    public const TypeBecomeGroupAdmin = 9;

    /**
     * 成为群主
     */
    public const TypeBecomeGroupMaster = 10;

    /**
     * 后台导入用户进群.
     */
    public const TypeAdminImportToGroup = 11;

    /**
     * 后台全域禁言.
     */
    public const TypeGlobalBan = 12;

    /**
     * 后台全域解禁.
     */
    public const TypeGlobalUnban = 13;

    /**
     * 降为一般群用户.
     */
    public const TypeDownToGroupUser = 14;

    /**
     * 群聊内
     */
    public const SceneInApp = 1;

    /**
     * 彩票后台.
     */
    public const SceneCpAdmin = 2;

    public const OperatorTypeSelf = 0;

    public const OperatorTypeOther = 1;

    /**
     * 站点编号
     *
     * @var string
     */
    public string $siteBid;

    /**
     * 日志类型
     *
     * @var int
     */
    public int $type;

    /**
     * 被操作用户ID
     *
     * @var int
     */
    public int $userId = 0;

    /**
     * 被操作用户彩票ID
     *
     * @var int
     */
    public int $extMemberId = 0;

    /**
     * 被操作用户名
     *
     * @var string
     */
    public string $extUsername = '';

    /**
     * 操作场景 1:群内 2:彩票后台.
     *
     * @var int
     */
    public int $scene = 0;

    /**
     * 彩票后台管理者ID
     *
     * @var int
     */
    public int $adminId = 0;

    /**
     * 操作人类型 0:用户自身 1:他人(群管理)
     *
     * @var int
     */
    public int $operatorType = 0;

    /**
     * 群管理用户ID
     *
     * @var int
     */
    public int $operatorUserId = 0;

    /**
     * 群管理彩票用户名
     *
     * @var string
     */
    public string $operatorUsername = '';

    /**
     * 群管理彩票用户ID
     *
     * @var int
     */
    public int $operatorExtMemberId = 0;

    /**
     * 备注说明.
     *
     * @var string
     */
    public string $remark = '';

    public function __construct(string $siteBid)
    {
        $this->siteBid = $siteBid;
    }

    public function setParam(
        int $userId,
        int $extMemberId,
        string $extUsername,
        int $scene = 0,
        int $adminId = 0,
        int $operatorType = 0,
        int $operatorExtMemberId = 0,
        string $operatorUsername = '',
        int $operatorUserId = 0,
        string $remark = '',
    ): self {
        $this->userId      = $userId;
        $this->extMemberId = $extMemberId;
        $this->extUsername = $extUsername;

        $this->scene               = $scene;
        $this->adminId             = $adminId;
        $this->operatorType        = $operatorType;
        $this->operatorExtMemberId = $operatorExtMemberId;
        $this->operatorUsername    = $operatorUsername;
        $this->operatorUserId      = $operatorUserId;
        $this->remark              = $remark;

        return $this;
    }

    /**
     * 设置日志类型.
     *
     * @param  int  $type
     *
     * @return $this
     */
    public function setLogType(int $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * 标记为用户自身操作.
     *
     * @return $this
     */
    public function bySelf(): self
    {
        $this->operatorType        = self::OperatorTypeSelf;
        $this->operatorExtMemberId = $this->extMemberId;
        $this->operatorUsername    = $this->extUsername;
        $this->operatorUserId      = $this->userId;

        return $this;
    }

    /**
     * 标记为群管理操作.
     *
     * @param  int     $opUserId
     * @param  int     $opExtMemberId
     * @param  string  $opUsername
     *
     * @return $this
     */
    public function byGroupAdmin(int $opUserId, int $opExtMemberId, string $opUsername): self
    {
        $this->operatorType        = self::OperatorTypeOther;
        $this->operatorUserId      = $opUserId;
        $this->operatorExtMemberId = $opExtMemberId;
        $this->operatorUsername    = $opUsername;

        return $this;
    }

    public function sceneInApp(): self
    {
        $this->scene = self::SceneInApp;

        return $this;
    }

    public function byCpAdmin(int $adminId): self
    {
        $this->operatorType = self::OperatorTypeOther;
        $this->scene        = self::SceneCpAdmin;
        $this->adminId      = $adminId;

        return $this;
    }

    /**
     * 设置备注.
     *
     * @param  string  $remark
     *
     * @return $this
     */
    public function withRemark(string $remark): self
    {
        $this->remark = $remark;

        return $this;
    }

    /**
     * 产生阵列(自带create_time)
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'site_bid'               => $this->siteBid,
            'type'                   => $this->type,
            'ext_member_id'          => $this->extMemberId,
            'ext_username'           => $this->extUsername,
            'user_id'                => $this->userId,
            'create_time'            => time(),
            'scene'                  => $this->scene,
            'admin_id'               => $this->adminId,
            'operator_type'          => $this->operatorType,
            'operator_ext_member_id' => $this->operatorExtMemberId,
            'operator_username'      => $this->operatorUsername,
            'operator_user_id'       => $this->operatorUserId,
            'remark'                 => $this->remark,
        ];
    }

}