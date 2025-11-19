<?php

namespace App\Lib;

class ConfigKey
{

    /**
     * 预设系统群封面图
     */
    public const DefaultSysGroupCover = 'sys_group_default_cover';

    /**
     * 预设一般群封面图大厅
     */
    public const DefaultGroupCoverLobby = 'group_default_cover_lobby';

    /**
     * 预设一般群封面聊天列表
     */
    public const DefaultGroupCoverMy = 'group_default_cover_my';

    /**
     * CDN URL
     */
    public const CdnUrl = 'cdn_url';

    /**
     * 群聊加密key.
     */
    public const MasterKey = 'master_key';

    /**
     * 开启红包.
     */
    public const BonusOpen = 'bonus_open';

    /**
     * 红包时长
     */
    public const LmHours = 'lm_ttl_hours';

    public const AwsS3Bucket = 'aws_bucket';

    public const AwsS3Region = 'aws_region';

    public const AwsS3Key = 'aws_key';

    public const AwsS3Token = 'aws_token';

    public const GroupLastChatCount = 'last_chat_count';

    public const SysLastChatCount = 'last_sys_chat_count';

    public const ServerOn = 'server_on';

    public const ServerCloseMsg = 'server_close_msg';

    public const ManualToken = 'manual_token';

    public const S3Dir = 's3_dir';

}