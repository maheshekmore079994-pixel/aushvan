<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: suppliers (with their MR / sales contacts and a ledger of what the shop owes them), purchases
 * (draft → confirmed, each confirmed line becomes a stock batch) and purchase returns (debit notes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('kind', 20)->default('distributor'); // distributor | wholesaler | manufacturer | other
            $table->string('contact_person', 100)->nullable();
            $table->string('mobile', 15)->nullable();
            $table->string('address', 300)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('drug_licence_no', 50)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0); // 0 = pay on delivery / not set
            $table->bigInteger('balance_paise')->default(0); // cached; positive = the shop owes the supplier
            $table->timestamp('last_payment_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('search_text', 300);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['store_id', 'name']);
        });

        // Medical representatives and other people who visit or take orders for a supplier (D-054).
        Schema::create('supplier_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('role', 20)->default('mr'); // mr | sales | delivery | accounts | owner | other
            $table->string('company', 150)->nullable(); // the manufacturer an MR represents, when not the supplier itself
            $table->string('mobile', 15)->nullable();
            $table->unsignedTinyInteger('visit_day')->nullable(); // 0 = Sunday … 6 = Saturday
            $table->string('schemes', 500)->nullable();
            $table->string('notes', 300)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['store_id', 'visit_day']);
        });

        Schema::create('supplier_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('entry_date');
            $table->string('type', 20); // opening | purchase | payment | return | adjustment | reversal
            $table->bigInteger('credit_paise')->default(0); // increases what the shop owes (a purchase)
            $table->bigInteger('debit_paise')->default(0);  // reduces it (a payment, a return)
            $table->bigInteger('balance_after_paise');
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('reverses_id')->nullable();
            $table->string('note', 300)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['supplier_id', 'id']);
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('purchase_no', 16)->nullable(); // given when confirmed
            $table->string('status', 12)->default('draft'); // draft | confirmed | cancelled
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('supplier_invoice_no', 50)->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('received_date');
            $table->bigInteger('gross_paise')->default(0);
            $table->bigInteger('discount_paise')->default(0);
            $table->bigInteger('taxable_paise')->default(0);
            $table->bigInteger('cgst_paise')->default(0);
            $table->bigInteger('sgst_paise')->default(0);
            $table->bigInteger('igst_paise')->default(0);
            $table->bigInteger('cess_paise')->default(0);
            $table->bigInteger('other_charges_paise')->default(0);
            $table->bigInteger('round_off_paise')->default(0);
            $table->bigInteger('total_paise')->default(0);
            $table->bigInteger('invoice_total_paise')->nullable(); // what the paper invoice says, typed for the check
            $table->bigInteger('paid_paise')->default(0);
            $table->boolean('inter_state')->default(false);
            $table->date('due_date')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->timestamps();
            $table->index(['store_id', 'status', 'received_date']);
            $table->index(['supplier_id', 'supplier_invoice_no']);
            $table->index(['store_id', 'purchase_no']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete(); // a draft may be deleted with its lines
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 150);
            $table->string('hsn_code', 8)->nullable();
            $table->string('unit_code', 10);           // unit the supplier billed in
            $table->unsignedInteger('unit_factor');    // base units in that unit
            $table->bigInteger('qty');                 // billed, in that unit
            $table->bigInteger('free_qty')->default(0); // free, in that unit
            $table->bigInteger('qty_base');            // billed + free, in base units
            $table->bigInteger('rate_paise');          // per unit, before tax
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->bigInteger('gross_paise');
            $table->bigInteger('discount_paise')->default(0);
            $table->decimal('tax_rate_percent', 5, 2)->default(0);
            $table->decimal('cess_percent', 5, 2)->default(0);
            $table->bigInteger('taxable_paise');
            $table->bigInteger('cgst_paise')->default(0);
            $table->bigInteger('sgst_paise')->default(0);
            $table->bigInteger('igst_paise')->default(0);
            $table->bigInteger('cess_paise')->default(0);
            $table->bigInteger('line_total_paise');
            $table->string('batch_no', 40)->nullable();
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->bigInteger('mrp_paise')->default(0);            // per pricing unit
            $table->bigInteger('selling_price_paise')->nullable();  // per pricing unit; null = keep the product's
            $table->decimal('cost_rate', 18, 6)->nullable();        // paise per base unit, priced when the draft is saved; confirm copies it to the batch
            $table->foreignId('batch_id')->nullable()->constrained('stock_batches')->restrictOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'id']);
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('return_no', 16);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('return_date');
            $table->string('reason', 300);
            $table->bigInteger('taxable_paise');
            $table->bigInteger('cgst_paise')->default(0);
            $table->bigInteger('sgst_paise')->default(0);
            $table->bigInteger('igst_paise')->default(0);
            $table->bigInteger('cess_paise')->default(0);
            $table->bigInteger('round_off_paise')->default(0);
            $table->bigInteger('total_paise');
            $table->string('settlement', 20); // reduce_balance | refund_received
            $table->string('refund_method', 10)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'return_no']);
            $table->index(['supplier_id', 'return_date']);
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 150);
            $table->foreignId('batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->string('batch_no', 40)->nullable();
            $table->string('bucket', 12);
            $table->bigInteger('qty_base');
            $table->decimal('cost_rate', 18, 6);
            $table->decimal('tax_rate_percent', 5, 2)->default(0);
            $table->decimal('cess_percent', 5, 2)->default(0);
            $table->bigInteger('taxable_paise');
            $table->bigInteger('cgst_paise')->default(0);
            $table->bigInteger('sgst_paise')->default(0);
            $table->bigInteger('igst_paise')->default(0);
            $table->bigInteger('cess_paise')->default(0);
            $table->bigInteger('line_total_paise');
            $table->timestamps();
        });

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('received_date')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_item_id')->nullable()->after('supplier_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_item_id');
            $table->dropConstrainedForeignId('supplier_id');
        });
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('supplier_ledger_entries');
        Schema::dropIfExists('supplier_contacts');
        Schema::dropIfExists('suppliers');
    }
};
