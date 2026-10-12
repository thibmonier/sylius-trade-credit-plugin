<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Entity;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Model\ResourceInterface;

/** Append-only movement of the committed amount of an account (written in SQL, never updated). */
interface TradeCreditLedgerEntryInterface extends ResourceInterface
{
    public const TYPE_RESERVATION = 'reservation';

    public const TYPE_SETTLEMENT = 'settlement';

    public const TYPE_RELEASE = 'release';

    /** Correction by `tmonier:trade-credit:recalculate --apply`. */
    public const TYPE_ADJUSTMENT = 'adjustment';

    public function getId(): ?string;

    public function getAccount(): ?TradeCreditAccountInterface;

    public function getOrder(): ?OrderInterface;

    public function getOrderPaymentTerms(): ?OrderPaymentTermsInterface;

    public function getType(): string;

    /** Signed change of the committed amount (positive for a reservation). */
    public function getAmount(): int;

    public function getCommittedAfter(): int;

    public function getReason(): ?string;

    public function getCreatedAt(): \DateTimeImmutable;
}
