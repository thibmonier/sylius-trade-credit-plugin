<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Entity;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Resource\Model\ResourceInterface;
use Tmonier\SyliusTradeCreditPlugin\Terms\EndOfMonthMode;

/**
 * One reservation of trade credit for an order paid on account: amount frozen at reservation,
 * terms and due date. Written by `TradeCreditReserver` only (SQL), read-only here. At most one
 * `reserved` row per order; a reservation is closed exactly once (settled or released).
 */
interface OrderPaymentTermsInterface extends ResourceInterface
{
    public const STATE_RESERVED = 'reserved';

    /** Paid: the amount no longer counts against the limit. */
    public const STATE_SETTLED = 'settled';

    /** Cancelled, failed or switched to another payment method. */
    public const STATE_RELEASED = 'released';

    public function getId(): ?string;

    public function getOrder(): ?OrderInterface;

    public function getAccount(): ?TradeCreditAccountInterface;

    public function getPaymentMethod(): ?PaymentMethodInterface;

    public function getAmount(): int;

    public function getCurrencyCode(): string;

    public function getNetDays(): int;

    public function getEndOfMonthMode(): EndOfMonthMode;

    public function getDueDate(): \DateTimeImmutable;

    public function getState(): string;

    public function isReserved(): bool;

    public function isOverdue(\DateTimeInterface $today): bool;

    public function getCreatedAt(): \DateTimeImmutable;

    public function getClosedAt(): ?\DateTimeImmutable;

    public function getClosingReason(): ?string;
}
