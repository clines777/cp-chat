<?php

namespace App\Lib;

/**
 * 所有错误代号.
 */
class ErrorCode
{

    /**
     * 没有错误
     */
    public const ErrNone = 0;

    /**
     * 请求数据不存在
     */
    public const ErrNotFound = 2;

    /**
     * 未定义错误
     */
    public const ErrUnknown = 100;

    /**
     * MsgType未匹配或MsgPayload格式错误.
     */
    public const ErrInvalidPayload = 101;

    /**
     * 登入处理失败.
     */
    public const ErrLoginFailed = 103;

    /**
     * 找不到该有的session, 断定用户已离线.
     */
    public const ErrSessionLost = 104;

    /**
     * 查无此聊天消息.
     */
    public const ErrChatNotExists = 105;

    /**
     * 进群失败.
     */
    public const ErrEnterGroupFailed = 106;

    /**
     * 讯息发送失败.
     */
    public const ErrSendChatFailed = 107;

    /**
     * 用户不属于该群组.
     */
    public const ErrNotBelongsToGroup = 108;

    /**
     * 用户等级不可发言.
     */
    public const ErrUserBlockBySpeakLevel = 109;

    /**
     * 非法场景操作, eg. 发起需在群内操作的行为时但操作人却不在群内, 例如置顶(会检查redis group online user)
     */
    public const ErrWrongOperateScene = 110;

    /**
     * 用户已禁言
     */
    public const ErrUserBanned = 111;

    /**
     * 非法操作, 例如只有管理员才能pin讯息
     */
    public const ErrInvalidOperate = 114;

    public const ErrPinChatFailed = 115;

    /**
     * 用户被全域禁言.
     */
    public const ErrUserGlobalBanned = 116;

    /**
     * 参数格式不合法.
     */
    public const ErrInvalidParam = 118;

    /**
     * 讯息删除失败.
     */
    public const ErrDelChatFailed = 119;

    /**
     * 禁言失败.
     */
    public const ErrBanFailed = 120;

    /**
     * 解禁失败.
     */
    public const ErrUnBanFailed = 121;

    /**
     * 踢群失败.
     */
    public const ErrKickUserFailed = 122;

    /**
     * 管理者登入失败
     */
    public const ErrAdminLoginFailed = 123;

    /**
     * 离开群组失败.
     */
    public const ErrLeaveGroupFailed = 124;

    /**
     * 不合法的API Token
     */
    public const ErrInvalidApiToken = 126;

    /**
     * 未找到该群组.
     */
    public const ErrGroupNotExists = 127;

    /**
     * 用户已加入该群组
     */
    public const ErrUserHasJoinedGroup = 128;

    /**
     * 群组已满员.
     */
    public const ErrGroupUserLimitExceed = 129;

    /**
     * 群组未开放主动加入.
     */
    public const ErrGroupNotOpenForJoin = 130;

    /**
     * 加群用户资格不符.
     */
    public const ErrJoinUserUnqualified = 131;

    /**
     * redis lock 锁定中
     */
    public const ErrBeingLocked = 132;

    /**
     * 撤销置顶消息失败
     */
    public const ErrUnpinChatFailed = 133;

    /**
     * 创建红包失败.
     */
    public const ErrCreateLuckyMoneyFailed = 134;

    /**
     * 群组当前红包额度不足
     */
    public const ErrGroupLuckyMoneyQuotaNotEnough = 135;

    /**
     * 紅包活動未開啟.
     */
    public const ErrBonusDisabled = 136;

    /**
     * 更新最后已读失败.
     */
    public const ErrUpdateLastReadFailed = 137;

    /**
     * 加密失败.
     */
    public const ErrEncryptError = 138;

    /**
     * 更新系统群已读失败.
     */
    public const ErrUpdateSysLastReadFailed = 138;

    /**
     * 进入聊天列表失败.
     */
    public const ErrEnterMyGroupFailed = 140;

    /**
     * 进入我的信息失败.
     */
    public const ErrEnterSelfInfoFailed = 141;

    /**
     * 获取历史消息失败.
     */
    public const ErrGetChatHistoryFailed = 142;

    /**
     * 更新系统消息已读失败.
     */
    public const ErrUpdateSysLastRead = 143;

    /**
     * 彩票用户尚有打码, 导致红包创建失败
     */
    public const ErrLmFailDueToUserRemainBet = 144;

    /**
     * 搶紅包 - 紅包不存在.
     */
    public const ErrGrabLmNotExists = 145;

    /**
     * 搶紅包 - 紅包已過期
     */
    public const ErrGrabLmExpired = 146;

    /**
     * 搶紅包 - 不明原因領取失敗.
     */
    public const ErrGrabLmFailed = 147;

    /**
     * 断线重连Token非法或已超时
     */
    public const ErrInvalidOrExpiredResumeToken = 148;

    /**
     * 断线重连失败.
     */
    public const ErrResumeFailed = 149;

    /**
     * 群置顶失败
     */
    public const ErrPingGroupFailed = 150;

    /**
     * 取消群置顶失败
     */
    public const ErrUnpinGroupFailed = 151;

    /**
     * 重复登入
     */
    public const ErrDuplicateLogin = 152;

    /**
     * 申请加入群组失败
     */
    public const ErrJoinGroupFailed = 153;

    /**
     * 申请退出群组失败
     */
    public const ErrQuitGroupFailed = 154;

    /**
     * 取我的聊天群失败,
     */
    public const ErrGetMyGroupFailed = 155;

    /**
     * 取大厅群失败
     */
    public const ErrGetLobbyGroupFailed = 156;

    /**
     * 进入大厅失败
     */
    public const ErrEnterLobbyFailed = 157;

    /**
     * 未查找到用户
     */
    public const ErrUserNotFound = 158;

    /**
     * 获取系统群历史讯息失败.
     */
    public const ErrGetSysChatHistoryFailed = 159;

    /**
     * 红包结算失败
     */
    public const ErrSettleLmFailed = 160;

    /**
     * 抢红包失败, 红包已抢完
     */
    public const ErrGrabLmNoStock = 161;

    /**
     * 关闭系统红包失败.
     */
    public const ErrCloseSysLmFailed = 162;

    /**
     * 红包配置同步失败
     */
    public const ErrSyncLmFailed = 163;

    /**
     * 进入系统群失败.
     */
    public const ErrEnterSysGroupFailed = 164;

    /**
     * 已领过红包
     */
    public const ErrAlreadyTakenLm = 165;

    /**
     * 发言间隔.
     */
    public const ErrSendChatInterval = 166;

    /**
     * 发送消息为空.
     */
    public const ErrSendChatEmpty = 167;

    /**
     * 发送讯息包含网址.
     */
    public const ErrSendChatContainsUrl = 168;

    /**
     * 群主不可退群.
     */
    public const ErrOwnerCannotQuitGroup = 169;

    /**
     * 群组已解散.
     */
    public const ErrGroupDismissed = 170;

    /**
     * 服务关闭中
     */
    public const ErrServiceClosed = 9901;

}