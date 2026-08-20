<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->string('call_type', 32)->nullable()->after('result'); // outbound|inbound|missed|voicemail|other
            $table->string('action')->nullable()->after('call_type');
            $table->index(['user_id', 'call_type', 'started_at']);
        });

        Schema::table('employee_daily_metrics', function (Blueprint $table) {
            $table->unsignedInteger('outbound_calls')->default(0)->after('calls_made');
            $table->unsignedInteger('inbound_calls')->default(0)->after('outbound_calls');
            $table->unsignedInteger('missed_calls')->default(0)->after('inbound_calls');
            $table->unsignedInteger('voicemail_calls')->default(0)->after('missed_calls');
            $table->unsignedInteger('other_calls')->default(0)->after('voicemail_calls');
            $table->unsignedInteger('outbound_minutes')->default(0)->after('minutes_on_call');
            $table->unsignedInteger('inbound_minutes')->default(0)->after('outbound_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'call_type', 'started_at']);
            $table->dropColumn(['call_type', 'action']);
        });

        Schema::table('employee_daily_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'outbound_calls',
                'inbound_calls',
                'missed_calls',
                'voicemail_calls',
                'other_calls',
                'outbound_minutes',
                'inbound_minutes',
            ]);
        });
    }
};
