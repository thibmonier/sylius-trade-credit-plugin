<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Workflow\Event\Event;
use Tmonier\SyliusTradeCreditPlugin\Account\PaymentTermsPolicyProvider;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Reservation\TradeCreditReserver;

/**
 * Symfony Workflow listeners (never guards: guards run on every `can()`):
 * - `sylius_order_checkout` transition `complete` (before the marking changes): reserve the
 *   order total when the payment is on account; a refusal aborts the completion;
 * - `sylius_payment` completed `complete`: settle (paid);
 * - `sylius_payment` completed `fail` / `cancel` and `sylius_order` completed `cancel`: release.
 * Settling and releasing are idempotent (see `TradeCreditReserver`).
 */
final class TradeCreditWorkflowListener
{
    public function __construct(
        private readonly TradeCreditReserver $reserver,
        private readonly PaymentTermsPolicyProvider $policyProvider,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function onCheckoutComplete(Event $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof OrderInterface) {
            return;
        }

        $payment = $order->getLastPayment(PaymentInterface::STATE_CART) ?? $order->getLastPayment(PaymentInterface::STATE_NEW);
        $paymentMethod = $payment?->getMethod();
        if (null !== $paymentMethod && $this->policyProvider->isOnAccount($paymentMethod)) {
            // The reservation references the order: an order completed before it was ever saved
            // (built in code) is saved first.
            if (null === $order->getId()) {
                $this->entityManager->persist($order);
                $this->entityManager->flush();
            }
            $this->reserver->reserve($order, $paymentMethod);
        }
    }

    public function onPaymentCompleted(Event $event): void
    {
        $this->closeForPayment($event, OrderPaymentTermsInterface::STATE_SETTLED, TradeCreditReserver::REASON_PAID);
    }

    public function onPaymentFailed(Event $event): void
    {
        $this->closeForPayment($event, OrderPaymentTermsInterface::STATE_RELEASED, TradeCreditReserver::REASON_PAYMENT_FAILED);
    }

    public function onPaymentCancelled(Event $event): void
    {
        $this->closeForPayment($event, OrderPaymentTermsInterface::STATE_RELEASED, TradeCreditReserver::REASON_PAYMENT_CANCELLED);
    }

    public function onOrderCancelled(Event $event): void
    {
        $order = $event->getSubject();
        if ($order instanceof OrderInterface) {
            $this->reserver->close($order, OrderPaymentTermsInterface::STATE_RELEASED, TradeCreditReserver::REASON_ORDER_CANCELLED);
        }
    }

    private function closeForPayment(Event $event, string $state, string $reason): void
    {
        $payment = $event->getSubject();
        if (!$payment instanceof PaymentInterface || !$this->policyProvider->isOnAccount($payment->getMethod())) {
            return;
        }

        $order = $payment->getOrder();
        if ($order instanceof OrderInterface) {
            $this->reserver->close($order, $state, $reason);
        }
    }
}
