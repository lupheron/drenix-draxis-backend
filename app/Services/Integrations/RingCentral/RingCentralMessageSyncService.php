<?php

namespace App\Services\Integrations\RingCentral;

use App\Models\ExternalIdMapping;
use App\Services\Integrations\UserMatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RingCentralMessageSyncService
{
    public function __construct(
        private readonly UserMatcher $matcher,
    ) {}

    /**
     * Incremental by default: only pull SMS newer than last saved message.
     * Use $full = true to backfill the full lookback window.
     *
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

        $extensions = collect($client->getExtensions());
        $summary = [
            'company' => $company,
            'mode' => $full ? 'full' : 'incremental',
            'users_matched' => 0,
            'extensions_mapped' => 0,
            'messages_inserted' => 0,
            'messages_updated' => 0,
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

            [$fromDateTime, $mode] = $this->resolveFetchWindow($user->id, $lookback, $full);

            try {
                $messages = $client->getSmsForExtension(
                    $extensionId,
                    $fromDateTime->toIso8601String(),
                    $toDateTime->toIso8601String(),
                );
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                $noPermission = str_contains($message, 'ReadMessages')
                    || str_contains($message, 'InsufficientPermissions');

                Log::error('RingCentral SMS sync failed for user', [
                    'whitelist' => $whitelistName,
                    'user_id' => $user->id,
                    'message' => $message,
                ]);

                $summary['details'][] = [
                    'whitelist' => $whitelistName,
                    'user_id' => $user->id,
                    'extension_id' => $extensionId,
                    'status' => $noPermission ? 'no_sms_permission' : 'error',
                    'mode' => $mode,
                    'error' => $noPermission
                        ? 'RingCentral app needs ReadMessages permission for Message Store / SMS.'
                        : $message,
                ];
                continue;
            }

            $inserted = 0;
            $updated = 0;

            foreach ($messages as $message) {
                $normalized = SmsNormalizer::normalize($message);
                if ($normalized === null || empty($normalized['sent_at'])) {
                    continue;
                }

                $payload = [
                    'user_id' => $user->id,
                    'company' => $company,
                    'external_id' => $normalized['external_id'],
                    'conversation_id' => $normalized['conversation_id'],
                    'direction' => $normalized['direction'],
                    'body' => $normalized['body'],
                    'from_number' => $normalized['from_number'],
                    'to_number' => $normalized['to_number'],
                    'peer_number' => $normalized['peer_number'],
                    'peer_name' => $normalized['peer_name'],
                    'sent_at' => Carbon::parse($normalized['sent_at']),
                    'status' => $normalized['status'],
                    'raw_json' => json_encode($message),
                    'updated_at' => now(),
                ];

                $existingId = DB::table('ringcentral_messages')
                    ->where('external_id', $normalized['external_id'])
                    ->value('id');

                if ($existingId) {
                    DB::table('ringcentral_messages')->where('id', $existingId)->update($payload);
                    $updated++;
                } else {
                    $payload['created_at'] = now();
                    DB::table('ringcentral_messages')->insert($payload);
                    $inserted++;
                }
            }

            $summary['messages_inserted'] += $inserted;
            $summary['messages_updated'] += $updated;
            $summary['details'][] = [
                'whitelist' => $whitelistName,
                'user_id' => $user->id,
                'extension_id' => $extensionId,
                'status' => 'synced',
                'mode' => $mode,
                'fetch_from' => $fromDateTime->toIso8601String(),
                'records_fetched' => count($messages),
                'messages_inserted' => $inserted,
                'messages_updated' => $updated,
            ];
        }

        return $summary;
    }

    /**
     * @return array{0: Carbon, 1: string}
     */
    private function resolveFetchWindow(int $userId, int $lookbackDays, bool $full): array
    {
        $fallbackFrom = now()->subDays($lookbackDays)->startOfDay();

        if ($full) {
            return [$fallbackFrom, 'full'];
        }

        $lastSentAt = DB::table('ringcentral_messages')
            ->where('user_id', $userId)
            ->max('sent_at');

        if (! $lastSentAt) {
            return [$fallbackFrom, 'initial_backfill'];
        }

        $from = Carbon::parse($lastSentAt)->subHours(2);

        if ($from->lt($fallbackFrom)) {
            $from = $fallbackFrom->copy();
        }

        return [$from, 'incremental'];
    }
}
