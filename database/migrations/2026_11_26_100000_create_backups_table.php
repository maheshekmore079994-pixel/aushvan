<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20); // manual | auto | scheduled | before_restore
            $table->string('file_name', 120);
            $table->string('local_path', 500)->nullable(); // copy on this PC/server; null once retention removed it
            $table->string('folder_path', 500)->nullable(); // verified copy in the backup folder (USB / second disk)
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('status', 20); // verified | failed
            $table->string('error', 60)->nullable(); // why it failed (an errors.<code> key)
            $table->string('warning', 60)->nullable(); // verified, but something to tell the owner (folder missing…)
            $table->text('manifest')->nullable(); // JSON: counts, schema, database file checksum
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
