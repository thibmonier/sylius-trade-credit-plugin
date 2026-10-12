<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Unit\Credit;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tmonier\SyliusTradeCreditPlugin\Credit\CreditPositionResolver;
use Tmonier\SyliusTradeCreditPlugin\Credit\ExternalCreditPosition;
use Tmonier\SyliusTradeCreditPlugin\Credit\ExternalCreditProviderInterface;
use Tmonier\SyliusTradeCreditPlugin\Credit\ExternalCreditUnavailableException;
use Tmonier\SyliusTradeCreditPlugin\Credit\InternalCreditProvider;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccount;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;

final class CreditPositionResolverTest extends TestCase
{
    public function testInternalByDefault(): void
    {
        $position = (new CreditPositionResolver(new InternalCreditProvider(), new NullLogger()))->resolve($this->account());

        self::assertFalse($position->external);
        self::assertSame(10000, $position->creditLimit);
        self::assertSame(3000, $position->committedAmount);
        self::assertSame(7000, $position->getAvailableAmount());
    }

    public function testTheExternalLimitAndCommittedAmountCount(): void
    {
        $resolver = new CreditPositionResolver($this->provider(new ExternalCreditPosition(8000, 2500)), new NullLogger());

        $position = $resolver->resolve($this->account());

        self::assertTrue($position->external);
        self::assertTrue($position->externalLimit);
        self::assertSame(8000 - 3000 - 2500, $position->getAvailableAmount());

        $withoutLimit = (new CreditPositionResolver($this->provider(new ExternalCreditPosition(null, 500)), new NullLogger()))->resolve($this->account());
        self::assertFalse($withoutLimit->externalLimit);
        self::assertSame(10000 - 3000 - 500, $withoutLimit->getAvailableAmount());
    }

    public function testAFailingProviderMeansUnknownPositionButTheStatementStillShowsTheSyliusFigures(): void
    {
        $resolver = new CreditPositionResolver($this->provider(new \RuntimeException('ERP timeout')), new NullLogger());

        self::assertSame(7000, $resolver->resolveForDisplay($this->account())->getAvailableAmount());
        $this->expectException(ExternalCreditUnavailableException::class);
        $resolver->resolve($this->account());
    }

    public function testANegativeExternalAmountIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalCreditPosition(null, -1);
    }

    private function account(): TradeCreditAccount
    {
        $account = new TradeCreditAccount();
        $account->setCreditLimit(10000);
        // Only the reserver changes it (in SQL).
        (new \ReflectionProperty(TradeCreditAccount::class, 'committedAmount'))->setValue($account, 3000);

        return $account;
    }

    private function provider(ExternalCreditPosition|\Throwable $result): ExternalCreditProviderInterface
    {
        return new class($result) implements ExternalCreditProviderInterface {
            public function __construct(private readonly ExternalCreditPosition|\Throwable $result)
            {
            }

            public function getPosition(TradeCreditAccountInterface $account): ExternalCreditPosition
            {
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
    }
}
