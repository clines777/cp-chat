<?php

namespace App\Lib\Facade;

use App\Lib\Helper;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Log\LogLevel;

class Log
{

    public const LogChanDefault = 'default';

    public const LogChanApi = 'api';

    public const LogChanWs = 'ws';

    public const LogChanName = 'log_chan';

    public const DefaultCate = 'Application';

    protected static string $file = BASE_PATH."/runtime/logs/logs.log";

    // 复用一个 Formatter，减少分配
    private static ?LineFormatter $formatter = null;

    private static function formatter(): LineFormatter
    {
        if (self::$formatter === null) {
            $fmt             = "[%datetime%] %channel% %level_name%: [%extra.category%] %message%";
            $date            = "Y-m-d H:i:s.u";
            self::$formatter = new LineFormatter($fmt, $date, true, true);
        }

        return self::$formatter;
    }

    public static function error(string $msg, string $category = self::DefaultCate): void
    {
        $logChan = \Hyperf\Context\Context::get(self::LogChanName, self::LogChanDefault);
        self::writeLog($logChan, $category, LogLevel::ERROR, $msg);
    }

    public static function info(string $msg, string $category = self::DefaultCate): void
    {
        $logChan = \Hyperf\Context\Context::get(self::LogChanName, self::LogChanDefault);
        self::writeLog($logChan, $category, LogLevel::INFO, $msg);
    }

    private static function writeLog(string $logChan, string $category, string $level, string $msg): void
    {
        try {
            $monologLevel = Level::fromName(strtolower($level));
            $record       = new LogRecord(
                datetime: new \DateTimeImmutable(), channel: $logChan, level: $monologLevel, message: $msg, context: [], extra: ['category' => $category],
            );

            $line = self::formatter()->format($record);
            $line = str_ends_with($line, PHP_EOL) ? $line : $line.PHP_EOL;

            file_put_contents(static::$file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            self::cli(sprintf('writeLog例外, 丢失log: %s, 错误讯息: %s', $msg, Helper::getExpDetails($e)), 'pushLog_err');
        }
    }

    public static function cli(string $msg, string $category = self::LogChanDefault): void
    {
        echo sprintf("[%s] %s\n", $category, $msg);
    }

}