<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 50);
            $table->string('name_local', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->decimal('rate_percent', 5, 2);
            $table->decimal('cess_percent', 5, 2)->default(0);
            $table->string('kind', 20)->default('taxable'); // taxable | nil | exempt | non_gst
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('drug_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->boolean('requires_prescription')->default(false);
            $table->boolean('requires_prescriber')->default(false);
            $table->boolean('requires_patient')->default(false);
            $table->boolean('requires_pharmacist')->default(false);
            $table->boolean('requires_register')->default(false);
            $table->unsignedTinyInteger('register_retention_years')->default(2);
            $table->boolean('keep_prescription_copy')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->string('warning_text', 300)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('name_local', 100)->nullable();
            $table->string('scope', 10)->default('both'); // medical | general | both
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['store_id', 'scope']);
        });

        Schema::create('manufacturers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->timestamps();
            $table->unique(['store_id', 'name']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('type', 10); // medical | general
            $table->string('name', 150);
            $table->string('name_local', 150)->nullable();
            $table->string('sku', 20)->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('brand', 100)->nullable();
            $table->foreignId('manufacturer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 500)->nullable();
            $table->string('hsn_code', 8)->nullable();
            $table->foreignId('tax_rate_id')->constrained()->restrictOnDelete();
            // Prices are per pricing unit (the pack the MRP is printed on), in paise, tax included.
            $table->bigInteger('mrp_paise')->default(0);
            $table->bigInteger('selling_price_paise')->default(0);
            $table->decimal('default_discount_percent', 5, 2)->default(0);
            $table->bigInteger('min_stock_qty')->default(0); // base units
            $table->boolean('tracks_batch')->default(false);
            $table->boolean('tracks_expiry')->default(false);
            $table->boolean('allow_loose_sale')->default(false);
            $table->string('rack_location', 50)->nullable();
            $table->boolean('is_quick_item')->default(false);
            $table->string('search_text', 500);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['store_id', 'sku']);
            $table->index(['store_id', 'type', 'is_active']);
            $table->index(['store_id', 'name']);
        });

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('factor_to_base'); // 1 of this unit = N base units
            $table->bigInteger('price_override_paise')->nullable();
            $table->boolean('is_base')->default(false);
            $table->boolean('is_pricing')->default(false); // MRP / selling price refer to this unit
            $table->timestamps();
            $table->unique(['product_id', 'unit_id']);
        });

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_unit_id')->constrained()->cascadeOnDelete();
            $table->string('barcode', 40);
            $table->boolean('is_internal')->default(false);
            $table->timestamps();
            $table->unique(['store_id', 'barcode']);
        });

        Schema::create('medicine_details', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('generic_name', 200)->nullable();
            $table->string('composition_text', 500)->nullable();
            $table->string('strength', 50)->nullable();
            $table->string('dosage_form', 30)->nullable();
            $table->foreignId('drug_schedule_id')->nullable()->constrained()->restrictOnDelete(); // null = not set yet
            $table->boolean('is_ndps')->default(false);
            $table->string('storage_instructions', 200)->nullable();
            $table->timestamps();
        });

        Schema::create('product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('field', 20); // mrp | selling_price | cost
            $table->bigInteger('old_paise')->nullable();
            $table->bigInteger('new_paise');
            $table->string('source', 30);
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['product_id', 'created_at']);
        });

        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('batch_no', 40)->nullable();
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable(); // last day the stock may be sold
            $table->bigInteger('mrp_paise');
            $table->bigInteger('selling_price_paise');
            $table->decimal('cost_rate', 18, 6); // paise per base unit
            $table->date('received_date');
            // Cached totals of inventory_movements; never written except by StockLedger.
            $table->bigInteger('qty_sellable')->default(0);
            $table->bigInteger('qty_unsellable')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'expiry_date']);
            $table->index(['store_id', 'expiry_date']);
            $table->index(['product_id', 'batch_no']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('reason', 30); // opening | damage | expiry_writeoff | count_correction | mark_unsellable
            $table->date('adjust_date');
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->string('bucket', 12); // sellable | unsellable
            $table->bigInteger('qty_delta');
            $table->string('type', 20);
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->decimal('cost_rate', 18, 6);
            $table->bigInteger('balance_after');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at');
            $table->index(['batch_id', 'id']);
            $table->index(['product_id', 'id']);
            $table->index(['source_type', 'source_id']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'inventory_movements', 'stock_adjustments', 'stock_batches', 'product_price_history',
            'medicine_details', 'product_barcodes', 'product_units', 'products', 'manufacturers',
            'categories', 'drug_schedules', 'tax_rates', 'units',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
