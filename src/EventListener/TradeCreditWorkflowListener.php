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
 * - `sylius_payment` completed `complete`: settle (paid), whatever the method of the reservation
 *   (the order is paid; also heals a reservation whose method switch was not saved);
 * - `sylius_payment` completed `fail` / `cancel` (before the core reprocesses the order and
 *   creates the next payment) and `sylius_order` completed `cancel`: release.
 * Releasing is driven by the open reservation of the order (made for the payment method), never
 * by the current state of the payment terms policy.
 * Settling and releasing are idempotent (see `TradeCreditReserver`).
 */
final readonly class TradeCreditWorkflowListener
{
    public function __construct(
        private TradeCreditReserver $reserver,
        private PaymentTermsPolicyProvider $policyProvider,
        private EntityManagerInterface $entityManager,
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
        if ($paymentMethod instanceof \Sylius\Component\Payment\Model\PaymentMethodInterface && $this->policyProvider->isOnAccount($paymentMethod)) {
            // The reservation references the order: an order completed before it was ever saved
            // (built in code) is saved first. Its id alone does not tell: with sequences
            // (PostgreSQL) a persisted order has an id before its row is inserted.
            if (null === $order->getId() || $this->entityManager->getUnitOfWork()->isScheduledForInsert($order)) {
                $this->entityManager->persist($order);
                $this->entityManager->flush();
            }
            $this->reserver->reserve($order, $paymentMethod);
        }
    }

    public function onPaymentCompleted(Event $event): void
    {
        $this->closeForPayment($event, OrderPaymentTermsInterface::STATE_SETTLED, TradeCreditReserver::REASON_PAID, false);
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

    private function closeForPayment(Event $event, string $state, string $reason, bool $sameMethodOnly = true): void
    {
        $payment = $event->getSubject();
        $paymentMethod = $payment instanceof PaymentInterface ? $payment->getMethod() : null;
        if (!$payment instanceof PaymentInterface || !$paymentMethod instanceof \Sylius\Component\Payment\Model\PaymentMethodInterface) {
            return;
        }

        // Driven by the reservation made for this payment method, whatever the policy is now.
        $order = $payment->getOrder();
        if ($order instanceof OrderInterface) {
            $this->reserver->close($order, $state, $reason, true, $sameMethodOnly ? $paymentMethod : null);
        }
    }
}
