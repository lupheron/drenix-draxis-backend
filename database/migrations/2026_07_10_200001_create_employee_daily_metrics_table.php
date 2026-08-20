<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('minutes_on_call')->default(0);
            $table->unsignedInteger('calls_made')->default(0);
            $table->unsignedInteger('lates')->default(0);
            $table->unsignedInteger('hires')->default(0);
            $table->unsignedInteger('follow_up')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index(['date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_daily_metrics');
    }
};
