<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Api;

/** Shop API: trade credit statement of the signed-in customer (amounts in minor units). */
final class TradeCreditStatementView
{
    /**
     * @param list<array{orderNumber: string, amount: int, orderDate: string, dueDate: string, overdue: bool}> $openItems
     */
    public function __construct(
        public readonly string $id,
        public readonly string $currencyCode,
        public readonly int $creditLimit,
        public readonly int $committedAmount,
        public readonly int $availableAmount,
        public readonly int $overdueAmount,
        public readonly bool $creditHold,
        public readonly array $openItems,
    ) {
    }
}
