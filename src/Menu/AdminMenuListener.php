<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AdminMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();
        $parent = $menu->getChild('customers') ?? $menu->getChild('sales') ?? $menu;

        $parent
            ->addChild('tmonier_trade_credit_open_items', ['route' => 'tmonier_trade_credit_admin_open_item_index'])
            ->setLabel('tmonier_trade_credit.ui.open_items')
            ->setLabelAttribute('icon', 'tabler:file-invoice')
        ;
        $parent
            ->addChild('tmonier_trade_credit_accounts', ['route' => 'tmonier_trade_credit_admin_account_index'])
            ->setLabel('tmonier_trade_credit.ui.trade_credit_accounts')
            ->setLabelAttribute('icon', 'tabler:credit-card-pay')
        ;
        $parent
            ->addChild('tmonier_trade_credit_policies', ['route' => 'tmonier_trade_credit_admin_payment_terms_policy_index'])
            ->setLabel('tmonier_trade_credit.ui.payment_terms_policies')
            ->setLabelAttribute('icon', 'tabler:calendar-dollar')
        ;
    }
}
