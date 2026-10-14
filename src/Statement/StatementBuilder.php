<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Statement;

use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusTradeCreditPlugin\Account\TradeCreditAccountProvider;
use Tmonier\SyliusTradeCreditPlugin\Credit\CreditPositionResolver;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/**
 * Statement of the account of a customer: limit, committed, available (with the external
 * position when there is one), open items and their ageing. Invoice PDF links are added when
 * sylius/invoicing-plugin is installed and enabled.
 */
final readonly class StatementBuilder
{
    public function __construct(
        private TradeCreditAccountProvider $accountProvider,
        private OrderPaymentTermsRepositoryInterface $termsRepository,
        private ClockInterface $clock,
        private UrlGeneratorInterface $urlGenerator,
        private CreditPositionResolver $positionResolver,
        private ?object $invoiceRepository = null,
    ) {
    }

    public function forCustomer(CustomerInterface $customer): ?Statement
    {
        $account = $this->accountProvider->forCustomer($customer);
        if (!$account instanceof \Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface) {
            return null;
        }

        $today = $this->clock->now();
        $items = [];
        // Open items, then orders that left payment on account and are unpaid after their due date.
        $leftUnpaid = $this->termsRepository->findLeftUnpaidOverdueByAccount($account, $today);
        foreach ([...$this->termsRepository->findOpenByAccount($account), ...$leftUnpaid] as $terms) {
            $order = $terms->getOrder();
            if (!$order instanceof OrderInterface) {
                continue;
            }
            $placedByViewer = $order->getCustomer() === $customer;
            $items[] = new StatementItem(
                $terms,
                (string) $order->getNumber(),
                $order->getTokenValue(),
                $terms->getAmount(),
                \DateTimeImmutable::createFromInterface($order->getCheckoutCompletedAt() ?? $terms->getCreatedAt()),
                $terms->getDueDate(),
                $terms->isOverdue($today) || in_array($terms, $leftUnpaid, true),
                $placedByViewer ? $this->invoiceUrl($order) : null,
                $placedByViewer,
                $placedByViewer ? null : $order->getCustomer()?->getFullName(),
            );
        }

        return new Statement($account, $items, $this->positionResolver->resolveForDisplay($account), $today);
    }

    private function invoiceUrl(OrderInterface $order): ?string
    {
        if (null === $this->invoiceRepository || !method_exists($this->invoiceRepository, 'findOneByOrder')) {
            return null;
        }

        $invoice = $this->invoiceRepository->findOneByOrder($order);
        if (!is_object($invoice) || !method_exists($invoice, 'id')) {
            return null;
        }

        try {
            return $this->urlGenerator->generate('sylius_invoicing_shop_invoice_download', ['id' => $invoice->id()]);
        } catch (RoutingException) {
            return null;
        }
    }
}
