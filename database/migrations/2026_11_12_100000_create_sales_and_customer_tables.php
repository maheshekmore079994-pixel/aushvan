<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('mobile', 15)->nullable();
            $table->string('village', 100)->nullable();
            $table->string('address', 300)->nullable();
            $table->bigInteger('credit_limit_paise')->default(0); // 0 = no limit set
            $table->bigInteger('balance_paise')->default(0);      // cached; positive = customer owes the shop
            $table->timestamp('last_payment_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('search_text', 300);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['store_id', 'mobile']);
            $table->index(['store_id', 'name']);
        });

        Schema::create('customer_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('entry_date');
            $table->string('type', 20); // opening | sale | payment | return | adjustment | reversal
            $table->bigInteger('debit_paise')->default(0);  // increases what the customer owes
            $table->bigInteger('credit_paise')->default(0); // reduces it
            $table->bigInteger('balance_after_paise');
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('reverses_id')->nullable();
            $table->string('note', 300)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['customer_id', 'id']);
        });

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('patient_name', 100);
            $table->string('patient_address', 300)->nullable();
            $table->string('prescriber_name', 100);
            $table->string('prescriber_address', 300)->nullable();
            $table->string('prescriber_reg_no', 50)->nullable();
            $table->date('prescription_date')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique(); // made on the sale screen; a repeated request returns the same bill
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('invoice_no', 16);
            $table->string('doc_kind', 30); // tax_invoice | bill_of_supply | invoice_cum_bill_of_supply | cash_memo
            $table->string('status', 12)->default('completed');
            $table->date('sale_date');
            $table->timestamp('completed_at');
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('buyer_name', 100)->nullable();
            $table->bigInteger('gross_paise');
            $table->bigInteger('discount_paise')->default(0);
            $table->bigInteger('taxable_paise');
            $table->bigInteger('cgst_paise')->default(0);
            $table->bigInteger('sgst_paise')->default(0);
            $table->bigInteger('igst_paise')->default(0);
            $table->bigInteger('cess_paise')->default(0);
            $table->bigInteger('round_off_paise')->default(0);
            $table->bigInteger('total_paise');
            $table->bigInteger('paid_paise');
            $table->bigInteger('credit_paise')->default(0); // udhar created by this bill
            $table->bigInteger('cost_paise');               // cost of the goods sold, for profit
            $table->foreignId('prescription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('verified_by_pharmacist_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('notes', 300)->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'invoice_no']);
            $table->index(['store_id', 'sale_date']);
            $table->index(['customer_id', 'sale_date']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // Everything below is copied at the time of sale so the bill never changes afterwards.
            $table->string('product_name', 150);
            $table->string('hsn_code', 8)->nullable();
            $table->string('schedule_code', 10)->nullable();
            $table->foreignId('batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->string('batch_no', 40)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('cost_rate', 18, 6);
            $table->string('unit_code', 10);            // unit the customer bought in
            $table->unsignedInteger('unit_factor');     // base units in that unit
            $table->bigInteger('qty');                  // in that unit
            $table->bigInteger('qty_base');
            $table->bigInteger('mrp_paise');            // per pack
            $table->bigInteger('pack_price_paise');     // selling price per pack
            $table->unsignedInteger('pack_factor');     // base units in a pack
            $table->bigInteger('gross_paise');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->bigInteger('discount_paise')->default(0);
            $table->decimal('tax_rate_percent', 5, 2)->default(0);
            $table->decimal('cess_percent', 5, 2)->default(0);
            $table->string('tax_kind', 20);
            $table->bigInteger('taxable_paise');
            $table->bigInteger('cgst_paise')->default(0);
            $table->bigInteger('sgst_paise')->default(0);
            $table->bigInteger('igst_paise')->default(0);
            $table->bigInteger('cess_paise')->default(0);
            $table->bigInteger('line_total_paise');
            $table->bigInteger('cost_paise');
            $table->bigInteger('returned_qty_base')->default(0);
            $table->index(['product_id', 'sale_id']);
            $table->index(['batch_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('direction', 3);  // in | out
            $table->string('method', 10);    // cash | upi | card | bank
            $table->bigInteger('amount_paise');
            $table->string('party_type', 50)->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('payable_type', 50)->nullable(); // the bill it pays; null = on account
            $table->unsignedBigInteger('payable_id')->nullable();
            $table->string('reference_no', 50)->nullable();
            $table->date('business_date');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at');
            $table->index(['business_date', 'method']);
            $table->index(['payable_type', 'payable_id']);
            $table->index(['party_type', 'party_id']);
        });
    }

    public function down(): void
    {
        foreach (['payments', 'sale_items', 'sales', 'prescriptions', 'customer_ledger_entries', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
