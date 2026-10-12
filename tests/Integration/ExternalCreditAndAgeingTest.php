<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Integration;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Tmonier\SyliusTradeCreditPlugin\Functional\TradeCreditTestTrait;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface;
use Tmonier\SyliusTradeCreditPlugin\Credit\ExternalCreditPosition;
use Tmonier\SyliusTradeCreditPlugin\Credit\ExternalCreditProviderInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;
use Tmonier\SyliusTradeCreditPlugin\Exception\TradeCreditReservationException;
use Tmonier\SyliusTradeCreditPlugin\Reservation\TradeCreditReserver;
use Tmonier\SyliusTradeCreditPlugin\Statement\StatementBuilder;

final class ExternalCreditAndAgeingTest extends KernelTestCase
{
    use TradeCreditTestTrait;

    private ExternalCreditPosition|\Throwable|null $external = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $test = $this;
        // Before any service using the credit position resolver is created.
        self::getContainer()->set('tmonier_trade_credit.external_credit_provider.internal', new class($test) implements ExternalCreditProviderInterface {
            public function __construct(private readonly ExternalCreditAndAgeingTest $test)
            {
            }

            public function getPosition(TradeCreditAccountInterface $account): ?ExternalCreditPosition
            {
                return $this->test->externalPosition();
            }
        });
        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBack();
        parent::tearDown();
    }

    public function externalPosition(): ?ExternalCreditPosition
    {
        if ($this->external instanceof \Throwable) {
            throw $this->external;
        }

        return $this->external;
    }

    /** What the customer owes in the ERP reduces the available credit, in the method list and at reservation. */
    public function testTheAmountOwedOutsideTheShopCounts(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer, 100000);
        $cart = $this->order('payment_selected', $method, $customer);
        $payment = $cart->getLastPayment(PaymentInterface::STATE_CART);
        self::assertNotNull($payment);
        $checker = $this->checker();
        $eligible = $checker->getIneligibility($method, $payment);
        self::assertNull($eligible);

        $this->external = new ExternalCreditPosition(null, 100000 - $cart->getTotal() + 1);
        $ineligibility = $checker->getIneligibility($method, $payment);
        self::assertSame('tmonier_trade_credit.payment_method.limit_exceeded', $ineligibility?->getMessage());
        $this->expectException(TradeCreditReservationException::class);
        $this->expectExceptionMessage('limit_exceeded');
        $this->apply($cart, OrderCheckoutTransitions::TRANSITION_COMPLETE);
    }

    /** The ERP limit replaces the account limit, in the atomic reservation too (stale entity). */
    public function testTheExternalLimitReplacesTheAccountLimit(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer, 1);
        $order = $this->order('payment_selected', $method, $customer);
        $this->external = new ExternalCreditPosition($order->getTotal(), 0);

        $this->apply($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);
        $this->entityManager()->flush();
        self::assertSame($order->getTotal(), $this->committed($account));

        $second = $this->order('payment_selected', $method, $customer);
        $this->expectException(TradeCreditReservationException::class);
        $this->expectExceptionMessage('limit_exceeded');
        $this->reserver()->reserve($second, $method);
    }

    /** ERP unreachable: payment on account is refused (fail closed), the statement still shows the Sylius figures. */
    public function testAnUnreachableProviderRefusesPaymentOnAccount(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer, 100000);
        $cart = $this->order('payment_selected', $method, $customer);
        $payment = $cart->getLastPayment(PaymentInterface::STATE_CART);
        self::assertNotNull($payment);
        $this->external = new \RuntimeException('ERP timeout');

        self::assertSame('tmonier_trade_credit.payment_method.unavailable', $this->checker()->getIneligibility($method, $payment)?->getMessage());
        self::assertSame(100000, $this->statementBuilder()->forCustomer($customer)?->position->getAvailableAmount());
        $this->expectException(TradeCreditReservationException::class);
        $this->expectExceptionMessage(TradeCreditReserver::UNAVAILABLE);
        $this->apply($cart, OrderCheckoutTransitions::TRANSITION_COMPLETE);
    }

    public function testTheStatementAndTheAdminSummaryShowTheAgeing(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer, 10000000);
        $account->setBlockWhenOverdue(false);
        $this->entityManager()->flush();
        $currency = (string) $account->getCurrencyCode();
        // The fixtures have open items too: compare with the ageing before our orders.
        $before = $this->termsRepository()->sumAgeingByCurrency(new \DateTimeImmutable())[$currency] ?? null;
        $amounts = [];
        foreach ([5 => 'current', -10 => 'days_1_30', -45 => 'days_31_60', -75 => 'days_61_90', -120 => 'days_over_90'] as $days => $bucket) {
            $order = $this->order('completed', $method, $customer);
            $this->connection()->executeStatement('UPDATE tmonier_trade_credit_order_terms SET due_date = ? WHERE order_id = ?', [(new \DateTimeImmutable(sprintf('%+d days', $days)))->format('Y-m-d'), $order->getId()]);
            $amounts[$bucket] = $order->getTotal();
        }
        $this->external = new ExternalCreditPosition(null, 2500);
        $this->entityManager()->clear();

        $statement = $this->statementBuilder()->forCustomer($this->reloadCustomer($customer->getId()));
        self::assertNotNull($statement);
        self::assertSame($amounts, $statement->ageing->toArray());
        self::assertSame(2500, $statement->position->externalCommittedAmount);
        self::assertSame(10000000 - array_sum($amounts) - 2500, $statement->position->getAvailableAmount());

        $all = $this->termsRepository()->sumAgeingByCurrency(new \DateTimeImmutable())[$currency] ?? null;
        self::assertNotNull($all);
        foreach ($amounts as $bucket => $amount) {
            self::assertSame($amount, $all->toArray()[$bucket] - ($before?->toArray()[$bucket] ?? 0), $bucket);
        }
    }

    private function reloadCustomer(mixed $id): \Sylius\Component\Core\Model\CustomerInterface
    {
        $customer = $this->entityManager()->find(\Sylius\Component\Core\Model\CustomerInterface::class, $id);
        self::assertInstanceOf(\Sylius\Component\Core\Model\CustomerInterface::class, $customer);

        return $customer;
    }

    private function checker(): PaymentMethodEligibilityCheckerInterface
    {
        $checker = $this->service(PaymentMethodEligibilityCheckerInterface::class);
        self::assertInstanceOf(PaymentMethodEligibilityCheckerInterface::class, $checker);

        return $checker;
    }

    private function statementBuilder(): StatementBuilder
    {
        $builder = $this->service('tmonier_trade_credit.statement_builder');
        self::assertInstanceOf(StatementBuilder::class, $builder);

        return $builder;
    }
}
