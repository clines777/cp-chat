<?php

namespace App\Lib;

/**
 * 简单分页元件.
 */
class Paginator
{

    /**
     * 总笔数.
     *
     * @var int
     */
    protected int $total;

    /**
     * 当前页次.
     *
     * @var int
     */
    protected int $page;

    /**
     * 每页笔数.
     *
     * @var int|mixed
     */
    protected int $pageSize;

    public function __construct(int $total, int $page = SysConst::DefaultPage, int $pageSize = SysConst::DefaultPageSize)
    {
        $this->total    = max(0, $total);
        $this->page     = max(1, $page);
        $this->pageSize = max(1, $pageSize);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }

    public function limit(): int
    {
        return $this->pageSize;
    }

    public function totalPages(): int
    {
        return (int)ceil($this->total / $this->pageSize);
    }

    public function get(): array
    {
        return [
            'total'       => $this->total,
            'page'        => $this->page,
            'page_size'   => $this->pageSize,
            'total_pages' => $this->totalPages(),
        ];
    }

}