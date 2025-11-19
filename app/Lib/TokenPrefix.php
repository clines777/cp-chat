<?php

namespace App\Lib;

/**
 * Token前缀.
 */
class TokenPrefix
{

    /**
     * 登入Token前缀.
     */
    public const Login = '001';

    /**
     * API Token, 主要用于辨识是哪个站的用户.
     */
    public const Api = '002';

    /**
     * 重新连线用的Token.
     */
    public const Resume = '003';

}