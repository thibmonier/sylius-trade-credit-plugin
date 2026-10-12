<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Unit\FacturX;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\InvoicingPlugin\Entity\InvoiceInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Tmonier\SyliusFacturXPlugin\Entity\ChannelSettings;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTerms;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\FacturX\TradeCreditInvoicePaymentTermsProvider;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

final class TradeCreditInvoicePaymentTermsProviderTest extends TestCase
{
    public function testTermsOfAnOrderPaidOnAccount(): void
    {
        $provider = new TradeCreditInvoicePaymentTermsProvider($this->repository($this->terms(45, 'days_then_end_of_month', '2026-12-31')), $this->translator());

        $terms = $provider->provide($this->invoice('fr_FR'), new ChannelSettings(), 120000);

        self::assertNotNull($terms);
        self::assertSame('2026-12-31', $terms->dueDate?->format('Y-m-d'));
        self::assertSame(
            'Paiement à terme : 45 jours fin de mois, échéance le 2026-12-31. Pénalités de retard : trois fois le taux d\'intérêt légal. Indemnité forfaitaire pour frais de recouvrement en cas de retard : 40 EUR.',
            $terms->description,
        );
    }

    public function testTheChannelNotesAreNotRepeated(): void
    {
        $settings = new ChannelSettings();
        $settings->setLatePaymentPenaltiesNote('Penalties: 10 %.');
        $settings->setRecoveryIndemnityNote('Indemnity: 40 EUR.');
        $provider = new TradeCreditInvoicePaymentTermsProvider($this->repository($this->terms(30, 'none', '2026-11-11')), $this->translator());

        self::assertSame('Payment on account: 30 days net, due 2026-11-11.', $provider->provide($this->invoice('en_US'), $settings, 100)?->description);
    }

    public function testOtherInvoicesAreLeftToTheNextProvider(): void
    {
        $provider = new TradeCreditInvoicePaymentTermsProvider($this->repository(null), $this->translator());

        self::assertNull($provider->provide($this->invoice('en_US'), new ChannelSettings(), 100));
    }

    private function terms(int $netDays, string $mode, string $dueDate): OrderPaymentTermsInterface
    {
        $terms = new OrderPaymentTerms();
        foreach (['netDays' => $netDays, 'endOfMonthMode' => $mode, 'dueDate' => new \DateTimeImmutable($dueDate)] as $property => $value) {
            (new \ReflectionProperty(OrderPaymentTerms::class, $property))->setValue($terms, $value);
        }

        return $terms;
    }

    private function repository(?OrderPaymentTermsInterface $terms): OrderPaymentTermsRepositoryInterface
    {
        $repository = $this->createMock(OrderPaymentTermsRepositoryInterface::class);
        $repository->method('findLatestForOrder')->willReturn($terms);

        return $repository;
    }

    private function invoice(string $localeCode): InvoiceInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getId')->willReturn(7);
        $invoice = $this->createMock(InvoiceInterface::class);
        $invoice->method('order')->willReturn($order);
        $invoice->method('localeCode')->willReturn($localeCode);

        return $invoice;
    }

    private function translator(): Translator
    {
        $translator = new Translator('en_US');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['en' => 'en_US', 'fr' => 'fr_FR'] as $file => $locale) {
            $translator->addResource('yaml', dirname(__DIR__, 3) . sprintf('/translations/messages.%s.yaml', $file), $locale);
        }

        return $translator;
    }
}
