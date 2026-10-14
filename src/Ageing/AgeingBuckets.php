<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Ageing;

/**
 * Ageing of the open items (amounts in minor units) by days past their due date: not due yet
 * (`current`), 1-30, 31-60, 61-90 and over 90 days.
 */
final readonly class AgeingBuckets
{
    public const BUCKETS = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_over_90'];

    public function __construct(
        public int $current = 0,
        public int $days1To30 = 0,
        public int $days31To60 = 0,
        public int $days61To90 = 0,
        public int $daysOver90 = 0,
    ) {
    }

    /** @param iterable<array{amount: int, dueDate: \DateTimeInterface}> $items */
    public static function fromItems(iterable $items, \DateTimeInterface $today): self
    {
        $amounts = array_fill_keys(self::BUCKETS, 0);
        foreach ($items as $item) {
            $amounts[self::bucket(self::daysPastDue($item['dueDate'], $today))] += $item['amount'];
        }

        return self::fromArray($amounts);
    }

    /** Days between the due date and today (0 or less: not due yet). */
    public static function daysPastDue(\DateTimeInterface $dueDate, \DateTimeInterface $today): int
    {
        $due = new \DateTimeImmutable($dueDate->format('Y-m-d'));
        $day = new \DateTimeImmutable($today->format('Y-m-d'));
        $days = (int) $due->diff($day)->days;

        return $day > $due ? $days : -$days;
    }

    public static function bucket(int $daysPastDue): string
    {
        return match (true) {
            $daysPastDue <= 0 => 'current',
            $daysPastDue <= 30 => 'days_1_30',
            $daysPastDue <= 60 => 'days_31_60',
            $daysPastDue <= 90 => 'days_61_90',
            default => 'days_over_90',
        };
    }

    /** @param array<string, int> $amounts keyed by bucket name */
    public static function fromArray(array $amounts): self
    {
        return new self(
            $amounts['current'] ?? 0,
            $amounts['days_1_30'] ?? 0,
            $amounts['days_31_60'] ?? 0,
            $amounts['days_61_90'] ?? 0,
            $amounts['days_over_90'] ?? 0,
        );
    }

    /** @return array{current: int, days_1_30: int, days_31_60: int, days_61_90: int, days_over_90: int} */
    public function toArray(): array
    {
        return [
            'current' => $this->current,
            'days_1_30' => $this->days1To30,
            'days_31_60' => $this->days31To60,
            'days_61_90' => $this->days61To90,
            'days_over_90' => $this->daysOver90,
        ];
    }

    public function getTotal(): int
    {
        return array_sum($this->toArray());
    }

    public function getOverdue(): int
    {
        return $this->getTotal() - $this->current;
    }
}
