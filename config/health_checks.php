<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tmonier\SyliusTradeCreditPlugin\Health\StaleReservationsCheck;

/*
 * The probe of the plugin, apart from its other services: the extension loads this file only when the health plugin
 * is installed. The autoconfiguration of the health plugin gives it its tag, never tag it by hand.
 */
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('tmonier_trade_credit.health.stale_reservations', StaleReservationsCheck::class)
        ->args([service('tmonier_trade_credit.reservation.maintenance')])
        ->autoconfigure()
    ;
};
