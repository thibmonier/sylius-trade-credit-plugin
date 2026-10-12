<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Entity;

use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Resource\Model\ResourceInterface;
use Sylius\Resource\Model\TimestampableInterface;
use Tmonier\SyliusTradeCreditPlugin\Terms\EndOfMonthMode;
use Tmonier\SyliusTradeCreditPlugin\Terms\PaymentTerms;

/**
 * Turns a payment method (Offline gateway) into "payment on account": orders paid with it
 * reserve trade credit and stay `awaiting_payment` until marked as paid.
 */
interface PaymentTermsPolicyInterface extends ResourceInterface, TimestampableInterface
{
    public function getId(): ?int;

    public function getPaymentMethod(): ?PaymentMethodInterface;

    public function setPaymentMethod(?PaymentMethodInterface $paymentMethod): void;

    public function getNetDays(): int;

    public function setNetDays(int $netDays): void;

    public function getEndOfMonthMode(): EndOfMonthMode;

    public function setEndOfMonthMode(EndOfMonthMode $endOfMonthMode): void;

    public function getTerms(): PaymentTerms;

    public function isEnabled(): bool;

    public function setEnabled(bool $enabled): void;
}
