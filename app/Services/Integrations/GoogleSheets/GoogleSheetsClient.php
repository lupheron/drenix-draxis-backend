<?php

namespace App\Services\Integrations\GoogleSheets;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GoogleSheetsClient
{
    private bool $credentialsLoaded = false;

    private ?array $credentials = null;

    public function isConfigured(): bool
    {
        $creds = $this->credentials();

        return is_array($creds) && ! empty($creds['client_email']) && ! empty($creds['private_key']);
    }

    /**
     * @return list<list<string|null>>
     */
    public function getTabValues(string $spreadsheetId, string $tabName): array
    {
        $token = $this->accessToken();
        $range = rawurlencode("'{$tabName}'!A:Z");

        $response = Http::timeout(120)
            ->withToken($token)
            ->get("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}");

        if (! $response->successful()) {
            $body = $response->json();
            $code = $body['error']['code'] ?? $response->status();
            $message = $body['error']['message'] ?? $response->body();

            throw new RuntimeException("Google Sheets API failed ({$code}): {$message}");
        }

        return $response->json('values') ?? [];
    }

    private function accessToken(): string
    {
        $creds = $this->credentials();
        if (! $creds) {
            throw new RuntimeException('Google service account not configured.');
        }

        return Cache::remember(
            'google_sheets_token_'.md5($creds['client_email'] ?? 'default'),
            3300,
            fn () => $this->fetchAccessToken($creds),
        );
    }

    private function fetchAccessToken(array $creds): string
    {
        $now = time();
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = $this->base64Url(json_encode([
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now,
        ]));

        $unsigned = "{$header}.{$claim}";
        $privateKey = openssl_pkey_get_private($creds['private_key']);
        if (! $privateKey) {
            throw new RuntimeException('Invalid Google service account private key. Re-paste GOOGLE_SERVICE_ACCOUNT_JSON or use GOOGLE_SERVICE_ACCOUNT_JSON_BASE64.');
        }

        openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $jwt = $unsigned.'.'.$this->base64Url($signature);

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Google OAuth failed: '.$response->body());
        }

        return (string) $response->json('access_token');
    }

    private function credentials(): ?array
    {
        if ($this->credentialsLoaded) {
            return $this->credentials;
        }

        $this->credentialsLoaded = true;

        $base64 = config('integrations.attendance.google_service_account_json_base64');
        if (is_string($base64) && $base64 !== '') {
            $decodedJson = base64_decode($base64, true);
            if ($decodedJson !== false) {
                $decoded = json_decode($decodedJson, true);
                if (is_array($decoded)) {
                    return $this->credentials = $decoded;
                }
            }
        }

        $json = config('integrations.attendance.google_service_account_json');
        if (is_string($json) && $json !== '') {
            $json = trim($json);
            if (str_starts_with($json, "'") && str_ends_with($json, "'")) {
                $json = substr($json, 1, -1);
            }

            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $this->credentials = $decoded;
            }

            Log::warning('GOOGLE_SERVICE_ACCOUNT_JSON is set but invalid JSON', [
                'json_error' => json_last_error_msg(),
            ]);
        }

        $path = config('integrations.attendance.google_service_account_path');
        if (is_string($path) && $path !== '' && is_readable($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                return $this->credentials = $decoded;
            }
        }

        Log::debug('Google service account not configured for Sheets sync.');

        return $this->credentials = null;
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
