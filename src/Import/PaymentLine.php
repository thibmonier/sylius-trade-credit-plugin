<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Import;

/** A line of a payments file: amount in minor units. */
final readonly class PaymentLine
{
    public function __construct(
        public int $lineNumber,
        public string $orderNumber,
        public int $amount,
        public ?string $currencyCode = null,
    ) {
    }
}
