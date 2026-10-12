<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\FacturX;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\InvoicingPlugin\Entity\InvoiceInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusFacturXPlugin\Entity\ChannelSettingsInterface;
use Tmonier\SyliusFacturXPlugin\PaymentTerms\InvoicePaymentTerms;
use Tmonier\SyliusFacturXPlugin\PaymentTerms\InvoicePaymentTermsProviderInterface;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Terms\EndOfMonthMode;

/**
 * Factur-X (tmonier/sylius-facturx-plugin 1.1+): due date (BT-9) and payment terms (BT-20) of an
 * order paid on account, with the late payment penalties and the 40 EUR fixed recovery
 * indemnity mentions required between professionals in France (L441-10). Other invoices are
 * left to the next provider (channel settings).
 */
final class TradeCreditInvoicePaymentTermsProvider implements InvoicePaymentTermsProviderInterface
{
    public function __construct(
        private readonly OrderPaymentTermsRepositoryInterface $termsRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function provide(InvoiceInterface $invoice, ChannelSettingsInterface $settings, int $duePayableAmount): ?InvoicePaymentTerms
    {
        $order = $invoice->order();
        if (!$order instanceof OrderInterface || null === $order->getId()) {
            return null;
        }

        $terms = $this->termsRepository->findLatestForOrder($order);
        if (null === $terms) {
            return null;
        }

        $locale = $invoice->localeCode();
        $parameters = ['%days%' => $terms->getNetDays(), '%due_date%' => $terms->getDueDate()->format('Y-m-d')];
        $termsText = $this->translator->trans(match ($terms->getEndOfMonthMode()) {
            EndOfMonthMode::None => 'tmonier_trade_credit.invoice.terms.net',
            EndOfMonthMode::DaysThenEndOfMonth => 'tmonier_trade_credit.invoice.terms.days_then_end_of_month',
            EndOfMonthMode::EndOfMonthThenDays => 'tmonier_trade_credit.invoice.terms.end_of_month_then_days',
        }, $parameters, 'messages', $locale);

        $description = implode(' ', array_filter([
            $termsText,
            // The channel notes are already written as BT-22 notes: the mentions are only added here when missing.
            null === $settings->getLatePaymentPenaltiesNote() ? $this->translator->trans('tmonier_trade_credit.invoice.late_payment_penalties', [], 'messages', $locale) : null,
            null === $settings->getRecoveryIndemnityNote() ? $this->translator->trans('tmonier_trade_credit.invoice.recovery_indemnity', [], 'messages', $locale) : null,
        ]));

        return new InvoicePaymentTerms($terms->getDueDate(), $description);
    }
}
