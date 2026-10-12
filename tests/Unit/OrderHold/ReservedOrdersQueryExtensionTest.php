<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Unit\OrderHold;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\HoldAwareUnpaidOrdersStateUpdater;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTerms;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccount;
use Tmonier\SyliusTradeCreditPlugin\Terms\EndOfMonthMode;
use Tmonier\SyliusTradeCreditPlugin\Terms\PaymentTerms;

final class ReservedOrdersQueryExtensionTest extends TestCase
{
    public function testItsParametersDoNotUseTheOrderHoldPrefix(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/OrderHold/ReservedOrdersQueryExtension.php');

        self::assertStringNotContainsString(HoldAwareUnpaidOrdersStateUpdater::PARAMETER_PREFIX, $source);
    }

    public function testAccountTermsOverrideThePolicyTerms(): void
    {
        $account = new TradeCreditAccount();
        $policyTerms = new PaymentTerms(30);

        self::assertSame($policyTerms, $account->getTerms($policyTerms));

        $account->setNetDays(45);
        $account->setEndOfMonthMode(EndOfMonthMode::DaysThenEndOfMonth);
        $terms = $account->getTerms($policyTerms);
        self::assertSame(45, $terms->netDays);
        self::assertSame(EndOfMonthMode::DaysThenEndOfMonth, $terms->endOfMonthMode);

        $account->setCreditLimit(1000);
        self::assertSame(1000, $account->getAvailableAmount());
        $account->setCreditLimit(-5);
        self::assertSame(0, $account->getCreditLimit());
    }

    public function testOverdueIsComparedByDay(): void
    {
        $terms = new OrderPaymentTerms();
        $reflection = new \ReflectionProperty(OrderPaymentTerms::class, 'dueDate');
        $reflection->setValue($terms, new \DateTimeImmutable('2026-10-12'));

        self::assertFalse($terms->isOverdue(new \DateTimeImmutable('2026-10-12 23:59')));
        self::assertTrue($terms->isOverdue(new \DateTimeImmutable('2026-10-13 00:01')));

        $state = new \ReflectionProperty(OrderPaymentTerms::class, 'state');
        $state->setValue($terms, OrderPaymentTerms::STATE_SETTLED);
        self::assertFalse($terms->isOverdue(new \DateTimeImmutable('2027-01-01')));
    }
}
