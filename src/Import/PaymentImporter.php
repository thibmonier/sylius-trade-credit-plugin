<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Import;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Payment\OpenItemSettler;
use Tmonier\SyliusTradeCreditPlugin\Repository\OrderPaymentTermsRepositoryInterface;

/**
 * Payments CSV import (bank transfers received): each line must match an open item exactly,
 * by order number and amount (and currency when given). All or nothing: a single error and no
 * line is settled. Idempotent: an order already paid is reported and skipped, never settled
 * twice, so the same file can be imported again.
 */
final readonly class PaymentImporter
{
    /** @param OrderRepositoryInterface<OrderInterface> $orderRepository */
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private OrderPaymentTermsRepositoryInterface $termsRepository,
        private OpenItemSettler $settler,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<PaymentLine> $lines
     * @param array<int, string> $readErrors errors of the reader, keyed by line number
     */
    public function import(array $lines, array $readErrors, bool $apply): PaymentImportResult
    {
        $result = new PaymentImportResult();
        foreach ($readErrors as $lineNumber => $message) {
            $result->add($lineNumber, '', null, PaymentImportResult::ERROR, $message);
        }

        /** @var array<string, OrderPaymentTermsInterface> $toSettle */
        $toSettle = [];
        $seen = [];
        foreach ($lines as $line) {
            if (isset($seen[$line->orderNumber])) {
                $result->add($line->lineNumber, $line->orderNumber, $line->amount, PaymentImportResult::ERROR, sprintf('order already on line %d', $seen[$line->orderNumber]));

                continue;
            }
            $seen[$line->orderNumber] = $line->lineNumber;

            [$status, $message, $terms] = $this->match($line);
            $result->add($line->lineNumber, $line->orderNumber, $line->amount, $status, $message);
            if (null !== $terms) {
                $toSettle[$line->orderNumber] = $terms;
            }
        }

        if (!$apply || $result->hasErrors() || [] === $toSettle) {
            return $result;
        }

        $this->entityManager->wrapInTransaction(function () use ($toSettle): void {
            foreach ($toSettle as $orderNumber => $terms) {
                // Locked and read again by the settler: a concurrent settlement completes it once.
                if (!$this->settler->settle($terms)) {
                    throw new \RuntimeException(sprintf('The open item of order #%s could not be settled.', $orderNumber));
                }
            }
            $this->entityManager->flush();
        });
        $result->markSettled();

        return $result;
    }

    /** @return array{0: string, 1: string|null, 2: OrderPaymentTermsInterface|null} */
    private function match(PaymentLine $line): array
    {
        $order = $this->orderRepository->findOneByNumber($line->orderNumber);
        if (!$order instanceof OrderInterface) {
            return [PaymentImportResult::ERROR, 'unknown order', null];
        }
        if (in_array($order->getPaymentState(), [OrderPaymentStates::STATE_PAID, OrderPaymentStates::STATE_PARTIALLY_REFUNDED, OrderPaymentStates::STATE_REFUNDED], true)) {
            return [PaymentImportResult::ALREADY_PAID, null, null];
        }

        $terms = $this->termsRepository->findReservedForOrder($order) ?? $this->termsRepository->findLastForOrder($order);
        if (!$terms instanceof \Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface || !$this->settler->payableBy($terms) instanceof \Sylius\Component\Core\Model\PaymentInterface) {
            return [PaymentImportResult::ERROR, 'not an open item', null];
        }
        if (null !== $line->currencyCode && $line->currencyCode !== $terms->getCurrencyCode()) {
            return [PaymentImportResult::ERROR, sprintf('currency %s, open item in %s', $line->currencyCode, $terms->getCurrencyCode()), null];
        }
        if ($line->amount !== $terms->getAmount()) {
            return [PaymentImportResult::ERROR, sprintf('amount %d, open item of %d (minor units)', $line->amount, $terms->getAmount()), null];
        }

        return [PaymentImportResult::TO_SETTLE, null, $terms];
    }
}
