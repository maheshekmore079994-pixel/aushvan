<?php

namespace App\Actions;

use App\Domain\Inventory\StockLedger;
use App\Domain\Shared\Audit;
use App\Domain\Shared\ShopClock;
use App\Exceptions\BusinessRuleException;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manual stock corrections. Every one needs a reason and leaves a ledger
 * entry with the user's name; stock is never simply overwritten.
 */
final class AdjustStock
{
    /** Take stock off sale but keep it in the shop (expired, damaged, waiting for supplier return). */
    public const MARK_UNSELLABLE = 'mark_unsellable';

    public const WRITE_OFF_DAMAGE = 'write_off_damage';

    public const WRITE_OFF_EXPIRY = 'write_off_expiry';

    /** Counted quantity differs from the system: set sellable stock to what was counted. */
    public const COUNT_CORRECTION = 'count_correction';

    public const ACTIONS = [self::MARK_UNSELLABLE, self::WRITE_OFF_DAMAGE, self::WRITE_OFF_EXPIRY, self::COUNT_CORRECTION];

    public function __construct(private StockLedger $ledger) {}

    /**
     * @param  int  $qtyBase  quantity in base units; for a count correction, the counted quantity
     * @param  string  $bucket  which stock a write-off comes from: sellable or unsellable
     */
    public function handle(User $user, StockBatch $batch, string $action, int $qtyBase, string $bucket, string $note): StockAdjustment
    {
        return DB::transaction(function () use ($user, $batch, $action, $qtyBase, $bucket, $note) {
            // Locked so a sale cannot land between reading the stock and correcting it.
            $batch->setRawAttributes(StockBatch::lockForUpdate()->findOrFail($batch->id)->getAttributes(), true);

            $adjustment = StockAdjustment::create([
                'ulid' => (string) Str::ulid(),
                'store_id' => $batch->store_id,
                'reason' => $action,
                'adjust_date' => ShopClock::todayString(),
                'note' => $note,
                'user_id' => $user->id,
            ]);

            $before = ['sellable' => $batch->qty_sellable, 'unsellable' => $batch->qty_unsellable];

            switch ($action) {
                case self::MARK_UNSELLABLE:
                    $this->ledger->record($batch, StockLedger::SELLABLE, -$qtyBase, 'bucket_transfer', $adjustment, $user->id, $note);
                    $this->ledger->record($batch, StockLedger::UNSELLABLE, $qtyBase, 'bucket_transfer', $adjustment, $user->id, $note);
                    break;

                case self::WRITE_OFF_DAMAGE:
                    $this->ledger->record($batch, $bucket, -$qtyBase, 'damage', $adjustment, $user->id, $note);
                    break;

                case self::WRITE_OFF_EXPIRY:
                    $this->ledger->record($batch, $bucket, -$qtyBase, 'expiry', $adjustment, $user->id, $note);
                    break;

                case self::COUNT_CORRECTION:
                    $difference = $qtyBase - $batch->qty_sellable;
                    if ($difference === 0) {
                        throw new BusinessRuleException('no_change', 'The counted quantity is the same as the stock in the system.');
                    }
                    if ($difference > 0 && $batch->isExpired()) {
                        throw new BusinessRuleException('already_expired', 'Expired stock cannot be added back for sale.');
                    }
                    $this->ledger->record($batch, StockLedger::SELLABLE, $difference, 'adjustment', $adjustment, $user->id, $note);
                    break;
            }

            $batch->refresh();
            Audit::log('stock.adjusted', $batch, $before,
                ['sellable' => $batch->qty_sellable, 'unsellable' => $batch->qty_unsellable, 'action' => $action], $note);

            return $adjustment;
        });
    }
}
