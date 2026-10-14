<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Statement;

use Tmonier\SyliusTradeCreditPlugin\Ageing\AgeingBuckets;
use Tmonier\SyliusTradeCreditPlugin\Credit\CreditPosition;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;

final readonly class Statement
{
    public AgeingBuckets $ageing;

    /** @param list<StatementItem> $items */
    public function __construct(
        public TradeCreditAccountInterface $account,
        public array $items,
        public CreditPosition $position,
        \DateTimeInterface $today,
    ) {
        $this->ageing = AgeingBuckets::fromItems(array_map(static fn (StatementItem $item): array => ['amount' => $item->amount, 'dueDate' => $item->dueDate], $items), $today);
    }

    public function getOverdueAmount(): int
    {
        return array_sum(array_map(static fn (StatementItem $item): int => $item->overdue ? $item->amount : 0, $this->items));
    }
}
