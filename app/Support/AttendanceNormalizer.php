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
            str_contains($a, 'checked out')
                || str_contains($a, 'check out')
                || str_contains($a, 'check-out') => 'check_out',
            str_contains($a, 'checked in')
                || str_contains($a, 'check in')
                || str_contains($a, 'check-in')
                || str_contains($a, 'entered') => 'check_in',
            // "exit" but not "no exit scan"
            str_contains($a, 'exit') && ! str_contains($a, 'no exit') => 'check_out',
            (bool) preg_match('/\b(out|leave)\b/', $a) => 'check_out',
            (bool) preg_match('/\b(in|enter)\b/', $a) => 'check_in',
            default => 'other',
        };
    }

    public static function didntComeFlag(?string $value): bool
    {
        $v = Str::lower(trim((string) $value));

        return in_array($v, ['yes', 'y', 'true', '1'], true);
    }

    /**
     * Day status badges: present | late | no_show | missing_punch | excused | pending_review.
     * Break is an event type only — never a day status when the employee checked in.
     */
    public static function deriveDayStatus(
        bool $didntCome,
        int $lateMinutes,
        ?string $checkIn,
        ?string $checkOut,
        bool $hasBreak = false,
        bool $manualOverrideStatus = false,
        ?string $overrideStatus = null,
    ): string {
        if ($manualOverrideStatus && $overrideStatus) {
            return $overrideStatus === 'break' ? 'present' : $overrideStatus;
        }

        if ($didntCome) {
            return 'no_show';
        }

        if ($lateMinutes > 0) {
            return 'late';
        }

        if ($checkIn) {
            return 'present';
        }

        if ($checkOut || $hasBreak) {
            return 'pending_review';
        }

        return 'pending_review';
    }

    /** Display status for Command Center / badges — never return break. */
    public static function displayStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return $status === 'break' ? 'present' : $status;
    }
}
