<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTradeCreditPlugin\Unit\Import;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusTradeCreditPlugin\Import\PaymentCsvReader;
use Tmonier\SyliusTradeCreditPlugin\Import\PaymentLine;

final class PaymentCsvReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testCommaSeparatedWithCurrencyAndExtraColumns(): void
    {
        $read = (new PaymentCsvReader())->read($this->file("reference,order_number,amount,currency\nVIR-1,000000021,13.12,eur\nVIR-2,#000000022,\"1,234.50\",\n\n"));

        self::assertSame([], $read['errors']);
        self::assertEquals([
            new PaymentLine(2, '000000021', 1312, 'EUR'),
            new PaymentLine(3, '000000022', 123450),
        ], $read['lines']);
    }

    public function testSemicolonSeparatedWithDecimalCommaAndBom(): void
    {
        $read = (new PaymentCsvReader())->read($this->file("\u{FEFF}Order_Number;Amount\n000000021;1 312,5\n000000022;7\n"));

        self::assertSame([], $read['errors']);
        self::assertSame([131250, 700], array_map(static fn (PaymentLine $line): int => $line->amount, $read['lines']));
    }

    public function testInvalidLinesAreReportedWithTheirNumber(): void
    {
        $read = (new PaymentCsvReader())->read($this->file("order_number;amount\n000000021;12.345\n;10\n000000023;-5\n000000024;0\n000000025;abc\n000000026;10.10\n"));

        self::assertSame([2, 3, 4, 5, 6], array_keys($read['errors']));
        self::assertSame('missing order number', $read['errors'][3]);
        self::assertCount(1, $read['lines']);
        self::assertSame(1010, $read['lines'][0]->amount);
    }

    public function testTheHeaderIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('order_number');

        (new PaymentCsvReader())->read($this->file("000000021;13.12\n"));
    }

    private function file(string $content): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'tmonier_tc_csv');
        file_put_contents($file, $content);
        $this->files[] = $file;

        return $file;
    }
}
