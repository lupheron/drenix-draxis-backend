<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_daily_metrics', function (Blueprint $table) {
            $table->unsignedInteger('loaded')->default(0)->after('hires');
        });
    }

    public function down(): void
    {
        Schema::table('employee_daily_metrics', function (Blueprint $table) {
            $table->dropColumn('loaded');
        });
    }
};
