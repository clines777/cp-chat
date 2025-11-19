<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Listener;

use Hyperf\Collection\Arr;
use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

use function Hyperf\Config\config;

#[Listener]
class DbQueryExecutedListener implements ListenerInterface
{

    private static bool $isEnable = true;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(ContainerInterface $container)
    {
        $appEnv           = config('sys.app_env', 'prod');
        static::$isEnable = strtolower($appEnv) !== 'prod';
        $this->logger     = $container->get(LoggerFactory::class)->get('sql-log', 'sql');
    }

    public function listen(): array
    {
        if (static::$isEnable) {//生产环境不启用, 节省效能
            return [
                QueryExecuted::class,
            ];
        }

        return [];
    }

    /**
     * @param  QueryExecuted  $event
     */
    public function process(object $event): void
    {
        if ($event instanceof QueryExecuted) {
            $sql = $event->sql;
            if ( ! Arr::isAssoc($event->bindings)) {
                $position = 0;
                foreach ($event->bindings as $value) {
                    $position = strpos($sql, '?', $position);
                    if ($position === false) {
                        break;
                    }
                    $value    = "'{$value}'";
                    $sql      = substr_replace($sql, $value, $position, 1);
                    $position += strlen($value);
                }
            }

            $this->logger->info(sprintf('[%s] %s', $event->time, $sql));
        }
    }

}
