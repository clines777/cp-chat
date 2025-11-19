<?php

namespace App\Middleware;

use App\Lib\ConfigKey;
use App\Lib\CpEncrypt;
use App\Lib\ErrorCode;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Repository\Impl\ConfigRepository;
use App\Repository\Impl\SiteRepository;
use Hyperf\Context\ApplicationContext;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponse;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ExtDecryptMiddleware implements MiddlewareInterface
{

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        $inputJson   = (string)$request->getBody();
        $isValidJson = ! empty($inputJson) && json_validate($inputJson);
        $input       = $isValidJson ? json_decode($inputJson, true) : [];
        if (empty($input['data']) || empty($input['h']) || empty($input['__site_bid__'])) {
            return $this->responseErr();
        }

        /** @var SiteRepository $siteRepo */
        $siteRepo    = Container::get(SiteRepository::class);
        $siteHostMap = $siteRepo->getSiteHostMap();
        if ( ! isset($siteHostMap[$input['__site_bid__']])) {
            return $this->responseErr();
        }

        if ( ! $this->isValidHash($input['h'], $input['__site_bid__'], $input['data'])) {
            Log::cli("input:".json_encode($input, 256), 'decode_hash_err');

            return $this->responseErr();
        }

        /** @var CpEncrypt $cpEncrypt */
        $cpEncrypt = Container::make(CpEncrypt::class, ['siteBid' => $input['__site_bid__']]);
        /** @var SiteRepository $siteRepo */
        $siteRepo = Container::get(SiteRepository::class);
        $rKey     = $siteRepo->getSiteRKey($input['__site_bid__']);
        if (empty($rKey)) {
            return $this->responseErr();
        }

        $decodedJson = $cpEncrypt::authcode((string)$input['data'], 'DECODE', CpEncrypt::Expire, $rKey);
        if (empty($decodedJson) || ! json_validate($decodedJson)) {
            return $this->responseErr();
        }

        $decoded             = json_decode($decodedJson, true);
        $decoded['site_bid'] = $input['__site_bid__'];
        if ( ! empty($input['__admin_id__'])) {//后台请求会带.
            $decoded['admin_id'] = (int)$input['__admin_id__'];
        }
        $request = $request->withParsedBody($decoded);

        return $handler->handle($request);
    }

    protected function responseErr(): PsrResponseInterface
    {
        /** @var HyperfResponse $response */
        $response = ApplicationContext::getContainer()->get(HyperfResponse::class);

        return $response->json([
            'code' => ErrorCode::ErrEncryptError,
            'msg'  => 'Invalid Request',
        ])->withStatus(403);
    }

    /**
     * 检查hash
     *
     * @param  string  $hash
     * @param  string  $siteBid
     * @param  string  $encode
     *
     * @return bool
     */
    protected function isValidHash(string $hash, string $siteBid, string $encode): bool
    {
        if (empty(trim($hash)) || empty(trim($siteBid)) || empty(trim($encode))) {
            return false;
        }

        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);
        $cKey       = $configRepo->getByKey(ConfigKey::MasterKey);

        return $hash === md5($siteBid.$cKey.$encode);
    }

}