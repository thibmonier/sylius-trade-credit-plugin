<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\OrderHold;

use Psr\Clock\ClockInterface;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface as CorePaymentInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\IneligibilityReason;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodIneligibility;
use Tmonier\SyliusTradeCreditPlugin\Account\BusinessCustomerChecker;
use Tmonier\SyliusTradeCreditPlugin\Account\OrderOwnerChecker;
use Tmonier\SyliusTradeCreditPlugin\Account\PaymentTermsPolicyProvider;
use Tmonier\SyliusTradeCreditPlugin\Account\TradeCreditAccountProvider;
use Tmonier\SyliusTradeCreditPlugin\Credit\CreditPositionResolver;
use Tmonier\SyliusTradeCreditPlugin\Credit\ExternalCreditUnavailableException;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/**
 * Offers a payment-on-account method (one with an enabled payment terms policy; a disabled policy
 * refuses the method) only to orders placed by their signed-in customer (never a guest checkout)
 * whose owner has an enabled trade credit account in the order currency, billed to a company,
 * not on hold, without overdue items (when the account blocks them) and with enough available
 * credit (unless exceeding is allowed; with an external credit provider, its limit and the amount
 * owed outside the shop count). Registered through order-hold, so it applies to the
 * shop and API method lists, the default method, method changes and checkout completion.
 */
final class TradeCreditEligibilityChecker implements PaymentMethodEligibilityCheckerInterface
{
    public function __construct(
        private readonly PaymentTermsPolicyProvider $policyProvider,
        private readonly TradeCreditAccountProvider $accountProvider,
        private readonly BusinessCustomerChecker $businessCustomerChecker,
        private readonly OrderOwnerChecker $orderOwnerChecker,
        private readonly OrderPaymentTermsRepositoryInterface $termsRepository,
        private readonly MoneyFormatterInterface $moneyFormatter,
        private readonly ClockInterface $clock,
        private readonly CreditPositionResolver $positionResolver,
    ) {
    }

    public function getIneligibility(PaymentMethodInterface $paymentMethod, PaymentInterface $payment): ?PaymentMethodIneligibility
    {
        $policy = $this->policyProvider->findPolicy($paymentMethod);
        if (null === $policy) {
            return null;
        }

        // A disabled policy turns the method off, it does not make it a plain Offline method.
        $order = $payment instanceof CorePaymentInterface ? $payment->getOrder() : null;
        if (!$order instanceof OrderInterface || !$this->orderOwnerChecker->isPlacedByItsCustomer($order)) {
            // Generic refusal: nothing about the account of the customer whose e-mail a guest typed.
            return new PaymentMethodIneligibility(IneligibilityReason::Restricted, 'tmonier_trade_credit.payment_method.no_account');
        }
        if (!$policy->isEnabled()) {
            return new PaymentMethodIneligibility(IneligibilityReason::Restricted, 'tmonier_trade_credit.payment_method.unavailable');
        }

        $reserved = null === $order->getId() ? null : $this->termsRepository->findReservedForOrder($order);
        // Placed order already reserved with this method: keep it valid.
        if (
            null !== $reserved &&
            OrderCheckoutStates::STATE_COMPLETED === $order->getCheckoutState() &&
            $reserved->getPaymentMethod()?->getId() === $paymentMethod->getId()
        ) {
            return null;
        }

        $account = $this->accountProvider->forOrder($order);
        if (null === $account || !$account->isEnabled() || $account->getCurrencyCode() !== $order->getCurrencyCode()) {
            return new PaymentMethodIneligibility(IneligibilityReason::Restricted, 'tmonier_trade_credit.payment_method.no_account');
        }
        if (!$this->businessCustomerChecker->isBusinessOrder($order)) {
            return new PaymentMethodIneligibility(IneligibilityReason::Restricted, 'tmonier_trade_credit.payment_method.company_required');
        }
        if ($account->isCreditHold()) {
            return new PaymentMethodIneligibility(IneligibilityReason::CreditHold, 'tmonier_trade_credit.payment_method.credit_hold');
        }
        if ($account->isBlockWhenOverdue() && $this->termsRepository->hasOverdue($account, $this->clock->now())) {
            return new PaymentMethodIneligibility(IneligibilityReason::Overdue, 'tmonier_trade_credit.payment_method.overdue');
        }

        if ($account->isAllowExceed()) {
            return null;
        }

        try {
            $position = $this->positionResolver->resolve($account);
        } catch (ExternalCreditUnavailableException) {
            // External position (ERP) unknown: fail closed.
            return new PaymentMethodIneligibility(IneligibilityReason::Restricted, 'tmonier_trade_credit.payment_method.unavailable');
        }

        // An open reservation of this order (other method, cart left by a failed save) is
        // replaced by the new one: its amount is available again.
        $available = $position->getAvailableAmount() + ($reserved?->getAmount() ?? 0);
        if ($available < $order->getTotal()) {
            return new PaymentMethodIneligibility(IneligibilityReason::CreditLimitExceeded, 'tmonier_trade_credit.payment_method.limit_exceeded', [
                '%available%' => $this->moneyFormatter->format(max(0, $available), (string) $account->getCurrencyCode(), $order->getLocaleCode()),
            ]);
        }

        return null;
    }
}
