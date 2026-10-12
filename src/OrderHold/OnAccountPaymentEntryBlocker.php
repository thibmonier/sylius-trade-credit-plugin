<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\OrderHold;

use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlock;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlockerInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/**
 * Orders paid on account are paid by bank transfer before the due date: no pay page, no
 * payment request (customers cannot complete or refund their own payment).
 */
final class OnAccountPaymentEntryBlocker implements PaymentEntryBlockerInterface
{
    public function __construct(private readonly OrderPaymentTermsRepositoryInterface $termsRepository)
    {
    }

    public function getBlock(OrderInterface $order): ?PaymentEntryBlock
    {
        if (null === $order->getId()) {
            return null;
        }

        $terms = $this->termsRepository->findReservedForOrder($order);

        return null === $terms ? null : new PaymentEntryBlock('tmonier_trade_credit.payment_entry.on_account', [
            '%due_date%' => $terms->getDueDate()->format('Y-m-d'),
        ]);
    }
}
