<?php

namespace App\Lib;

use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Repository\Impl\SiteRepository;
use GuzzleHttp\Client;

class CpApi
{

    protected string $apiHost;

    protected string $siteBid;

    protected array $reqInfo;

    public const MainPath = 'talk/cp-chat';

    /**
     * 查用户状态.
     */
    public const PathUserLmCreateInfo = 'user-lm';

    public const PathAddBackUserBalance = 'settle-lm';

    /**
     * 同步创建的红包
     */
    public const PathSyncLm = 'sync-lm';

    /**
     * 同步红包纪录进行派奖.
     */
    public const PathSendLm = 'send-lucky';

    /**
     * 扣除发红包的用户余额.
     */
    public const PathSubLmCost = 'sub-lm-cost';

    /**
     * 测试连线
     */
    public const PathPing = 'pong';

    public const PathKey = 'k';

    public function __construct(string $siteBid)
    {
        /** @var SiteRepository $siteRepo */
        $siteRepo    = Container::get(SiteRepository::class);
        $siteHostMap = $siteRepo->getSiteHostMap();
        $siteHost    = $siteHostMap[$siteBid] ?? [];
        if (empty($siteHost)) {
            throw new \InvalidArgumentException('未匹配彩票端的api host! siteBid:'.$siteBid);
        }
        $this->apiHost = $siteHost->api_host;
        $this->siteBid = $siteBid;
    }

    /**
     * 获取对应站点RKey
     *
     * @param  string  $masterKey
     *
     * @return string
     */
    public function getReceiveKey(string $masterKey): string
    {
        $apiUrl  = $this->apiUrl(static::PathKey);
        $check   = strtoupper(sha1($masterKey));
        $check   = CaesarCipher::enc($check);
        $respStr = $this->post($apiUrl, ['t' => time(), 'check' => $check], 10, false);//加密需要用到各站的receive_key, 但这支function就是要拿receive_key, 所以不走加密.
        Log::info(
            sprintf(
                '获取彩票端加密KEY: %s',
                json_encode($this->reqInfo, 256),
            ),
            'getReceiveKey_info',
        );
        $data = ! empty($respStr) && json_validate($respStr) ? json_decode($respStr, true) : [];
        if ( ! empty($data['data']['key'])) {
            return base64_decode($data['data']['key']);
        }

        return '';
    }

    /**
     * 取彩票端用户资讯 - 抢红包检查状态用.
     *
     * @param  int  $extMemberId
     *
     * @return array
     */
    public function getCreateLmInfo(int $extMemberId): array
    {
        $apiUrl  = $this->apiUrl(self::PathUserLmCreateInfo);
        $data    = ['member_id' => $extMemberId];
        $respStr = $this->post($apiUrl, $data);
        Log::info(
            sprintf(
                '检查并获取用户发红包资讯: %s',
                json_encode($this->reqInfo, 256),
            ),
            'syncLm_req_info',
        );

        $resp = ! empty($respStr) && json_validate($respStr) ? (array)json_decode($respStr, true) : [];
        if ( ! $this->respOk($resp)) {
            $msg = $resp['errmsg'] ?? '';

            return [[], $msg];
        }

        $respData = $resp['data'] ?? [];

        return [$respData, 'ok'];
    }

    //同步红包活动配置
    public function syncLm(array $lmInfo): array
    {
        $apiUrl  = $this->apiUrl(self::PathSyncLm);
        $respStr = $this->post($apiUrl, $lmInfo);
        Log::info(
            sprintf(
                '红包活动配置同步至彩票端: %s',
                json_encode($this->reqInfo, 256),
            ),
            'syncLm_req_info',
        );
        $resp = ! empty($respStr) && json_validate($respStr) ? json_decode($respStr, true) : [];
        if ( ! $this->respOk($resp)) {
            return [];
        }

        return $resp;
    }

    /**
     * 同步红包派奖纪录.
     *
     * @param  array  $lm
     * @param  array  $record
     *
     * @return array
     */
    public function sendCpFunding(array $lm, array $record): array
    {
        $apiUrl  = $this->apiUrl(self::PathSendLm);
        $data    = ['lm' => $lm, 'record' => $record];
        $respStr = $this->post($apiUrl, $data);
        Log::info(
            sprintf(
                '处理红包委托派奖:  %s',
                json_encode($this->reqInfo, 256),
            ),
            'syncLmRecord_req_info',
        );

        return ! empty($respStr) && json_validate($respStr) ? json_decode($respStr, true) : [];
    }

    /**
     * 扣除彩票用户红包费用.
     *
     * @param  array   $user
     * @param  array   $lmInfo
     * @param  string  $lmCost
     *
     * @return array
     */
    public function subCpMemberBalance(array $user, array $lmInfo, string $lmCost): array
    {
        $apiUrl = $this->apiUrl(static::PathSubLmCost);
        $data   = [
            'member_id' => (int)$user['ext_member_id'],
            'username'  => $user['ext_username'],
            'lm_id'     => (int)$lmInfo['id'],
            'lm_title'  => $lmInfo['title'],
            'lm_cost'   => $lmCost,
        ];

        $respStr = $this->post($apiUrl, $data);
        Log::info(
            sprintf(
                '发送请求至彩票端扣除用户发红包费用: %s',
                json_encode($this->getReqInfo(), 256),
            ),
            'subCpMemberBalance_info',
        );
        $resp = ! empty($respStr) && json_validate($respStr) ? json_decode($respStr, true) : [];

        $ok = $this->respOk($resp);
        if ( ! $ok) {
            $msg = $resp['errmsg'] ?? '';

            return [false, $msg];
        }

        return [true, ''];
    }

