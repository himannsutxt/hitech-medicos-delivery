<?php
declare(strict_types=1);
namespace HMD;

/** Safe sort/order/paging from request params (sort columns whitelisted). */
final class ListQuery
{
    public function __construct(public string $sort, public string $order, public int $page, public int $limit) {}

    public static function from(array $p, array $sortable, string $default, string $defaultOrder = 'DESC'): self
    {
        $sort = in_array($p['sort'] ?? '', $sortable, true) ? $p['sort'] : $default;
        $o = strtoupper((string)($p['order'] ?? ''));
        $order = in_array($o, ['ASC', 'DESC'], true) ? $o : $defaultOrder;
        $page = max(1, (int)($p['page'] ?? 1));
        $limit = in_array((int)($p['limit'] ?? 0), [25, 50, 100], true) ? (int)$p['limit'] : 25;
        return new self($sort, $order, $page, $limit);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    public function sql(): string
    {
        return ' ORDER BY ' . $this->sort . ' ' . $this->order . ' LIMIT ' . $this->limit . ' OFFSET ' . $this->offset();
    }
}
