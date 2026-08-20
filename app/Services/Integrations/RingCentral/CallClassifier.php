<?php

namespace App\Services\Integrations\RingCentral;

class CallClassifier
{
    /**
     * Normalize RingCentral call-log into a stable call_type for the UI.
     *
     * @return array{call_type: string, action: ?string, direction: ?string, result: ?string}
     */
    public static function classify(array $call): array
    {
        $direction = strtolower(trim((string) ($call['direction'] ?? '')));
        $result = strtolower(trim((string) ($call['result'] ?? '')));
        $action = strtolower(trim((string) ($call['action'] ?? '')));
        $reason = strtolower(trim((string) ($call['reason'] ?? '')));

        $blob = "{$result} {$action} {$reason}";

        $callType = match (true) {
            str_contains($blob, 'voicemail') => 'voicemail',
            str_contains($blob, 'missed')
                || str_contains($blob, 'no answer')
                || str_contains($blob, 'noanswer') => 'missed',
            $direction === 'outbound' => 'outbound',
            $direction === 'inbound' => 'inbound',
            default => 'other',
        };

        return [
            'call_type' => $callType,
            'action' => $call['action'] ?? null,
            'direction' => $call['direction'] ?? null,
            'result' => $call['result'] ?? null,
        ];
    }
}
