<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monday_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('company', 10);
            $table->string('external_id')->unique(); // Monday item id
            $table->string('board_id');
            $table->string('board_name')->nullable();
            $table->string('board_kind'); // new_leads | follow_up | hr_process
            $table->string('group_title')->nullable();
            $table->string('metric_type'); // leads | follow_up | hires | loaded | rejected
            $table->string('item_name')->nullable();
            $table->string('source_label')->nullable();
            $table->date('metric_date');
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'metric_date']);
            $table->index(['company', 'metric_type', 'metric_date']);
            $table->index(['board_kind', 'metric_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monday_items');
    }
};
