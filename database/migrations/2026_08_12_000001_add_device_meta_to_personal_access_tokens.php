<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('personal_access_tokens', 'ip')) {
                $table->string('ip', 45)->nullable()->after('abilities');
            }
            if (! Schema::hasColumn('personal_access_tokens', 'user_agent')) {
                $table->text('user_agent')->nullable()->after('ip');
            }
            if (! Schema::hasColumn('personal_access_tokens', 'device_label')) {
                $table->string('device_label')->nullable()->after('user_agent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            foreach (['device_label', 'user_agent', 'ip'] as $col) {
                if (Schema::hasColumn('personal_access_tokens', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
