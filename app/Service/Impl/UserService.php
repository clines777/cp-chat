<?php

namespace App\Service\Impl;

use App\Lib\CommonResult;
use App\Lib\ConfigKey;
use App\Lib\ErrorCode;
use App\Lib\Facade\Cache;
use App\Lib\Facade\Container;
use App\Lib\Facade\Log;
use App\Lib\Facade\Validator;
use App\Lib\Helper;
use App\Lib\MsgPayload;
use App\Lib\NoSqlKey;
use App\Lib\Retrier;
use App\Lib\Scene;
use App\Lib\TokenPrefix;
use App\Lib\UserLogBuilder;
use App\Model\User;
use App\Repository\Impl\AvatarRepository;
use App\Repository\Impl\ConfigRepository;
use App\Repository\Impl\SessionRepository;
use App\Repository\Impl\UserLogRepository;
use App\Repository\Impl\UserRepository;
use stdClass;
use Swoole\WebSocket\Server;

use function Hyperf\Config\config;

class UserService
{

    protected UserRepository $userRepository;

    public function __construct()
    {
        $this->userRepository = new UserRepository();
    }

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Random\RandomException
     */
    public function getLoginToken(array $param): CommonResult
    {
        $validate = Validator::validate($param, [
            "member_id"     => "required|integer",
            "username"      => "required|string",
            "user_level"    => "required|integer",
            "platform_type" => "required|integer",
            "device"        => 'required|integer',
            "tpl"           => "required|integer",
            'site_bid'      => "required|string",
        ]);

        if (empty($validate->success)) {
            return CommonResult::invalidParam($validate->msg);
        }

        $token = $this->genToken(TokenPrefix::Login, $validate->validated['site_bid']);
        if (empty($token)) {
            return CommonResult::error('Token生成失败1!');
        }
        $cacheData = [
            'token'             => $token,
            'ext_member_id'     => $validate->validated['member_id'],
            'ext_username'      => $validate->validated['username'],
            'site_bid'          => $validate->validated['site_bid'],
            'user_level'        => $validate->validated['user_level'],
            'ext_platform_type' => $validate->validated['platform_type'],
            'device'            => $validate->validated['device'],
            'tpl'               => $validate->validated['tpl'],
        ];

        //登入Token只缓存不入DB, 后续用户带Token登入时验证过再生成聊天用Token并存入user表.
        $isSaved = $this->userRepository->cSaveLoginToken($cacheData, $token);
        if ( ! $isSaved) {
            return CommonResult::error('Token生成失败2!');
        }

        return CommonResult::make(
            ErrorCode::ErrNone,
            'ok',
            ['token' => $token, 'username' => $cacheData['ext_username'], 'site_bid' => $cacheData['site_bid']],
        );
    }

    /**
     * 生成token
     *
     * @param  string  $prefix   前缀1, 用于表示此Token的种类.
     * @param  string  $siteBid  前缀2, 用于表示此Token的来源站点.
     *
     * @return string
     * @throws \Random\RandomException
     */
    protected function genToken(string $prefix, string $siteBid): string
    {
        if (empty($prefix)) {
            return '';
        }

        $hash = bin2hex(random_bytes(16)).(int)(microtime(true) * 1000);

        $randHash = preg_replace_callback('/[a-z]/i', function ($matches) {
            return rand(0, 1) ? strtoupper($matches[0]) : strtolower($matches[0]);
        }, $hash);

        return sprintf('%s%s%s', $prefix, $siteBid, $randHash);
    }

