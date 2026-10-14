<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Addressing\Model\CountryInterface;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Addressing\Model\ZoneMemberInterface;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Product\Factory\ProductFactoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tmonier\SyliusTradeCreditPlugin\Account\PaymentTermsPolicyProvider;
use Tmonier\SyliusTradeCreditPlugin\Entity\PaymentTermsPolicyInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;
use Tmonier\SyliusTradeCreditPlugin\Factory\TradeCreditAccountFactory;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Reservation\TradeCreditReserver;
use Tmonier\SyliusTradeCreditPlugin\Terms\EndOfMonthMode;

/**
 * Tests run against the test application loaded with the (random) Sylius fixtures, each one in
 * a transaction rolled back at the end. They create the catalog data, customers and orders
 * they need: a zone, a shipping method and two offline payment methods of the channel.
 */
trait TradeCreditTestTrait
{
    private ?ShippingMethodInterface $shippingMethod = null;

    /** Company of the billing addresses of the next orders (B2B check). */
    private ?string $company = 'Acme Industries';

    abstract protected static function getContainer(): ContainerInterface;

    private function beginTransaction(): void
    {
        $this->connection()->beginTransaction();
    }

    private function rollBack(): void
    {
        if ($this->connection()->isTransactionActive()) {
            $this->connection()->rollBack();
        }
    }

    private function channel(): ChannelInterface
    {
        $channel = $this->entityManager()->getRepository(ChannelInterface::class)->findOneBy(['hostname' => 'localhost']);
        if (!$channel instanceof ChannelInterface) {
            $channel = $this->entityManager()->getRepository(ChannelInterface::class)->findOneBy(['enabled' => true]);
        }
        self::assertInstanceOf(ChannelInterface::class, $channel);

        return $channel;
    }

    private function localeCode(): string
    {
        return (string) $this->channel()->getDefaultLocale()?->getCode();
    }

    private function paymentMethod(string $name): PaymentMethodInterface
    {
        $channel = $this->channel();
        /** @var PaymentMethodFactoryInterface<PaymentMethodInterface> $factory */
        $factory = $this->service('sylius.factory.payment_method');
        $method = $factory->createWithGateway('offline');
        $code = 'tmonier_pay_' . bin2hex(random_bytes(4));
        $method->setCode($code);
        $method->getGatewayConfig()?->setGatewayName($code);
        $method->setCurrentLocale($this->localeCode());
        $method->setFallbackLocale($this->localeCode());
        $method->setName($name . ' ' . $code);
        $method->addChannel($channel);
        $method->setEnabled(true);
        $method->setPosition(-1);
        $this->entityManager()->persist($method);
        $this->entityManager()->flush();

        return $method;
    }

    private function customer(bool $withAccount = true): CustomerInterface
    {
        /** @var FactoryInterface<CustomerInterface> $factory */
        $factory = $this->service('sylius.factory.customer');
        $customer = $factory->createNew();
        $customer->setEmail(sprintf('hold-%s@example.com', bin2hex(random_bytes(4))));
        $customer->setFirstName('Ada');
        $customer->setLastName('Buyer');
        if ($withAccount) {
            /** @var FactoryInterface<ShopUserInterface> $userFactory */
            $userFactory = $this->service('sylius.factory.shop_user');
            $user = $userFactory->createNew();
            $user->setPlainPassword('sylius');
            $user->setEnabled(true);
            $customer->setUser($user);
        }
        $this->entityManager()->persist($customer);

        return $customer;
    }

    /**
     * An order of `$customer` (a new guest customer by default) in the given checkout state:
     * `addressed`, `shipping_selected`, `payment_selected` or `completed`.
     */
    private function order(string $checkoutState, ?PaymentMethodInterface $paymentMethod = null, ?CustomerInterface $customer = null, bool $guest = false): OrderInterface
    {
        $channel = $this->channel();
        /** @var FactoryInterface<OrderInterface> $orderFactory */
        $orderFactory = $this->service('sylius.factory.order');
        $order = $orderFactory->createNew();
        $order->setChannel($channel);
        $order->setLocaleCode($this->localeCode());
        $order->setCurrencyCode((string) $channel->getBaseCurrency()?->getCode());
        if (!$guest && $customer?->getUser() instanceof \Sylius\Component\User\Model\UserInterface) {
            $order->setCustomerWithAuthorization($customer);
        } else {
            $order->setCustomer($customer ?? $this->customer(false));
        }
        $order->setTokenValue(bin2hex(random_bytes(10)));

        /** @var FactoryInterface<OrderItemInterface> $itemFactory */
        $itemFactory = $this->service('sylius.factory.order_item');
        $item = $itemFactory->createNew();
        $item->setVariant($this->variant());
        /** @var OrderItemQuantityModifierInterface $quantityModifier */
        $quantityModifier = $this->service('sylius.modifier.order_item_quantity');
        $quantityModifier->modify($item, 2);
        /** @var OrderModifierInterface $orderModifier */
        $orderModifier = $this->service('sylius.modifier.order');
        $orderModifier->addToOrder($order, $item);

        $order->setShippingAddress($this->address());
        $order->setBillingAddress($this->address());
        $this->entityManager()->persist($order);

        $this->shippingMethod();
        $this->apply($order, OrderCheckoutTransitions::TRANSITION_ADDRESS);
        if ('addressed' !== $checkoutState) {
            $this->apply($order, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING);
        }
        if (in_array($checkoutState, ['payment_selected', 'completed'], true)) {
            $payment = $order->getLastPayment(PaymentInterface::STATE_CART);
            self::assertNotNull($payment);
            if ($paymentMethod instanceof \Sylius\Component\Core\Model\PaymentMethodInterface) {
                $payment->setMethod($paymentMethod);
            }
            $this->apply($order, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);
        }
        if ('completed' === $checkoutState) {
            $this->apply($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);
        }
        $this->entityManager()->flush();

        return $order;
    }

