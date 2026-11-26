<?php

namespace App\Domain\Cash;

use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The cash drawer's arithmetic. Every payment belongs to exactly one cash day: a payment made while a day is
 * open is stamped with it inside the payment's own transaction (Payment::creating, which also locks the day's
 * row so a closing happening at the same moment waits); a payment made while no day is open (after last
 * night's count) is adopted by the next day that opens or reopens, because that is the drawer the money is in.
 * Expected cash = opening float + cash in − cash out over the day's payments and cash movements.
 */
final class DayBook
{
    /** The open day of a shop, if any. */
    public function open(int $storeId): ?CashSession
    {
        return CashSession::where('store_id', $storeId)->where('status', CashSession::OPEN)->orderByDesc('business_date')->first();
    }

    /** The last day that was closed before the given session (or before now when null). */
    public function lastClosed(int $storeId, ?CashSession $before = null): ?CashSession
    {
        return CashSession::where('store_id', $storeId)->where('status', CashSession::CLOSED)
            ->when($before, fn ($q) => $q->where('business_date', '<', $before->business_date->toDateString()))
            ->orderByDesc('business_date')->first();
    }

    /**
     * Gives the payments made while no day was open (from the given moment on) to this day. Called inside the
     * opening / reopening transaction. The stamp is bookkeeping, not money: the payment rows themselves never change.
     */
    public function adoptOrphans(CashSession $session, Carbon $since): int
    {
        return DB::table('payments')->where('store_id', $session->store_id)->whereNull('cash_session_id')
            ->where('created_at', '>=', $since)->update(['cash_session_id' => $session->id]);
    }

    /**
     * The figures for one session: cash in and out by kind, cash movements, non-cash money received (to compare
     * with the UPI / bank app), the bills of the day's time span, and the expected cash.
     *
     * @return array<string, mixed>
     */
    public function figures(CashSession $session): array
    {
        $storeId = $session->store_id;
        $from = $this->windowStart($session);
        $to = $session->isOpen() ? null : $session->closed_at;

        $byKind = ['cash_sales' => 0, 'udhar_received' => 0, 'supplier_refunds' => 0, 'other_in' => 0,
            'refunds' => 0, 'expenses' => 0, 'paid_to_suppliers' => 0, 'other_out' => 0];
        $types = [
            (new Sale)->getMorphClass() => ['in' => 'cash_sales', 'out' => 'refunds'],
            (new SaleReturn)->getMorphClass() => ['in' => 'other_in', 'out' => 'refunds'],
            (new Purchase)->getMorphClass() => ['in' => 'supplier_refunds', 'out' => 'paid_to_suppliers'],
            (new PurchaseReturn)->getMorphClass() => ['in' => 'supplier_refunds', 'out' => 'other_out'],
            (new Expense)->getMorphClass() => ['in' => 'other_in', 'out' => 'expenses'],
        ];
        $parties = [
            (new Customer)->getMorphClass() => ['in' => 'udhar_received', 'out' => 'refunds'],
            (new Supplier)->getMorphClass() => ['in' => 'supplier_refunds', 'out' => 'paid_to_suppliers'],
        ];
        $payments = fn () => DB::table('payments')->where('store_id', $storeId)->where('cash_session_id', $session->id);
        $rows = $payments()->where('method', 'cash')
            ->groupBy('direction', 'payable_type', 'party_type')
            ->selectRaw('direction, payable_type, party_type, SUM(amount_paise) as amount')->get();
        foreach ($rows as $row) {
            $kind = $types[$row->payable_type][$row->direction] ?? $parties[$row->party_type][$row->direction] ?? ($row->direction === 'in' ? 'other_in' : 'other_out');
            $byKind[$kind] += (int) $row->amount;
        }

        $movements = CashMovement::where('cash_session_id', $session->id)->orderBy('id')->get();
        $cashIn = (int) $movements->where('type', CashMovement::CASH_IN)->sum('amount_paise');
        $cashOut = (int) $movements->where('type', '!=', CashMovement::CASH_IN)->sum('amount_paise');

        $in = $byKind['cash_sales'] + $byKind['udhar_received'] + $byKind['supplier_refunds'] + $byKind['other_in'] + $cashIn;
        $out = $byKind['refunds'] + $byKind['expenses'] + $byKind['paid_to_suppliers'] + $byKind['other_out'] + $cashOut;

        $nonCash = $payments()->where('direction', 'in')->where('method', '!=', 'cash')
            ->groupBy('method')->selectRaw('method, SUM(amount_paise) as amount')->pluck('amount', 'method');
        $bills = DB::table('sales')->where('store_id', $storeId)->where('status', 'completed')
            ->where('completed_at', '>=', $from)->when($to, fn ($q) => $q->where('completed_at', '<=', $to));

        return [
            'window_from' => $from->toIso8601String(),
            'window_to' => $to?->toIso8601String(),
            'opening_cash_paise' => $session->opening_cash_paise,
            'cash' => $byKind + ['cash_in_paise' => $cashIn, 'cash_out_paise' => $cashOut, 'in_paise' => $in, 'out_paise' => $out],
            'expected_cash_paise' => $session->opening_cash_paise + $in - $out,
            'non_cash' => ['upi' => (int) ($nonCash['upi'] ?? 0), 'card' => (int) ($nonCash['card'] ?? 0), 'bank' => (int) ($nonCash['bank'] ?? 0)],
            'bills' => ['count' => (clone $bills)->count(), 'total_paise' => (int) (clone $bills)->sum('total_paise'), 'udhar_paise' => (int) (clone $bills)->sum('credit_paise')],
            'movements' => $movements->map(fn (CashMovement $m) => ['id' => $m->id, 'type' => $m->type, 'amount_paise' => $m->amount_paise, 'note' => $m->note, 'at' => $m->created_at->toIso8601String()])->all(),
        ];
    }

    /** The moment this day's span starts for the bill count: the previous closing, or this opening when nothing was closed before. */
    public function windowStart(CashSession $session): Carbon
    {
        $previous = $this->lastClosed($session->store_id, $session);

        return $previous?->closed_at?->copy() ?? $session->opened_at->copy();
    }
}
