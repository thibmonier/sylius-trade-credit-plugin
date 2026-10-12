<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Credit;

use Tmonier\SyliusTradeCreditPlugin\Entity\TradeCreditAccountInterface;

/**
 * Credit position of an account kept in an external system (ERP, accounting): its credit limit
 * and the amount the customer owes outside the shop. Payment on account then uses
 * `limit − committed in Sylius − committed outside` as the available credit.
 *
 * Alias this interface to your implementation. The default one returns null (account managed
 * in Sylius only). An exception means "position unknown": payment on account is refused while
 * the external system cannot be reached (fail closed), the statement shows the Sylius figures.
 */
interface ExternalCreditProviderInterface
{
    /** Null: no external position for this account (Sylius figures only). */
    public function getPosition(TradeCreditAccountInterface $account): ?ExternalCreditPosition;
}
