<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CancelExpense;
use App\Actions\RecordExpense;
use App\Domain\Shared\Audit;
use App\Domain\Shared\ShopClock;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'category_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $storeId = $request->user()->store_id;
        $today = ShopClock::today();
        $from = $filters['from'] ?? $today->copy()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? $today->toDateString();
        $query = Expense::with(['category:id,name,name_local', 'user:id,name'])->where('store_id', $storeId)
            ->whereDate('expense_date', '>=', $from)->whereDate('expense_date', '<=', $to)
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('expense_category_id', (int) $id));
        $page = (clone $query)->orderByDesc('expense_date')->orderByDesc('id')->paginate(30);
        $total = (int) (clone $query)->whereNull('cancelled_at')->sum('amount_paise');
        $byCategory = (clone $query)->whereNull('cancelled_at')->selectRaw('expense_category_id, SUM(amount_paise) as amount')
            ->groupBy('expense_category_id')->pluck('amount', 'expense_category_id');

        return response()->json([
            'data' => collect($page->items())->map(fn (Expense $e) => $this->present($e)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'from' => $from,
            'to' => $to,
            'total_paise' => $total,
            'by_category' => ExpenseCategory::where('store_id', $storeId)->whereIn('id', $byCategory->keys())->orderBy('sort_order')->get()
                ->map(fn (ExpenseCategory $c) => ['id' => $c->id, 'name' => $c->name, 'name_local' => $c->name_local, 'amount_paise' => (int) $byCategory[$c->id]]),
        ]);
    }

    public function store(Request $request, RecordExpense $action): JsonResponse
    {
        $data = $request->validate([
            'expense_category_id' => ['required', 'integer'],
            'amount_paise' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'method' => ['required', Rule::in(Payment::METHODS)],
            // Not in the future, not older than a year: the cash leaves the drawer when the expense is recorded, whatever its date.
            'expense_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.ShopClock::todayString(), 'after_or_equal:'.ShopClock::today()->subYear()->toDateString()],
            'description' => ['nullable', 'string', 'max:300'],
            'reference_no' => ['nullable', 'string', 'max:50'],
        ]);

        return response()->json($this->present($action->handle($request->user(), $data)->load(['category', 'user'])), 201);
    }

    public function cancel(Request $request, Expense $expense, CancelExpense $action): JsonResponse
    {
        abort_unless($expense->store_id === $request->user()->store_id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return response()->json($this->present($action->handle($request->user(), $expense, trim($data['reason']))->load(['category', 'user'])));
    }

    public function categories(Request $request): JsonResponse
    {
        $storeId = $request->user()->store_id;
        $all = ! empty($request->query('all'));

        return response()->json(['data' => ExpenseCategory::where('store_id', $storeId)->when(! $all, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (ExpenseCategory $c) => ['id' => $c->id, 'name' => $c->name, 'name_local' => $c->name_local, 'is_active' => $c->is_active])]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:100'], 'name_local' => ['nullable', 'string', 'max:100']]);
        $storeId = $request->user()->store_id;
        // Same head typed again (any letter case) is the existing one, brought back if it was hidden.
        $category = ExpenseCategory::where('store_id', $storeId)->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->first()
            ?? ExpenseCategory::create(['store_id' => $storeId, 'name' => $data['name']] + [
                'name_local' => $data['name_local'] ?? null,
                'sort_order' => (int) ExpenseCategory::where('store_id', $storeId)->max('sort_order') + 1,
            ]);
        if (! $category->is_active) {
            $category->update(['is_active' => true]);
        }
        Audit::log('expenses.category_saved', $category);

        return response()->json(['id' => $category->id, 'name' => $category->name, 'name_local' => $category->name_local, 'is_active' => true], 201);
    }

    public function updateCategory(Request $request, ExpenseCategory $category): JsonResponse
    {
        abort_unless($category->store_id === $request->user()->store_id, 404);
        if ($request->has('name')) {
            $request->merge(['name' => trim((string) $request->input('name'))]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:100', Rule::unique('expense_categories', 'name')->where('store_id', $category->store_id)->ignore($category->id)],
            'name_local' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $changes = [];
        if (isset($data['name'])) {
            $changes['name'] = $data['name'];
        }
        if (array_key_exists('name_local', $data)) {
            $changes['name_local'] = $data['name_local']; // null clears it
        }
        if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
            $changes['is_active'] = (bool) $data['is_active'];
        }
        $category->update($changes);
        Audit::log('expenses.category_saved', $category);

        return response()->json(['id' => $category->id, 'name' => $category->name, 'name_local' => $category->name_local, 'is_active' => $category->is_active]);
    }

    /** @return array<string, mixed> */
    private function present(Expense $e): array
    {
        return [
            'id' => $e->id,
            'date' => $e->expense_date->toDateString(),
            'category' => ['id' => $e->expense_category_id, 'name' => $e->category->name, 'name_local' => $e->category->name_local],
            'amount_paise' => $e->amount_paise,
            'method' => $e->method,
            'description' => $e->description,
            'reference_no' => $e->reference_no,
            'by' => $e->user->name,
            'cancelled' => $e->cancelled_at ? ['at' => $e->cancelled_at->toIso8601String(), 'reason' => $e->cancel_reason] : null,
        ];
    }
}
