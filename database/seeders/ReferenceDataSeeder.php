<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\DrugSchedule;
use App\Models\ExpenseCategory;
use App\Models\Store;
use App\Models\TaxRate;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Starting lists every shop needs: units, GST rates, drug schedules and
 * categories. Safe to run repeatedly; it only adds what is missing and never
 * overwrites what the shop has edited.
 *
 * These are editable starting values, not rules in code. GST rates reflect
 * the slabs in force since 22 Sep 2025; the drug schedule switches reflect
 * the Drugs Rules, 1945 as read on 11 Nov 2026 (see docs/MEDICAL_DOMAIN_GUIDE.md).
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['PCS', 'Piece', 'नग'], ['TAB', 'Tablet', 'गोळी'], ['CAP', 'Capsule', 'कॅप्सूल'], ['STRIP', 'Strip', 'स्ट्रिप'],
            ['BTL', 'Bottle', 'बाटली'], ['TUBE', 'Tube', 'ट्यूब'], ['BOX', 'Box', 'बॉक्स'], ['PKT', 'Packet', 'पाकीट'],
            ['SACHET', 'Sachet', 'सॅशे'], ['VIAL', 'Vial', 'व्हायल'], ['G', 'Gram', 'ग्रॅम'], ['KG', 'Kilogram', 'किलो'],
            ['ML', 'Millilitre', 'मिली'], ['L', 'Litre', 'लिटर'], ['DOZ', 'Dozen', 'डझन'],
        ] as [$code, $name, $local]) {
            Unit::firstOrCreate(['code' => $code], ['name' => $name, 'name_local' => $local]);
        }

        foreach ([
            ['GST 0% (Nil)', '0', 'nil'], ['GST 5%', '5', 'taxable'], ['GST 18%', '18', 'taxable'],
            ['GST 40%', '40', 'taxable'], ['Exempt', '0', 'exempt'],
        ] as [$name, $rate, $kind]) {
            TaxRate::firstOrCreate(['name' => $name], ['rate_percent' => $rate, 'kind' => $kind]);
        }

        $schedules = [
            ['NONE', 'Not a scheduled drug', []],
            ['H', 'Schedule H', [
                'requires_prescription' => true, 'requires_prescriber' => true, 'requires_patient' => true,
                'requires_pharmacist' => true, 'warning_text' => 'Sell only on the prescription of a registered medical practitioner.',
            ]],
            ['H1', 'Schedule H1', [
                'requires_prescription' => true, 'requires_prescriber' => true, 'requires_patient' => true,
                'requires_pharmacist' => true, 'requires_register' => true, 'register_retention_years' => 3,
                'warning_text' => 'Prescription only. Entry in the Schedule H1 register is required.',
            ]],
            ['X', 'Schedule X', [
                'requires_prescription' => true, 'requires_prescriber' => true, 'requires_patient' => true,
                'requires_pharmacist' => true, 'requires_register' => true, 'keep_prescription_copy' => true,
                'is_enabled' => false, // needs a Form 20F licence; the owner switches it on
                'warning_text' => 'Needs a Schedule X licence, prescription in duplicate and the bound register.',
            ]],
            ['C', 'Schedule C / C(1)', ['requires_pharmacist' => true, 'warning_text' => 'Biological product. Check storage conditions.']],
        ];
        foreach ($schedules as $order => [$code, $name, $flags]) {
            DrugSchedule::firstOrCreate(['code' => $code], ['name' => $name, 'sort_order' => $order] + $flags);
        }

        foreach (Store::all() as $store) {
            $this->categoriesFor($store->id);
            ExpenseCategory::seedDefaults($store->id);
        }
    }

    public function categoriesFor(int $storeId): void
    {
        foreach ([
            ['Tablets & Capsules', 'गोळ्या आणि कॅप्सूल', 'medical'], ['Syrups & Liquids', 'सिरप', 'medical'],
            ['Creams & Ointments', 'मलम', 'medical'], ['Drops & Sprays', 'ड्रॉप्स', 'medical'],
            ['Injections', 'इंजेक्शन', 'medical'], ['First Aid & Surgical', 'प्रथमोपचार', 'medical'],
            ['Health & Hygiene', 'आरोग्य आणि स्वच्छता', 'both'], ['Baby Products', 'बाळाचे साहित्य', 'both'],
            ['Groceries & Staples', 'किराणा', 'general'], ['Snacks & Biscuits', 'नाश्ता आणि बिस्किटे', 'general'],
            ['Beverages', 'पेये', 'general'], ['Personal Care', 'वैयक्तिक निगा', 'general'],
            ['Household & Cleaning', 'घरगुती आणि स्वच्छता', 'general'], ['Stationery', 'स्टेशनरी', 'general'],
            ['Other', 'इतर', 'both'],
        ] as $order => [$name, $local, $scope]) {
            Category::firstOrCreate(['store_id' => $storeId, 'name' => $name], ['name_local' => $local, 'scope' => $scope, 'sort_order' => $order]);
        }
    }
}
