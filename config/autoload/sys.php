<?php

use App\Lib\DeployConfig;
use Hyperf\Context\ApplicationContext;

$deployConfig = ApplicationContext::getContainer()->get(DeployConfig::class);
$deployConfig->setCurrent(DeployConfig::TypeSever);

return [
    'server_id'      => (int)$deployConfig->config('server_id'),//多台server时适用, 大于等于0的正整数, 每台server不能相同
    'app_env'        => $deployConfig->config('app_env'),
    'close_wait_sec' => 5,
];