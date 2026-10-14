<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\OrderHold;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderCancellationExclusionInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderHold;
use Tmonier\SyliusTradeCreditPlugin\Entity\OrderPaymentTermsInterface;
use Tmonier\SyliusTradeCreditPlugin\Reservation\TradeCreditReserver;

/**
 * An order on account that lost its reservation (payment failed or cancelled, switched to
 * another method) becomes a regular unpaid order: the unpaid-orders expiration period then runs
 * from that release, not from the checkout completed weeks ago.
 */
final readonly class LostReservationCancellationExclusion implements UnpaidOrderCancellationExclusionInterface
{
    /** @param class-string<OrderPaymentTermsInterface> $termsClass */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $termsClass,
    ) {
    }

    public function getHold(OrderInterface $order): ?UnpaidOrderHold
    {
        if (null === $order->getId()) {
            return null;
        }

        $closedAt = $this->entityManager->getConnection()->fetchOne(
            sprintf(
                'SELECT MAX(closed_at) FROM %s WHERE order_id = ? AND state = ? AND closing_reason IN (?, ?, ?)',
                $this->entityManager->getClassMetadata($this->termsClass)->getTableName(),
            ),
            [$order->getId(), OrderPaymentTermsInterface::STATE_RELEASED, ...TradeCreditReserver::LOST_RESERVATION_REASONS],
        );

        return is_string($closedAt) && '' !== $closedAt ? UnpaidOrderHold::since(new \DateTimeImmutable($closedAt), 'tmonier_trade_credit') : null;
    }
}
