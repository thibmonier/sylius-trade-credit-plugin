<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Entity;

use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Resource\Model\TimestampableTrait;
use Tmonier\SyliusTradeCreditPlugin\Terms\EndOfMonthMode;
use Tmonier\SyliusTradeCreditPlugin\Terms\PaymentTerms;

class PaymentTermsPolicy implements PaymentTermsPolicyInterface
{
    use TimestampableTrait;

    protected ?int $id = null;

    protected ?PaymentMethodInterface $paymentMethod = null;

    protected int $netDays = 30;

    protected string $endOfMonthMode = 'none';

    protected bool $enabled = true;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPaymentMethod(): ?PaymentMethodInterface
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(?PaymentMethodInterface $paymentMethod): void
    {
        $this->paymentMethod = $paymentMethod;
    }

    public function getNetDays(): int
    {
        return $this->netDays;
    }

    public function setNetDays(int $netDays): void
    {
        $this->netDays = max(0, $netDays);
    }

    public function getEndOfMonthMode(): EndOfMonthMode
    {
        return EndOfMonthMode::tryFrom($this->endOfMonthMode) ?? EndOfMonthMode::None;
    }

    public function setEndOfMonthMode(EndOfMonthMode $endOfMonthMode): void
    {
        $this->endOfMonthMode = $endOfMonthMode->value;
    }

    public function getTerms(): PaymentTerms
    {
        return new PaymentTerms($this->netDays, $this->getEndOfMonthMode());
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
}
