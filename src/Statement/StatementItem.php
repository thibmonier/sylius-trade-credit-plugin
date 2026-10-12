<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Statement;

use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;

/** An open item of a statement: an order paid on account, not paid yet. */
final class StatementItem
{
    public function __construct(
        public readonly OrderPaymentTermsInterface $terms,
        public readonly string $orderNumber,
        public readonly ?string $orderTokenValue,
        public readonly int $amount,
        public readonly \DateTimeImmutable $orderDate,
        public readonly \DateTimeImmutable $dueDate,
        public readonly bool $overdue,
        public readonly ?string $invoiceUrl = null,
    ) {
    }
}
