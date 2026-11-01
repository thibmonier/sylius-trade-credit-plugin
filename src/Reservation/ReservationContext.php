<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Reservation;

use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;
use Tmonier\SyliusTradeCreditPlugin\Terms\PaymentTerms;

final readonly class ReservationContext
{
    public function __construct(
        public TradeCreditAccountInterface $account,
        public int $amount,
        public bool $placed,
        public PaymentTerms $accountTerms,
        public string $limitSql,
        public int $externalCommitted,
    ) {
    }
}
