<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shared\Audit;
use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorController extends Controller
{
    /** Doctors matching the typed text, most recently used first is not tracked: alphabetical, 15 at most. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'all' => ['nullable', 'boolean']]);
        $query = Doctor::where('store_id', $request->user()->store_id)->when(empty($filters['all']), fn ($q) => $q->where('is_active', true));
        foreach (preg_split('/\s+/u', mb_strtolower(trim((string) ($filters['search'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $query->where('search_text', 'like', '%'.addcslashes($word, '%_\\').'%');
        }

        return response()->json(['data' => $query->orderBy('name')->limit(empty($filters['all']) ? 15 : 500)->get()->map(fn (Doctor $d) => $this->present($d))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $doctor = Doctor::create($this->attributes($data) + ['store_id' => $request->user()->store_id])->refresh();
        Audit::log('doctors.created', $doctor);

        return response()->json($this->present($doctor), 201);
    }

    public function update(Request $request, Doctor $doctor): JsonResponse
    {
        abort_unless($doctor->store_id === $request->user()->store_id, 404);
        $data = $this->validated($request);
        $doctor->update($this->attributes($data) + (array_key_exists('is_active', $data) ? ['is_active' => (bool) $data['is_active']] : []));
        Audit::log('doctors.updated', $doctor);

        return response()->json($this->present($doctor->refresh()));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'reg_no' => ['nullable', 'string', 'max:50'],
            'clinic' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:300'],
            'phone' => ['nullable', 'regex:/^[0-9]{10}$/'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'reg_no' => isset($data['reg_no']) ? trim($data['reg_no']) ?: null : null,
            'clinic' => $data['clinic'] ?? null,
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'search_text' => Product::buildSearchText($data['name'], $data['reg_no'] ?? null, $data['clinic'] ?? null, $data['phone'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function present(Doctor $d): array
    {
        return ['id' => $d->id, 'name' => $d->name, 'reg_no' => $d->reg_no, 'clinic' => $d->clinic, 'address' => $d->address, 'phone' => $d->phone, 'is_active' => $d->is_active];
    }
}
