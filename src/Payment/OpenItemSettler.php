<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Payment;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/**
 * Settles an open item paid by bank transfer (admin "Mark as paid", payments CSV import): the
 * core `sylius_payment` `complete` transition of the pending payment. It settles the
 * reservation and, with the Invoicing Plugin, dispatches OrderPaymentPaid like any payment.
 * In one transaction, the payment row locked and read again first: concurrent settlements (Mark
 * as paid, payments import) complete it once; the transition is flushed here.
 */
final class OpenItemSettler
{
    public function __construct(
        private readonly OrderPaymentTermsRepositoryInterface $termsRepository,
        private readonly StateMachineInterface $stateMachine,
        private readonly ClockInterface $clock,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** The payment to complete, or null when the item cannot be settled. */
    public function payableBy(OrderPaymentTermsInterface $terms): ?PaymentInterface
    {
        $order = $terms->getOrder();
        if (!$order instanceof OrderInterface) {
            return null;
        }
        // An open item, or an order that left payment on account and is overdue.
        if (!$terms->isReserved() && !$this->termsRepository->isLeftUnpaidOverdue($terms, $this->clock->now())) {
            return null;
        }

        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW) ?? $order->getLastPayment(PaymentInterface::STATE_PROCESSING);

        return null !== $payment && $this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE) ? $payment : null;
    }

    public function settle(OrderPaymentTermsInterface $terms): bool
    {
        if (null === $this->payableBy($terms)) {
            return false;
        }

        return $this->entityManager->wrapInTransaction(function () use ($terms): bool {
            // The entities may be stale (another request settled the item meanwhile).
            $payment = $this->payableBy($terms);
            if (null === $payment) {
                return false;
            }
            $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($terms);
            $payment = $this->payableBy($terms);
            if (null === $payment) {
                return false;
            }

            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
            $this->entityManager->flush();

            return true;
        });
    }
}
