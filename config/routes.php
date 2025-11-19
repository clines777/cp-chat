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

use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Hyperf\HttpServer\Router\Router;

Router::get('/web_doc', function (HttpResponse $response) {
    if ( ! (\App\Lib\Helper::isTestEnv() || \App\Lib\Helper::isDevEnv())) {
        return $response->withStatus(404)->raw('not found');
    }

    $swaggerPath = BASE_PATH.'/app/Lib/Data/swagger.html';
    if ( ! is_file($swaggerPath)) {
        return $response->withStatus(404)->raw('swagger.html not found');
    }
    $openApiFile = BASE_PATH.'/app/Lib/Data/openapi.json';
    $openApiJson = file_exists($openApiFile) ? file_get_contents($openApiFile) : '';
    if (empty($openApiJson)) {
        return $response->withStatus(404)->raw('openapi.json not found');
    }

    $html = file_get_contents($swaggerPath);
    $html = str_replace('__OPENAPI_JSON__', $openApiJson, $html);

    // 直接返回 HTML（会带上 text/html）
    return $response->html($html);
});