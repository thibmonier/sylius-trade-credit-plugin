<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Import;

/** Result of a payments file: per line, the open item to settle, "already paid" or an error. */
final class PaymentImportResult
{
    public const TO_SETTLE = 'to_settle';

    public const SETTLED = 'settled';

    public const ALREADY_PAID = 'already_paid';

    public const ERROR = 'error';

    /** @var array<int, array{orderNumber: string, amount: int|null, status: string, message: string|null}> */
    private array $lines = [];

    public function add(int $lineNumber, string $orderNumber, ?int $amount, string $status, ?string $message = null): void
    {
        $this->lines[$lineNumber] = ['orderNumber' => $orderNumber, 'amount' => $amount, 'status' => $status, 'message' => $message];
        ksort($this->lines);
    }

    /** @return array<int, array{orderNumber: string, amount: int|null, status: string, message: string|null}> */
    public function getLines(): array
    {
        return $this->lines;
    }

    public function hasErrors(): bool
    {
        return [] !== $this->linesWith(self::ERROR);
    }

    /** @return array<int, array{orderNumber: string, amount: int|null, status: string, message: string|null}> */
    public function linesWith(string $status): array
    {
        return array_filter($this->lines, static fn (array $line): bool => $status === $line['status']);
    }

    public function markSettled(): void
    {
        foreach ($this->lines as $lineNumber => $line) {
            if (self::TO_SETTLE === $line['status']) {
                $this->lines[$lineNumber]['status'] = self::SETTLED;
            }
        }
    }
}
