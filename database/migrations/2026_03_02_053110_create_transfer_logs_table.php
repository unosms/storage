<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transfer_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 20)->default('upload');
            $table->string('status', 20)->default('in_progress');
            $table->string('original_name');
            $table->string('filename');
            $table->string('ftp_path');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->decimal('speed_kbps', 10, 2)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_logs');
    }
};
