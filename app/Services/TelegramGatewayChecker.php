<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramGatewayChecker
{
    private const CONNECT_TIMEOUT = 3;

    private const REQUEST_TIMEOUT = 12;

    /**
     * @param  string  $e164  Must include leading + (e.g. +19147607591)
     * @return array{present: bool, status: string}
     */
    public function check(string $e164): array
    {
        $token = $this->gatewayToken();
        if (! filled($token)) {
            return ['present' => false, 'status' => 'not_configured'];
        }

        if (! str_starts_with($e164, '+')) {
            Log::warning('Telegram Gateway called without E.164 + prefix', [
                'phone_format' => 'invalid',
            ]);

            return ['present' => false, 'status' => 'error'];
        }

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT)
                ->withToken((string) $token)
                ->acceptJson()
                ->asJson()
                ->post('https://gatewayapi.telegram.org/checkSendAbility', [
                    'phone_number' => $e164,
                ]);

            return $this->parseResponse($response, $e164);
        } catch (ConnectionException $e) {
            Log::warning('Telegram Gateway timeout/connection', [
                'error' => $e->getMessage(),
            ]);

            return ['present' => false, 'status' => 'error'];
        } catch (Throwable $e) {
            Log::warning('Telegram Gateway exception', [
                'error' => $e->getMessage(),
            ]);

            return ['present' => false, 'status' => 'error'];
        }
    }

    /**
     * Queue a Gateway check onto an Http::pool PendingRequest.
     *
     * @return \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface
     */
    public function poolRequest(\Illuminate\Http\Client\PendingRequest $request, string $e164): mixed
    {
        $token = (string) $this->gatewayToken();

        return $request
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::REQUEST_TIMEOUT)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->post('https://gatewayapi.telegram.org/checkSendAbility', [
                'phone_number' => $e164,
            ]);
    }

    /**
     * @return array{present: bool, status: string}
     */
    public function parseResponse(mixed $response, string $e164): array
    {
        if ($response instanceof Throwable) {
            Log::warning('Telegram Gateway timeout/connection', [
                'error' => $response->getMessage(),
            ]);

            return ['present' => false, 'status' => 'error'];
        }

        if (! $response instanceof Response) {
            return ['present' => false, 'status' => 'error'];
        }

        $json = $response->json() ?? [];
        $error = is_array($json)
            ? (string) ($json['error'] ?? $json['description'] ?? '')
            : '';

        Log::info('Telegram Gateway checkSendAbility', [
            'phone_format' => 'e164',
            'http_status' => $response->status(),
            'ok' => $json['ok'] ?? null,
            'error' => $error !== '' ? $error : null,
        ]);

        if ($response->successful() && ($json['ok'] ?? null) === true) {
            return ['present' => true, 'status' => 'found'];
        }

        $errorUpper = strtoupper($error);

        if (str_contains($errorUpper, 'PHONE_NUMBER_INVALID')) {
            Log::warning('Telegram PHONE_NUMBER_INVALID after normalization', [
                'e164' => $e164,
            ]);

            return ['present' => false, 'status' => 'error'];
        }

        if (
            str_contains($errorUpper, 'BALANCE')
            || str_contains($errorUpper, 'FLOOD')
            || str_contains($errorUpper, 'UNAUTHORIZED')
            || str_contains($errorUpper, 'AUTH')
            || in_array($response->status(), [401, 402, 429, 500, 502, 503, 504], true)
        ) {
            return ['present' => false, 'status' => 'error'];
        }

        // Cannot send / not found / privacy — do not claim "does not exist"
        if (($json['ok'] ?? null) === false || $response->status() === 400 || $error !== '') {
            return ['present' => false, 'status' => 'not_found_or_hidden'];
        }

        return ['present' => false, 'status' => 'error'];
    }

    public function isConfigured(): bool
    {
        return filled($this->gatewayToken());
    }

    private function gatewayToken(): ?string
    {
        // Prefer TG_GATEWAY_TOKEN; fall back to legacy TG_BOT_TOKEN (same Gateway token)
        $token = config('services.telegram.gateway_token') ?: config('services.telegram.bot_token');

        return filled($token) ? (string) $token : null;
    }
}
