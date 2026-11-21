<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shared\Audit;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStoreRequest;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->present($request->user()->store));
    }

    public function update(UpdateStoreRequest $request): JsonResponse
    {
        $store = $request->user()->store;
        $data = $request->safe()->except('settings');
        $settings = array_intersect_key($request->validated('settings', []), Store::SHOP_SETTINGS);

        DB::transaction(function () use ($store, $data, $settings) {
            $before = $this->present($store);

            $store->update($data);
            foreach ($settings as $key => $value) {
                $store->putSetting($key, $value === '' ? null : $value);
            }

            $after = $this->present($store->refresh());
            $changedOld = [];
            $changedNew = [];
            foreach ($this->flatten($after) as $key => $value) {
                $old = $this->flatten($before)[$key] ?? null;
                if ($old !== $value) {
                    $changedOld[$key] = $old;
                    $changedNew[$key] = $value;
                }
            }
            if ($changedNew !== []) {
                Audit::log('settings.shop_updated', $store, $changedOld, $changedNew);
            }
        });

        return response()->json($this->present($store->refresh()));
    }

    /** @return array<string, mixed> */
    private function present(Store $store): array
    {
        return [
            'name' => $store->name,
            'legal_name' => $store->legal_name,
            'address' => $store->address,
            'village' => $store->village,
            'taluka' => $store->taluka,
            'district' => $store->district,
            'state_code' => $store->state_code,
            'pincode' => $store->pincode,
            'phone' => $store->phone,
            'gst_mode' => $store->gst_mode->value,
            'gstin' => $store->gstin,
            'settings' => $store->shopSettings(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function flatten(array $data): array
    {
        $settings = $data['settings'];
        unset($data['settings']);

        return $data + $settings;
    }
}
