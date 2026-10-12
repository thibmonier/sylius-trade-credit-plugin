<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AccountMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
        $event->getMenu()
            ->addChild('tmonier_trade_credit', ['route' => 'tmonier_trade_credit_shop_statement'])
            ->setLabel('tmonier_trade_credit.ui.trade_credit')
            ->setLabelAttribute('icon', 'tabler:credit-card-pay')
        ;
    }
}
