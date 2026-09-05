<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lead phone → WhatsApp / Telegram presence checks.
 *
 * Telegram note: privacy "Who can find me by my number" can hide accounts from
 * contact-import / lookup APIs even when the phone opens a chat in the Telegram app.
 * Empty lookup ⇒ telegram_status "not_found_or_hidden" (not "does not exist").
 */
class LeadSocialVerificationService
{
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

        $wa = $this->checkWhatsApp($digits);
        $tg = $this->checkTelegram($digits);

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
     * @return array{present: bool, status: string}
     */
    private function checkWhatsApp(string $digits): array
    {
        $token = config('services.whapi.token');
        if (! $token) {
            return ['present' => false, 'status' => 'error'];
        }

        $candidates = array_values(array_unique(array_filter([
            $digits,
            '+'.$digits,
        ])));

        $lastStatus = 'error';

        foreach ($candidates as $contact) {
            try {
                $response = Http::timeout(25)
                    ->withToken($token)
                    ->acceptJson()
                    ->post('https://gate.whapi.cloud/contacts', [
                        'blocking' => 'wait',
                        'force_check' => true,
                        'contacts' => [$contact],
                    ]);

                Log::info('Whapi contacts response', [
                    'contact_format' => str_starts_with($contact, '+') ? 'e164' : 'digits',
                    'http_status' => $response->status(),
                    'body' => $this->redactSecrets($response->json() ?? $response->body()),
                ]);

                if ($response->failed()) {
                    $lastStatus = 'error';
                    continue;
                }

                $row = $response->json('contacts.0')
                    ?? ($response->json('contacts')[0] ?? null);

                $parsed = $this->parseWhapiContactStatus($row);
                if ($parsed['status'] === 'pending') {
                    // One short retry with wait
                    usleep(800_000);
                    $retry = Http::timeout(25)
                        ->withToken($token)
                        ->acceptJson()
                        ->post('https://gate.whapi.cloud/contacts', [
                            'blocking' => 'wait',
                            'force_check' => true,
                            'contacts' => [$contact],
                        ]);

                    Log::info('Whapi contacts retry', [
                        'http_status' => $retry->status(),
                        'body' => $this->redactSecrets($retry->json() ?? $retry->body()),
                    ]);

                    $parsed = $this->parseWhapiContactStatus(
                        $retry->json('contacts.0') ?? ($retry->json('contacts')[0] ?? null)
                    );
                }

                if ($parsed['status'] === 'valid') {
                    return ['present' => true, 'status' => 'valid'];
                }

                if ($parsed['status'] === 'not_registered') {
                    $lastStatus = 'not_registered';
                    // try next phone format before concluding
                    continue;
                }

                if ($parsed['status'] === 'pending') {
                    return ['present' => false, 'status' => 'pending'];
                }

                $lastStatus = $parsed['status'];
            } catch (\Throwable $e) {
                Log::warning('Whapi contacts exception', ['error' => $e->getMessage()]);
                $lastStatus = 'error';
            }
        }

        return [
            'present' => false,
            'status' => $lastStatus === 'not_registered' ? 'not_registered' : $lastStatus,
        ];
    }

    /**
     * @return array{status: string}
     */
    private function parseWhapiContactStatus(mixed $row): array
    {
        if (! is_array($row)) {
            return ['status' => 'error'];
        }

        $status = strtolower(trim((string) ($row['status'] ?? '')));
        $waId = $row['wa_id'] ?? $row['id'] ?? null;

        return match (true) {
            in_array($status, ['valid', 'success', 'registered'], true) => ['status' => 'valid'],
            $waId && ! in_array($status, ['invalid', 'not_registered', 'failed'], true) => ['status' => 'valid'],
            in_array($status, ['invalid', 'not_registered', 'failed', 'error'], true) => ['status' => 'not_registered'],
            in_array($status, ['pending', 'processing', 'checking'], true) => ['status' => 'pending'],
            $status === '' && $waId => ['status' => 'valid'],
            $status === '' => ['status' => 'pending'],
            default => ['status' => 'error'],
        };
    }

    /**
     * @return array{present: bool, status: string, username?: string|null, name?: string|null}
     */
    private function checkTelegram(string $digits): array
    {
        // 1) MTProto user session (ImportContacts) — preferred when session file exists
        $session = config('services.telegram.session_file');
        $apiId = config('services.telegram.api_id');
        $apiHash = config('services.telegram.api_hash');
        if ($session && $apiId && $apiHash && is_readable($session)) {
            $mt = $this->checkTelegramViaMtprotoImport($digits, (string) $session, (string) $apiId, (string) $apiHash);
            if ($mt !== null) {
                return $mt;
            }
        }

        // 2) CheckNumber.ai-style lookup provider
        $lookupKey = config('services.telegram.lookup_key');
        if ($lookupKey) {
            return $this->checkTelegramViaLookupApi($digits, (string) $lookupKey);
        }

        // 3) Telegram Gateway checkSendAbility (official delivery check — not Bot API)
        $gatewayToken = config('services.telegram.bot_token');
        if ($gatewayToken) {
            return $this->checkTelegramViaGateway($digits, (string) $gatewayToken);
        }

        return ['present' => false, 'status' => 'not_configured'];
    }

