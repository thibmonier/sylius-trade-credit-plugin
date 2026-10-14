<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Api;

/** Shop API: trade credit statement of the signed-in customer (amounts in minor units). */
final readonly class TradeCreditStatementView
{
    /**
     * `availableAmount` is never negative (an account allowed to exceed its limit shows 0);
     * `enabled` false or `creditHold` true: payment on account is not available. With an
     * external credit provider (ERP), `creditLimit` is its limit and `externalCommittedAmount`
     * what is owed outside the shop. `ageing`: open items by days past due.
     *
     * @param list<array{orderNumber: string, amount: int, orderDate: string, dueDate: string, overdue: bool, daysPastDue: int, placedByYou: bool}> $openItems
     * @param array{current: int, days_1_30: int, days_31_60: int, days_61_90: int, days_over_90: int} $ageing
     */
    public function __construct(
        public string $id,
        public string $currencyCode,
        public int $creditLimit,
        public int $committedAmount,
        public int $availableAmount,
        public int $overdueAmount,
        public bool $creditHold,
        public bool $enabled,
        public array $openItems,
        public int $externalCommittedAmount = 0,
        public array $ageing = ['current' => 0, 'days_1_30' => 0, 'days_31_60' => 0, 'days_61_90' => 0, 'days_over_90' => 0],
    ) {
    }
}
