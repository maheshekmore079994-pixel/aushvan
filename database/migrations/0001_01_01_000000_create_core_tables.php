<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('village')->nullable();
            $table->string('taluka')->nullable();
            $table->string('district')->nullable();
            $table->string('state_code', 2)->default('27'); // GST state code; 27 = Maharashtra
            $table->string('pincode', 6)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('gst_mode', 20)->default('unregistered');
            $table->string('gstin', 15)->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('key', 100);
            $table->text('value')->nullable(); // JSON-encoded
            $table->timestamps();
            $table->unique(['store_id', 'key']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('username', 50)->unique();
            $table->string('mobile', 15)->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->string('pin')->nullable(); // hashed; authorises actions, never a login
            $table->string('locale', 5)->default('en');
            $table->boolean('is_pharmacist')->default(false);
            $table->string('pharmacist_reg_no', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['action', 'created_at']);
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('doc_type', 30);
            $table->string('prefix', 6)->default('');
            $table->string('financial_year', 5); // e.g. 26-27
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['store_id', 'doc_type', 'prefix', 'financial_year'], 'number_sequences_unique');
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('key', 64);
            $table->string('method', 10);
            $table->string('path', 255);
            $table->unsignedSmallInteger('response_status')->nullable(); // null = still being processed
            $table->longText('response_body')->nullable();
            $table->timestamp('created_at');
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('stores');
    }
};
