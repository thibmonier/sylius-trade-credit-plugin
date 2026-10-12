<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Integration;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Tmonier\SyliusTradeCreditPlugin\Functional\TradeCreditTestTrait;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\IneligibilityReason;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderHoldResolverInterface;

/** Payment on account offered through the order-hold checkers (shop and API lists, default method). */
final class EligibilityTest extends KernelTestCase
{
    use TradeCreditTestTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBack();
        parent::tearDown();
    }

    public function testAnEligibleBusinessCustomerIsOfferedPaymentOnAccount(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer);

        self::assertContains($method, $this->methodsOf($this->order('shipping_selected', null, $customer)));
    }

    public function testNoAccount(): void
    {
        $method = $this->onAccountMethod();

        $this->assertRefused($method, $this->order('shipping_selected'), IneligibilityReason::Restricted, 'tmonier_trade_credit.payment_method.no_account');
    }

    public function testDisabledAccount(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer)->setEnabled(false);

        $this->assertRefused($method, $this->order('shipping_selected', null, $customer), IneligibilityReason::Restricted, 'tmonier_trade_credit.payment_method.no_account');
    }

    public function testNoCompanyOnTheBillingAddress(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer);
        $this->company = null;

        $this->assertRefused($method, $this->order('shipping_selected', null, $customer), IneligibilityReason::Restricted, 'tmonier_trade_credit.payment_method.company_required');
    }

    public function testCreditHold(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer)->setCreditHold(true);

        $this->assertRefused($method, $this->order('shipping_selected', null, $customer), IneligibilityReason::CreditHold);
    }

    public function testOverLimit(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer, 100);
        $order = $this->order('shipping_selected', null, $customer);

        $ineligibility = $this->assertRefused($method, $order, IneligibilityReason::CreditLimitExceeded);
        self::assertArrayHasKey('%available%', $ineligibility->getParameters());
    }

    public function testOverdueItemsWhenTheAccountBlocksThem(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $placed = $this->order('completed', $method, $customer);
        $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET due_date = ? WHERE order_id = ?', [(new \DateTimeImmutable('-3 days'))->format('Y-m-d'), $placed->getId()]);

        $this->assertRefused($method, $this->order('shipping_selected', null, $customer), IneligibilityReason::Overdue);

        $account->setBlockWhenOverdue(false);
        self::assertContains($method, $this->methodsOf($this->order('shipping_selected', null, $customer)));
    }

    public function testAPlacedOrderOnAccountKeepsItsMethodEvenWhenTheLimitIsReached(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $account->setCreditLimit(1);

        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);
        self::assertNull($this->checker()->getIneligibility($method, $payment));
    }

    public function testTheDefaultMethodIsNeverARefusedMethodOnAccount(): void
    {
        $method = $this->onAccountMethod();
        $method->setPosition(-100);
        $this->entityManager()->flush();

        $order = $this->order('shipping_selected');

        self::assertNotSame($method, $order->getLastPayment(PaymentInterface::STATE_CART)?->getMethod());
    }

    public function testOrdersOnAccountAreHeldForTheUnpaidOrdersCommandOnlyThroughTheQuery(): void
    {
        $resolver = $this->service(UnpaidOrderHoldResolverInterface::class);
        self::assertInstanceOf(UnpaidOrderHoldResolverInterface::class, $resolver);
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer);

        // Excluded in SQL (query extension), no per-order hold needed.
        self::assertNull($resolver->resolve($this->order('completed', $method, $customer)));
    }

    /** @return list<\Sylius\Component\Payment\Model\PaymentMethodInterface> */
    private function methodsOf(OrderInterface $order): array
    {
        $payment = $order->getLastPayment(PaymentInterface::STATE_CART);
        self::assertNotNull($payment);
        $resolver = $this->service('sylius.resolver.payment_methods');
        self::assertInstanceOf(PaymentMethodsResolverInterface::class, $resolver);

        return array_values($resolver->getSupportedMethods($payment));
    }

    private function assertRefused(PaymentMethodInterface $method, OrderInterface $order, IneligibilityReason $reason, ?string $message = null): \Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodIneligibility
    {
        self::assertNotContains($method, $this->methodsOf($order));
        $payment = $order->getLastPayment(PaymentInterface::STATE_CART);
        self::assertNotNull($payment);
        $ineligibility = $this->checker()->getIneligibility($method, $payment);
        self::assertNotNull($ineligibility);
        self::assertSame($reason, $ineligibility->getReason());
        if (null !== $message) {
            self::assertSame($message, $ineligibility->getMessage());
        }

        return $ineligibility;
    }

    private function checker(): PaymentMethodEligibilityCheckerInterface
    {
        $checker = $this->service(PaymentMethodEligibilityCheckerInterface::class);
        self::assertInstanceOf(PaymentMethodEligibilityCheckerInterface::class, $checker);

        return $checker;
    }
}
