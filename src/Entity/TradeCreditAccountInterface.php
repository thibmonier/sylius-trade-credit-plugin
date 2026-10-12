<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Entity;

use Sylius\Resource\Model\ResourceInterface;
use Sylius\Resource\Model\TimestampableInterface;
use Tmonier\SyliusTradeCreditPlugin\Terms\EndOfMonthMode;
use Tmonier\SyliusTradeCreditPlugin\Terms\PaymentTerms;

/**
 * Trade credit line of an account owner (order-hold `AccountOwner`: the customer by default).
 * Amounts in minor units of the account currency. `committedAmount` is only changed by atomic
 * SQL updates (reservations and releases), never through the entity.
 */
interface TradeCreditAccountInterface extends ResourceInterface, TimestampableInterface
{
    public function getId(): ?int;

    public function getOwnerType(): ?string;

    public function getOwnerIdentifier(): ?string;

    public function setOwner(string $type, string $identifier): void;

    public function getCurrencyCode(): ?string;

    public function setCurrencyCode(?string $currencyCode): void;

    public function getCreditLimit(): int;

    public function setCreditLimit(int $creditLimit): void;

    public function getCommittedAmount(): int;

    public function getAvailableAmount(): int;

    public function isAllowExceed(): bool;

    public function setAllowExceed(bool $allowExceed): void;

    public function isCreditHold(): bool;

    public function setCreditHold(bool $creditHold): void;

    public function isBlockWhenOverdue(): bool;

    public function setBlockWhenOverdue(bool $blockWhenOverdue): void;

    /** Overrides the net days of the payment method policy; null: the policy's. */
    public function getNetDays(): ?int;

    public function setNetDays(?int $netDays): void;

    public function getEndOfMonthMode(): ?EndOfMonthMode;

    public function setEndOfMonthMode(?EndOfMonthMode $endOfMonthMode): void;

    /** Account terms when set, the given (policy) terms otherwise. */
    public function getTerms(PaymentTerms $default): PaymentTerms;

    public function isEnabled(): bool;

    public function setEnabled(bool $enabled): void;

    public function getNote(): ?string;

    public function setNote(?string $note): void;
}
