<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckInterface;
use Tmonier\SyliusTradeCreditPlugin\DependencyInjection\TmonierSyliusTradeCreditExtension;
use Tmonier\SyliusTradeCreditPlugin\TmonierSyliusTradeCreditPlugin;

/**
 * A shop that installed the plugin and not the health plugin: the extension loads, no probe is registered, and no class of
 * the health plugin is needed. The interface check is injected, the health plugin being installed here for the tests.
 */
final class HealthProbeExtensionTest extends TestCase
{
    private const PROBE = 'tmonier_trade_credit.health.stale_reservations';

    public function testTheExtensionLoadsWithoutAnyProbeWhenTheHealthPluginIsAbsent(): void
    {
        $container = $this->loaded(static fn (string $interface): bool => false);

        self::assertTrue($container->hasDefinition('tmonier_trade_credit.reservation.maintenance'), 'The services of the plugin are loaded.');
        self::assertFalse($container->hasDefinition(self::PROBE));
    }

    public function testNoServiceOfTheHealthNamespaceIsRegisteredWithoutTheHealthPlugin(): void
    {
        $container = $this->loaded(static fn (string $interface): bool => false);

        $classes = array_map(static fn ($definition): ?string => $definition->getClass(), $container->getDefinitions());
        self::assertSame([], array_values(array_filter($classes, static fn (?string $class): bool => null !== $class && str_contains($class, '\\Health\\'))));
    }

    public function testTheProbeIsAutoconfiguredWhenTheHealthPluginIsInstalled(): void
    {
        $container = $this->loaded(static fn (string $interface): bool => HealthCheckInterface::class === $interface);

        self::assertTrue($container->hasDefinition(self::PROBE));
        self::assertTrue($container->getDefinition(self::PROBE)->isAutoconfigured());
    }

    public function testTheBundleHandsTheCheckToItsExtension(): void
    {
        $asked = [];
        $bundle = new TmonierSyliusTradeCreditPlugin(static function (string $interface) use (&$asked): bool {
            $asked[] = $interface;

            return false;
        });
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container = new ContainerBuilder();
        $container->registerExtension($extension);
        $extension->load([[]], $container);

        self::assertContains(HealthCheckInterface::class, $asked);
    }

    /**
     * @param \Closure(string): bool $interfaceExists
     */
    private function loaded(\Closure $interfaceExists): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new TmonierSyliusTradeCreditExtension($interfaceExists))->load([[]], $container);

        return $container;
    }
}
