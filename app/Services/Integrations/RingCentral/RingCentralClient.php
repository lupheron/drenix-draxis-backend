<?php

namespace App\Services\Integrations\RingCentral;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RingCentralClient
{
    private const REQUEST_GAP_MS = 1200;

    private const MAX_ATTEMPTS = 5;

    private ?string $accessToken = null;

    private int $consecutive429s = 0;

    public function __construct(
        private readonly array $config,
    ) {}

    public function authenticate(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $cacheKey = 'ringcentral_token_'.md5($this->config['client_id'] ?? 'default');

        $this->accessToken = Cache::remember($cacheKey, 3300, function () {
            $this->pace();

            $response = Http::timeout(30)->withBasicAuth(
                $this->config['client_id'],
                $this->config['client_secret'],
            )->asForm()->post(
                rtrim($this->config['server_url'], '/').'/restapi/oauth/token',
                [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $this->config['jwt'],
                ],
            );

            if (! $response->successful()) {
                throw new RuntimeException('RingCentral auth failed: '.$response->body());
            }

            return $response->json('access_token');
        });

        return $this->accessToken;
    }

    public function getExtensions(): array
    {
        $records = [];
        $page = 1;

        do {
            $payload = $this->get('/restapi/v1.0/account/~/extension', [
                'page' => $page,
                'perPage' => 100,
                'status' => 'Enabled',
            ]);

            $records = array_merge($records, $payload['records'] ?? []);
            $page++;
        } while ($page <= (int) ($payload['paging']['totalPages'] ?? 1));

        return $records;
    }

    /**
     * Fetch call logs for a date range in 7-day windows (complete pagination, fewer 429s).
     */
    public function getCallLogForExtension(string $extensionId, string $dateFrom, string $dateTo): array
    {
        $records = [];
        $cursorFrom = \Carbon\Carbon::parse($dateFrom);
        $end = \Carbon\Carbon::parse($dateTo);

        while ($cursorFrom->lte($end)) {
            $cursorTo = $cursorFrom->copy()->addDays(6)->endOfDay();
            if ($cursorTo->gt($end)) {
                $cursorTo = $end->copy();
            }

            $page = 1;

            do {
                $payload = $this->get("/restapi/v1.0/account/~/extension/{$extensionId}/call-log", [
                    'dateFrom' => $cursorFrom->toIso8601String(),
                    'dateTo' => $cursorTo->toIso8601String(),
                    'type' => 'Voice',
                    'view' => 'Simple',
                    'page' => $page,
                    'perPage' => 100,
                ]);

                $records = array_merge($records, $payload['records'] ?? []);
                $page++;
            } while ($page <= (int) ($payload['paging']['totalPages'] ?? 1));

            $cursorFrom = $cursorTo->copy()->addDay()->startOfDay();
        }

        return $records;
    }

    /**
     * Fetch SMS messages from Message Store in 7-day windows (complete pagination).
     */
    public function getSmsForExtension(string $extensionId, string $dateFrom, string $dateTo): array
    {
        $records = [];
        $cursorFrom = \Carbon\Carbon::parse($dateFrom);
        $end = \Carbon\Carbon::parse($dateTo);

        while ($cursorFrom->lte($end)) {
            $cursorTo = $cursorFrom->copy()->addDays(6)->endOfDay();
            if ($cursorTo->gt($end)) {
                $cursorTo = $end->copy();
            }

            $page = 1;

            do {
                $payload = $this->get("/restapi/v1.0/account/~/extension/{$extensionId}/message-store", [
                    'dateFrom' => $cursorFrom->toIso8601String(),
                    'dateTo' => $cursorTo->toIso8601String(),
                    'messageType' => 'SMS',
                    'availability' => 'Alive',
                    'page' => $page,
                    'perPage' => 100,
                ]);

                $records = array_merge($records, $payload['records'] ?? []);
                $page++;
            } while ($page <= (int) ($payload['paging']['totalPages'] ?? 1));

            $cursorFrom = $cursorTo->copy()->addDay()->startOfDay();
        }

        return $records;
    }

    private function get(string $path, array $query = [], int $attempt = 1): array
    {
        $this->pace();

        $response = Http::timeout(90)->withToken($this->authenticate())
            ->get(rtrim($this->config['server_url'], '/').$path, $query);

        if ($response->status() === 429) {
            $this->consecutive429s++;

            if ($attempt >= self::MAX_ATTEMPTS) {
                throw new RuntimeException("RingCentral rate limited (429) after {$attempt} retries: {$path}");
            }

            $waitMs = $this->resolve429WaitMs($response, $this->consecutive429s);

            Log::warning('RingCentral 429 — backing off', [
                'path' => $path,
                'attempt' => $attempt,
                'consecutive_429s' => $this->consecutive429s,
                'wait_ms' => $waitMs,
            ]);

            usleep($waitMs * 1000);

            return $this->get($path, $query, $attempt + 1);
        }

        $this->consecutive429s = 0;

        if ($response->status() === 401 && $attempt === 1) {
            $this->accessToken = null;
            Cache::forget('ringcentral_token_'.md5($this->config['client_id'] ?? 'default'));
            $this->authenticate();

            return $this->get($path, $query, $attempt + 1);
        }

        if (! $response->successful()) {
            throw new RuntimeException("RingCentral GET {$path} failed [{$response->status()}]: ".$response->body());
        }

        return $response->json();
    }

    private function resolve429WaitMs($response, int $consecutive429s): int
    {
        $retryAfter = (int) ($response->header('Retry-After') ?: 0);
        if ($retryAfter > 0) {
            return $retryAfter * 1000;
        }

        $window = (int) ($response->header('X-Rate-Limit-Window') ?: 0);
        if ($window > 0) {
            return $window * 1000;
        }

        // Match old Next.js: 60000 * 2^(consecutive429s - 1)
        $exp = max(1, $consecutive429s);

        return (int) (60_000 * (2 ** ($exp - 1)));
    }

    private function pace(): void
    {
        usleep(self::REQUEST_GAP_MS * 1000);
    }
}
