<?php

namespace App\Service\Impl;

use App\Lib\CommonResult;
use App\Lib\Facade\Validator;
use App\Lib\Helper;
use App\Model\Marquee;
use App\Repository\Impl\MarqueeRepository;

class MarqueeService
{

    protected MarqueeRepository $marqueeRepo;

    public function __construct(MarqueeRepository $marqueeRepo)
    {
        $this->marqueeRepo = $marqueeRepo;
    }

    /**
     * 跑马灯接收与处理.
     *
     * @param  array  $data
     *
     * @return CommonResult
     */
    public function processMarquee(array $data): CommonResult
    {
        $vResult = Validator::validate(
            $data,
            [
                'type'       => 'integer|in:0,1',
                'site_bid'   => 'required|string',
                'content'    => 'required|string|max:50',
                'start_time' => 'required|integer',
                'end_time'   => 'required|integer',
            ],
        );

        if ( ! $vResult->success) {
            return CommonResult::invalidParam("参数错误!".$vResult->msg);
        }

        $data['start_time'] = date('Y-m-d', $data['start_time']) !== '1970-01-01' ? (int)$data['start_time'] : 0;
        $data['end_time']   = date('Y-m-d', $data['end_time']) !== '1970-01-01' ? (int)$data['end_time'] : 0;
        if ($data['end_time'] <= $data['start_time']) {
            return CommonResult::invalidParam("参数时间设定错误!".json_encode($data, 256));
        }

        $now = time();
        if ($data['end_time'] < $now) {
            return CommonResult::invalidParam("参数时间设定错误! 結束時間已超過");
        }

        if (empty($vResult->validated['type'])) {
            $vResult->validated['type'] = Marquee::TypeDefault;
        }
        $vResult->validated['content']     = Helper::secureStr($vResult->validated['content']);
        $vResult->validated['create_time'] = $now;
        $vResult->validated['update_time'] = $now;
        $vResult->validated['site_bid']    = strtoupper($vResult->validated['site_bid']);
        $id                                = $this->marqueeRepo->saveAndGetId($vResult->validated);
        if (empty($id)) {
            return CommonResult::invalidParam("添加失败");
        }
        $marquee = $this->marqueeRepo->getOne($id);

        /**
         * 广播内容.
         */
        $castMarquee = [
            'id'         => $marquee['id'],
            'type'       => $marquee['type'],
            'content'    => $marquee['content'],
            'start_time' => $marquee['start_time'],
            'end_time'   => $marquee['end_time'],
            'site_bid'   => $marquee['site_bid'],
        ];
        //避免被意外清掉, 放redis
        $this->marqueeRepo->rSetMarquee($castMarquee, $marquee['end_time'] - $now);

        return CommonResult::success();
    }

}