    /** A completed, unpaid order whose checkout was completed `$age` ago (e.g. `10 days`). */
    private function unpaidOrder(string $age, ?PaymentMethodInterface $paymentMethod = null): OrderInterface
    {
        $order = $this->order('completed', $paymentMethod ?? $this->paymentMethod('Bank transfer'));
        $order->setCheckoutCompletedAt(new \DateTime('-' . $age));
        $this->entityManager()->flush();

        return $order;
    }

    private function apply(OrderInterface $order, string $transition): void
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = $this->service('sylius_abstraction.state_machine');
        self::assertTrue($stateMachine->can($order, OrderCheckoutTransitions::GRAPH, $transition), sprintf('Checkout transition "%s" from "%s"', $transition, (string) $order->getCheckoutState()));
        $stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, $transition);
    }

    private function address(): AddressInterface
    {
        /** @var FactoryInterface<AddressInterface> $factory */
        $factory = $this->service('sylius.factory.address');
        $address = $factory->createNew();
        $address->setFirstName('Ada');
        $address->setLastName('Buyer');
        $address->setStreet('1 Main Street');
        $address->setCity('Springfield');
        $address->setPostcode('12345');
        $address->setCountryCode('US');
        $address->setCompany($this->company);

        return $address;
    }

    /** A flat-rate shipping method of the channel shipping to the US (zone created by the test). */
    private function shippingMethod(): ShippingMethodInterface
    {
        if (null !== $this->shippingMethod) {
            return $this->shippingMethod;
        }

        $channel = $this->channel();
        $country = $this->entityManager()->getRepository(CountryInterface::class)->findOneBy(['code' => 'US']);
        if (!$country instanceof CountryInterface) {
            /** @var FactoryInterface<CountryInterface> $countryFactory */
            $countryFactory = $this->service('sylius.factory.country');
            $country = $countryFactory->createNew();
            $country->setCode('US');
            $this->entityManager()->persist($country);
        }
        $country->setEnabled(true);

        /** @var FactoryInterface<ZoneInterface> $zoneFactory */
        $zoneFactory = $this->service('sylius.factory.zone');
        /** @var FactoryInterface<ZoneMemberInterface> $memberFactory */
        $memberFactory = $this->service('sylius.factory.zone_member');
        $zone = $zoneFactory->createNew();
        $zone->setCode('TMONIER_' . strtoupper(bin2hex(random_bytes(4))));
        $zone->setName((string) $zone->getCode());
        $zone->setType(ZoneInterface::TYPE_COUNTRY);
        $member = $memberFactory->createNew();
        $member->setCode('US');
        $zone->addMember($member);
        $this->entityManager()->persist($zone);

        /** @var FactoryInterface<ShippingMethodInterface> $factory */
        $factory = $this->service('sylius.factory.shipping_method');
        $method = $factory->createNew();
        $code = 'tmonier_ship_' . bin2hex(random_bytes(4));
        $method->setCode($code);
        $method->setCurrentLocale($this->localeCode());
        $method->setFallbackLocale($this->localeCode());
        $method->setName('Test ' . $code);
        $method->setZone($zone);
        $method->setCalculator('flat_rate');
        $method->setConfiguration([(string) $channel->getCode() => ['amount' => 500]]);
        $method->addChannel($channel);
        $method->setEnabled(true);
        $method->setPosition(-1);
        $this->entityManager()->persist($method);
        $this->entityManager()->flush();

        return $this->shippingMethod = $method;
    }

    /** An enabled, untracked variant of a new product sold in the channel. */
    private function variant(): ProductVariantInterface
    {
        $channel = $this->channel();
        /** @var ProductFactoryInterface<ProductInterface> $productFactory */
        $productFactory = $this->service('sylius.factory.product');
        /** @var FactoryInterface<ChannelPricingInterface> $pricingFactory */
        $pricingFactory = $this->service('sylius.factory.channel_pricing');
        $code = 'tmonier_product_' . bin2hex(random_bytes(4));

        $product = $productFactory->createWithVariant();
        $product->setCode($code);
        $product->setCurrentLocale($this->localeCode());
        $product->setFallbackLocale($this->localeCode());
        $product->setName('Test product ' . $code);
        $product->setSlug($code);
        $product->setEnabled(true);
        $product->addChannel($channel);

        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        $variant->setCode($code . '_variant');
        $variant->setCurrentLocale($this->localeCode());
        $variant->setFallbackLocale($this->localeCode());
        $variant->setName('Test variant ' . $code);
        $variant->setEnabled(true);
        $variant->setTracked(false);
        $variant->setShippingRequired(true);
        $pricing = $pricingFactory->createNew();
        $pricing->setChannelCode($channel->getCode());
        $pricing->setPrice(2500);
        $pricing->setOriginalPrice(2500);
        $variant->addChannelPricing($pricing);

        $this->entityManager()->persist($product);
        $this->entityManager()->flush();

        return $variant;
    }

    /** An Offline payment method with a payment terms policy ("payment on account"). */
    private function onAccountMethod(int $netDays = 30, EndOfMonthMode $mode = EndOfMonthMode::None): PaymentMethodInterface
    {
        $method = $this->paymentMethod('On account');
        /** @var FactoryInterface<PaymentTermsPolicyInterface> $factory */
        $factory = $this->service('tmonier_trade_credit.factory.payment_terms_policy');
        $policy = $factory->createNew();
        $policy->setPaymentMethod($method);
        $policy->setNetDays($netDays);
        $policy->setEndOfMonthMode($mode);
        $this->entityManager()->persist($policy);
        $this->entityManager()->flush();
        $this->policyProvider()->reset();

        return $method;
    }

    private function account(CustomerInterface $customer, int $limit = 100000, bool $allowExceed = false): TradeCreditAccountInterface
    {
        $this->entityManager()->flush();
        /** @var TradeCreditAccountFactory $factory */
        $factory = $this->service('tmonier_trade_credit.factory.account');
        $account = $factory->createForCustomer($customer->getId());
        $account->setCurrencyCode($this->channel()->getBaseCurrency()?->getCode());
        $account->setCreditLimit($limit);
        $account->setAllowExceed($allowExceed);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function policyProvider(): PaymentTermsPolicyProvider
    {
        $provider = $this->service('tmonier_trade_credit.provider.payment_terms_policy');
        self::assertInstanceOf(PaymentTermsPolicyProvider::class, $provider);

        return $provider;
    }

    private function reserver(): TradeCreditReserver
    {
        $reserver = $this->service('tmonier_trade_credit.reserver');
        self::assertInstanceOf(TradeCreditReserver::class, $reserver);

        return $reserver;
    }

    private function termsRepository(): OrderPaymentTermsRepositoryInterface
    {
        $repository = $this->service('tmonier_trade_credit.repository.order_payment_terms');
        self::assertInstanceOf(OrderPaymentTermsRepositoryInterface::class, $repository);

        return $repository;
    }

    /** Committed amount read in SQL (the entity may be stale). */
    private function committed(TradeCreditAccountInterface $account): int
    {
        return self::intOf($this->connection()->fetchOne('SELECT committed_amount FROM tmonier_trade_credit_account WHERE id = ?', [$account->getId()]));
    }

    /** @return list<array{type: string, amount: int}> */
    private function ledger(TradeCreditAccountInterface $account): array
    {
        /** @var list<array{type: string, amount: int|string}> $rows */
        $rows = $this->connection()->fetchAllAssociative('SELECT type, amount FROM tmonier_trade_credit_ledger_entry WHERE account_id = ? ORDER BY created_at, id', [$account->getId()]);

        return array_map(static fn (array $row): array => ['type' => $row['type'], 'amount' => (int) $row['amount']], $rows);
    }

    /** @return list<string> */
    private function termsStates(OrderInterface $order): array
    {
        /** @var list<string> $states */
        $states = $this->connection()->fetchFirstColumn('SELECT state FROM tmonier_trade_credit_order_terms WHERE order_id = ? ORDER BY created_at, id', [$order->getId()]);

        return $states;
    }

    private static function intOf(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private static function idOf(object $resource): string
    {
        $id = method_exists($resource, 'getId') ? $resource->getId() : null;
        self::assertTrue(is_int($id) || is_string($id));

        return (string) $id;
    }

    private function reload(OrderInterface $order): OrderInterface
    {
        $reloaded = $this->entityManager()->getRepository(OrderInterface::class)->find($order->getId());
        self::assertInstanceOf(OrderInterface::class, $reloaded);
        $this->entityManager()->refresh($reloaded);

        return $reloaded;
    }

    private function service(string $id): object
    {
        // The test container throws for unknown services.
        return self::getContainer()->get($id);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}
