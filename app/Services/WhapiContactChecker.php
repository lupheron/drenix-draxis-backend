<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhapiContactChecker
{
    private const CONNECT_TIMEOUT = 3;

    private const REQUEST_TIMEOUT = 12;

    /**
     * @return array{present: bool, status: string}
     */
    public function check(string $digitsOnly): array
    {
        $token = config('services.whapi.token');
        if (! filled($token)) {
            return ['present' => false, 'status' => 'not_configured'];
        }

        $base = rtrim((string) config('services.whapi.base_url', 'https://gate.whapi.cloud'), '/');
        $url = $base.'/contacts';

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT)
                ->withToken((string) $token)
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'blocking' => 'wait',
                    'contacts' => [$digitsOnly],
                ]);

            return $this->parseResponse($response);
        } catch (ConnectionException $e) {
            Log::warning('Whapi contacts timeout/connection', [
                'error' => $e->getMessage(),
            ]);

            return ['present' => false, 'status' => 'error'];
        } catch (Throwable $e) {
            Log::warning('Whapi contacts exception', [
                'error' => $e->getMessage(),
            ]);

            return ['present' => false, 'status' => 'error'];
        }
    }

    /**
     * Queue a contacts check onto an Http::pool PendingRequest.
     *
     * @return \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface
     */
    public function poolRequest(\Illuminate\Http\Client\PendingRequest $request, string $digitsOnly): mixed
    {
        $token = (string) config('services.whapi.token');
        $base = rtrim((string) config('services.whapi.base_url', 'https://gate.whapi.cloud'), '/');

        return $request
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::REQUEST_TIMEOUT)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->post($base.'/contacts', [
                'blocking' => 'wait',
                'contacts' => [$digitsOnly],
            ]);
    }

    /**
     * @return array{present: bool, status: string}
     */
    public function parseResponse(mixed $response): array
    {
        if ($response instanceof Throwable) {
            Log::warning('Whapi contacts timeout/connection', [
                'error' => $response->getMessage(),
            ]);

            return ['present' => false, 'status' => 'error'];
        }

        if (! $response instanceof Response) {
            return ['present' => false, 'status' => 'error'];
        }

        $json = $response->json() ?? [];
        $error = is_array($json) ? ($json['error'] ?? null) : null;

        Log::info('Whapi contacts response', [
            'contact_format' => 'digits',
            'http_status' => $response->status(),
            'error' => $error,
            'wa_id' => data_get($json, 'contacts.0.wa_id') ?? data_get($json, 'contacts.0.id'),
            'status' => data_get($json, 'contacts.0.status'),
        ]);

        if ($response->status() === 404) {
            Log::warning('Whapi channel not found — check WHAPI_TOKEN', [
                'error' => $error,
            ]);

            return ['present' => false, 'status' => 'error'];
        }

        if ($response->failed()) {
            return ['present' => false, 'status' => 'error'];
        }

        $row = data_get($json, 'contacts.0');
        if (! is_array($row)) {
            return ['present' => false, 'status' => 'error'];
        }

        $status = strtolower(trim((string) ($row['status'] ?? '')));
        $waId = $row['wa_id'] ?? $row['id'] ?? null;

        if (in_array($status, ['valid', 'success', 'registered'], true) || ($waId && $status === '')) {
            return ['present' => true, 'status' => 'valid'];
        }

        if ($waId && ! in_array($status, ['invalid', 'not_registered', 'failed', 'error'], true)) {
            return ['present' => true, 'status' => 'valid'];
        }

        if (in_array($status, ['invalid', 'not_registered', 'failed'], true)) {
            return ['present' => false, 'status' => 'not_registered'];
        }

        if (in_array($status, ['pending', 'processing', 'checking'], true) || $status === '') {
            return ['present' => false, 'status' => 'pending'];
        }

        return ['present' => false, 'status' => 'error'];
    }
}