    /**
     * 一般用户登入聊天室(验证登入Token并进行Token置换)
     *
     * @param  Server               $server
     * @param  int                  $fd
     * @param  \App\Lib\MsgPayload  $payload
     *
     * @return \App\Lib\CommonResult ['data' => ['user' => [
     *  'id'         => $userInfo['id'],
     *  'code'       => $userInfo['code'],
     *  'name'       => Helper::maskUsername($userInfo['ext_username']),
     *  'avatar_url' => $this->getAvatarUrl($userInfo['avatar_id']),
     *  'api'        => $apiToken,
     *  'resume'     => $resumeToken,
     *  ],]]
     * @throws \Random\RandomException
     */
    public function login(Server $server, int $fd, MsgPayload $payload): CommonResult
    {
        if (empty($payload->data['token'])) {
            return CommonResult::error('缺少登入令牌');
        }
        $loginToken = trim($payload->data['token']);
        $prefix     = substr($loginToken, 0, 3);
        if ($prefix !== TokenPrefix::Login) {
            Log::error("无效的登入令牌1 login_token1:".$loginToken, 'token_prefix_err');

            return CommonResult::error('无效的登入令牌');
        }

        //验证登入Token
        $loginTokenKey  = NoSqlKey::loginTokenKey($loginToken);
        $loginTokenInfo = Cache::get($loginTokenKey);//生成Login Token时放缓存的暂存数据, 避免被刷先不存入DB
        if (empty($loginTokenInfo)) {
            Log::error("登入令牌已超时 login_token2:".$loginToken, 'token_cache_not_found');

            return CommonResult::error('登入令牌已超时');
        }

        [$ok, $lackKeys] = $this->containsValidFields($loginTokenInfo);
        if ( ! $ok) {
            Log::error(
                sprintf(
                    '登入Token资讯缺少栏位: %s, Token数据: %s',
                    json_encode($lackKeys, JSON_UNESCAPED_UNICODE),
                    json_encode($loginTokenInfo, JSON_UNESCAPED_UNICODE),
                ),
                'login_token_field_err',
            );

            return CommonResult::error('无效的登入令牌');
        }

        $loginTokenInfo['site_bid'] = strtoupper($loginTokenInfo['site_bid']);
        $result                     = $this->addUserIfNotExists($loginTokenInfo);
        if ( ! $result->isSuccess()) {
            Log::error("登入失败! ".$result->msg, 'login_result_failed');

            return CommonResult::error('登入失败');
        }
        $userInfo = $result->data;

        //检查重复登入 - 需后踢前
        /** @var SessionRepository $sessionRepo */
        $sessionRepo  = Container::get(SessionRepository::class);
        $existsConnFd = $sessionRepo->rGetExistsUserFd($userInfo['id']);
        if ($existsConnFd > 0) {
            $sessionRepo->rCleanUserSessions($existsConnFd);
            Log::info(sprintf('重复登入用户ID: %s, fd: %s', $userInfo['id'], $fd), 'duplicated_login');
            Helper::disconnect($server, $existsConnFd, MsgPayload::error(ErrorCode::ErrDuplicateLogin, null, '同帐号已登入'));
        }

        $resumeToken = $this->genToken(TokenPrefix::Resume, $userInfo['site_bid']);
        $apiToken    = $this->genToken(TokenPrefix::Api, $userInfo['site_bid']);
        if (empty($apiToken) || empty($resumeToken)) {
            Log::error('api跟resume token生成失败', 'gen_in_app_token_failed');

            return CommonResult::error('登入失败');
        }

        [$ok, $msg] = $this->initSessions($fd, $userInfo, $apiToken, $resumeToken);
        if ( ! $ok) {
            Log::error("session建立失败:".$msg, 'initSessions failed');

            return CommonResult::error('session建立失败');
        }

        $sessionRepo->rSetScene($fd, Scene::LoginScene);//纪录初始场景

        $result = CommonResult::success('ok', [
            'user' => [
                'id'            => $userInfo['id'],
                'user_level'    => $userInfo['user_level'],
                'is_global_ban' => (int)$userInfo['is_global_ban'] === User::IsGlobalBanYes ? 1 : 0,
                'code'          => $userInfo['code'],
                'name'          => Helper::maskUsername($userInfo['ext_username']),
                'avatar_url'    => $this->getAvatarUrl($userInfo['avatar_id']),
                'api'           => $apiToken,
                'resume'        => $resumeToken,
            ],
        ]);

        Cache::del(NoSqlKey::extQueryUserId($userInfo['site_bid'], $userInfo['ext_member_id']));

        unset($resumeToken, $apiToken, $sessionData, $apiTokenData);

        return $result;
    }