    /**
     * ImportContacts via MadelineProto when the package + logged-in session are available.
     *
     * @return array{present: bool, status: string, username?: string|null, name?: string|null}|null
     */
    private function checkTelegramViaMtprotoImport(
        string $digits,
        string $sessionFile,
        string $apiId,
        string $apiHash,
    ): ?array {
        if (! class_exists(\danog\MadelineProto\API::class)) {
            Log::info('MadelineProto not installed; skip MTProto ImportContacts');

            return null;
        }

        try {
            $settings = (new \danog\MadelineProto\Settings)
                ->getAppInfo()
                ->setApiId((int) $apiId)
                ->setApiHash($apiHash);

            $api = new \danog\MadelineProto\API($sessionFile, $settings);
            $api->start();

            $e164 = '+'.$digits;
            $result = $api->contacts->importContacts(
                contacts: [[
                    '_' => 'inputPhoneContact',
                    'client_id' => random_int(1, PHP_INT_MAX),
                    'phone' => $e164,
                    'first_name' => 'Lead',
                    'last_name' => 'Check',
                ]],
            );

            Log::info('Telegram MTProto importContacts result', [
                'users_count' => count($result['users'] ?? []),
                'imported' => count($result['imported'] ?? []),
                'retry_contacts' => count($result['retry_contacts'] ?? []),
            ]);

            $users = $result['users'] ?? [];
            if ($users === []) {
                // Account may still exist but privacy hides phone from contact import
                return ['present' => false, 'status' => 'not_found_or_hidden'];
            }

            $user = $users[0];
            $name = trim(implode(' ', array_filter([
                $user['first_name'] ?? null,
                $user['last_name'] ?? null,
            ])));

            return [
                'present' => true,
                'status' => 'found',
                'username' => $user['username'] ?? null,
                'name' => $name !== '' ? $name : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Telegram MTProto ImportContacts failed', ['error' => $e->getMessage()]);

            return ['present' => false, 'status' => 'error'];
        }
    }

    /**
     * @return array{present: bool, status: string}
     */
    private function checkTelegramViaLookupApi(string $digits, string $apiKey): array
    {
        try {
            $response = Http::timeout(25)->get('https://api.checknumber.ai/v1/telegram/check', [
                'api_key' => $apiKey,
                'phone' => $digits,
            ]);

            Log::info('CheckNumber.ai telegram response', [
                'http_status' => $response->status(),
                'body' => $this->redactSecrets($response->json() ?? $response->body()),
            ]);

            if ($response->failed()) {
                return ['present' => false, 'status' => 'error'];
            }

            if ($response->json('exists') === true) {
                return ['present' => true, 'status' => 'found'];
            }

            // Provider "false" cannot prove non-existence under Telegram privacy
            return ['present' => false, 'status' => 'not_found_or_hidden'];
        } catch (\Throwable $e) {
            Log::warning('CheckNumber.ai exception', ['error' => $e->getMessage()]);

            return ['present' => false, 'status' => 'error'];
        }
    }

    /**
     * Official Gateway: ok ⇒ reachable on Telegram (found).
     * Delivery/ability errors ⇒ not_found_or_hidden (privacy or not registered).
     * Auth/balance/network ⇒ error.
     *
     * @return array{present: bool, status: string}
     */
    private function checkTelegramViaGateway(string $digits, string $gatewayToken): array
    {
        $candidates = array_values(array_unique([
            '+'.$digits,
            $digits,
        ]));

        $sawAbilityDenied = false;

        foreach ($candidates as $phone) {
            try {
                $response = Http::timeout(25)
                    ->withToken($gatewayToken)
                    ->acceptJson()
                    ->asJson()
                    ->post('https://gatewayapi.telegram.org/checkSendAbility', [
                        'phone_number' => $phone,
                    ]);

                $json = $response->json() ?? [];
                Log::info('Telegram Gateway checkSendAbility', [
                    'phone_format' => str_starts_with($phone, '+') ? 'e164' : 'digits',
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

                // Balance / auth / flood → hard error (don't claim privacy)
                if (
                    str_contains($error, 'BALANCE')
                    || str_contains($error, 'FLOOD')
                    || str_contains($error, 'UNAUTHORIZED')
                    || str_contains($error, 'AUTH')
                    || $response->status() === 401
                    || $response->status() === 402
                    || $response->status() === 429
                ) {
                    return ['present' => false, 'status' => 'error'];
                }

                // Cannot send / not occupied / invalid phone for delivery
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

                return ['present' => false, 'status' => 'error'];
            } catch (\Throwable $e) {
                Log::warning('Telegram Gateway exception', ['error' => $e->getMessage()]);

                return ['present' => false, 'status' => 'error'];
            }
        }

        if ($sawAbilityDenied) {
            return ['present' => false, 'status' => 'not_found_or_hidden'];
        }

        return ['present' => false, 'status' => 'error'];
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
