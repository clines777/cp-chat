<?php

namespace App\Lib;

//用户场景定义
class Scene
{

    /**
     * 登入后初始场景.
     */
    public const LoginScene = self::MyGroup;

    /**
     * 在聊天群内.
     */
    public const InGroup = 'in_group';

    /**
     * 在大厅.
     */
    public const Lobby = 'lobby';

    /**
     * 在聊天列表页.
     */
    public const MyGroup = 'my_group';

    /**
     * 系统频道页.
     */
    public const SysGroup = 'sys_group';

    /**
     * 我的资讯.
     */
    public const SelfInfo = 'self_info';

    /**
     * @param  string  $scene
     * @param  int     $groupId
     *
     * @return array
     */
    public static function buildScene(string $scene, int $groupId = 0): array
    {
        return ['scene' => ['name' => $scene, 'group_id' => $groupId]];
    }

    /**
     * 取group id
     *
     * @param  array  $session
     *
     * @return int
     */
    public static function fetchGroupId(array $session): int
    {
        if (empty($session['scene']) || $session['scene']['name'] !== Scene::InGroup || empty($session['scene']['group_id'])) {
            return 0;
        }

        return (int)$session['scene']['group_id'];
    }

    /**
     * 从session取出场景设定
     *
     * @param  array  $session
     *
     * @return array
     */
    public static function getSceneFromSession(array $session): array
    {
        if ( ! empty($session['scene'])) {
            return $session['scene'];
        }

        return [];
    }

}