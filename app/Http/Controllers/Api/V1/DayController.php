<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CloseDay;
use App\Actions\MoveCash;
use App\Actions\OpenDay;
use App\Actions\ReopenDay;
use App\Domain\Cash\DayBook;
use App\Domain\Shared\ShopClock;
use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DayController extends Controller
{
    public function __construct(private DayBook $book) {}

    /** The open day with its live figures, or the last closed day and a suggested float for opening. */
    public function show(Request $request): JsonResponse
    {
        $storeId = $request->user()->store_id;
        $open = $this->book->open($storeId);
        $last = $this->book->lastClosed($storeId);

        return response()->json([
            'open' => $open ? $this->present($open) : null,
            'last_closed' => $last ? $this->present($last, false) : null,
            'suggested_opening_paise' => $last?->counted_cash_paise ?? 0,
            'today' => ShopClock::todayString(),
        ]);
    }

    public function open(Request $request, OpenDay $action): JsonResponse
    {
        $data = $request->validate(['opening_cash_paise' => ['required', 'integer', 'min:0', 'max:100000000000']]);

        return response()->json($this->present($action->handle($request->user(), $data['opening_cash_paise'])), 201);
    }

    public function close(Request $request, CloseDay $action): JsonResponse
    {
        $data = $request->validate([
            'counted_cash_paise' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        return response()->json($this->present($action->handle($request->user(), $data['counted_cash_paise'], $data['note'] ?? null)));
    }

    public function reopen(Request $request, CashSession $session, ReopenDay $action): JsonResponse
    {
        abort_unless($session->store_id === $request->user()->store_id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return response()->json($this->present($action->handle($request->user(), $session, trim($data['reason']))));
    }

    public function move(Request $request, MoveCash $action): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(CashMovement::TYPES)],
            'amount_paise' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $action->handle($request->user(), $data['type'], $data['amount_paise'], $data['note'] ?? null);
        $open = $this->book->open($request->user()->store_id);

        return response()->json($open ? $this->present($open) : null, 201);
    }

    /** Closed days, newest first. */
    public function history(Request $request): JsonResponse
    {
        $page = CashSession::with(['opener:id,name', 'closer:id,name'])->where('store_id', $request->user()->store_id)
            ->orderByDesc('business_date')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (CashSession $s) => $this->present($s, false)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function session(Request $request, CashSession $session): JsonResponse
    {
        abort_unless($session->store_id === $request->user()->store_id, 404);

        return response()->json($this->present($session));
    }

    /** @return array<string, mixed> */
    private function present(CashSession $s, bool $withFigures = true): array
    {
        $s->loadMissing(['opener:id,name', 'closer:id,name']);
        $row = [
            'id' => $s->id,
            'business_date' => $s->business_date->toDateString(),
            'status' => $s->status,
            'opened_by' => $s->opener?->name,
            'opened_at' => $s->opened_at->toIso8601String(),
            'opening_cash_paise' => $s->opening_cash_paise,
            'closed_by' => $s->closer?->name,
            'closed_at' => $s->closed_at?->toIso8601String(),
            'expected_cash_paise' => $s->expected_cash_paise,
            'counted_cash_paise' => $s->counted_cash_paise,
            'difference_paise' => $s->difference_paise,
            'note' => $s->note,
            'reopened' => $s->reopened_at ? ['at' => $s->reopened_at->toIso8601String(), 'reason' => $s->reopen_reason] : null,
        ];
        if ($withFigures) {
            // A closed day shows the figures as they were when it was closed; an open day computes them now.
            $row['figures'] = $s->isOpen() ? $this->book->figures($s) : ($s->summary ?? $this->book->figures($s));
        }

        return $row;
    }
}
