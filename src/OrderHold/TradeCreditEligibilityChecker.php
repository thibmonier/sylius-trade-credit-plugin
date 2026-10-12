<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\OrderHold;

use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface as CorePaymentInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\IneligibilityReason;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodIneligibility;
use Tmonier\SyliusTradeCreditPlugin\Account\BusinessCustomerChecker;
use Tmonier\SyliusTradeCreditPlugin\Account\PaymentTermsPolicyProvider;
use Tmonier\SyliusTradeCreditPlugin\Account\TradeCreditAccountProvider;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/**
 * Offers a payment-on-account method (one with an enabled payment terms policy) only to orders
 * whose owner has an enabled trade credit account in the order currency, billed to a company,
 * not on hold, without overdue items (when the account blocks them) and with enough available
 * credit (unless exceeding is allowed). Registered through order-hold, so it applies to the
 * shop and API method lists, the default method, method changes and checkout completion.
 */
final class TradeCreditEligibilityChecker implements PaymentMethodEligibilityCheckerInterface
{
    public function __construct(
        private readonly PaymentTermsPolicyProvider $policyProvider,
        private readonly TradeCreditAccountProvider $accountProvider,
        private readonly BusinessCustomerChecker $businessCustomerChecker,
        private readonly OrderPaymentTermsRepositoryInterface $termsRepository,
        private readonly MoneyFormatterInterface $moneyFormatter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getIneligibility(PaymentMethodInterface $paymentMethod, PaymentInterface $payment): ?PaymentMethodIneligibility
    {
        if (!$this->policyProvider->isOnAccount($paymentMethod)) {
            return null;
        }

        $order = $payment instanceof CorePaymentInterface ? $payment->getOrder() : null;
        if (!$order instanceof OrderInterface) {
            return new PaymentMethodIneligibility(IneligibilityReason::Restricted, 'tmonier_trade_credit.payment_method.no_account');
        }

        // Already reserved (placed order paid on account): keep it valid.
        if (null !== $order->getId() && null !== $this->termsRepository->findReservedForOrder($order)) {
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
        if (!$account->isAllowExceed() && $account->getAvailableAmount() < $order->getTotal()) {
            return new PaymentMethodIneligibility(IneligibilityReason::CreditLimitExceeded, 'tmonier_trade_credit.payment_method.limit_exceeded', [
                '%available%' => $this->moneyFormatter->format(max(0, $account->getAvailableAmount()), (string) $account->getCurrencyCode(), $order->getLocaleCode()),
            ]);
        }

        return null;
    }
}
