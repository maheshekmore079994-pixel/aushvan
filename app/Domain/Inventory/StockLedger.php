<?php

namespace App\Domain\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\InventoryMovement;
use App\Models\StockBatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The only code allowed to change stock. Each call writes one ledger row
 * and moves the batch's cached quantity by the same amount, inside the
 * caller's transaction, and refuses to take stock below zero.
 */
final class StockLedger
{
    public const SELLABLE = 'sellable';

    public const UNSELLABLE = 'unsellable';

    public function record(
        StockBatch $batch,
        string $bucket,
        int $qtyDelta,
        string $type,
        Model $source,
        int $userId,
        ?string $note = null,
    ): InventoryMovement {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Stock may only be changed inside a database transaction.');
        }
        if (! in_array($bucket, [self::SELLABLE, self::UNSELLABLE], true)) {
            throw new LogicException("Unknown stock bucket: {$bucket}");
        }
        if ($qtyDelta === 0) {
            throw new LogicException('A stock movement cannot be zero.');
        }

        $column = 'qty_'.$bucket;
        $query = StockBatch::whereKey($batch->id);

        // One atomic statement: the row changes only if enough stock is there.
        $changed = $qtyDelta > 0
            ? $query->increment($column, $qtyDelta)
            : $query->where($column, '>=', -$qtyDelta)->decrement($column, -$qtyDelta);

        if ($changed === 0) {
            throw new BusinessRuleException(
                'insufficient_stock',
                'Not enough stock in this batch.',
                ['batch_id' => $batch->id, 'available' => (int) StockBatch::whereKey($batch->id)->value($column)],
            );
        }

        $batch->refresh();

        return InventoryMovement::create([
            'ulid' => (string) Str::ulid(),
            'store_id' => $batch->store_id,
            'product_id' => $batch->product_id,
            'batch_id' => $batch->id,
            'bucket' => $bucket,
            'qty_delta' => $qtyDelta,
            'type' => $type,
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'cost_rate' => $batch->cost_rate,
            'balance_after' => $batch->{$column},
            'user_id' => $userId,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    /**
     * Batches whose cached quantity disagrees with the ledger. Empty = healthy.
     *
     * @return list<array{batch_id: int, bucket: string, cached: int, ledger: int}>
     */
    public function findMismatches(?int $storeId = null): array
    {
        $ledger = DB::table('inventory_movements')
            ->selectRaw('batch_id, bucket, SUM(qty_delta) as total')
            ->groupBy('batch_id', 'bucket')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->batch_id.'|'.$row->bucket => (int) $row->total]);

        $problems = [];
        StockBatch::query()->when($storeId, fn ($q, $id) => $q->where('store_id', $id))->select(['id', 'qty_sellable', 'qty_unsellable'])
            ->chunkById(500, function ($batches) use ($ledger, &$problems) {
                foreach ($batches as $batch) {
                    foreach ([self::SELLABLE, self::UNSELLABLE] as $bucket) {
                        $cached = $batch->{'qty_'.$bucket};
                        $total = $ledger[$batch->id.'|'.$bucket] ?? 0;
                        if ($cached !== $total) {
                            $problems[] = ['batch_id' => $batch->id, 'bucket' => $bucket, 'cached' => $cached, 'ledger' => $total];
                        }
                    }
                }
            });

        return $problems;
    }
}
