<?php

namespace App\Domain\Shared;

use App\Models\NumberSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Issues consecutive document numbers (bills, returns, receipts).
 *
 * Call it inside the same database transaction that saves the document, so
 * a failed save rolls the number back and no gaps or duplicates appear.
 * Format: {prefix}{financial year}/{6 digits}, e.g. "A26-27/000123" — within
 * the 16-character limit for GST invoice numbers.
 */
final class NumberSequencer
{
    public const MAX_LENGTH = 16;

    public function next(int $storeId, string $docType, string $prefix = '', ?CarbonInterface $at = null): string
    {
        $fy = FinancialYear::codeFor($at);

        return DB::transaction(function () use ($storeId, $docType, $prefix, $fy) {
            $keys = [
                'store_id' => $storeId,
                'doc_type' => $docType,
                'prefix' => $prefix,
                'financial_year' => $fy,
            ];

            NumberSequence::firstOrCreate($keys, ['next_number' => 1]);

            // Row lock on MySQL; SQLite allows only one writer at a time anyway.
            $sequence = NumberSequence::where($keys)->lockForUpdate()->firstOrFail();
            $number = $sequence->next_number;
            $sequence->update(['next_number' => $number + 1]);

            $formatted = sprintf('%s%s/%06d', $prefix, $fy, $number);
            if (strlen($formatted) > self::MAX_LENGTH) {
                throw new LogicException("Document number {$formatted} is longer than ".self::MAX_LENGTH.' characters');
            }

            return $formatted;
        });
    }
}
