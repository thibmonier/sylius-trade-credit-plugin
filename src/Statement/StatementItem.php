<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Statement;

use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;

/**
 * An open item of a statement: an order paid on account, not paid yet. With a shared (company)
 * account, orders of other buyers are listed without link: only their own customer can open them.
 */
final readonly class StatementItem
{
    public function __construct(
        public OrderPaymentTermsInterface $terms,
        public string $orderNumber,
        public ?string $orderTokenValue,
        public int $amount,
        public \DateTimeImmutable $orderDate,
        public \DateTimeImmutable $dueDate,
        public bool $overdue,
        public ?string $invoiceUrl = null,
        public bool $placedByViewer = true,
        public ?string $buyerName = null,
    ) {
    }
}
