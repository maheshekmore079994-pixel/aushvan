<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: the doctor master picked on the prescription step (D-054), and a serial number on every
 * prescription record (Rule 65(3): the serial is also written on the prescription itself).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('reg_no', 50)->nullable();
            $table->string('clinic', 150)->nullable();
            $table->string('address', 300)->nullable();
            $table->string('phone', 15)->nullable();
            $table->string('search_text', 400);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['store_id', 'name']);
        });

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->string('serial_no', 16)->nullable()->after('id');
            $table->foreignId('doctor_id')->nullable()->after('customer_id')->constrained()->restrictOnDelete();
            $table->index('serial_no'); // not with store_id: MySQL would lend that index to the store_id foreign key and refuse to drop it
        });

        // One reversal per ledger entry, guaranteed by the database as well as by the code.
        Schema::table('customer_ledger_entries', function (Blueprint $table) {
            $table->unique('reverses_id');
        });
        Schema::table('supplier_ledger_entries', function (Blueprint $table) {
            $table->unique('reverses_id');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_ledger_entries', function (Blueprint $table) {
            $table->dropUnique(['reverses_id']);
        });
        Schema::table('customer_ledger_entries', function (Blueprint $table) {
            $table->dropUnique(['reverses_id']);
        });
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('doctor_id');
            $table->dropIndex(['serial_no']);
            $table->dropColumn('serial_no');
        });
        Schema::dropIfExists('doctors');
    }
};
