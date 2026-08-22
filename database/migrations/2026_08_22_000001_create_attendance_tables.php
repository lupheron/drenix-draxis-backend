<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('company', 10)->index();
            $table->string('external_key')->unique(); // sheet row fingerprint
            $table->string('sheet_tab')->nullable();
            $table->unsignedInteger('sheet_row')->nullable();
            $table->string('employee_sheet_id')->nullable();
            $table->string('employee_name')->nullable();
            $table->string('action')->nullable(); // raw Action column
            $table->string('event_type')->nullable(); // check_in|check_out|break|no_show|other
            $table->timestampTz('occurred_at')->nullable()->index();
            $table->date('shift_date')->nullable()->index();
            $table->string('shift_time')->nullable();
            $table->unsignedInteger('late_minutes')->nullable();
            $table->string('status_raw')->nullable();
            $table->text('notes')->nullable();
            $table->string('didnt_come')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'shift_date']);
            $table->index(['company', 'shift_date']);
        });

        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('company', 10)->index();
            $table->date('date')->index();
            $table->string('status', 32)->default('pending_review')->index();
            // present|late|no_show|break|missing_punch|excused|pending_review
            $table->timestampTz('check_in_at')->nullable();
            $table->timestampTz('check_out_at')->nullable();
            $table->timestampTz('break_at')->nullable();
            $table->unsignedInteger('late_minutes')->default(0);
            $table->string('shift_start')->nullable();
            $table->string('shift_end')->nullable();
            $table->text('sheet_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->boolean('is_manual_override')->default(false);
            $table->foreignId('overridden_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('overridden_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index(['company', 'date', 'status']);
        });

        Schema::create('attendance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('company', 10)->index();
            $table->string('type', 16); // dispute|absence
            $table->date('date')->index();
            $table->foreignId('related_day_id')->nullable()->constrained('attendance_days')->nullOnDelete();
            $table->text('message');
            $table->string('status', 16)->default('pending')->index(); // pending|approved|rejected|resolved
            $table->text('admin_comment')->nullable();
            $table->foreignId('resolved_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('resolved_by_access_account_id')->nullable()->constrained('access_accounts')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['company', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('notifiable_type'); // Admin|AccessAccount
            $table->unsignedBigInteger('notifiable_id');
            $table->string('type'); // attendance_request
            $table->string('company', 10)->nullable()->index();
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        Schema::create('attendance_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_day_id')->nullable()->constrained('attendance_days')->nullOnDelete();
            $table->foreignId('attendance_request_id')->nullable()->constrained('attendance_requests')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type')->nullable(); // Admin|AccessAccount|system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action'); // sync_upsert|override|approve|reject|excuse
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();
        });

        Schema::table('employee_daily_metrics', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_daily_metrics', 'no_shows')) {
                $table->unsignedInteger('no_shows')->default(0)->after('lates');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_daily_metrics', function (Blueprint $table) {
            if (Schema::hasColumn('employee_daily_metrics', 'no_shows')) {
                $table->dropColumn('no_shows');
            }
        });

        Schema::dropIfExists('attendance_audit_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('attendance_requests');
        Schema::dropIfExists('attendance_days');
        Schema::dropIfExists('attendance_events');
    }
};