    /**
     * 初始化用户Session
     */
    public function initSessions(int $fd, array $userInfo, string $apiToken, string $resumeToken): array
    {
        /** @var SessionRepository $sessionRepo */
        $sessionRepo = Container::get(SessionRepository::class);

        $sessionTtl = config('business.session_ttl');

        /**
         * 'id'                    => (int)$user['id'],
         * 'site_bid'              => $user['site_bid'],
         * 'user_level'            => (int)$user['user_level'],
         * 'api_token'             => $apiToken,
         * 'resume_token'          => $resumeToken,
         * 'ext_member_id'         => (int)$user['ext_member_id'],
         * 'ext_username'          => $user['ext_username'],
         * 'ext_platform_type'     => (int)$user['ext_platform_type'],
         * 'code'                  => $user['code'],
         * 'avatar_id'             => (int)$user['avatar_id'],
         * 'is_global_ban'         => (int)$user['is_global_ban'],
         * 'scene'                 => Scene::buildScene(Scene::LoginScene),
         * 'sys_chat_last_read_id' => (int)$user['sys_chat_last_read_id'],
         */
        $sessionData = $this->buildSessionData($userInfo, $apiToken, $resumeToken);

        /**
         * 'id'                => (int)$user['id'],
         * 'site_bid'          => $user['site_bid'],
         * 'user_level'        => (int)$user['user_level'],
         * 'ext_member_id'     => (int)$user['ext_member_id'],
         * 'ext_username'      => $user['ext_username'],
         * 'ext_platform_type' => (int)$user['ext_platform_type'],
         * 'code'              => $user['code'],
         * 'avatar_id'         => (int)$user['avatar_id'],
         */
        $apiTokenData = $this->buildApiTokenInfo($sessionData);

        if ( ! $sessionRepo->rMakeSession($fd, json_encode($sessionData), $sessionTtl)
            || ! $sessionRepo->rSaveApiToken($apiToken, json_encode($apiTokenData), $sessionTtl)
            || ! $sessionRepo->rSaveResumeToken($resumeToken, ['user_id' => $userInfo['id']], config('business.resume_token_ttl'))
            || ! $sessionRepo->rMakeUserIdToFdMapping($userInfo['id'], $fd, $sessionTtl)
            || ! $sessionRepo->rSetSiteOnline($userInfo['site_bid'], $userInfo)
        ) {
            Log::error('登入时session或token设置失败!', 'initSessions_err');

            return [false, 'session建立失败'];
        }

        return [true, 'ok'];
    }

    /**
     * 恢复连线
     *
     * @param  \Swoole\WebSocket\Server  $server
     * @param  int                       $fd
     * @param  array                     $resumeInfo
     *
     * @return \App\Lib\CommonResult
     * @throws \Random\RandomException
     */
    public function resume(Server $server, int $fd, array $resumeInfo): CommonResult
    {
        $userId = isset($resumeInfo['user_id']) ? (int)$resumeInfo['user_id'] : 0;
        if (empty($userId)) {
            Log::error('恢复连线失败, user_id not found', 'resume_err');

            return CommonResult::error('恢复连线失败');
        }

        $userInfo = $this->userRepository->getOne($userId);
        if (empty($userInfo)) {
            return CommonResult::error('查无此用户');
        }
        
        $resumeToken = $this->genToken(TokenPrefix::Resume, $userInfo['site_bid']);
        $apiToken    = $this->genToken(TokenPrefix::Api, $userInfo['site_bid']);
        if (empty($apiToken) || empty($resumeToken)) {
            return CommonResult::error('令牌获取失败');
        }

        [$ok, $msg] = $this->initSessions($fd, $userInfo, $apiToken, $resumeToken);
        if ( ! $ok) {
            Log::error("恢复连线session建立失败:".$msg, 'initSessions failed');

            return CommonResult::error('session建立失败');
        }

        /** @var SessionRepository $sessionRepo */
        $sessionRepo = Container::get(SessionRepository::class);
        $sessionRepo->rSetScene($fd, Scene::LoginScene);//暂时先固定跳转回登入后场景

        $result = CommonResult::success('ok', [
            'user' => [
                'id'            => $userInfo['id'],
                'user_level'    => $userInfo['user_level'],
                'is_global_ban' => (int)$userInfo['is_global_ban'] === User::IsGlobalBanYes ? 1 : 0,
                'code'          => $userInfo['code'],
                'name'          => Helper::maskUsername($userInfo['ext_username']),
                'avatar_url'    => $this->getAvatarUrl($userInfo['avatar_id']),
                'api'           => $apiToken,
                'resume'        => $resumeToken,
            ],
        ]);

        unset($resumeToken, $apiToken, $sessionData, $apiTokenData);

        return $result;
    }

