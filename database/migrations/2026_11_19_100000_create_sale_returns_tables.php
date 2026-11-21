<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Customer returns (credit notes) and bill cancellation. A bill is never edited; these documents point at it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('cancelled_by')->nullable()->after('notes')->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->string('cancel_reason', 300)->nullable()->after('cancelled_at');
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('return_no', 16);
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('return_date');
            $table->string('reason', 300);
            $table->string('refund_mode', 20);              // refund | account_credit
            $table->string('refund_method', 10)->nullable(); // cash | upi | card | bank when refunded
            $table->bigInteger('gross_paise');
            $table->bigInteger('discount_paise')->default(0);
            $table->bigInteger('taxable_paise');
            $table->bigInteger('cgst_paise')->default(0);
            $table->bigInteger('sgst_paise')->default(0);
            $table->bigInteger('igst_paise')->default(0);
            $table->bigInteger('cess_paise')->default(0);
            $table->bigInteger('round_off_paise')->default(0);
            $table->bigInteger('total_paise');
            $table->bigInteger('cost_paise');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'return_no']);
            $table->index(['store_id', 'return_date']);
            $table->index(['sale_id']);
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->string('unit_code', 10);
            $table->bigInteger('qty');       // in the unit the line was sold in
            $table->bigInteger('qty_base');
            $table->string('bucket', 12);    // sellable | unsellable
            $table->bigInteger('gross_paise');
            $table->bigInteger('discount_paise')->default(0);
            $table->bigInteger('taxable_paise');
            $table->bigInteger('cgst_paise')->default(0);
            $table->bigInteger('sgst_paise')->default(0);
            $table->bigInteger('igst_paise')->default(0);
            $table->bigInteger('cess_paise')->default(0);
            $table->bigInteger('line_total_paise');
            $table->bigInteger('cost_paise');
            $table->foreignId('restock_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['sale_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
