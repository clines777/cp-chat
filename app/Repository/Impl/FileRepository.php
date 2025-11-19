<?php

namespace App\Repository\Impl;

use Hyperf\DbConnection\Db;

class FileRepository
{

    public string $table = 'file';

    /**
     * 以md5查询
     *
     * @param  string  $siteBid  站点编号
     * @param  string  $md5      档案md5
     *
     * @return array
     */
    public function getByMd5(string $siteBid, string $md5): array
    {
        return (array)DB::table($this->table)->where('md5', trim($md5))->where('site_bid', $siteBid)->first();
    }

    /**
     * 写入.
     *
     * @param  array  $array
     *
     * @return bool
     */
    public function add(array $array): bool
    {
        return DB::table($this->table)->insert($array);
    }

}