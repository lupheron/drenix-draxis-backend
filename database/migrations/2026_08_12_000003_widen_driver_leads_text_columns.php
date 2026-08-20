<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_leads', function (Blueprint $table) {
            $table->text('state')->nullable()->change();
            $table->text('position')->nullable()->change();
            $table->text('platform')->nullable()->change();
            $table->text('recruiter')->nullable()->change();
            $table->text('status_label')->nullable()->change();
            $table->text('group_title')->nullable()->change();
            $table->text('name')->nullable()->change();
            $table->text('email')->nullable()->change();
            $table->text('email_normalized')->nullable()->change();
            $table->text('name_normalized')->nullable()->change();
            $table->text('board_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep as text — no safe downsize
    }
};
