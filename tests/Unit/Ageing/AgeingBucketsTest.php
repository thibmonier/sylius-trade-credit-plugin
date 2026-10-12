<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Unit\Ageing;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusTradeCreditPlugin\Ageing\AgeingBuckets;

final class AgeingBucketsTest extends TestCase
{
    public function testDaysPastDue(): void
    {
        $today = new \DateTimeImmutable('2026-03-01 15:30');

        self::assertSame(0, AgeingBuckets::daysPastDue(new \DateTimeImmutable('2026-03-01'), $today));
        self::assertSame(1, AgeingBuckets::daysPastDue(new \DateTimeImmutable('2026-02-28'), $today));
        self::assertSame(-5, AgeingBuckets::daysPastDue(new \DateTimeImmutable('2026-03-06 23:00'), $today));
        // Across a leap day.
        self::assertSame(2, AgeingBuckets::daysPastDue(new \DateTimeImmutable('2028-02-28'), new \DateTimeImmutable('2028-03-01')));
    }

    public function testBucketBoundaries(): void
    {
        $expected = [-10 => 'current', 0 => 'current', 1 => 'days_1_30', 30 => 'days_1_30', 31 => 'days_31_60', 60 => 'days_31_60', 61 => 'days_61_90', 90 => 'days_61_90', 91 => 'days_over_90', 400 => 'days_over_90'];
        foreach ($expected as $days => $bucket) {
            self::assertSame($bucket, AgeingBuckets::bucket($days), (string) $days);
        }
    }

    public function testFromItems(): void
    {
        $today = new \DateTimeImmutable('2026-06-30');
        $ageing = AgeingBuckets::fromItems([
            ['amount' => 100, 'dueDate' => new \DateTimeImmutable('2026-07-15')],
            ['amount' => 200, 'dueDate' => new \DateTimeImmutable('2026-06-30')],
            ['amount' => 300, 'dueDate' => new \DateTimeImmutable('2026-06-29')],
            ['amount' => 400, 'dueDate' => new \DateTimeImmutable('2026-05-01')],
            ['amount' => 500, 'dueDate' => new \DateTimeImmutable('2026-04-01')],
            ['amount' => 600, 'dueDate' => new \DateTimeImmutable('2025-12-31')],
        ], $today);

        self::assertSame(['current' => 300, 'days_1_30' => 300, 'days_31_60' => 400, 'days_61_90' => 500, 'days_over_90' => 600], $ageing->toArray());
        self::assertSame(2100, $ageing->getTotal());
        self::assertSame(1800, $ageing->getOverdue());
    }
}
