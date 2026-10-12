<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Entity;

use Sylius\Component\Core\Model\OrderInterface;

class TradeCreditLedgerEntry implements TradeCreditLedgerEntryInterface
{
    protected ?string $id = null;

    protected ?TradeCreditAccountInterface $account = null;

    protected ?OrderInterface $order = null;

    protected ?OrderPaymentTermsInterface $orderPaymentTerms = null;

    protected string $type = self::TYPE_RESERVATION;

    /** BIGINT: strings with DBAL 3. */
    protected int|string $amount = 0;

    protected int|string $committedAfter = 0;

    protected ?string $reason = null;

    protected \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getAccount(): ?TradeCreditAccountInterface
    {
        return $this->account;
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    public function getOrderPaymentTerms(): ?OrderPaymentTermsInterface
    {
        return $this->orderPaymentTerms;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getAmount(): int
    {
        return (int) $this->amount;
    }

    public function getCommittedAfter(): int
    {
        return (int) $this->committedAfter;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
