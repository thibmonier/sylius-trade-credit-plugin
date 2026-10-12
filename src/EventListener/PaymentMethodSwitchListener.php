<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\EventListener;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Tmonier\SyliusTradeCreditPlugin\Account\PaymentTermsPolicyProvider;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Reservation\TradeCreditReserver;

/**
 * Doctrine `onFlush`: payment method changes of placed orders, whatever the code path (shop
 * order page, API, admin):
 * - to payment on account: reserve (a refusal aborts the flush, so the change is not saved);
 * - from payment on account: release;
 * - new payment on account created after a failed or cancelled one: reserve.
 */
final class PaymentMethodSwitchListener
{
    public function __construct(
        private readonly TradeCreditReserver $reserver,
        private readonly PaymentTermsPolicyProvider $policyProvider,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof PaymentInterface || !$this->isPendingPaymentOfPlacedOrder($entity)) {
                continue;
            }
            $changeSet = $unitOfWork->getEntityChangeSet($entity);
            if (!isset($changeSet['method'])) {
                continue;
            }
            $old = $changeSet['method'][0] ?? null;
            $new = $changeSet['method'][1] ?? null;
            $wasOnAccount = $old instanceof PaymentMethodInterface && $this->policyProvider->isOnAccount($old);
            $isOnAccount = $new instanceof PaymentMethodInterface && $this->policyProvider->isOnAccount($new);
            $order = $entity->getOrder();
            if (!$order instanceof OrderInterface || $wasOnAccount === $isOnAccount) {
                continue;
            }

            if ($wasOnAccount) {
                $this->reserver->close($order, OrderPaymentTermsInterface::STATE_RELEASED, TradeCreditReserver::REASON_PAYMENT_METHOD_CHANGED, false);
            }
            if ($new instanceof PaymentMethodInterface && $isOnAccount) {
                $this->reserver->reserve($order, $new, false);
            }
        }

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $method = $entity instanceof PaymentInterface ? $entity->getMethod() : null;
            $order = $entity instanceof PaymentInterface ? $entity->getOrder() : null;
            if (
                $entity instanceof PaymentInterface &&
                $method instanceof PaymentMethodInterface &&
                $order instanceof OrderInterface &&
                null !== $order->getId() &&
                $this->isPendingPaymentOfPlacedOrder($entity) &&
                $this->policyProvider->isOnAccount($method)
            ) {
                $this->reserver->reserve($order, $method, false);
            }
        }
    }

    private function isPendingPaymentOfPlacedOrder(PaymentInterface $payment): bool
    {
        $order = $payment->getOrder();

        return PaymentInterface::STATE_NEW === $payment->getState() &&
            $order instanceof OrderInterface &&
            OrderCheckoutStates::STATE_COMPLETED === $order->getCheckoutState() &&
            OrderInterface::STATE_NEW === $order->getState();
    }
}
