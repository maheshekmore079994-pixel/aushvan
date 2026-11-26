<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7: the cash drawer (one session per business day: opened with a counted float, closed with a counted
 * total), cash taken out or put in outside bills, and shop expenses with their categories.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->string('status', 10)->default('open'); // open | closed
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->bigInteger('opening_cash_paise');
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->bigInteger('expected_cash_paise')->nullable();
            $table->bigInteger('counted_cash_paise')->nullable();
            $table->bigInteger('difference_paise')->nullable();
            $table->text('summary')->nullable(); // JSON: the figures as they were when the day was closed
            $table->string('note', 300)->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason', 300)->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'business_date']);
            $table->index(['store_id', 'status']);
        });

        // Cash that moves without a bill, purchase or expense: a float put in, a bank deposit, the owner taking money.
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // cash_in | bank_deposit | owner_withdrawal | other_out
            $table->bigInteger('amount_paise');
            $table->string('note', 300)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['store_id', 'created_at']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('name_local', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'name']);
        });

        // Every payment made while a day is open is stamped with it, inside the payment's own transaction, so the
        // drawer's figures never depend on the clock alone (a bill committing at the very second of closing).
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable()->after('business_date')->constrained()->restrictOnDelete();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_paise');
            $table->date('expense_date');
            $table->string('method', 10); // cash | upi | card | bank
            $table->string('description', 300)->nullable();
            $table->string('reference_no', 50)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->timestamps();
            $table->index(['store_id', 'expense_date']);
            $table->index(['expense_category_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_session_id');
        });
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_sessions');
    }
};
