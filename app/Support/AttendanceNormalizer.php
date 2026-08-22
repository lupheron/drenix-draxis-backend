<?php

namespace App\Support;

use Illuminate\Support\Str;

class AttendanceNormalizer
{
    public static function normalizeName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $cleaned = preg_replace('/\s+/', ' ', Str::lower(trim($name)));

        return $cleaned !== '' ? $cleaned : null;
    }

    public static function parseEventType(?string $action): string
    {
        $a = Str::lower(trim((string) $action));

        return match (true) {
            $a === '' => 'other',
            str_contains($a, 'break') => 'break',
            str_contains($a, 'no show') || str_contains($a, 'no-show') => 'no_show',
            str_contains($a, 'exit') || str_contains($a, 'out') || str_contains($a, 'leave') => 'check_out',
            str_contains($a, 'enter') || str_contains($a, 'in') => 'check_in',
            default => 'other',
        };
    }

    public static function didntComeFlag(?string $value): bool
    {
        $v = Str::lower(trim((string) $value));

        return in_array($v, ['yes', 'y', 'true', '1'], true);
    }

    public static function deriveDayStatus(
        bool $didntCome,
        int $lateMinutes,
        ?string $checkIn,
        ?string $checkOut,
        bool $hasBreak,
        bool $manualOverrideStatus = false,
        ?string $overrideStatus = null,
    ): string {
        if ($manualOverrideStatus && $overrideStatus) {
            return $overrideStatus;
        }

        if ($didntCome) {
            return 'no_show';
        }

        if ($lateMinutes > 0 && $checkIn) {
            return 'late';
        }

        if ($hasBreak) {
            return 'break';
        }

        if ($checkIn && ! $checkOut) {
            return 'missing_punch';
        }

        if ($checkIn && $checkOut) {
            return 'present';
        }

        if ($checkIn) {
            return 'present';
        }

        return 'pending_review';
    }
}
