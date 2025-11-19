<?php

namespace App\Lib;

/**
 * 系统常数.
 */
class SysConst
{

    /**
     * 金额小数位数.
     */
    public const MoneyDecimal = 3;

    /**
     * 预设页次.
     */
    public const DefaultPage = 1;

    /**
     * 前端列表数据预设每页笔数.
     */
    public const DefaultPageSize = 20;

    /**
     * 红包纪录预设每页笔数.
     */
    public const LmRecordPageSize = 50;

    /**
     * 后台查询数据每页笔数.
     */
    public const AdminIndexPageSize = 25;

    /**
     * APP_ENV - 生产
     */
    public const EnvProduction = 'prod';

    /**
     * APP_ENV - 测试环境
     */
    public const EnvTest = 'test';

    /**
     * APP_ENV - 本地开发
     */
    public const EnvDev = 'dev';

}