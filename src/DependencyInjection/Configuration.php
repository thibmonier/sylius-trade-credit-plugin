<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\DependencyInjection;

use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Sylius\Resource\Factory\Factory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTerms;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\PaymentTermsPolicy;
use Tmonier\SyliusTradeCreditPlugin\Entity\PaymentTermsPolicyInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccount;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditLedgerEntry;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditLedgerEntryInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepository;
use Tmonier\SyliusTradeCreditPlugin\Repository\PaymentTermsPolicyRepository;
use Tmonier\SyliusTradeCreditPlugin\Repository\TradeCreditAccountRepository;
use Tmonier\SyliusTradeCreditPlugin\Repository\TradeCreditLedgerEntryRepository;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tmonier_sylius_trade_credit');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('legal_limits')
                    ->info('Payment terms above these limits show a warning in the admin (France, article L441-10 of the Code de commerce: 60 days, or 45 days end of month). Not legal advice; nothing is blocked.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('net_days')->defaultValue(60)->min(0)->end()
                        ->integerNode('end_of_month_days')->defaultValue(45)->min(0)->end()
                        ->arrayNode('channels')
                            ->info('Per channel code, e.g. for sector caps (L441-11).')
                            ->useAttributeAsKey('code')
                            ->arrayPrototype()
                                ->children()
                                    ->integerNode('net_days')->isRequired()->min(0)->end()
                                    ->integerNode('end_of_month_days')->isRequired()->min(0)->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('driver')->defaultValue(SyliusResourceBundle::DRIVER_DOCTRINE_ORM)->end()
                ->arrayNode('resources')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('account')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(TradeCreditAccount::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(TradeCreditAccountInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('repository')->defaultValue(TradeCreditAccountRepository::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('payment_terms_policy')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(PaymentTermsPolicy::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(PaymentTermsPolicyInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('repository')->defaultValue(PaymentTermsPolicyRepository::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('order_payment_terms')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(OrderPaymentTerms::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(OrderPaymentTermsInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('repository')->defaultValue(OrderPaymentTermsRepository::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('ledger_entry')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(TradeCreditLedgerEntry::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(TradeCreditLedgerEntryInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('repository')->defaultValue(TradeCreditLedgerEntryRepository::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
