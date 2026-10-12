<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\EventListener;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Tmonier\SyliusTradeCreditPlugin\Account\PaymentTermsPolicyProvider;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Exception\TradeCreditReservationException;
use Tmonier\SyliusTradeCreditPlugin\Reservation\TradeCreditReserver;

/**
 * Doctrine `onFlush`: payment method changes of placed orders, whatever the code path (shop
 * order page, API, admin):
 * - to payment on account: reserve (a refusal aborts the flush, so the change is not saved),
 *   replacing the reservation of the previous on-account method with the same start date;
 * - from payment on account (the reservation made for the previous method): release;
 * - new payment on account created after a failed or cancelled one: reserve (a refusal is only
 *   logged: the payment then needs another method).
 */
final class PaymentMethodSwitchListener
{
    public function __construct(
        private readonly TradeCreditReserver $reserver,
        private readonly PaymentTermsPolicyProvider $policyProvider,
        private readonly LoggerInterface $logger,
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
            $order = $entity->getOrder();
            if (!$order instanceof OrderInterface) {
                continue;
            }

            if ($new instanceof PaymentMethodInterface && $this->policyProvider->isOnAccount($new)) {
                // Replaces the reservation of another on-account method, keeps the start date.
                $this->reserver->reserve($order, $new, false);
            } elseif ($old instanceof PaymentMethodInterface) {
                $this->reserver->close($order, OrderPaymentTermsInterface::STATE_RELEASED, TradeCreditReserver::REASON_PAYMENT_METHOD_CHANGED, false, $old);
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
                try {
                    $this->reserver->reserve($order, $method, false);
                } catch (TradeCreditReservationException $exception) {
                    // Created by the order processor (e.g. after a failed payment): the method is
                    // now refused by the eligibility checker, so the pay routes send the customer
                    // to the order page to choose another one. Not worth failing the flush.
                    $this->logger->warning(sprintf('No trade credit reserved for the new payment of order #%s: %s', (string) $order->getNumber(), $exception->getTranslationKey()));
                }
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
