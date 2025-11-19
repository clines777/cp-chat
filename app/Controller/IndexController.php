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

namespace App\Controller;

use App\Lib\CpApi;
use App\Lib\Facade\Container;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Psr\Http\Message\ServerRequestInterface;

#[Controller(prefix: "/")]
class IndexController extends AbstractController
{

    #[GetMapping("ping")]
    public function ping()
    {
        return ['msg' => 'pong'];
    }

    #[GetMapping("ping-cp")]
    public function pingCp(ServerRequestInterface $request)
    {
        $getParam = $request->getQueryParams();
        $siteBid  = isset($getParam['site_bid']) ? trim($getParam['site_bid']) : '';
        if (empty($siteBid)) {
            return ['msg' => 'invalid'];
        }

        /** @var CpApi $cpApi */
        $cpApi = Container::make(CpApi::class, ['siteBid' => $siteBid]);

        [$ok, $data] = $cpApi->ping();
        if ( ! $ok) {
            return ['msg' => '连线失败'];
        }

        return $data;
    }

}
