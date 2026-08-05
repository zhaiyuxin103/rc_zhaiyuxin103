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
        Schema::create('outbounds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('idempotency_key', 255);
            $table->char('request_fingerprint', 64);
            $table->string('http_method', 10)->default('POST');
            $table->string('target_url', 2048);
            $table->text('headers')->nullable();
            $table->json('payload');
            $table->string('status', 32)->index();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedSmallInteger('last_response_status')->nullable();
            $table->text('last_error')->nullable();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['status', 'next_attempt_at']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbounds');
    }
};
