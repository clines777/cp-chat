<?php

namespace App\Lib;

use App\Lib\Facade\Log;

/**
 * 布署替用用config
 */
class DeployConfig
{

    public function __construct()
    {
        $this->fetchConfigs();
    }

    protected array $databaseConfig = [];

    protected array $redisConfig = [];

    protected array $serverConfig = [];

    protected const MainPath = BASE_PATH.'/deploy_config/';

    public const TypeDatabase = 'database';

    public const TypeRedis = 'redis';

    public const TypeSever = 'server';

    protected string $current = self::TypeDatabase;

    /**
     * 初始化config档
     *
     * @return void
     */
    public function fetchConfigs(): void
    {
        $dbConfigPath     = self::MainPath.'database.php';
        $redisConfigPath  = self::MainPath.'redis.php';
        $serverConfigPath = self::MainPath.'server.php';

        try {
            if (file_exists($dbConfigPath)) {
                $this->databaseConfig = require_once $dbConfigPath;
            } else {
                throw new \InvalidArgumentException('Database Config file does not exist');
            }

            if (file_exists($redisConfigPath)) {
                $this->redisConfig = require_once $redisConfigPath;
            } else {
                throw new \InvalidArgumentException('Redis Config file does not exist');
            }

            if (file_exists($serverConfigPath)) {
                $this->serverConfig = require_once $serverConfigPath;
            } else {
                throw new \InvalidArgumentException('Server Config file does not exist');
            }
        } catch (\Throwable $e) {
            Log::cli('部署config不存在! 讯息: %s'.Helper::getExpDetails($e), 'config file not found');
        }
    }

    public function setCurrent(string $current): void
    {
        $this->current = $current;
    }

    /**
     * @param  string  $firstLevel
     * @param  string  $secondLevel
     *
     * @return mixed
     */
    public function config(string $firstLevel, string $secondLevel = ''): mixed
    {
        $config = [];
        switch ($this->current):
            case self::TypeDatabase:
                $config = $this->databaseConfig;
                break;
            case self::TypeRedis:
                $config = $this->redisConfig;
                break;
            case self::TypeSever:
                $config = $this->serverConfig;
                break;
        endswitch;

        if ( ! empty($secondLevel) && isset($config[$firstLevel][$secondLevel])) {
            return $config[$firstLevel][$secondLevel];
        }

        return $config[$firstLevel] ?? '';
    }

    public function printAll(): void
    {
        Log::cli(json_encode($this->databaseConfig, JSON_UNESCAPED_UNICODE), 'dbconfig');
        Log::cli(json_encode($this->redisConfig, JSON_UNESCAPED_UNICODE), 'redisconfig');
        Log::cli(json_encode($this->serverConfig, JSON_UNESCAPED_UNICODE), 'serverconfig');
    }

}