    /**
     * @param  array  $tokenInfo  token资讯
     *
     * @return \App\Lib\CommonResult
     */
    protected function addUserIfNotExists(array $tokenInfo): CommonResult
    {
        //tokenInfo:{
        //  "token": "001B66665d0781c32b00eee87d57de17385f4ba1753766306010",
        //  "ext_member_id": 610086,
        //  "ext_username": "felix9spin",
        //  "site_bid": "B666",
        //  "user_level": 13,
        //  "ext_platform_type": 1,
        //  "device": 1,
        //  "tpl": 1
        //}

        /** @var UserLogBuilder $userLogger */
        $userLogger = Container::make(UserLogBuilder::class, ['siteBid' => $tokenInfo['site_bid']]);

        $existsUser = $this->userRepository->getOneUserByCondition(
            ['site_bid' => $tokenInfo['site_bid'], 'ext_member_id' => $tokenInfo['ext_member_id']],
        );
        $time       = time();
        if (empty($existsUser)) {
            $avatarId = $this->getRandAvatarId();
            $userCode = Retrier::run(function () use ($tokenInfo) {
                return $this->createUserCode($tokenInfo['ext_username'], $tokenInfo['site_bid']);
            });
            if ($userCode === '') {
                return CommonResult::error('忙碌中, 请稍候重试');
            }

            $insert   = [
                'ext_member_id'     => (int)$tokenInfo['ext_member_id'],
                'ext_username'      => $tokenInfo['ext_username'],
                'ext_platform_type' => (int)$tokenInfo['ext_platform_type'],
                'site_bid'          => $tokenInfo['site_bid'],
                'user_level'        => (int)$tokenInfo['user_level'],
                'last_login_time'   => $time,
                'create_time'       => $time,
                'update_time'       => $time,
                'avatar_id'         => $avatarId,
                'code'              => $userCode,
            ];
            $userInfo = $this->userRepository->insertAndGet($insert);
            if ( ! $userInfo) {
                return CommonResult::error('登入时写入用户表失败!');
            }

            $userLogger
                ->setParam($userInfo['id'], $userInfo['ext_member_id'], $userInfo['ext_username'])->sceneInApp()->bySelf()->setLogType(UserLogBuilder::TypeCreateUser)->withRemark(
                    '用户初次进入群聊 聊天号:'.$userCode,
                );
        } else {
            $userInfo = $this->userRepository->updateAndGet(
                $existsUser['id'],
                ['user_level' => (int)$tokenInfo['user_level'], 'last_login_time' => $time, 'update_time' => $time,],
            );
            if ( ! $userInfo) {
                return CommonResult::error('登入时更新登入资讯失败!');
            }
            $userLogger
                ->setParam($userInfo['id'], $userInfo['ext_member_id'], $userInfo['ext_username'])->sceneInApp()->bySelf()->setLogType(UserLogBuilder::TypeLogin)->withRemark(
                    '用户登入',
                );
        }
        /** @var UserLogRepository $userLogRepo */
        $userLogRepo = Container::get(UserLogRepository::class);
        $userLogRepo->addWithBuilder($userLogger);

        return CommonResult::success('ok', $userInfo);
    }

    /**
     * 生成并检查user.code是否重复.
     *
     * @param  string  $extUsername
     * @param  string  $siteBid
     *
     * @return array
     */
    public function createUserCode(string $extUsername, string $siteBid): array
    {
        $code = Helper::genUserCode($extUsername, $siteBid);
        if ( ! $this->userRepository->codeExists($code)) {
            return [true, $code];
        }

        return [false, ''];
    }

    /**
     * 取默认头像数据.
     *
     * @return CommonResult
     */
    public function getAvatars(): CommonResult
    {
        /** @var AvatarRepository $avatarRepo */
        $avatarRepo = Container::get(AvatarRepository::class);
        $map        = $avatarRepo->getMap();
        if ( ! empty($map)) {
            $list = [];
            /** @var ConfigRepository $configRepo */
            $configRepo = Container::get(ConfigRepository::class);
            $cdnUrl     = $configRepo->getByKey(ConfigKey::CdnUrl);
            foreach ($map as $id => $path) {
                $each      = new stdClass();
                $each->id  = $id;
                $each->url = $cdnUrl.$path;
                $list[]    = $each;
            }
        }

        return CommonResult::success('ok', $list);
    }

    /**
     * 组建session数据.
     *
     * @param  array   $user
     * @param  string  $apiToken
     * @param  string  $resumeToken
     *
     * @return array
     */
    public function buildSessionData(array $user, string $apiToken, string $resumeToken): array
    {
        return [
            'id'                => (int)$user['id'],
            'site_bid'          => $user['site_bid'],
            'user_level'        => (int)$user['user_level'],
            'api_token'         => $apiToken,
            'resume_token'      => $resumeToken,
            'ext_member_id'     => (int)$user['ext_member_id'],
            'ext_username'      => $user['ext_username'],
            'ext_platform_type' => (int)$user['ext_platform_type'],
            'code'              => $user['code'],
            'avatar_id'         => (int)$user['avatar_id'],
            'scene'             => Scene::buildScene(Scene::LoginScene),
            'init_time'         => time(),
        ];
    }