    /**
     * 返回红包余额给一般用户.
     *
     * @param  array   $userInfo
     * @param  array   $lm
     *
     * @param  string  $remainBalance
     * @param  int     $closeType
     *
     * @return array
     */
    public function addBackUserBalance(array $userInfo, array $lm, string $remainBalance, int $closeType): array
    {
        $apiUrl  = $this->apiUrl(static::PathAddBackUserBalance);
        $input   = [
            'username'      => $userInfo['ext_username'],
            'member_id'     => (int)$userInfo['ext_member_id'],
            'platform_type' => (int)$userInfo['ext_platform_type'],
            'lm_id'         => (int)$lm['id'],
            'lm_title'      => $lm['title'],
            'balance'       => $remainBalance,
            'close_type'    => $closeType,
        ];
        $respStr = $this->post($apiUrl, $input);
        Log::info(
            sprintf(
                '发送请求至彩票端补回用户红包余额: %s',
                json_encode($this->getReqInfo(), 256),
            ),
            'addBackUserBalance_info',
        );
        $resp = ! empty($respStr) && json_validate($respStr) ? json_decode($respStr, true) : [];
        if ( ! $this->respOk($resp)) {
            $msg = $resp['errmsg'] ?? '';

            return [false, $msg];
        }

        return [true, 'ok'];
    }

    /**
     * 打到彩票对应站, 测试连线用.
     *
     * @return array
     */
    public function ping(): array
    {
        $apiUrl  = $this->apiUrl(self::PathPing);
        $input   = ['t' => time(), 'site_bid' => $this->siteBid];
        $respStr = $this->post($apiUrl, $input);
        Log::info(
            sprintf(
                '发送请求至彩票端测试连线: %s',
                json_encode($this->getReqInfo(), 256),
            ),
            'ping_info',
        );

        $resp = ! empty($respStr) && json_validate($respStr) ? json_decode($respStr, true) : [];
        if ( ! $this->respOk($resp)) {
            $msg = $resp['errmsg'] ?? '';

            return [false, $msg];
        }

        return [true, $resp['data'] ?? []];
    }

    /**
     * 加密.
     *
     * @param  array  $dataToEncrypt
     *
     * @return array ['data' => $encoded, 'h' => md5($encoded), '__site_bid__' => $this->siteBid]
     */
    protected function encodeAndHash(array $dataToEncrypt): array
    {
        $infoJson = json_encode($dataToEncrypt);
        $rKey     = $this->findRKey($this->siteBid);
        $encoded  = CpEncrypt::authCode($infoJson, 'ENCODE', CpEncrypt::Expire, $rKey);
        if (empty($encoded)) {
            return [];
        }

        return ['data' => $encoded, 'h' => md5($encoded), '__site_bid__' => $this->siteBid];
    }

    /**
     * post request
     *
     * @param  string  $apiUrl
     * @param  array   $rawParam
     * @param  int     $timeout
     * @param  bool    $encode
     *
     * @return string
     */
    protected function post(string $apiUrl, array $rawParam, int $timeout = 10, bool $encode = true): string
    {
        $this->reqInfo['req']['url']    = $apiUrl;
        $this->reqInfo['req']['origin'] = $rawParam;
        $jsonBody                       = $rawParam;
        if ($encode) {
            $jsonBody = $this->encodeAndHash($rawParam);
        }

        $c                            = new Client();
        $body                         = null;
        $respStr                      = '';
        $options                      = [
            'json'         => $jsonBody,
            'timeout'      => $timeout,
            'Content-Type' => 'application/json',
            'verify'       => false,
        ];
        $this->reqInfo['req']['info'] = $options;
        try {
            $response                          = $c->post($apiUrl, $options);
            $httpCode                          = $response->getStatusCode();
            $this->reqInfo['req']['http_code'] = $httpCode;
            if ($httpCode !== 200) {
                return '';
            }

            $body                          = $response->getBody();
            $respStr                       = (string)$body;
            $this->reqInfo['resp']['body'] = $respStr;
            //            Log::info(
            //                sprintf('呼叫彩票API: %s', json_encode($this->reqInfo, JSON_UNESCAPED_UNICODE)),
            //                'cp_request_info',
            //            );
        } catch (\Throwable $e) {
            Log::error(sprintf('呼叫彩票端API异常! URL: %s 参数: %s, 讯息: %s', $apiUrl, json_encode($rawParam, 256), Helper::getExpDetails($e)), 'call_cp_error');
        }
        finally {
            if ($body instanceof \Psr\Http\Message\StreamInterface) {
                $body->close();
            }
        }

        return $respStr;
    }

    /**
     * @return array
     */
    public function getReqInfo(): array
    {
        return $this->reqInfo;
    }

    /**
     * 组建API Url
     *
     * @param  string  $subPath
     *
     * @return string
     */
    protected function apiUrl(string $subPath): string
    {
        return sprintf('%s/%s/%s', $this->apiHost, static::MainPath, $subPath);
    }

    /**
     * 拿对应站点的receive_key
     *
     * @param  string  $siteBid
     *
     * @return string
     */
    protected function findRKey(string $siteBid): string
    {
        /** @var \App\Repository\Impl\SiteRepository $siteRepo */
        $siteRepo = Container::get(SiteRepository::class);

        return $siteRepo->getSiteRKey($siteBid);
    }

    /**
     * is CP Response OK
     *
     * @param  array  $resp
     *
     * @return bool
     */
    protected function respOk(array $resp): bool
    {
        return isset($resp['errcode']) && (int)$resp['errcode'] === 0;
    }

}