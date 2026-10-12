<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Exception;

/**
 * The credit could not be reserved (limit reached, hold, no account) when the order was
 * completed or switched to payment on account. Mapped to a redirect with a flash message in the
 * shop and to a 422 response in the API, never a server error.
 */
final class TradeCreditReservationException extends \RuntimeException
{
    public function __construct(
        private readonly string $translationKey,
        private readonly ?string $orderTokenValue = null,
    ) {
        parent::__construct(sprintf('Trade credit cannot be reserved (%s).', $translationKey));
    }

    /** Key in the `flashes` and `validators` domains. */
    public function getTranslationKey(): string
    {
        return $this->translationKey;
    }

    public function getOrderTokenValue(): ?string
    {
        return $this->orderTokenValue;
    }
}
