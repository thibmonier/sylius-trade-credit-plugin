<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Entity;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Tmonier\SyliusTradeCreditPlugin\Terms\EndOfMonthMode;

class OrderPaymentTerms implements OrderPaymentTermsInterface
{
    protected ?string $id = null;

    protected ?OrderInterface $order = null;

    protected ?TradeCreditAccountInterface $account = null;

    protected ?PaymentMethodInterface $paymentMethod = null;

    protected int $amount = 0;

    protected string $currencyCode = '';

    protected int $netDays = 0;

    protected string $endOfMonthMode = 'none';

    protected \DateTimeImmutable $dueDate;

    protected string $state = self::STATE_RESERVED;

    protected \DateTimeImmutable $createdAt;

    protected ?\DateTimeImmutable $closedAt = null;

    protected ?string $closingReason = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->dueDate = $this->createdAt;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    public function getAccount(): ?TradeCreditAccountInterface
    {
        return $this->account;
    }

    public function getPaymentMethod(): ?PaymentMethodInterface
    {
        return $this->paymentMethod;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    public function getNetDays(): int
    {
        return $this->netDays;
    }

    public function getEndOfMonthMode(): EndOfMonthMode
    {
        return EndOfMonthMode::tryFrom($this->endOfMonthMode) ?? EndOfMonthMode::None;
    }

    public function getDueDate(): \DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function isReserved(): bool
    {
        return self::STATE_RESERVED === $this->state;
    }

    public function isOverdue(\DateTimeInterface $today): bool
    {
        return $this->isReserved() && $this->dueDate->format('Y-m-d') < $today->format('Y-m-d');
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getClosingReason(): ?string
    {
        return $this->closingReason;
    }
}
