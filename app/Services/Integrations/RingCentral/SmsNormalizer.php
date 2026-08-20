<?php

namespace App\Services\Integrations\RingCentral;

/**
 * Normalize RingCentral Message Store SMS records into stable conversation fields.
 */
class SmsNormalizer
{
    /**
     * @return array{
     *   external_id: string,
     *   conversation_id: string,
     *   direction: string,
     *   body: string|null,
     *   from_number: string|null,
     *   to_number: string|null,
     *   peer_number: string|null,
     *   peer_name: string|null,
     *   sent_at: string|null,
     *   status: string|null
     * }|null
     */
    public static function normalize(array $message): ?array
    {
        $externalId = (string) ($message['id'] ?? '');
        if ($externalId === '') {
            return null;
        }

        $type = strtoupper((string) ($message['type'] ?? ''));
        if ($type !== '' && $type !== 'SMS') {
            return null;
        }

        $rawDirection = strtolower((string) ($message['direction'] ?? ''));
        $direction = $rawDirection === 'outbound' ? 'outbound' : 'inbound';

        $fromNumber = self::phone($message['from'] ?? null);
        $fromName = self::name($message['from'] ?? null);

        $toList = $message['to'] ?? [];
        $toFirst = is_array($toList) && $toList !== [] ? $toList[0] : null;
        $toNumber = self::phone($toFirst);
        $toName = self::name($toFirst);

        if ($direction === 'inbound') {
            $peerNumber = $fromNumber;
            $peerName = $fromName;
        } else {
            $peerNumber = $toNumber;
            $peerName = $toName;
        }

        $conversationId = self::conversationId($message, $peerNumber);

        $body = $message['subject'] ?? null;
        if (is_string($body)) {
            $body = trim($body);
            if ($body === '') {
                $body = null;
            }
        } else {
            $body = null;
        }

        return [
            'external_id' => $externalId,
            'conversation_id' => $conversationId,
            'direction' => $direction,
            'body' => $body,
            'from_number' => $fromNumber,
            'to_number' => $toNumber,
            'peer_number' => $peerNumber,
            'peer_name' => $peerName,
            'sent_at' => $message['creationTime'] ?? $message['lastModifiedTime'] ?? null,
            'status' => isset($message['messageStatus']) ? (string) $message['messageStatus'] : null,
        ];
    }

    public static function conversationId(array $message, ?string $peerNumber): string
    {
        $rcId = $message['conversationId']
            ?? ($message['conversation']['id'] ?? null);

        if ($rcId !== null && $rcId !== '') {
            return (string) $rcId;
        }

        $normalizedPeer = self::normalizePhone($peerNumber);
        if ($normalizedPeer !== null) {
            return 'peer:'.$normalizedPeer;
        }

        return 'unknown:'.(string) ($message['id'] ?? '0');
    }

    public static function preview(?string $body, int $max = 120): string
    {
        $text = trim((string) $body);
        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    private static function phone(mixed $party): ?string
    {
        if (! is_array($party)) {
            return null;
        }

        $number = $party['phoneNumber'] ?? null;
        if (! is_string($number) || trim($number) === '') {
            return null;
        }

        return trim($number);
    }

    private static function name(mixed $party): ?string
    {
        if (! is_array($party)) {
            return null;
        }

        $name = $party['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return trim($name);
    }

    private static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === null || $digits === '') {
            return null;
        }

        // US 10-digit → E.164-ish key
        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return '+'.$digits;
    }
}
