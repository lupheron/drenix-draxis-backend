<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('company', 10);
            $table->string('external_id')->unique();
            $table->timestamp('started_at');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('direction')->nullable();
            $table->string('result')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
            $table->index(['company', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
