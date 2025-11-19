<?php

namespace App\Lib;

class MsgType
{

    /**
     * Ping.
     */
    public const Ping = 'ping';

    /**
     * Pong.
     */
    public const Pong = 'pong';

    /**
     * 用户请求进.
     */
    public const EnterGroup = 'enter_group';

    /**
     * 进群后初始讯息
     */
    public const EnterGroupOK = 'enter_group_ok';

    /**
     * 离开聊天群.
     */
    public const LeaveGroup = 'leave_group';

    /**
     * 回应成功离开.
     */
    public const LeaveGroupOK = 'leave_group_ok';

    /**
     * 发送聊天讯息.
     */
    public const SendChat = 'send_chat';

    /**
     * 发送聊天讯息ok.
     */
    public const SendChatOK = 'send_chat_ok';

    /**
     * 广播聊天讯息.
     */
    public const CastChat = 'chat_cast';

    /**
     * 登陆.
     */
    public const Login = 'login';

    /**
     * 登陆成功.
     */
    public const LoginOK = 'login_ok';

    /**
     * 统一错误.
     */
    public const Error = 'error';

    /**
     * Pin聊天讯息.
     */
    public const PinChat = 'pin_chat';

    /**
     * Pin聊天讯息OK.
     */
    public const PinChatOK = 'pin_chat_ok';

    /**
     * 讯息置顶广播.
     */
    public const PinChatCast = 'pin_chat_cast';

    /**
     * 取消置顶.
     */
    public const UnpinChat = 'unpin_chat';

    /**
     * 取消置顶OK.
     */
    public const UnpinChatOK = 'unpin_chat_ok';

    /**
     * 取消置顶广播.
     */
    public const UnpinChatCast = 'unpin_chat_cast';

    /**
     * 删除聊天讯息.
     */
    public const DelChat = 'del_chat';

    /**
     * 删除聊天讯息OK.
     */
    public const DelChatOK = 'del_chat_ok';

    /**
     * 删除聊天讯息广播.
     */
    public const DelChatCast = 'del_chat_cast';

    /**
     * 将用户禁言
     */
    public const BanUser = 'ban_user';

    /**
     * 将用户禁言OK.
     */
    public const BanUserOK = 'ban_user_ok';

    /**
     * 通知被禁言用户已被禁言.
     */
    public const UserBanAck = 'ban_user_ack';

    /**
     * 用户解禁
     */
    public const UnbanUser = 'unban_user';

    /**
     * 用户解禁OK.
     */
    public const UnBanUserOK = 'unban_user_ok';

    /**
     * 通知被解禁用户已解禁
     */
    public const UserUnbanAck = 'unban_user_ack';

    /**
     * 踢群.
     */
    public const KickUser = 'kick_user';

    /**
     * 踢群OK.
     */
    public const KickUserOK = 'kick_user_ok';

    /**
     * 通知用户已被踢出群组.
     */
    public const KickUserAck = 'kick_user_ack';

    /**
     * 通知群内有人被踢
     */
    public const KickUserCast = 'kick_user_cast';

    /**
     * 管理者解散群组.
     */
    public const GroupDismissCast = 'group_dismiss_cast';

    /**
     * 进入大厅.
     */
    public const EnterLobby = 'enter_lobby';

    /**
     * 进入大厅成功通知
     */
    public const EnterLobbyOK = 'enter_lobby_ok';

    /**
     * 進入系統頻道
     */
    public const EnterSysGroup = 'enter_sys_group';

    /**
     * 進入系統頻道OK
     */
    public const EnterSysGroupOK = 'enter_sys_group_ok';

    /**
     * 系統頻道新消息.
     */
    public const SysGroupChatAck = 'sys_group_chat_ack';

    /**
     * 进入我的群组
     */
    public const EnterMyGroup = 'enter_my_group';

    /**
     * 进入我的群组OK
     */
    public const EnterMyGroupOK = 'enter_my_group_ok';

    /**
     * 进入我的资讯页.
     */
    public const EnterSelfInfo = 'enter_self';

    /**
     * 进入我的资讯页OK
     */
    public const EnterSelfInfoOK = 'enter_self_ok';

    /**
     * 更新群组最新已读
     */
    public const UpdateLastRead = 'update_last_read';

    /**
     * 更新群组最新已读OK
     */
    public const UpdateLastReadOK = 'update_last_read_ok';

    /**
     * 拉取群组历史讯息
     */
    public const GetChatHistory = 'get_chat_history';

    /**
     * 拉取群组历史讯息OK
     */
    public const GetChatHistoryOK = 'get_chat_history_ok';

    /**
     * 拉取系统群历史讯息.
     */
    public const GetSysChatHistory = 'get_sys_chat_history';

    /**
     * 拉取系统群历史讯息OK
     */
    public const GetSysChatHistoryOK = 'get_sys_chat_history_ok';

    /**
     * 更新系统群最新已读.
     */
    public const UpdateSysLastRead = 'update_sys_last_read';

    /**
     * 更新系统群最新已读OK.
     */
    public const UpdateSysLastReadOK = 'update_sys_last_read_ok';

    /**
     * 用户退群通知.
     */
    public const UserQuitCast = 'user_quit_cast';

    /**
     * 跑馬燈廣播
     */
    public const MarqueeCast = 'marquee_cast';

    /**
     * 红包关闭
     */
    public const LmClosed = 'lm_closed';

    /**
     * 全域禁言通知.
     */
    public const GlobalBanAck = 'global_ban_ack';

    /**
     * 全域解禁言通知.
     */
    public const GlobalUnbanAck = 'global_un_ban_ack';

    /**
     * 更新群状态.
     */
    public const GroupStateCast = 'group_state_cast';

    /**
     * 恢复连线.
     */
    public const Resume = 'resume';

    /**
     * 恢复连线成功.
     */
    public const ResumeOK = 'resume_ok';

    /**
     * 取大厅群列表
     */
    public const GetLobbyGroup = 'get_lobby_group';

    public const GetLobbyGroupOK = 'get_lobby_group_ok';

    /**
     * 取聊天列表
     */
    public const GetMyGroup = 'get_my_group';

    public const GetMyGroupOK = 'get_my_group_ok';

    /**
     * 红包关闭
     */
    public const SysLmClosed = 'sys_lm_closed';

    /**
     * 服务关闭.
     */
    public const ServiceClose = 'service_close';

    /**
     * 聊天列表 - 最新讯息.
     */
    public const MyGroupLastChat = 'my_group_last_chat';

    /**
     * 聊天列表 - 系统群最新讯息.
     */
    public const SysGroupLastChat = 'sys_group_last_chat';

    /**
     * 群内用户身份变更.
     */
    public const UserRoleChange = 'user_role_change';

}