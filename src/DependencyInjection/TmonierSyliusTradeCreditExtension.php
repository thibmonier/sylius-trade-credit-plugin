<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Tmonier\SyliusFacturXPlugin\PaymentTerms\InvoicePaymentTermsProviderInterface;

final class TmonierSyliusTradeCreditExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{legal_limits: array{net_days: int, end_of_month_days: int, channels: array<string, array{net_days: int, end_of_month_days: int}>}, reminders: array{levels: list<int>}, driver: string, resources: array<string, mixed>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $this->registerResources('tmonier_trade_credit', $config['driver'], $config['resources'], $container);

        $container->setParameter('tmonier_trade_credit.legal_limits.net_days', $config['legal_limits']['net_days']);
        $container->setParameter('tmonier_trade_credit.legal_limits.end_of_month_days', $config['legal_limits']['end_of_month_days']);
        $container->setParameter('tmonier_trade_credit.legal_limits.channels', $config['legal_limits']['channels']);
        $levels = array_values(array_unique($config['reminders']['levels']));
        sort($levels);
        $container->setParameter('tmonier_trade_credit.reminders.levels', $levels);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');

        // Factur-X (optional): per-order due date (BT-9) and payment terms (BT-20).
        if (interface_exists(InvoicePaymentTermsProviderInterface::class)) {
            $loader->load('services/facturx.php');
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        // Grids are processed before this extension is loaded: the model class parameters have
        // to exist at prepend time.
        $configs = array_map(
            static fn (array $config): array => isset($config['resources']) ? ['resources' => $config['resources']] : [],
            $container->getExtensionConfig($this->getAlias()),
        );
        /** @var array{resources: array<string, array{classes: array{model: string}}>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);
        foreach ($config['resources'] as $name => $resource) {
            $container->setParameter(sprintf('tmonier_trade_credit.model.%s.class', $name), $resource['classes']['model']);
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'TmonierSyliusTradeCreditPlugin' => [
                        'type' => 'xml',
                        'dir' => 'config/doctrine',
                        'prefix' => 'Tmonier\SyliusTradeCreditPlugin\Entity',
                        'is_bundle' => true,
                    ],
                ],
            ],
        ]);

        if ($container->hasExtension('api_platform')) {
            $container->prependExtensionConfig('api_platform', [
                'mapping' => ['paths' => [\dirname(__DIR__, 2) . '/config/api_platform']],
            ]);
        }

        $this->prependDoctrineMigrations($container);
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Tmonier\SyliusTradeCreditPlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@TmonierSyliusTradeCreditPlugin/src/Migrations';
    }

    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
        ];
    }
}
