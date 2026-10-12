<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Import;

/** A line of a payments file: amount in minor units. */
final class PaymentLine
{
    public function __construct(
        public readonly int $lineNumber,
        public readonly string $orderNumber,
        public readonly int $amount,
        public readonly ?string $currencyCode = null,
    ) {
    }
}
