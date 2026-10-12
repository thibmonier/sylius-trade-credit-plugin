<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Statement;

use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;

/**
 * An open item of a statement: an order paid on account, not paid yet. With a shared (company)
 * account, orders of other buyers are listed without link: only their own customer can open them.
 */
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
        public readonly bool $placedByViewer = true,
        public readonly ?string $buyerName = null,
    ) {
    }
}
