<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lead phone → WhatsApp / Telegram presence checks.
 *
 * Designed to finish in <20s: providers run in parallel with hard HTTP timeouts.
 * Timeouts/errors return partial 200 data (never hang into Cloudflare 504).
 *
 * Telegram privacy "Who can find me by my number" can hide accounts from APIs
 * even when the phone opens a chat in the Telegram app → not_found_or_hidden.
 */
class LeadSocialVerificationService
{
    private const CONNECT_TIMEOUT = 3;

    private const REQUEST_TIMEOUT = 12;

    private const CACHE_TTL_SECONDS = 86400;

    /**
     * @return array{
     *   phone: string,
     *   whatsapp: bool,
     *   whatsapp_status: string,
     *   telegram: bool,
     *   telegram_status: string,
     *   telegram_username?: string|null,
     *   telegram_name?: string|null,
     *   facebook_search_url: string,
     *   instagram_search_url: string
     * }
     */
    public function verify(string $rawPhone): array
    {
        $digits = preg_replace('/\D/', '', $rawPhone) ?? '';

        $cacheKey = 'lead_social_verify:v2:'.$digits;

        try {
            return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($digits) {
                return $this->verifyFresh($digits);
            });
        } catch (Throwable $e) {
            Log::warning('Lead social verify cache/run failed', ['error' => $e->getMessage()]);

            return $this->emptyResult($digits, 'error', 'error');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyFresh(string $digits): array
    {
        $started = microtime(true);

        $whapiToken = config('services.whapi.token');
        $gatewayToken = config('services.telegram.bot_token');
        $lookupKey = config('services.telegram.lookup_key');

        $wa = ['present' => false, 'status' => $whapiToken ? 'error' : 'error'];
        $tg = ['present' => false, 'status' => 'not_configured'];

        if (! $whapiToken && ! $gatewayToken && ! $lookupKey) {
            Log::warning('Lead social verify: no WHAPI_TOKEN / TG_BOT_TOKEN / TG_LOOKUP_KEY configured');

            return $this->emptyResult($digits, 'error', 'not_configured');
        }

        if ($lookupKey || $gatewayToken) {
            $tg = ['present' => false, 'status' => 'error'];
        }

        try {
            $responses = Http::pool(function (Pool $pool) use ($digits, $whapiToken, $gatewayToken, $lookupKey) {
                if ($whapiToken) {
                    // Parallel formats; no_wait so Whapi does not block the request
                    $pool->as('wa_digits')
                        ->connectTimeout(self::CONNECT_TIMEOUT)
                        ->timeout(self::REQUEST_TIMEOUT)
                        ->withToken($whapiToken)
                        ->acceptJson()
                        ->post('https://gate.whapi.cloud/contacts', [
                            'blocking' => 'no_wait',
                            'force_check' => true,
                            'contacts' => [$digits],
                        ]);

                    $pool->as('wa_e164')
                        ->connectTimeout(self::CONNECT_TIMEOUT)
                        ->timeout(self::REQUEST_TIMEOUT)
                        ->withToken($whapiToken)
                        ->acceptJson()
                        ->post('https://gate.whapi.cloud/contacts', [
                            'blocking' => 'no_wait',
                            'force_check' => true,
                            'contacts' => ['+'.$digits],
                        ]);
                }

                if ($lookupKey) {
                    $pool->as('tg_lookup')
                        ->connectTimeout(self::CONNECT_TIMEOUT)
                        ->timeout(self::REQUEST_TIMEOUT)
                        ->get('https://api.checknumber.ai/v1/telegram/check', [
                            'api_key' => $lookupKey,
                            'phone' => $digits,
                        ]);
                } elseif ($gatewayToken) {
                    $pool->as('tg_e164')
                        ->connectTimeout(self::CONNECT_TIMEOUT)
                        ->timeout(self::REQUEST_TIMEOUT)
                        ->withToken($gatewayToken)
                        ->acceptJson()
                        ->asJson()
                        ->post('https://gatewayapi.telegram.org/checkSendAbility', [
                            'phone_number' => '+'.$digits,
                        ]);

                    $pool->as('tg_digits')
                        ->connectTimeout(self::CONNECT_TIMEOUT)
                        ->timeout(self::REQUEST_TIMEOUT)
                        ->withToken($gatewayToken)
                        ->acceptJson()
                        ->asJson()
                        ->post('https://gatewayapi.telegram.org/checkSendAbility', [
                            'phone_number' => $digits,
                        ]);
                }
            });
        } catch (Throwable $e) {
            Log::warning('Lead social verify pool failed', [
                'error' => $e->getMessage(),
                'ms' => (int) ((microtime(true) - $started) * 1000),
            ]);

            return $this->emptyResult($digits, 'error', $gatewayToken || $lookupKey ? 'error' : 'not_configured');
        }

        if ($whapiToken) {
            $wa = $this->resolveWhatsAppFromPool($responses);
        }

        if ($lookupKey) {
            $tg = $this->resolveTelegramLookupFromPool($responses);
        } elseif ($gatewayToken) {
            $tg = $this->resolveTelegramGatewayFromPool($responses);
        }

        $elapsedMs = (int) ((microtime(true) - $started) * 1000);
        Log::info('Lead social verify done', [
            'phone_len' => strlen($digits),
            'ms' => $elapsedMs,
            'whatsapp_status' => $wa['status'],
            'telegram_status' => $tg['status'],
        ]);

        $data = [
            'phone' => $digits,
            'whatsapp' => $wa['present'],
            'whatsapp_status' => $wa['status'],
            'telegram' => $tg['present'],
            'telegram_status' => $tg['status'],
            'facebook_search_url' => 'https://www.facebook.com/search/top/?q='.urlencode($digits),
            'instagram_search_url' => 'https://www.instagram.com/',
        ];

        if (! empty($tg['username'])) {
            $data['telegram_username'] = $tg['username'];
        }
        if (! empty($tg['name'])) {
            $data['telegram_name'] = $tg['name'];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $responses
     * @return array{present: bool, status: string}
     */
    private function resolveWhatsAppFromPool(array $responses): array
    {
        $last = 'error';

        foreach (['wa_digits', 'wa_e164'] as $key) {
            $response = $responses[$key] ?? null;
            $parsed = $this->parseWhapiResponse($key, $response);

            if ($parsed['status'] === 'valid') {
                return ['present' => true, 'status' => 'valid'];
            }

            if ($parsed['status'] === 'not_registered') {
                $last = 'not_registered';
                continue;
            }

            if ($parsed['status'] === 'pending') {
                $last = 'pending';
                continue;
            }

            $last = $parsed['status'];
        }

        return [
            'present' => false,
            'status' => in_array($last, ['not_registered', 'pending', 'error'], true) ? $last : 'error',
        ];
    }

    /**
     * @return array{present: bool, status: string}
     */
    private function parseWhapiResponse(string $label, mixed $response): array
    {
        if ($response instanceof Throwable) {
            Log::warning('Whapi request failed', [
                'label' => $label,
                'error' => $response->getMessage(),
            ]);

            return ['present' => false, 'status' => 'error'];
        }

        if (! $response instanceof Response) {
            return ['present' => false, 'status' => 'error'];
        }

        Log::info('Whapi contacts response', [
            'label' => $label,
            'http_status' => $response->status(),
            'body' => $this->redactSecrets($response->json() ?? $response->body()),
        ]);

        if ($response->failed()) {
            return ['present' => false, 'status' => 'error'];
        }

        $row = $response->json('contacts.0')
            ?? ($response->json('contacts')[0] ?? null);

        return $this->parseWhapiContactStatus($row);
    }

    /**
     * @return array{present: bool, status: string}
     */
    private function parseWhapiContactStatus(mixed $row): array
    {
        if (! is_array($row)) {
            return ['present' => false, 'status' => 'error'];
        }

        $status = strtolower(trim((string) ($row['status'] ?? '')));
        $waId = $row['wa_id'] ?? $row['id'] ?? null;

        return match (true) {
            in_array($status, ['valid', 'success', 'registered'], true) => ['present' => true, 'status' => 'valid'],
            $waId && ! in_array($status, ['invalid', 'not_registered', 'failed'], true) => ['present' => true, 'status' => 'valid'],
            in_array($status, ['invalid', 'not_registered', 'failed', 'error'], true) => ['present' => false, 'status' => 'not_registered'],
            in_array($status, ['pending', 'processing', 'checking'], true) => ['present' => false, 'status' => 'pending'],
            $status === '' && $waId => ['present' => true, 'status' => 'valid'],
            $status === '' => ['present' => false, 'status' => 'pending'],
            default => ['present' => false, 'status' => 'error'],
        };
    }

    /**
     * @param  array<string, mixed>  $responses
     * @return array{present: bool, status: string}
     */
    private function resolveTelegramLookupFromPool(array $responses): array
    {
        $response = $responses['tg_lookup'] ?? null;

        if ($response instanceof Throwable) {
            Log::warning('Telegram lookup request failed', ['error' => $response->getMessage()]);

            return ['present' => false, 'status' => 'error'];
        }

        if (! $response instanceof Response) {
            return ['present' => false, 'status' => 'error'];
        }

        Log::info('Telegram lookup response', [
            'http_status' => $response->status(),
            'body' => $this->redactSecrets($response->json() ?? $response->body()),
        ]);

        if ($response->failed()) {
            return ['present' => false, 'status' => 'error'];
        }

        if ($response->json('exists') === true) {
            return ['present' => true, 'status' => 'found'];
        }

        return ['present' => false, 'status' => 'not_found_or_hidden'];
    }

    /**
     * @param  array<string, mixed>  $responses
     * @return array{present: bool, status: string}
     */
    private function resolveTelegramGatewayFromPool(array $responses): array
    {
        $sawAbilityDenied = false;
        $sawHardError = false;

        foreach (['tg_e164', 'tg_digits'] as $key) {
            $response = $responses[$key] ?? null;

            if ($response instanceof Throwable) {
                Log::warning('Telegram Gateway request failed', [
                    'label' => $key,
                    'error' => $response->getMessage(),
                ]);
                $sawHardError = true;
                continue;
            }

            if (! $response instanceof Response) {
                $sawHardError = true;
                continue;
            }

            $json = $response->json() ?? [];
            Log::info('Telegram Gateway checkSendAbility', [
                'label' => $key,
                'http_status' => $response->status(),
                'body' => $this->redactSecrets($json ?: $response->body()),
            ]);

            if ($response->successful() && ($json['ok'] ?? null) === true) {
                return ['present' => true, 'status' => 'found'];
            }

            $error = strtoupper((string) (
                $json['error']
                ?? $json['description']
                ?? $json['error_code']
                ?? ''
            ));

            if (
                str_contains($error, 'BALANCE')
                || str_contains($error, 'FLOOD')
                || str_contains($error, 'UNAUTHORIZED')
                || str_contains($error, 'AUTH')
                || in_array($response->status(), [401, 402, 429, 500, 502, 503, 504], true)
            ) {
                $sawHardError = true;
                continue;
            }

            if (
                ($json['ok'] ?? null) === false
                || $response->status() === 400
                || str_contains($error, 'PHONE')
                || str_contains($error, 'OCCUPIED')
                || str_contains($error, 'UNOCCUPIED')
                || str_contains($error, 'INVALID')
            ) {
                $sawAbilityDenied = true;
                continue;
            }

            $sawHardError = true;
        }

        if ($sawAbilityDenied) {
            return ['present' => false, 'status' => 'not_found_or_hidden'];
        }

        return ['present' => false, 'status' => $sawHardError ? 'error' : 'error'];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(string $digits, string $waStatus, string $tgStatus): array
    {
        return [
            'phone' => $digits,
            'whatsapp' => false,
            'whatsapp_status' => $waStatus,
            'telegram' => false,
            'telegram_status' => $tgStatus,
            'facebook_search_url' => 'https://www.facebook.com/search/top/?q='.urlencode($digits),
            'instagram_search_url' => 'https://www.instagram.com/',
        ];
    }

    private function redactSecrets(mixed $payload): mixed
    {
        if (is_string($payload)) {
            return preg_replace('/(Bearer\s+)\S+/i', '$1[REDACTED]', $payload);
        }

        if (! is_array($payload)) {
            return $payload;
        }

        $redacted = $payload;
        foreach (['token', 'api_key', 'access_token', 'authorization'] as $key) {
            if (isset($redacted[$key])) {
                $redacted[$key] = '[REDACTED]';
            }
        }

        return $redacted;
    }
}
