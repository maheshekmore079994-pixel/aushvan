<?php

namespace App\Domain\Parties;

use App\Domain\Shared\ShopClock;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The only code allowed to change what the shop owes a supplier. Each call writes one ledger line and moves
 * the supplier's balance by the same amount, so the last line of the statement always equals the balance shown.
 */
final class SupplierLedger
{
    /** credit = the shop owes more (a purchase); debit = the shop owes less (a payment or a return). */
    public function post(
        Supplier $supplier,
        string $type,
        int $creditPaise,
        int $debitPaise,
        ?Model $source,
        int $userId,
        ?string $note = null,
        ?int $reversesId = null,
    ): SupplierLedgerEntry {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The supplier ledger may only be changed inside a database transaction.');
        }
        if ($creditPaise < 0 || $debitPaise < 0 || ($creditPaise === 0 && $debitPaise === 0)) {
            throw new LogicException('A ledger entry needs a positive credit or debit.');
        }

        $moved = Supplier::withTrashed()->whereKey($supplier->id)->increment('balance_paise', $creditPaise - $debitPaise);
        if ($moved !== 1) {
            throw new LogicException('Supplier balance could not be updated.');
        }
        if ($type === 'payment') {
            Supplier::withTrashed()->whereKey($supplier->id)->update(['last_payment_at' => now()]);
        }
        $supplier->refresh();

        return SupplierLedgerEntry::create([
            'ulid' => (string) Str::ulid(),
            'store_id' => $supplier->store_id,
            'supplier_id' => $supplier->id,
            'entry_date' => ShopClock::todayString(),
            'type' => $type,
            'credit_paise' => $creditPaise,
            'debit_paise' => $debitPaise,
            'balance_after_paise' => $supplier->balance_paise,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'reverses_id' => $reversesId,
            'note' => $note,
            'user_id' => $userId,
            'created_at' => now(),
        ]);
    }

    /**
     * Suppliers whose balance does not equal the total of their ledger. Empty = healthy.
     *
     * @return list<array{supplier_id: int, balance: int, ledger: int}>
     */
    public function findMismatches(): array
    {
        $totals = DB::table('supplier_ledger_entries')
            ->selectRaw('supplier_id, SUM(credit_paise) - SUM(debit_paise) as total')
            ->groupBy('supplier_id')
            ->pluck('total', 'supplier_id');

        $problems = [];
        foreach (Supplier::withTrashed()->get(['id', 'balance_paise']) as $supplier) {
            $ledger = (int) ($totals[$supplier->id] ?? 0);
            if ($ledger !== $supplier->balance_paise) {
                $problems[] = ['supplier_id' => $supplier->id, 'balance' => $supplier->balance_paise, 'ledger' => $ledger];
            }
        }

        return $problems;
    }
}
