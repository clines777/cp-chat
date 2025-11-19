<?php

namespace App\Lib;

use JsonSerializable;
use stdClass;

/**
 * WebSocket Message Body.
 */
class MsgPayload implements JsonSerializable
{

    /**
     * 事件类型.
     *
     * @var string
     */
    public string $type = '';

    /**
     * 数据.
     *
     * @var array
     */
    public array $data = [];

    /**
     * client传递进来的payload中的meta栏位, 用于让前端辨识来源.
     *
     * @var \stdClass
     */
    protected stdClass $meta;

    /**
     * 设值并返回自身实体.
     *
     * @param  string         $type
     * @param  array          $data
     * @param  stdClass|null  $meta
     *
     * @return $this
     */
    public static function make(string $type, array $data = [], stdClass $meta = null): static
    {
        $m       = new static();
        $m->type = $type;
        $m->data = $data;
        if ($meta == null) {
            $m->meta = new stdClass();
        } else {
            $m->meta = $meta;
        }

        return $m;
    }

    /**
     * 构建错误回传格式.
     *
     * @param  int                       $code
     * @param  \App\Lib\MsgPayload|null  $originPayload
     * @param  string                    $msg
     *
     * @return \App\Lib\MsgPayload
     */
    public static function error(int $code, ?MsgPayload $originPayload = null, string $msg = ''): static
    {
        $m       = new static();
        $m->type = MsgType::Error;
        $m->data = ['code' => $code, 'msg' => $msg];
        if ($originPayload !== null) {
            $m->data['origin'] = $originPayload->asArray();
        }

        return $m;
    }

    /**
     * 返回当前物件状态的阵列资料.
     *
     * @param  bool  $ignoreEmpty  空data或meta栏位不返回
     *
     * @return array
     */
    public function asArray(bool $ignoreEmpty = true): array
    {
        $arr         = [];
        $arr['type'] = $this->type;
        if ( ! $ignoreEmpty || ! empty($this->data)) {
            $arr['data'] = $this->data;
        }
        $arr['meta'] = $this->getMeta();

        return $arr;
    }

    /**
     * @param  string  $str
     *
     * @return \App\Lib\MsgPayload|null
     */
    public static function fromJson(string $str): ?static
    {
        if (empty($str)) {
            return null;
        }
        $arr = json_decode($str, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $type = $arr['type'] ?? '';
        $data = $arr['data'] ?? [];
        $meta = isset($arr['meta']) ? (object)$arr['meta'] : new stdClass();

        return self::make($type, $data, $meta);
    }

    /**
     * @return mixed
     */
    public function jsonSerialize(): string
    {
        return json_encode($this->asArray());
    }

    public function getMeta(): stdClass
    {
        if (empty($this->meta)) {
            return new stdClass();
        }

        return $this->meta;
    }

}