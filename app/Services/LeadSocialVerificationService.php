<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class LeadSocialVerificationService
{
    private const CACHE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly PhoneNormalizer $phones,
        private readonly WhapiContactChecker $whapi,
        private readonly TelegramGatewayChecker $telegram,
    ) {}

    /**
     * @return array{
     *   phone: string,
     *   whatsapp: bool,
     *   telegram: bool,
     *   whatsapp_status: string,
     *   telegram_status: string,
     *   facebook_search_url: string,
     *   instagram_search_url: string
     * }
     */
    public function verify(string $rawPhone): array
    {
        $normalized = $this->phones->normalize($rawPhone);
        $digits = $normalized['digits'];
        $e164 = $normalized['e164'];

        $cacheKey = 'lead_social:'.$digits;

        try {
            return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($digits, $e164) {
                return $this->verifyFresh($digits, $e164);
            });
        } catch (Throwable $e) {
            Log::warning('Lead social verify failed', ['error' => $e->getMessage()]);

            return $this->payload($digits, 'error', 'error');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyFresh(string $digits, string $e164): array
    {
        $started = microtime(true);
        $whapiConfigured = filled(config('services.whapi.token'));
        $tgConfigured = $this->telegram->isConfigured();

        $waStatus = $whapiConfigured ? 'error' : 'not_configured';
        $tgStatus = $tgConfigured ? 'error' : 'not_configured';

        if (! $whapiConfigured && ! $tgConfigured) {
            Log::warning('Lead social verify: WHAPI_TOKEN and TG_GATEWAY_TOKEN both empty');

            return $this->payload($digits, 'not_configured', 'not_configured');
        }

        try {
            $responses = Http::pool(function (Pool $pool) use ($digits, $e164, $whapiConfigured, $tgConfigured) {
                if ($whapiConfigured) {
                    $this->whapi->poolRequest($pool->as('whapi'), $digits);
                }
                if ($tgConfigured) {
                    $this->telegram->poolRequest($pool->as('telegram'), $e164);
                }
            });
        } catch (Throwable $e) {
            Log::warning('Lead social verify pool failed', [
                'error' => $e->getMessage(),
                'ms' => (int) ((microtime(true) - $started) * 1000),
            ]);

            return $this->payload($digits, $waStatus, $tgStatus);
        }

        if ($whapiConfigured) {
            $wa = $this->whapi->parseResponse($responses['whapi'] ?? null);
            $waStatus = $wa['status'];
        }

        if ($tgConfigured) {
            $tg = $this->telegram->parseResponse($responses['telegram'] ?? null, $e164);
            $tgStatus = $tg['status'];
        }

        Log::info('Lead social verify done', [
            'ms' => (int) ((microtime(true) - $started) * 1000),
            'whatsapp_status' => $waStatus,
            'telegram_status' => $tgStatus,
        ]);

        return $this->payload($digits, $waStatus, $tgStatus);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $digits, string $waStatus, string $tgStatus): array
    {
        return [
            'phone' => $digits,
            'whatsapp' => $waStatus === 'valid',
            'telegram' => $tgStatus === 'found',
            'whatsapp_status' => $waStatus,
            'telegram_status' => $tgStatus,
            'facebook_search_url' => 'https://www.facebook.com/search/top?q='.urlencode($digits),
            'instagram_search_url' => 'https://www.google.com/search?q='.urlencode('site:instagram.com '.$digits),
        ];
    }
}
