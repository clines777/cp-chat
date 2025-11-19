<?php

namespace App\Service\Impl;

use App\Lib\ConfigKey;
use App\Lib\Facade\Container;
use App\Repository\Impl\AvatarRepository;
use App\Repository\Impl\ConfigRepository;

class AvatarService
{

    /**
     * 取頭像url
     *
     * @param  string  $avatarId
     *
     * @return string
     */
    public function getAvatarUrl(string $avatarId): string
    {
        /** @var AvatarRepository $avatarRepo */
        $avatarRepo = Container::get(AvatarRepository::class);
        $map        = $avatarRepo->getMap();
        $path       = $map[$avatarId] ?? '';
        if (empty($path)) {
            return '';
        }

        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);
        $cdnUrl     = $configRepo->getByKey(ConfigKey::CdnUrl);

        return $cdnUrl.$path;
    }

    /**
     * 取系统头像.
     *
     * @return string
     */
    public function getSystemAvatarUrl(): string
    {
        /** @var AvatarRepository $avatarRepo */
        $avatarRepo = Container::get(AvatarRepository::class);
        $path       = $avatarRepo->getSysAvatarPath();

        /** @var ConfigRepository $configRepo */
        $configRepo = Container::get(ConfigRepository::class);
        $cdnUrl     = $configRepo->getByKey(ConfigKey::CdnUrl);

        return $cdnUrl.$path;
    }

}