<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Functional;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class ApiTradeCreditTest extends WebTestCase
{
    use TradeCreditTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBack();
        parent::tearDown();
    }

    public function testTheStatementOfTheSignedInCustomer(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer(true);
        $this->account($customer, 100000);
        $order = $this->order('completed', $method, $customer);

        $data = $this->json('GET', '/api/v2/shop/trade-credit/statement', null, $customer);

        self::assertResponseIsSuccessful();
        self::assertSame(100000, $data['creditLimit'] ?? null);
        self::assertSame($order->getTotal(), $data['committedAmount'] ?? null);
        self::assertSame(100000 - $order->getTotal(), $data['availableAmount'] ?? null);
        $items = $data['openItems'] ?? null;
        self::assertIsArray($items);
        self::assertCount(1, $items);
        self::assertIsArray($items[0]);
        self::assertSame($order->getNumber(), $items[0]['orderNumber'] ?? null);
        self::assertFalse($items[0]['overdue'] ?? null);
    }

    public function testTheStatementRequiresAuthentication(): void
    {
        $this->json('GET', '/api/v2/shop/trade-credit/statement');

        self::assertContains($this->client->getResponse()->getStatusCode(), [401, 403]);
    }

    public function testTheStatementOfACustomerWithoutAccountIsNotFound(): void
    {
        $this->json('GET', '/api/v2/shop/trade-credit/statement', null, $this->customer(true));

        self::assertResponseStatusCodeSame(404);
    }

    public function testPaymentOnAccountIsNotListedAboveTheLimit(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer, 100);
        $order = $this->order('shipping_selected', null, $customer);

        $data = $this->json('GET', sprintf('/api/v2/shop/orders/%s/payments/%s/methods', (string) $order->getTokenValue(), $this->paymentId($order)));

        self::assertResponseIsSuccessful();
        $members = $data['hydra:member'] ?? $data['member'] ?? [];
        self::assertIsArray($members);
        self::assertNotContains($method->getCode(), array_map(static fn (mixed $member): mixed => is_array($member) ? ($member['code'] ?? null) : null, $members));
    }

    public function testARefusedReservationIsA422(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('payment_selected', $method, $customer);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $connection = $this->connection();
        $accountId = $account->getId();
        $dispatcher->addListener('workflow.sylius_order_checkout.transition.complete', static function () use ($connection, $accountId): void {
            $connection->executeStatement('UPDATE tmonier_trade_credit_account SET committed_amount = credit_limit WHERE id = ?', [$accountId]);
        }, 100);

        $data = $this->json('PATCH', sprintf('/api/v2/shop/orders/%s/complete', (string) $order->getTokenValue()), []);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The order total exceeds your available credit', (string) json_encode($data));
        self::assertSame([], $this->termsStates($order));
    }

    public function testTheCompletionIsRefusedWithAReadableMessageAboveTheLimit(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $account = $this->account($customer);
        $order = $this->order('payment_selected', $method, $customer);
        $account->setCreditLimit(100);
        $this->entityManager()->flush();

        $data = $this->json('PATCH', sprintf('/api/v2/shop/orders/%s/complete', (string) $order->getTokenValue()), []);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('exceeds the credit available for', (string) json_encode($data));
    }

    /** Customers cannot start (and so neither complete nor refund) payment requests of an order on account. */
    public function testNoPaymentRequestForAnOrderOnAccount(): void
    {
        $method = $this->onAccountMethod();
        $customer = $this->customer();
        $this->account($customer);
        $order = $this->order('completed', $method, $customer);

        foreach (['capture', 'refund'] as $action) {
            $data = $this->json('POST', sprintf('/api/v2/shop/orders/%s/payment-requests', (string) $order->getTokenValue()), [
                'paymentId' => $this->paymentId($order),
                'paymentMethodCode' => $method->getCode(),
                'action' => $action,
            ]);

            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('This order is paid on account', (string) json_encode($data));
        }
        self::assertSame(0, self::intOf($this->connection()->fetchOne('SELECT COUNT(*) FROM sylius_payment_request r INNER JOIN sylius_payment p ON p.id = r.payment_id WHERE p.order_id = ?', [$order->getId()])));
    }

    private function paymentId(OrderInterface $order): string
    {
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW) ?? $order->getLastPayment();
        self::assertNotNull($payment);
        self::assertIsInt($payment->getId());

        return (string) $payment->getId();
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    private function json(string $method, string $uri, ?array $body = null, ?CustomerInterface $customer = null): array
    {
        $server = [
            'CONTENT_TYPE' => 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ];
        if (null !== $customer) {
            $user = $customer->getUser();
            self::assertInstanceOf(ShopUserInterface::class, $user);
            $this->entityManager()->flush();
            $manager = self::getContainer()->get('lexik_jwt_authentication.jwt_manager');
            self::assertInstanceOf(JWTTokenManagerInterface::class, $manager);
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $manager->create($user);
        }

        $this->client->request($method, $uri, server: $server, content: null === $body ? null : (string) json_encode($body));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($data) ? $data : [];
    }
}
