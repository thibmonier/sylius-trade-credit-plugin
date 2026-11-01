<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Reservation;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Tmonier\SyliusTradeCreditPlugin\Account\OrderOwnerChecker;
use Tmonier\SyliusTradeCreditPlugin\Account\PaymentTermsPolicyProvider;
use Tmonier\SyliusTradeCreditPlugin\Account\TradeCreditAccountProvider;
use Tmonier\SyliusTradeCreditPlugin\Credit\CreditPositionResolver;
use Tmonier\SyliusTradeCreditPlugin\Credit\ExternalCreditUnavailableException;
use Tmonier\SyliusTradeCreditPlugin\Entity\PaymentTermsPolicyInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;
use Tmonier\SyliusTradeCreditPlugin\Exception\TradeCreditReservationException;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

final readonly class ReservationValidator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TradeCreditAccountProvider $accountProvider,
        private PaymentTermsPolicyProvider $policyProvider,
        private OrderPaymentTermsRepositoryInterface $termsRepository,
        private OrderOwnerChecker $orderOwnerChecker,
        private CreditPositionResolver $positionResolver,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Validates that the order can be reserved on trade credit; null when the payment method
     * has no policy (not on account).
     *
     * @throws TradeCreditReservationException
     */
    public function validate(OrderInterface $order, PaymentMethodInterface $paymentMethod): ?ReservationContext
    {
        $policy = $this->policyProvider->forPaymentMethod($paymentMethod);
        if (!$policy instanceof PaymentTermsPolicyInterface) {
            return null;
        }
        if (null === $order->getId() || $this->entityManager->getUnitOfWork()->isScheduledForInsert($order)) {
            throw new \LogicException('An order must be saved before its trade credit is reserved.');
        }
        if (!$this->orderOwnerChecker->isPlacedByItsCustomer($order)) {
            throw new TradeCreditReservationException('tmonier_trade_credit.reservation.no_account', $order->getTokenValue());
        }

        $account = $this->accountProvider->forOrder($order);
        if (!$account instanceof TradeCreditAccountInterface || null === $account->getId() || !$account->isEnabled()) {
            throw new TradeCreditReservationException('tmonier_trade_credit.reservation.no_account', $order->getTokenValue());
        }
        if ($account->getCurrencyCode() !== $order->getCurrencyCode()) {
            throw new TradeCreditReservationException('tmonier_trade_credit.reservation.currency', $order->getTokenValue());
        }
        $now = $this->clock->now();
        if ($account->isBlockWhenOverdue() && $this->termsRepository->hasOverdue($account, $now)) {
            throw new TradeCreditReservationException('tmonier_trade_credit.reservation.overdue', $order->getTokenValue());
        }

        $limitSql = 'credit_limit';
        $externalCommitted = 0;
        if (!$account->isAllowExceed()) {
            try {
                $position = $this->positionResolver->resolve($account);
            } catch (ExternalCreditUnavailableException) {
                throw new TradeCreditReservationException(TradeCreditReserver::UNAVAILABLE, $order->getTokenValue());
            }
            if ($position->externalLimit) {
                $limitSql = (string) $position->creditLimit;
            }
            $externalCommitted = $position->externalCommittedAmount;
        }

        return new ReservationContext(
            account: $account,
            amount: $order->getTotal(),
            placed: OrderCheckoutStates::STATE_COMPLETED === $order->getCheckoutState(),
            accountTerms: $account->getTerms($policy->getTerms()),
            limitSql: $limitSql,
            externalCommitted: $externalCommitted,
        );
    }
}
