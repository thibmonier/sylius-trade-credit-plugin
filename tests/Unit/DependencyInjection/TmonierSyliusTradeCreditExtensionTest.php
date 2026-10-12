<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tmonier\SyliusTradeCreditPlugin\DependencyInjection\TmonierSyliusTradeCreditExtension;

final class TmonierSyliusTradeCreditExtensionTest extends TestCase
{
    public function testTheExtensionPointsOfTheOtherPluginsAreUsed(): void
    {
        $container = new ContainerBuilder();
        (new TmonierSyliusTradeCreditExtension())->load([[
            'legal_limits' => ['channels' => ['B2B' => ['net_days' => 30, 'end_of_month_days' => 30]]],
        ]], $container);

        // order-hold: one checker, one blocker, one query extension (never a decorator).
        self::assertSame(['tmonier_trade_credit.order_hold.eligibility_checker'], array_keys($container->findTaggedServiceIds('tmonier_order_hold.payment_method_eligibility_checker')));
        self::assertSame(['tmonier_trade_credit.order_hold.payment_entry_blocker'], array_keys($container->findTaggedServiceIds('tmonier_order_hold.payment_entry_blocker')));
        self::assertSame(['tmonier_trade_credit.order_hold.unpaid_orders_query_extension'], array_keys($container->findTaggedServiceIds('tmonier_order_hold.unpaid_order_candidate_query_extension')));

        // Factur-X (installed in require-dev): the per-order terms provider, before the channel settings.
        $tags = $container->findTaggedServiceIds('tmonier_facturx.invoice_payment_terms_provider');
        self::assertSame([['priority' => 10]], $tags['tmonier_trade_credit.facturx.payment_terms_provider'] ?? null);

        self::assertSame(60, $container->getParameter('tmonier_trade_credit.legal_limits.net_days'));
        self::assertSame(['B2B' => ['net_days' => 30, 'end_of_month_days' => 30]], $container->getParameter('tmonier_trade_credit.legal_limits.channels'));

        foreach ($container->getDefinitions() as $id => $definition) {
            self::assertNull($definition->getDecoratedService() !== null && !str_starts_with($id, 'tmonier_trade_credit.custom_factory.') ? $id : null, 'Only the plugin\'s own factory is decorated');
        }
    }
}
