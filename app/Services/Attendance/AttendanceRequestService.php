<?php

namespace App\Services\Attendance;

use App\Models\AppNotification;
use App\Models\AttendanceAuditLog;
use App\Models\AttendanceDay;
use App\Models\AttendanceRequest;
use App\Models\User;
use App\Services\Integrations\Attendance\AttendanceMetricsAggregator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceRequestService
{
    public function __construct(
        private readonly AttendanceScopeService $scope,
        private readonly AttendanceMetricsAggregator $metrics,
    ) {}

    public function create(User $employee, array $payload): AttendanceRequest
    {
        $request = AttendanceRequest::query()->create([
            'user_id' => $employee->id,
            'company' => $employee->company,
            'type' => $payload['type'],
            'date' => $payload['date'],
            'related_day_id' => $payload['related_day_id'] ?? null,
            'message' => $payload['message'],
            'status' => 'pending',
        ]);

        foreach ($this->scope->notificationRecipients($employee->company, $employee) as $recipient) {
            AppNotification::query()->create([
                'notifiable_type' => $recipient['type'],
                'notifiable_id' => $recipient['id'],
                'type' => 'attendance_request',
                'company' => $employee->company,
                'title' => ucfirst($request->type).' request from '.$employee->first_name.' '.$employee->last_name,
                'body' => $request->message,
                'data' => [
                    'attendance_request_id' => $request->id,
                    'user_id' => $employee->id,
                    'date' => $request->date->toDateString(),
                    'request_type' => $request->type,
                ],
            ]);
        }

        return $request;
    }

    public function approve(mixed $actor, AttendanceRequest $request, ?string $comment = null): AttendanceRequest
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['status' => ['Request is already resolved.']]);
        }

        return DB::transaction(function () use ($actor, $request, $comment) {
            $day = $this->resolveRelatedDay($request);

            if ($request->type === 'absence') {
                $before = $day?->toArray();
                $day = $this->overrideDay($day, $request, $actor, [
                    'status' => 'excused',
                    'admin_note' => $comment ?: 'Absence approved.',
                ]);
                $this->log($actor, 'approve', $day, $request, $before, $day->toArray());
                $this->metrics->rebuildForUserDate($request->user_id, $request->date->toDateString());
            } else {
                $this->log($actor, 'approve', $day, $request, null, ['status' => 'approved']);
            }

            $request->fill([
                'status' => $request->type === 'absence' ? 'approved' : 'resolved',
                'admin_comment' => $comment,
                'resolved_at' => now(),
                'resolved_by_admin_id' => $actor instanceof \App\Models\Admin ? $actor->id : null,
                'resolved_by_access_account_id' => $actor instanceof \App\Models\AccessAccount ? $actor->id : null,
            ])->save();

            return $request->fresh();
        });
    }

    public function reject(mixed $actor, AttendanceRequest $request, ?string $comment = null): AttendanceRequest
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['status' => ['Request is already resolved.']]);
        }

        $request->fill([
            'status' => 'rejected',
            'admin_comment' => $comment,
            'resolved_at' => now(),
            'resolved_by_admin_id' => $actor instanceof \App\Models\Admin ? $actor->id : null,
            'resolved_by_access_account_id' => $actor instanceof \App\Models\AccessAccount ? $actor->id : null,
        ])->save();

        $this->log($actor, 'reject', $request->relatedDay, $request, null, $request->toArray());

        return $request->fresh();
    }

    public function overrideDay(
        ?AttendanceDay $day,
        ?AttendanceRequest $request,
        mixed $actor,
        array $fields,
    ): AttendanceDay {
        if (! $day && $request) {
            $day = AttendanceDay::query()->firstOrNew([
                'user_id' => $request->user_id,
                'date' => $request->date,
            ]);
            $day->company = $request->company;
        }

        if (! $day) {
            throw ValidationException::withMessages(['day' => ['Attendance day not found.']]);
        }

        $day->fill(array_merge($fields, [
            'is_manual_override' => true,
            'overridden_by_admin_id' => $actor instanceof \App\Models\Admin ? $actor->id : null,
            'overridden_at' => now(),
        ]))->save();

        return $day;
    }

    private function resolveRelatedDay(AttendanceRequest $request): ?AttendanceDay
    {
        if ($request->related_day_id) {
            return AttendanceDay::query()->find($request->related_day_id);
        }

        return AttendanceDay::query()
            ->where('user_id', $request->user_id)
            ->where('date', $request->date)
            ->first();
    }

    private function log(
        mixed $actor,
        string $action,
        ?AttendanceDay $day,
        AttendanceRequest $request,
        ?array $before,
        ?array $after,
    ): void {
        AttendanceAuditLog::query()->create([
            'attendance_day_id' => $day?->id,
            'attendance_request_id' => $request->id,
            'user_id' => $request->user_id,
            'actor_type' => $actor ? $actor::class : 'system',
            'actor_id' => $actor?->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
