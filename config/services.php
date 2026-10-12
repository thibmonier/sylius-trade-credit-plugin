<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sylius\Bundle\GridBundle\Form\Type\Filter\BooleanFilterType;
use Tmonier\SyliusTradeCreditPlugin\Account\BusinessCustomerChecker;
use Tmonier\SyliusTradeCreditPlugin\Account\PaymentTermsPolicyProvider;
use Tmonier\SyliusTradeCreditPlugin\Account\TradeCreditAccountProvider;
use Tmonier\SyliusTradeCreditPlugin\Api\TradeCreditStatementProvider;
use Tmonier\SyliusTradeCreditPlugin\Command\RecalculateCommand;
use Tmonier\SyliusTradeCreditPlugin\Controller\Admin\MarkAsPaidController;
use Tmonier\SyliusTradeCreditPlugin\Controller\Shop\StatementController;
use Tmonier\SyliusTradeCreditPlugin\EventListener\PaymentMethodSwitchListener;
use Tmonier\SyliusTradeCreditPlugin\EventListener\ReservationExceptionListener;
use Tmonier\SyliusTradeCreditPlugin\EventListener\TradeCreditWorkflowListener;
use Tmonier\SyliusTradeCreditPlugin\Factory\TradeCreditAccountFactory;
use Tmonier\SyliusTradeCreditPlugin\Fixture\TradeCreditFixture;
use Tmonier\SyliusTradeCreditPlugin\Form\Type\PaymentTermsPolicyType;
use Tmonier\SyliusTradeCreditPlugin\Form\Type\TradeCreditAccountType;
use Tmonier\SyliusTradeCreditPlugin\Grid\Filter\OverdueFilter;
use Tmonier\SyliusTradeCreditPlugin\Menu\AccountMenuListener;
use Tmonier\SyliusTradeCreditPlugin\Menu\AdminMenuListener;
use Tmonier\SyliusTradeCreditPlugin\OrderHold\OnAccountPaymentEntryBlocker;
use Tmonier\SyliusTradeCreditPlugin\OrderHold\ReservedOrdersQueryExtension;
use Tmonier\SyliusTradeCreditPlugin\OrderHold\TradeCreditEligibilityChecker;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\PaymentTermsPolicyRepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\TradeCreditAccountRepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\TradeCreditLedgerEntryRepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Reservation\TradeCreditReserver;
use Tmonier\SyliusTradeCreditPlugin\Statement\StatementBuilder;
use Tmonier\SyliusTradeCreditPlugin\Terms\LegalTermsChecker;
use Tmonier\SyliusTradeCreditPlugin\Twig\TradeCreditExtension;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->alias(TradeCreditAccountRepositoryInterface::class, 'tmonier_trade_credit.repository.account');
    $services->alias(PaymentTermsPolicyRepositoryInterface::class, 'tmonier_trade_credit.repository.payment_terms_policy');
    $services->alias(OrderPaymentTermsRepositoryInterface::class, 'tmonier_trade_credit.repository.order_payment_terms');
    $services->alias(TradeCreditLedgerEntryRepositoryInterface::class, 'tmonier_trade_credit.repository.ledger_entry');

    $services
        ->set('tmonier_trade_credit.custom_factory.account', TradeCreditAccountFactory::class)
        ->decorate('tmonier_trade_credit.factory.account')
        ->args([
            service('.inner'),
            service('tmonier_order_hold.resolver.account_owner'),
            service('sylius.repository.customer'),
            service('sylius.repository.channel'),
        ])
    ;

    // Accounts, policies and terms

    $services
        ->set('tmonier_trade_credit.provider.payment_terms_policy', PaymentTermsPolicyProvider::class)
        ->args([service('tmonier_trade_credit.repository.payment_terms_policy')])
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services->alias(PaymentTermsPolicyProvider::class, 'tmonier_trade_credit.provider.payment_terms_policy');

    $services
        ->set('tmonier_trade_credit.provider.account', TradeCreditAccountProvider::class)
        ->args([
            service('tmonier_order_hold.resolver.account_owner'),
            service('tmonier_trade_credit.repository.account'),
        ])
    ;
    $services->alias(TradeCreditAccountProvider::class, 'tmonier_trade_credit.provider.account');

    $services->set('tmonier_trade_credit.checker.business_customer', BusinessCustomerChecker::class);

    $services
        ->set('tmonier_trade_credit.checker.legal_terms', LegalTermsChecker::class)
        ->args([
            param('tmonier_trade_credit.legal_limits.net_days'),
            param('tmonier_trade_credit.legal_limits.end_of_month_days'),
            param('tmonier_trade_credit.legal_limits.channels'),
        ])
    ;

    $services
        ->set('tmonier_trade_credit.reserver', TradeCreditReserver::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('tmonier_trade_credit.provider.account'),
            service('tmonier_trade_credit.provider.payment_terms_policy'),
            service('tmonier_trade_credit.repository.order_payment_terms'),
            service('clock'),
            service('logger'),
            param('tmonier_trade_credit.model.account.class'),
            param('tmonier_trade_credit.model.order_payment_terms.class'),
            param('tmonier_trade_credit.model.ledger_entry.class'),
        ])
        ->tag('monolog.logger', ['channel' => 'tmonier_trade_credit'])
    ;
    $services->alias(TradeCreditReserver::class, 'tmonier_trade_credit.reserver');

    // order-hold extension points

    $services
        ->set('tmonier_trade_credit.order_hold.eligibility_checker', TradeCreditEligibilityChecker::class)
        ->args([
            service('tmonier_trade_credit.provider.payment_terms_policy'),
            service('tmonier_trade_credit.provider.account'),
            service('tmonier_trade_credit.checker.business_customer'),
            service('tmonier_trade_credit.repository.order_payment_terms'),
            service('sylius.formatter.money'),
            service('clock'),
        ])
        ->tag('tmonier_order_hold.payment_method_eligibility_checker')
    ;

    $services
        ->set('tmonier_trade_credit.order_hold.payment_entry_blocker', OnAccountPaymentEntryBlocker::class)
        ->args([service('tmonier_trade_credit.repository.order_payment_terms')])
        ->tag('tmonier_order_hold.payment_entry_blocker')
    ;

    $services
        ->set('tmonier_trade_credit.order_hold.unpaid_orders_query_extension', ReservedOrdersQueryExtension::class)
        ->args([param('tmonier_trade_credit.model.order_payment_terms.class')])
        ->tag('tmonier_order_hold.unpaid_order_candidate_query_extension')
    ;

    // Reservations and releases

    $services
        ->set('tmonier_trade_credit.listener.workflow', TradeCreditWorkflowListener::class)
        ->args([
            service('tmonier_trade_credit.reserver'),
            service('tmonier_trade_credit.provider.payment_terms_policy'),
            service('doctrine.orm.entity_manager'),
        ])
        ->tag('kernel.event_listener', ['event' => 'workflow.sylius_order_checkout.transition.complete', 'method' => 'onCheckoutComplete'])
        ->tag('kernel.event_listener', ['event' => 'workflow.sylius_payment.completed.complete', 'method' => 'onPaymentCompleted'])
        ->tag('kernel.event_listener', ['event' => 'workflow.sylius_payment.completed.fail', 'method' => 'onPaymentFailed'])
        ->tag('kernel.event_listener', ['event' => 'workflow.sylius_payment.completed.cancel', 'method' => 'onPaymentCancelled'])
        ->tag('kernel.event_listener', ['event' => 'workflow.sylius_order.completed.cancel', 'method' => 'onOrderCancelled'])
    ;

    $services
        ->set('tmonier_trade_credit.listener.payment_method_switch', PaymentMethodSwitchListener::class)
        ->args([
            service('tmonier_trade_credit.reserver'),
            service('tmonier_trade_credit.provider.payment_terms_policy'),
        ])
        ->tag('doctrine.event_listener', ['event' => 'onFlush'])
    ;

    $services
        ->set('tmonier_trade_credit.listener.reservation_exception', ReservationExceptionListener::class)
        ->args([
            service('router'),
            service('translator'),
        ])
        ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'priority' => 64])
    ;

    // Statement (shop page and API)

    $services
        ->set('tmonier_trade_credit.statement_builder', StatementBuilder::class)
        ->args([
            service('tmonier_trade_credit.provider.account'),
            service('tmonier_trade_credit.repository.order_payment_terms'),
            service('clock'),
            service('router'),
            service('sylius_invoicing.repository.invoice')->nullOnInvalid(),
        ])
    ;

    $services
        ->set('tmonier_trade_credit.controller.shop.statement', StatementController::class)
        ->args([
            service('tmonier_trade_credit.statement_builder'),
            service('security.helper'),
            service('twig'),
            service('router'),
        ])
        ->public()
        ->tag('controller.service_arguments')
    ;

    $services
        ->set('tmonier_trade_credit.api.statement_provider', TradeCreditStatementProvider::class)
        ->args([
            service('tmonier_trade_credit.statement_builder'),
            service('security.helper'),
        ])
        ->tag('api_platform.state_provider')
    ;

    $services
        ->set('tmonier_trade_credit.listener.account_menu', AccountMenuListener::class)
        ->tag('kernel.event_listener', ['event' => 'sylius.menu.shop.account'])
    ;

    // Admin

    $services
        ->set('tmonier_trade_credit.form.type.account', TradeCreditAccountType::class)
        ->args([
            param('tmonier_trade_credit.model.account.class'),
            service('sylius.repository.currency'),
            ['sylius'],
        ])
        ->tag('form.type')
    ;

    $services
        ->set('tmonier_trade_credit.form.type.payment_terms_policy', PaymentTermsPolicyType::class)
        ->args([
            param('tmonier_trade_credit.model.payment_terms_policy.class'),
            param('sylius.model.payment_method.class'),
            ['sylius'],
        ])
        ->tag('form.type')
    ;

    $services
        ->set('tmonier_trade_credit.controller.admin.mark_as_paid', MarkAsPaidController::class)
        ->args([
            service('tmonier_trade_credit.repository.order_payment_terms'),
            service('sylius_abstraction.state_machine'),
            service('doctrine.orm.entity_manager'),
            service('security.csrf.token_manager'),
            service('router'),
        ])
        ->public()
        ->tag('controller.service_arguments')
    ;

    $services
        ->set('tmonier_trade_credit.grid_filter.overdue', OverdueFilter::class)
        ->args([service('clock')])
        ->tag('sylius.grid_filter', ['type' => 'tmonier_trade_credit_overdue', 'form_type' => BooleanFilterType::class])
    ;

    $services
        ->set('tmonier_trade_credit.listener.admin_menu', AdminMenuListener::class)
        ->tag('kernel.event_listener', ['event' => 'sylius.menu.admin.main'])
    ;

    $services
        ->set('tmonier_trade_credit.twig.extension', TradeCreditExtension::class)
        ->args([
            service('tmonier_trade_credit.provider.account'),
            service('tmonier_trade_credit.repository.order_payment_terms'),
            service('tmonier_trade_credit.checker.legal_terms'),
            service('clock'),
            service('sylius.repository.customer'),
        ])
        ->tag('twig.extension')
    ;

    $services
        ->set('tmonier_trade_credit.command.recalculate', RecalculateCommand::class)
        ->args([
            service('tmonier_trade_credit.repository.account'),
            service('tmonier_trade_credit.repository.order_payment_terms'),
            service('tmonier_trade_credit.reserver'),
        ])
        ->tag('console.command')
    ;

    if (class_exists(\Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture::class)) {
        $services
            ->set('tmonier_trade_credit.fixture.trade_credit', TradeCreditFixture::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service('sylius.factory.payment_method'),
                service('tmonier_trade_credit.factory.payment_terms_policy'),
                service('tmonier_trade_credit.factory.account'),
                service('sylius.factory.customer'),
                service('sylius.factory.shop_user'),
                service('sylius.factory.order'),
                service('sylius.factory.order_item'),
                service('sylius.factory.address'),
                service('sylius.modifier.order_item_quantity'),
                service('sylius.modifier.order'),
                service('sylius_abstraction.state_machine'),
                param('tmonier_trade_credit.model.order_payment_terms.class'),
            ])
            ->tag('sylius_fixtures.fixture')
        ;
    }
};
