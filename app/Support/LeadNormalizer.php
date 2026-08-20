<?php

namespace App\Support;

use Illuminate\Support\Str;

class LeadNormalizer
{
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === null || $digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return '1'.$digits;
        }

        return $digits;
    }

    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return Str::lower(trim($email));
    }

    public static function normalizeName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $cleaned = preg_replace('/\s+/', ' ', Str::lower(trim($name)));

        return $cleaned !== '' ? $cleaned : null;
    }

    /**
     * Name for cross-board matching — strips Monday "(copy)" / trailing "copy".
     */
    public static function canonicalName(?string $name): ?string
    {
        $normalized = self::normalizeName($name);
        if ($normalized === null) {
            return null;
        }

        $canonical = preg_replace('/\s*\(copy\)\s*/i', ' ', $normalized) ?? $normalized;
        $canonical = preg_replace('/\scopy$/i', '', $canonical) ?? $canonical;
        $canonical = preg_replace('/\s+/', ' ', trim($canonical)) ?? '';

        return $canonical !== '' ? $canonical : null;
    }

    public static function isProcessBoardName(?string $boardName): bool
    {
        $t = Str::lower(trim((string) $boardName));
        if ($t === '') {
            return false;
        }

        if (str_contains($t, 'hr process')) {
            return true;
        }

        // "JM Process", "BP Process", "JDM Process", "WF Process"
        if (preg_match('/\b(jm|bp|wf|jdm)\b.*\bprocess\b/', $t) === 1) {
            return true;
        }

        if (preg_match('/\bprocess\b.*\b(jm|bp|wf|jdm)\b/', $t) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Recruiter name from "New leads Winston" / "Follow up Isaac".
     */
    public static function ownerFromBoardName(?string $boardName): ?string
    {
        $t = trim((string) $boardName);
        if ($t === '' || self::isProcessBoardName($t)) {
            return null;
        }

        if (preg_match('/^(new leads|follow[- ]?up)\s+(.+)$/i', $t, $matches) === 1) {
            $owner = trim($matches[2]);

            return $owner !== '' ? $owner : null;
        }

        return null;
    }

    public static function columnValue(?array $columns, array $needles): ?string
    {
        if (! is_array($columns) || $columns === []) {
            return null;
        }

        $needles = array_map(fn ($n) => Str::lower(trim((string) $n)), $needles);

        foreach ($columns as $title => $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }
            $hay = Str::lower(trim((string) $title));
            foreach ($needles as $needle) {
                if ($hay === $needle) {
                    return $text;
                }
            }
        }

        foreach ($columns as $title => $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }
            $hay = Str::lower(trim((string) $title));
            foreach ($needles as $needle) {
                if (str_contains($hay, $needle)) {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * MOVE TO is a person (Isaac) vs a pipeline label (Hired).
     */
    public static function isPersonMoveTarget(?string $value): bool
    {
        $t = Str::lower(trim((string) $value));
        if ($t === '') {
            return false;
        }

        foreach ([
            'hired', 'rejected', 'loaded', 'follow', 'callback', 'call back',
            'n/a', 'not valid', 'invalid', 'terminated', 'company driver',
            'lease', 'possible', 'get update', 'not touched',
        ] as $blocked) {
            if ($t === $blocked || str_contains($t, $blocked)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{count: ?int, label: ?string}
     */
    public static function parseCalls(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return ['count' => null, 'label' => null];
        }

        $t = Str::lower($raw);
        if (preg_match('/\d+/', $t, $matches) === 1) {
            $count = (int) $matches[0];

            return ['count' => $count, 'label' => (string) $count];
        }
        if (str_contains($t, 'more')) {
            return ['count' => null, 'label' => 'More'];
        }
        if (str_contains($t, 'not')) {
            return ['count' => 0, 'label' => 'Not touched'];
        }

        return ['count' => null, 'label' => $raw];
    }

    /**
     * Process-board outcomes that override recruiter-board groups.
     */
    public static function isAuthoritativeProcessStatus(?string $statusKey): bool
    {
        return in_array(Str::lower(trim((string) $statusKey)), [
            'hired',
            'rejected',
            'terminated',
        ], true);
    }

    /**
     * Map group/status text → stable filter keys for UI.
     */
    public static function statusKey(?string $label): string
    {
        $t = Str::lower(trim((string) $label));

        return match (true) {
            $t === '' => 'unknown',
            str_contains($t, 'reject') => 'rejected',
            $t === 'n/a' || $t === 'na' || str_contains($t, 'n/a') || $t === 'not answered' => 'n_a',
            str_contains($t, 'follow') => 'follow_up',
            str_contains($t, 'call back') || str_contains($t, 'callback') => 'call_back',
            str_contains($t, 'not valid') || str_contains($t, 'invalid') => 'not_valid',
            str_contains($t, 'hir') => 'hired',
            str_contains($t, 'lease') => 'lease_driver',
            str_contains($t, 'company driver') => 'company_driver',
            str_contains($t, 'termin') || str_contains($t, 'fired') => 'terminated',
            str_contains($t, 'return') => 'returned',
            str_contains($t, 'load') => 'loaded',
            default => Str::slug($t, '_'),
        };
    }

    /**
     * Exact-duplicate fingerprint: name + group + every column text (sorted by title).
     * Board / Monday item id intentionally excluded so clones across boards collapse.
     */
    public static function contentHash(string $name, ?string $groupTitle, array $columnsByTitle): string
    {
        ksort($columnsByTitle);
        $payload = [
            'name' => self::normalizeName($name) ?? '',
            'group' => Str::lower(trim((string) $groupTitle)),
            'columns' => $columnsByTitle,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