    /**
     * 组建Api Token资讯. (主要把状态都排除, 状态都拿socket session.)
     *
     * @param  array  $user
     *
     * @return array
     */
    protected function buildApiTokenInfo(array $user): array
    {
        if (empty($user)) {
            return [];
        }

        return [
            'id'                => (int)$user['id'],
            'site_bid'          => $user['site_bid'],
            'user_level'        => (int)$user['user_level'],
            'ext_member_id'     => (int)$user['ext_member_id'],
            'ext_username'      => $user['ext_username'],
            'ext_platform_type' => (int)$user['ext_platform_type'],
            'code'              => $user['code'],
            'avatar_id'         => (int)$user['avatar_id'],
        ];
    }

    /**
     * 设置头像.
     *
     * @param  mixed  $user
     * @param  array  $body
     *
     * @return \App\Lib\CommonResult
     */
    public function setAvatar(mixed $user, array $body): CommonResult
    {
        $vResult = Validator::validate($body, ['id' => 'required|integer']);
        if ( ! $vResult->success) {
            return CommonResult::invalidParam('参数错误');
        }
        $param = $vResult->validated;

        /** @var AvatarRepository $avatarRepo */
        $avatarRepo = Container::get(AvatarRepository::class);
        $avatar     = $avatarRepo->getById($param['id']);
        if (empty($avatar)) {
            return CommonResult::error('查无此头像');
        }

        $ok = $this->userRepository->updateAvatar($user['id'], $param['id']);
        if ( ! $ok) {
            return CommonResult::error('操作失败!');
        }

        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);

        $data = ['id' => $avatar['id'], 'avatar_url' => $configRepo->getByKey(ConfigKey::CdnUrl).$avatar['filename']];

        return CommonResult::success('ok', $data);
    }

    /**
     * @param  int  $avatarId
     *
     * @return string
     */
    protected function getAvatarUrl(int $avatarId): string
    {
        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);
        $cdnUrl     = $configRepo->getByKey(ConfigKey::CdnUrl);

        /** @var AvatarRepository $avatarRepo */
        $avatarRepo = Container::get(AvatarRepository::class);
        $avatarMap  = $avatarRepo->getMap();
        $path       = $avatarMap[$avatarId] ?? '';
        if (empty($path)) {
            $k    = array_rand($avatarMap);
            $path = $avatarMap[$k];
        }

        return $cdnUrl.$path;
    }

    /**
     * 取用户我的页面基本讯息
     *
     * @param  array  $session  session
     *
     * @return \App\Lib\CommonResult
     */
    public function getUserInfo(array $session): CommonResult
    {
        $info = ['code' => $session['code']];

        return CommonResult::success('ok', ['data' => $info]);
    }

    /**
     * @param  array  $cacheUserInfo
     *
     * @return array
     */
    protected function containsValidFields(array $cacheUserInfo): array
    {
        $lackKeys = [];
        $keys     = ['token', 'ext_member_id', 'ext_username', 'ext_platform_type', 'site_bid', 'user_level', 'device', 'tpl'];
        foreach ($keys as $key) {
            if ( ! isset($cacheUserInfo[$key])) {
                $lackKeys[] = $key;
            }
        }

        if ( ! empty($lackKeys)) {
            return [false, $lackKeys];
        }

        return [true, []];
    }

    /**
     * @param  array  $lm            紅包配置
     * @param  int    $grabLmUserId  搶紅包的用戶ID
     * @param  bool   $isSysLm       是否取系统资讯.
     *
     * @return array
     */
    public function getLmCreatorInfo(array $lm, int $grabLmUserId, bool $isSysLm): array
    {
        /** @var \App\Service\Impl\AvatarService $avatarService */
        $avatarService = Container::get(AvatarService::class);
        if ( ! $isSysLm) {
            $creator = $this->userRepository->getOne($lm['user_id']);
            if (empty($creator)) {
                return [];
            }
            $avatarUrl      = $avatarService->getAvatarUrl($creator['avatar_id']);
            $displayName    = Helper::maskUsername($creator['ext_username']);
            $detailViewable = 0;
        } else {
            $avatarUrl      = $avatarService->getSystemAvatarUrl();
            $displayName    = '系统';
            $detailViewable = $grabLmUserId === (int)$lm['user_id'] ? 1 : 0;
        }

        return [
            'creator_name'    => $displayName,
            'avatar_url'      => $avatarUrl,
            'detail_viewable' => $detailViewable,
        ];
    }

    /**
     * 随机取头像ID
     *
     * @return int
     */
    protected function getRandAvatarId(): int
    {
        /** @var AvatarRepository $avatarRepo */
        $avatarRepo = Container::get(AvatarRepository::class);
        $avatars    = $avatarRepo->getMap();

        return array_rand($avatars);
    }

}