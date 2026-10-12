<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Entity;

use Sylius\Resource\Model\TimestampableTrait;
use Tmonier\SyliusTradeCreditPlugin\Terms\EndOfMonthMode;
use Tmonier\SyliusTradeCreditPlugin\Terms\PaymentTerms;

class TradeCreditAccount implements TradeCreditAccountInterface
{
    use TimestampableTrait;

    protected ?int $id = null;

    protected ?string $ownerType = null;

    protected ?string $ownerIdentifier = null;

    protected ?string $currencyCode = null;

    /** BIGINT: a string with DBAL 3. */
    protected int|string $creditLimit = 0;

    protected int|string $committedAmount = 0;

    protected bool $allowExceed = false;

    protected bool $creditHold = false;

    protected bool $blockWhenOverdue = true;

    protected ?int $netDays = null;

    protected ?string $endOfMonthMode = null;

    protected bool $enabled = true;

    protected ?string $note = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwnerType(): ?string
    {
        return $this->ownerType;
    }

    public function getOwnerIdentifier(): ?string
    {
        return $this->ownerIdentifier;
    }

    public function setOwner(string $type, string $identifier): void
    {
        $this->ownerType = $type;
        $this->ownerIdentifier = $identifier;
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(?string $currencyCode): void
    {
        $this->currencyCode = $currencyCode;
    }

    public function getCreditLimit(): int
    {
        return (int) $this->creditLimit;
    }

    public function setCreditLimit(int $creditLimit): void
    {
        $this->creditLimit = max(0, $creditLimit);
    }

    public function getCommittedAmount(): int
    {
        return (int) $this->committedAmount;
    }

    public function getAvailableAmount(): int
    {
        return $this->getCreditLimit() - $this->getCommittedAmount();
    }

    public function isAllowExceed(): bool
    {
        return $this->allowExceed;
    }

    public function setAllowExceed(bool $allowExceed): void
    {
        $this->allowExceed = $allowExceed;
    }

    public function isCreditHold(): bool
    {
        return $this->creditHold;
    }

    public function setCreditHold(bool $creditHold): void
    {
        $this->creditHold = $creditHold;
    }

    public function isBlockWhenOverdue(): bool
    {
        return $this->blockWhenOverdue;
    }

    public function setBlockWhenOverdue(bool $blockWhenOverdue): void
    {
        $this->blockWhenOverdue = $blockWhenOverdue;
    }

    public function getNetDays(): ?int
    {
        return $this->netDays;
    }

    public function setNetDays(?int $netDays): void
    {
        $this->netDays = null === $netDays ? null : max(0, $netDays);
    }

    public function getEndOfMonthMode(): ?EndOfMonthMode
    {
        return null === $this->endOfMonthMode ? null : EndOfMonthMode::tryFrom($this->endOfMonthMode);
    }

    public function setEndOfMonthMode(?EndOfMonthMode $endOfMonthMode): void
    {
        $this->endOfMonthMode = $endOfMonthMode?->value;
    }

    public function getTerms(PaymentTerms $default): PaymentTerms
    {
        if (null === $this->netDays) {
            return $default;
        }

        // No mode of its own ("terms of the payment method"): the end-of-month mode of the policy.
        return new PaymentTerms($this->netDays, $this->getEndOfMonthMode() ?? $default->endOfMonthMode);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $note = null === $note ? null : trim($note);
        $this->note = '' === $note ? null : $note;
    }
}
