<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Statement;

use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;

final class Statement
{
    /** @param list<StatementItem> $items */
    public function __construct(
        public readonly TradeCreditAccountInterface $account,
        public readonly array $items,
    ) {
    }

    public function getOverdueAmount(): int
    {
        return array_sum(array_map(static fn (StatementItem $item): int => $item->overdue ? $item->amount : 0, $this->items));
    }
}
