<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Credit;

/** Amounts in minor units of the account currency. */
final readonly class ExternalCreditPosition
{
    /**
     * @param int|null $creditLimit replaces the limit of the account when not null
     * @param int $externalCommittedAmount owed outside the shop (open invoices of the ERP), added to the committed amount
     */
    public function __construct(
        public ?int $creditLimit = null,
        public int $externalCommittedAmount = 0,
    ) {
        if ($externalCommittedAmount < 0) {
            throw new \InvalidArgumentException('The external committed amount cannot be negative.');
        }
    }
}
