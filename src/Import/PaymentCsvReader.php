<?php

declare(strict_types=1);

namespace Tmonier\SyliusTradeCreditPlugin\Import;

/**
 * Reads a payments CSV file: a header line with `order_number` and `amount` (optional
 * `currency`; other columns are ignored), separated by `,`, `;` or tabs. Amounts are decimals
 * with at most two decimals (`1234.56`, `1 234,56`), converted to minor units.
 */
final class PaymentCsvReader
{
    /**
     * @return array{lines: list<PaymentLine>, errors: array<int, string>} errors keyed by line number
     */
    public function read(string $path): array
    {
        $handle = is_readable($path) ? fopen($path, 'r') : false;
        if (false === $handle) {
            throw new \InvalidArgumentException(sprintf('Cannot read "%s".', $path));
        }

        try {
            $header = fgets($handle);
            if (false === $header) {
                throw new \InvalidArgumentException('The file is empty.');
            }
            $header = (string) preg_replace('/^\xEF\xBB\xBF/', '', $header);
            $delimiter = $this->delimiter($header);
            $columns = array_map(static fn (?string $column): string => strtolower(trim((string) $column)), str_getcsv(trim($header), $delimiter, '"', ''));
            $orderColumn = array_search('order_number', $columns, true);
            $amountColumn = array_search('amount', $columns, true);
            $currencyColumn = array_search('currency', $columns, true);
            if (false === $orderColumn || false === $amountColumn) {
                throw new \InvalidArgumentException('The header must contain the "order_number" and "amount" columns.');
            }

            $lines = [];
            $errors = [];
            $lineNumber = 1;
            while (false !== ($row = fgetcsv($handle, null, $delimiter, '"', ''))) {
                ++$lineNumber;
                if ([null] === $row || [] === array_filter($row, static fn (?string $value): bool => '' !== trim((string) $value))) {
                    continue;
                }
                $orderNumber = ltrim(trim($row[$orderColumn] ?? ''), '#');
                $amount = $this->amount($row[$amountColumn] ?? '');
                $currency = false === $currencyColumn ? '' : strtoupper(trim($row[$currencyColumn] ?? ''));
                if ('' === $orderNumber) {
                    $errors[$lineNumber] = 'missing order number';
                } elseif (null === $amount) {
                    $errors[$lineNumber] = sprintf('invalid amount "%s"', $row[$amountColumn] ?? '');
                } else {
                    $lines[] = new PaymentLine($lineNumber, $orderNumber, $amount, '' === $currency ? null : $currency);
                }
            }

            return ['lines' => $lines, 'errors' => $errors];
        } finally {
            fclose($handle);
        }
    }

    private function delimiter(string $header): string
    {
        $counts = [';' => substr_count($header, ';'), ',' => substr_count($header, ','), "\t" => substr_count($header, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /** Minor units, or null when the amount is not a positive decimal with at most two decimals. */
    private function amount(string $value): ?int
    {
        $value = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($value));
        if (str_contains($value, ',') && !str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            // 1,234.56: thousands separator.
            $value = str_replace(',', '', $value);
        }
        if (1 !== preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            return null;
        }
        $minor = (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '0', 2, '0');

        return $minor > 0 ? $minor : null;
    }
}
