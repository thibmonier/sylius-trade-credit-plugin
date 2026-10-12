<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Integration;

use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tests\Tmonier\SyliusTradeCreditPlugin\Functional\TradeCreditTestTrait;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Payment\OpenItemSettler;
use Tmonier\SyliusTradeCreditPlugin\Reservation\TradeCreditReserver;

final class OpenItemSettlerTest extends KernelTestCase
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

    /** Another request (import, Mark as paid) completed the payment meanwhile: the entities in memory are stale. */
    public function testAPaymentCompletedByAnotherRequestIsNotCompletedAgain(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $terms = $this->termsRepository()->findReservedForOrder($order);
        self::assertNotNull($terms);
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);
        $this->connection()->executeStatement('UPDATE sylius_payment SET state = ? WHERE id = ?', [PaymentInterface::STATE_COMPLETED, $payment->getId()]);
        $this->reserver()->close($order, OrderPaymentTermsInterface::STATE_SETTLED, TradeCreditReserver::REASON_PAID, false);
        $completions = 0;
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener('workflow.sylius_payment.completed.complete', static function () use (&$completions): void {
            ++$completions;
        });

        $settler = self::getContainer()->get(OpenItemSettler::class);
        self::assertInstanceOf(OpenItemSettler::class, $settler);

        self::assertFalse($settler->settle($terms));
        self::assertSame(0, $completions);
    }

    public function testAnOpenItemIsSettledOnce(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('completed', $method, $customer);
        $terms = $this->termsRepository()->findReservedForOrder($order);
        self::assertNotNull($terms);
        $settler = self::getContainer()->get(OpenItemSettler::class);
        self::assertInstanceOf(OpenItemSettler::class, $settler);

        self::assertTrue($settler->settle($terms));
        self::assertFalse($settler->settle($terms));
        self::assertSame(0, $this->committed($account));
        self::assertSame(['reservation', 'settlement'], array_column($this->ledger($account), 'type'));
    }
}
