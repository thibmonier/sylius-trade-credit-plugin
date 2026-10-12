<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tmonier\SyliusTradeCreditPlugin\FacturX\TradeCreditInvoicePaymentTermsProvider;

// Loaded only when tmonier/sylius-facturx-plugin (1.1+) is installed.
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('tmonier_trade_credit.facturx.payment_terms_provider', TradeCreditInvoicePaymentTermsProvider::class)
        ->args([
            service('tmonier_trade_credit.repository.order_payment_terms'),
            service('translator'),
        ])
        ->tag('tmonier_facturx.invoice_payment_terms_provider', ['priority' => 10])
    ;
};
