<?php

namespace App\Services\Integrations\RingCentral;

use App\Models\ExternalIdMapping;
use App\Services\Integrations\MetricsAggregator;
use App\Services\Integrations\UserMatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RingCentralSyncService
{
    public function __construct(
        private readonly UserMatcher $matcher,
        private readonly MetricsAggregator $aggregator,
    ) {}

    /**
     * Incremental by default: only pull calls newer than last saved call_log.
     * Use $full = true to backfill the full lookback window.
     *
     * Saves ALL call types (outbound, inbound, missed, voicemail, other) into call_logs.
     * Frontend never hits RingCentral — reads from our DB only.
     */
    public function sync(string $company, bool $full = false): array
    {
        $company = strtoupper($company);
        $profile = config("integrations.companies.{$company}");

        if (! $profile) {
            throw new \InvalidArgumentException("No integration profile for company {$company}.");
        }

        $client = new RingCentralClient($profile['ringcentral']);
        $client->authenticate();

        $lookback = (int) config('integrations.sync.ringcentral_lookback_days', 30);
        $toDateTime = now()->endOfDay();
        $toDate = $toDateTime->toDateString();

        $extensions = collect($client->getExtensions());
        $summary = [
            'company' => $company,
            'mode' => $full ? 'full' : 'incremental',
            'users_matched' => 0,
            'extensions_mapped' => 0,
            'calls_inserted' => 0,
            'calls_updated' => 0,
            'details' => [],
        ];

        foreach ($profile['whitelist'] as $entry) {
            $whitelistName = $entry['name'];
            $aliases = $entry['match_aliases'] ?? [];

            $user = $this->matcher->findUserByWhitelistName($company, $whitelistName, $aliases);

            if (! $user) {
                $summary['details'][] = [
                    'whitelist' => $whitelistName,
                    'status' => 'no_draxis_user',
                ];
                continue;
            }

            $summary['users_matched']++;

            $extension = $this->matcher->findExtensionByDisplayName($extensions, $whitelistName, $aliases);

            if (! $extension) {
                $summary['details'][] = [
                    'whitelist' => $whitelistName,
                    'user_id' => $user->id,
                    'status' => 'no_ringcentral_extension',
                ];
                continue;
            }

            $extensionId = (string) $extension['id'];

            ExternalIdMapping::updateOrCreate(
                [
                    'provider' => 'ringcentral',
                    'external_id' => $extensionId,
                ],
                [
                    'user_id' => $user->id,
                    'metadata' => [
                        'company' => $company,
                        'display_name' => $extension['name'] ?? null,
                        'extension_number' => $extension['extensionNumber'] ?? null,
                        'last_synced_at' => now()->toIso8601String(),
                    ],
                ],
            );

            $summary['extensions_mapped']++;

            [$fromDateTime, $fromDate, $mode] = $this->resolveFetchWindow(
                $user->id,
                $lookback,
                $full,
            );

            try {
                $calls = $client->getCallLogForExtension(
                    $extensionId,
                    $fromDateTime->toIso8601String(),
                    $toDateTime->toIso8601String(),
                );
            } catch (\Throwable $e) {
                Log::error('RingCentral sync failed for user', [
                    'whitelist' => $whitelistName,
                    'user_id' => $user->id,
                    'message' => $e->getMessage(),
                ]);

                $summary['details'][] = [
                    'whitelist' => $whitelistName,
                    'user_id' => $user->id,
                    'extension_id' => $extensionId,
                    'status' => 'error',
                    'mode' => $mode,
                    'error' => $e->getMessage(),
                ];
                continue;
            }

            $inserted = 0;
            $updated = 0;

            foreach ($calls as $call) {
                $externalId = (string) ($call['telephonySessionId'] ?? $call['id'] ?? '');

                if ($externalId === '') {
                    continue;
                }

                $classified = CallClassifier::classify($call);

                $payload = [
                    'user_id' => $user->id,
                    'company' => $company,
                    'external_id' => $externalId,
                    'started_at' => Carbon::parse($call['startTime'] ?? now()),
                    'duration_seconds' => (int) ($call['duration'] ?? 0),
                    'direction' => $classified['direction'],
                    'result' => $classified['result'],
                    'call_type' => $classified['call_type'],
                    'action' => $classified['action'],
                    'raw' => json_encode($call),
                    'updated_at' => now(),
                ];

                $existingId = DB::table('call_logs')->where('external_id', $externalId)->value('id');

                if ($existingId) {
                    DB::table('call_logs')->where('id', $existingId)->update($payload);
                    $updated++;
                } else {
                    $payload['created_at'] = now();
                    DB::table('call_logs')->insert($payload);
                    $inserted++;
                }
            }

            $this->aggregator->rebuildCallMetricsFromLogs($user->id, $fromDate, $toDate);

            $summary['calls_inserted'] += $inserted;
            $summary['calls_updated'] += $updated;
            $summary['details'][] = [
                'whitelist' => $whitelistName,
                'user_id' => $user->id,
                'extension_id' => $extensionId,
                'status' => 'synced',
                'mode' => $mode,
                'fetch_from' => $fromDateTime->toIso8601String(),
                'records_fetched' => count($calls),
                'calls_inserted' => $inserted,
                'calls_updated' => $updated,
            ];
        }

        return $summary;
    }

    /**
     * @return array{0: Carbon, 1: string, 2: string}
     */
    private function resolveFetchWindow(int $userId, int $lookbackDays, bool $full): array
    {
        $fallbackFrom = now()->subDays($lookbackDays)->startOfDay();

        if ($full) {
            return [$fallbackFrom, $fallbackFrom->toDateString(), 'full'];
        }

        $lastStartedAt = DB::table('call_logs')
            ->where('user_id', $userId)
            ->max('started_at');

        if (! $lastStartedAt) {
            return [$fallbackFrom, $fallbackFrom->toDateString(), 'initial_backfill'];
        }

        $from = Carbon::parse($lastStartedAt)->subHours(2);

        if ($from->lt($fallbackFrom)) {
            $from = $fallbackFrom->copy();
        }

        return [$from, $from->toDateString(), 'incremental'];
    }
}
