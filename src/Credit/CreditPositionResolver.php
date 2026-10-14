<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Credit;

use Psr\Log\LoggerInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;

/** Position of an account: the external one (ERP) when there is one, else the Sylius figures. */
final readonly class CreditPositionResolver
{
    public function __construct(
        private ExternalCreditProviderInterface $externalCreditProvider,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws ExternalCreditUnavailableException */
    public function resolve(TradeCreditAccountInterface $account): CreditPosition
    {
        try {
            $external = $this->externalCreditProvider->getPosition($account);
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('External credit position of trade credit account #%s unavailable: %s', (string) $account->getId(), $exception->getMessage()), ['exception' => $exception]);

            throw new ExternalCreditUnavailableException('External credit position unavailable.', 0, $exception);
        }

        return $external instanceof \Tmonier\SyliusTradeCreditPlugin\Credit\ExternalCreditPosition
            ? new CreditPosition($external->creditLimit ?? $account->getCreditLimit(), $account->getCommittedAmount(), $external->externalCommittedAmount, true, null !== $external->creditLimit)
            : new CreditPosition($account->getCreditLimit(), $account->getCommittedAmount());
    }

    /** The Sylius figures when the external position is unavailable (display only, never to decide). */
    public function resolveForDisplay(TradeCreditAccountInterface $account): CreditPosition
    {
        try {
            return $this->resolve($account);
        } catch (ExternalCreditUnavailableException) {
            return new CreditPosition($account->getCreditLimit(), $account->getCommittedAmount());
        }
    }
}
