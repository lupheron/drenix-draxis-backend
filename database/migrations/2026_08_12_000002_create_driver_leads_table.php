<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_leads', function (Blueprint $table) {
            $table->id();
            $table->string('company', 10)->default('JM')->index();

            // Monday identity
            $table->string('monday_item_id')->nullable()->unique();
            $table->string('board_id')->nullable()->index();
            $table->string('board_name')->nullable()->index();
            $table->string('group_id')->nullable();
            $table->string('group_title')->nullable()->index();

            // Searchable / display fields
            $table->string('name')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->string('phone_normalized', 32)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('email_normalized')->nullable()->index();
            $table->string('name_normalized')->nullable()->index();

            $table->string('status_label')->nullable()->index(); // group or status column
            $table->string('status_key')->nullable()->index();   // normalized filter key: rejected, n_a, follow_up...
            $table->text('notes')->nullable();
            $table->string('platform')->nullable();
            $table->string('position')->nullable();
            $table->string('state')->nullable();
            $table->string('recruiter')->nullable()->index();
            $table->date('applied_on')->nullable()->index();
            $table->date('contacted_on')->nullable();

            // Exact-duplicate fingerprint (all column values + name + group)
            $table->string('content_hash', 64)->unique();

            $table->json('columns')->nullable(); // full column map title=>text
            $table->json('raw')->nullable();

            $table->timestamp('monday_created_at')->nullable();
            $table->timestamp('monday_updated_at')->nullable();
            $table->timestamps();

            $table->index(['company', 'status_key']);
            $table->index(['company', 'applied_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_leads');
    }
};
