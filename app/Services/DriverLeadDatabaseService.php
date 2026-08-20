<?php

namespace App\Services;

use App\Models\DriverLead;
use App\Support\LeadNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DriverLeadDatabaseService
{
    /**
     * Search by any combination of name / phone / email (AND across provided fields).
     *
     * Status for hired / rejected / terminated comes from HR Process boards when a
     * matching driver exists there. Column details stay on the recruiter board row.
     */
    public function search(?string $name, ?string $phone, ?string $email, string $company = 'JM'): array
    {
        $name = $name !== null ? trim($name) : null;
        $phone = $phone !== null ? trim($phone) : null;
        $email = $email !== null ? trim($email) : null;
        $company = strtoupper($company);

        if (($name === null || $name === '')
            && ($phone === null || $phone === '')
            && ($email === null || $email === '')) {
            return [
                'found' => false,
                'message' => 'Provide at least one of: name, phone, email.',
                'data' => [],
            ];
        }

        $query = DriverLead::query()->where('company', $company);

        if ($name !== null && $name !== '') {
            $norm = LeadNormalizer::normalizeName($name);
            $canonical = LeadNormalizer::canonicalName($name);
            $query->where(function ($q) use ($name, $norm, $canonical) {
                $q->where('name', 'ilike', '%'.$name.'%');
                if ($norm) {
                    $q->orWhere('name_normalized', 'ilike', '%'.$norm.'%');
                }
                if ($canonical && $canonical !== $norm) {
                    $q->orWhere('name_normalized', 'ilike', '%'.$canonical.'%');
                }
            });
        }

        if ($phone !== null && $phone !== '') {
            $normPhone = LeadNormalizer::normalizePhone($phone);
            $query->where(function ($q) use ($phone, $normPhone) {
                $q->where('phone', 'ilike', '%'.$phone.'%');
                if ($normPhone) {
                    $q->orWhere('phone_normalized', 'like', '%'.$normPhone.'%');
                }
            });
        }

        if ($email !== null && $email !== '') {
            $normEmail = LeadNormalizer::normalizeEmail($email);
            $query->where(function ($q) use ($email, $normEmail) {
                $q->where('email', 'ilike', '%'.$email.'%');
                if ($normEmail) {
                    $q->orWhere('email_normalized', $normEmail);
                }
            });
        }

        $rows = $query
            ->orderByDesc('applied_on')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        if ($rows->isEmpty()) {
            return [
                'found' => false,
                'message' => 'Nothing found',
                'data' => [],
            ];
        }

        $rows = $this->expandWithRelatedProcessLeads($rows, $company);

        return [
            'found' => true,
            'message' => null,
            'data' => $this->groupHistory($rows),
        ];
    }

    /**
     * Browse / filter list for table+card UI.
     */
    public function list(
        string $company = 'JM',
        ?string $statusKey = null,
        ?string $boardName = null,
        int $page = 1,
        int $perPage = 50,
    ): LengthAwarePaginator {
        $company = strtoupper($company);
        $query = DriverLead::query()
            ->where('company', $company)
            ->orderByDesc('applied_on')
            ->orderByDesc('id');

        if ($statusKey) {
            $normalized = LeadNormalizer::statusKey($statusKey);
            $query->where(function ($q) use ($statusKey, $normalized, $company) {
                $q->where('status_key', $statusKey)
                    ->orWhere('status_key', $normalized);

                // Include recruiter rows whose Process-board twin has this status.
                if (LeadNormalizer::isAuthoritativeProcessStatus($normalized)) {
                    $q->orWhere(function ($hr) use ($normalized, $company) {
                        $hr->where(function ($board) {
                            $this->scopeNonProcessBoards($board);
                        })->whereExists(function ($sub) use ($normalized, $company) {
                            $sub->selectRaw('1')
                                ->from('driver_leads as process')
                                ->where('process.company', $company)
                                ->where('process.status_key', $normalized)
                                ->where(function ($board) {
                                    $board->where('process.board_name', 'ilike', '%HR Process%')
                                        ->orWhere('process.board_name', 'ilike', '%Process%JM%')
                                        ->orWhere('process.board_name', 'ilike', '%Process%BP%')
                                        ->orWhere('process.board_name', 'ilike', '%Process%WF%')
                                        ->orWhere('process.board_name', 'ilike', '%Process%JDM%')
                                        ->orWhere('process.board_name', 'ilike', '%JM%Process%')
                                        ->orWhere('process.board_name', 'ilike', '%BP%Process%')
                                        ->orWhere('process.board_name', 'ilike', '%WF%Process%')
                                        ->orWhere('process.board_name', 'ilike', '%JDM%Process%');
                                })
                                ->where(function ($match) {
                                    $match->where(function ($phone) {
                                        $phone->whereNotNull('driver_leads.phone_normalized')
                                            ->whereColumn(
                                                'process.phone_normalized',
                                                'driver_leads.phone_normalized',
                                            );
                                    })->orWhere(function ($email) {
                                        $email->whereNotNull('driver_leads.email_normalized')
                                            ->whereColumn(
                                                'process.email_normalized',
                                                'driver_leads.email_normalized',
                                            );
                                    })->orWhere(function ($name) {
                                        $name->whereNotNull('driver_leads.name_normalized')
                                            ->whereNotNull('process.name_normalized')
                                            ->whereRaw(
                                                "regexp_replace(lower(coalesce(process.name_normalized, '')), '\\s*\\(copy\\)\\s*', ' ', 'gi') = regexp_replace(lower(coalesce(driver_leads.name_normalized, '')), '\\s*\\(copy\\)\\s*', ' ', 'gi')"
                                            );
                                    });
                                });
                        });
                    });
                }
            });
        }

        if ($boardName) {
            $query->where('board_name', 'ilike', '%'.$boardName.'%');
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  Collection<int, DriverLead>  $leads
     * @return Collection<int, array<string, mixed>>
     */
    public function formatLeads(Collection $leads, string $company): Collection
    {
        $overrides = $this->findProcessOverrides($leads, strtoupper($company));

        return $leads->map(
            fn (DriverLead $lead) => $this->formatLead(
                $lead,
                $overrides->get($lead->id),
            )
        )->values();
    }

    public function formatLead(DriverLead $lead, ?DriverLead $processOverride = null): array
    {
        $boardStatus = $lead->status_label;
        $boardStatusKey = $lead->status_key;
        $status = $boardStatus;
        $statusKey = $boardStatusKey;
        $statusSource = null;

        if ($processOverride
            && ! LeadNormalizer::isProcessBoardName($lead->board_name)
            && LeadNormalizer::isAuthoritativeProcessStatus($processOverride->status_key)
        ) {
            $status = $processOverride->status_label;
            $statusKey = $processOverride->status_key;
            $statusSource = [
                'board_name' => $processOverride->board_name,
                'board_id' => $processOverride->board_id,
                'status' => $processOverride->status_label,
                'status_key' => $processOverride->status_key,
                'group_title' => $processOverride->group_title,
            ];
        }

        $columns = is_array($lead->columns) ? $lead->columns : [];
        $boardOwner = LeadNormalizer::ownerFromBoardName($lead->board_name);
        $movedToRaw = LeadNormalizer::columnValue($columns, ['move to', 'moved to']);
        $movedTo = LeadNormalizer::isPersonMoveTarget($movedToRaw) ? $movedToRaw : null;
        $owner = $movedTo ?: ($boardOwner ?: $lead->recruiter);
        $calls = LeadNormalizer::parseCalls(
            LeadNormalizer::columnValue($columns, ['calls', 'call']),
        );

        return [
            'id' => $lead->id,
            'monday_item_id' => $lead->monday_item_id,
            'board_id' => $lead->board_id,
            'board_name' => $lead->board_name,
            'group_title' => $lead->group_title,
            'name' => $lead->name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'status' => $status,
            'status_key' => $statusKey,
            'board_status' => $boardStatus,
            'board_status_key' => $boardStatusKey,
            'status_source' => $statusSource,
            'notes' => $lead->notes,
            'platform' => $lead->platform,
            'position' => $lead->position,
            'state' => $lead->state,
            'recruiter' => $lead->recruiter,
            'board_owner' => $boardOwner,
            'owner' => $owner,
            'moved_to' => $movedTo,
            'calls' => $calls['count'],
            'calls_label' => $calls['label'],
            'got_cdl' => LeadNormalizer::columnValue($columns, ['got cdl', 'cdl']),
            'applied_on' => optional($lead->applied_on)?->toDateString(),
            'contacted_on' => optional($lead->contacted_on)?->toDateString(),
            'extra_columns' => $this->uniqueColumns($lead, $columns),
            'monday_created_at' => optional($lead->monday_created_at)?->toIso8601String(),
            'monday_updated_at' => optional($lead->monday_updated_at)?->toIso8601String(),
        ];
    }

    /**
     * Drop fields already shown on the card so Monday columns are not dumped twice.
     *
     * @param  array<string, mixed>  $columns
     * @return array<string, string>
     */
    private function uniqueColumns(DriverLead $lead, array $columns): array
    {
        $skipExact = [
            'notes', 'note', 'comment', 'reason',
            'platform', 'source',
            'position', 'job',
            'state',
            'recruiter',
            'status',
            'date',
            'calls', 'call',
            'name', 'phone', 'email', 'number', 'mobile', 'cell',
        ];
        $skipContains = ['last updated', 'date contacted', 'move to', 'moved to'];

        $shown = array_filter([
            Str::lower(trim((string) $lead->notes)),
            Str::lower(trim((string) $lead->platform)),
            Str::lower(trim((string) $lead->position)),
            Str::lower(trim((string) $lead->state)),
            Str::lower(trim((string) $lead->recruiter)),
        ]);

        $extra = [];
        foreach ($columns as $title => $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }
            $hay = Str::lower(trim((string) $title));
            $skipThis = in_array($hay, $skipExact, true);
            foreach ($skipContains as $needle) {
                if (str_contains($hay, $needle)) {
                    $skipThis = true;
                    break;
                }
            }
            if ($skipThis) {
                continue;
            }
            if (in_array(Str::lower($text), $shown, true)) {
                continue;
            }
            $extra[(string) $title] = $text;
        }

        return $extra;
    }

    /**
     * @param  Collection<int, DriverLead>  $rows
     */
    private function expandWithRelatedProcessLeads(Collection $rows, string $company): Collection
    {
        $phones = $rows->pluck('phone_normalized')->filter()->unique()->values();
        $emails = $rows->pluck('email_normalized')->filter()->unique()->values();
        $names = $rows
            ->map(fn (DriverLead $lead) => LeadNormalizer::canonicalName($lead->name))
            ->filter()
            ->unique()
            ->values();

        if ($phones->isEmpty() && $emails->isEmpty() && $names->isEmpty()) {
            return $rows;
        }

        $extra = DriverLead::query()
            ->where('company', $company)
            ->where(function (Builder $q) {
                $this->scopeProcessBoards($q);
            })
            ->whereIn('status_key', ['hired', 'rejected', 'terminated'])
            ->where(function (Builder $q) use ($phones, $emails, $names) {
                if ($phones->isNotEmpty()) {
                    $q->orWhereIn('phone_normalized', $phones->all());
                }
                if ($emails->isNotEmpty()) {
                    $q->orWhereIn('email_normalized', $emails->all());
                }
                foreach ($names as $name) {
                    $q->orWhere('name_normalized', $name)
                        ->orWhere('name_normalized', 'ilike', $name.' %')
                        ->orWhere('name_normalized', 'ilike', $name.' (copy)%');
                }
            })
            ->limit(500)
            ->get()
            ->filter(function (DriverLead $lead) use ($phones, $emails, $names) {
                return $this->matchesIdentityTokens($lead, $phones, $emails, $names);
            });

        return $rows->concat($extra)->unique('id')->values();
    }

    /**
     * @param  Collection<int, DriverLead>  $leads
     * @return Collection<int, DriverLead>  keyed by recruiter lead id
     */
    private function findProcessOverrides(Collection $leads, string $company): Collection
    {
        if ($leads->isEmpty()) {
            return collect();
        }

        $phones = $leads->pluck('phone_normalized')->filter()->unique()->values();
        $emails = $leads->pluck('email_normalized')->filter()->unique()->values();
        $names = $leads
            ->map(fn (DriverLead $lead) => LeadNormalizer::canonicalName($lead->name))
            ->filter()
            ->unique()
            ->values();

        $processLeads = DriverLead::query()
            ->where('company', $company)
            ->where(function (Builder $q) {
                $this->scopeProcessBoards($q);
            })
            ->whereIn('status_key', ['hired', 'rejected', 'terminated'])
            ->where(function (Builder $q) use ($phones, $emails, $names) {
                if ($phones->isNotEmpty()) {
                    $q->orWhereIn('phone_normalized', $phones->all());
                }
                if ($emails->isNotEmpty()) {
                    $q->orWhereIn('email_normalized', $emails->all());
                }
                foreach ($names as $name) {
                    $q->orWhere('name_normalized', $name)
                        ->orWhere('name_normalized', 'ilike', $name.' %')
                        ->orWhere('name_normalized', 'ilike', $name.' (copy)%');
                }
            })
            ->orderByDesc('monday_updated_at')
            ->orderByDesc('id')
            ->limit(1000)
            ->get();

        if ($processLeads->isEmpty()) {
            return collect();
        }

        $overrides = collect();

        foreach ($leads as $lead) {
            if (LeadNormalizer::isProcessBoardName($lead->board_name)) {
                continue;
            }

            $match = $this->bestProcessMatch($lead, $processLeads);
            if ($match) {
                $overrides->put($lead->id, $match);
            }
        }

        return $overrides;
    }

    /**
     * @param  Collection<int, DriverLead>  $processLeads
     */
    private function bestProcessMatch(DriverLead $lead, Collection $processLeads): ?DriverLead
    {
        $leadPhone = $lead->phone_normalized;
        $leadEmail = $lead->email_normalized;
        $leadName = LeadNormalizer::canonicalName($lead->name);

        $matches = $processLeads->filter(function (DriverLead $process) use ($leadPhone, $leadEmail, $leadName) {
            if ($leadPhone && $process->phone_normalized && $leadPhone === $process->phone_normalized) {
                return true;
            }
            if ($leadEmail && $process->email_normalized && $leadEmail === $process->email_normalized) {
                return true;
            }
            $processName = LeadNormalizer::canonicalName($process->name);

            return $leadName && $processName && $leadName === $processName;
        });

        if ($matches->isEmpty()) {
            return null;
        }

        // Prefer phone/email matches over name-only, then newest update.
        return $matches
            ->sortByDesc(function (DriverLead $process) use ($leadPhone, $leadEmail) {
                $score = 0;
                if ($leadPhone && $process->phone_normalized === $leadPhone) {
                    $score += 100;
                }
                if ($leadEmail && $process->email_normalized === $leadEmail) {
                    $score += 50;
                }
                $updated = optional($process->monday_updated_at)?->timestamp ?? 0;

                return $score * 1_000_000_000_000 + $updated;
            })
            ->first();
    }

    /**
     * @param  Collection<int, DriverLead>  $rows
     */
    private function groupHistory(Collection $rows): array
    {
        $groups = $this->clusterByIdentity($rows);
        $company = (string) ($rows->first()?->company ?? 'JM');
        $overrides = $this->findProcessOverrides($rows, $company);

        return $groups->map(function (Collection $group) use ($overrides) {
            $history = $group
                ->sortByDesc(function (DriverLead $l) {
                    $updated = optional($l->monday_updated_at)?->timestamp ?? 0;
                    $applied = optional($l->applied_on)?->timestamp ?? 0;

                    return $updated.'-'.$applied.'-'.$l->id;
                })
                ->values()
                ->map(fn (DriverLead $l) => $this->formatLead($l, $overrides->get($l->id)));

            $currentOwner = $this->resolveCurrentOwner($history);
            $originOwner = $this->resolveOriginOwner($history, $currentOwner);
            $ownership = $this->ownershipTrail($history, $originOwner, $currentOwner);
            $calls = $this->bestCalls($history);

            $history = $history
                ->map(function (array $row) use ($currentOwner) {
                    $row['placement'] = $this->placementFor($row, $currentOwner);

                    return $row;
                })
                ->sortBy(function (array $row) {
                    $rank = match ($row['placement'] ?? '') {
                        'current' => 0,
                        'previous' => 1,
                        default => 2,
                    };

                    return $rank.'-'.($row['monday_updated_at'] ?? '');
                })
                ->values()
                ->all();

            $first = collect($history)->firstWhere('placement', 'current')
                ?? $history[0]
                ?? null;

            $statuses = collect($history)
                ->pluck('status_key')
                ->unique()
                ->values()
                ->all();

            return [
                'name' => $first['name'] ?? $group->first()?->name,
                'phone' => $first['phone'] ?? $group->first()?->phone,
                'email' => $first['email'] ?? $group->first()?->email,
                'application_count' => $group->count(),
                'statuses' => $statuses,
                'current_owner' => $currentOwner,
                'origin_owner' => $originOwner,
                'ownership' => $ownership,
                'calls' => $calls['count'],
                'calls_label' => $calls['label'],
                'history' => $history,
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $history
     */
    private function resolveCurrentOwner(Collection $history): ?string
    {
        $moved = $history
            ->pluck('moved_to')
            ->filter()
            ->unique(fn ($name) => Str::lower((string) $name));

        if ($moved->isNotEmpty()) {
            $latestMove = $history->first(fn (array $row) => ! empty($row['moved_to']));

            return $latestMove['moved_to'] ?? $moved->first();
        }

        $recruiterRow = $history->first(
            fn (array $row) => empty($row['status_source']) && ! empty($row['owner']),
        );

        return $recruiterRow['owner'] ?? $history->first()['owner'] ?? null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $history
     */
    private function resolveOriginOwner(Collection $history, ?string $currentOwner): ?string
    {
        $oldest = $history
            ->sortBy(fn (array $row) => $row['monday_created_at'] ?? $row['applied_on'] ?? '')
            ->first(fn (array $row) => ! empty($row['board_owner']));

        $origin = $oldest['board_owner'] ?? null;
        if ($origin && $currentOwner && Str::lower($origin) === Str::lower($currentOwner)) {
            return $origin;
        }

        return $origin;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $history
     * @return list<array{owner: string, role: string}>
     */
    private function ownershipTrail(Collection $history, ?string $origin, ?string $current): array
    {
        $names = [];
        foreach ($history as $row) {
            foreach ([$row['board_owner'] ?? null, $row['moved_to'] ?? null, $row['owner'] ?? null] as $name) {
                if (! $name) {
                    continue;
                }
                $key = Str::lower((string) $name);
                if (! isset($names[$key])) {
                    $names[$key] = (string) $name;
                }
            }
        }

        $ordered = [];
        if ($origin) {
            $ordered[Str::lower($origin)] = $origin;
        }
        foreach ($names as $key => $name) {
            if (! isset($ordered[$key])) {
                $ordered[$key] = $name;
            }
        }
        if ($current) {
            unset($ordered[Str::lower($current)]);
            $ordered[Str::lower($current)] = $current;
        }

        $trail = [];
        foreach (array_values($ordered) as $owner) {
            $role = 'desk';
            if ($origin && Str::lower($owner) === Str::lower($origin)) {
                $role = 'first';
            }
            if ($current && Str::lower($owner) === Str::lower($current)) {
                $role = 'current';
            }
            $trail[] = [
                'owner' => $owner,
                'role' => $role,
            ];
        }

        return $trail;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $history
     * @return array{count: ?int, label: ?string}
     */
    private function bestCalls(Collection $history): array
    {
        $numeric = $history
            ->pluck('calls')
            ->filter(fn ($v) => is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)))
            ->map(fn ($v) => (int) $v);

        if ($numeric->isNotEmpty()) {
            $count = $numeric->max();

            return ['count' => $count, 'label' => (string) $count];
        }

        $label = $history->pluck('calls_label')->filter()->first();

        return LeadNormalizer::parseCalls($label);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function placementFor(array $row, ?string $currentOwner): string
    {
        if (LeadNormalizer::isProcessBoardName($row['board_name'] ?? null)) {
            return 'process';
        }

        $current = Str::lower(trim((string) $currentOwner));
        $boardOwner = Str::lower(trim((string) ($row['board_owner'] ?? '')));
        if ($current !== '' && $boardOwner === $current) {
            return 'current';
        }

        return 'previous';
    }

    /**
     * Union leads that share phone, email, or canonical name.
     *
     * @param  Collection<int, DriverLead>  $rows
     * @return Collection<int, Collection<int, DriverLead>>
     */
    private function clusterByIdentity(Collection $rows): Collection
    {
        $ids = $rows->pluck('id')->all();
        $parent = [];
        foreach ($ids as $id) {
            $parent[$id] = $id;
        }

        $find = function (int $id) use (&$parent, &$find): int {
            if ($parent[$id] !== $id) {
                $parent[$id] = $find($parent[$id]);
            }

            return $parent[$id];
        };

        $union = function (int $a, int $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };

        $tokenOwners = [];
        foreach ($rows as $lead) {
            foreach ($this->identityTokens($lead) as $token) {
                if (isset($tokenOwners[$token])) {
                    $union((int) $lead->id, (int) $tokenOwners[$token]);
                } else {
                    $tokenOwners[$token] = (int) $lead->id;
                }
            }
        }

        return $rows
            ->groupBy(fn (DriverLead $lead) => $find((int) $lead->id))
            ->values();
    }

    /**
     * @return list<string>
     */
    private function identityTokens(DriverLead $lead): array
    {
        $tokens = [];
        if ($lead->phone_normalized) {
            $tokens[] = 'p:'.$lead->phone_normalized;
        }
        if ($lead->email_normalized) {
            $tokens[] = 'e:'.$lead->email_normalized;
        }
        if ($name = LeadNormalizer::canonicalName($lead->name)) {
            $tokens[] = 'n:'.$name;
        }
        if ($tokens === []) {
            $tokens[] = 'id:'.$lead->id;
        }

        return $tokens;
    }

    /**
     * @param  Collection<int, string>  $phones
     * @param  Collection<int, string>  $emails
     * @param  Collection<int, string>  $names
     */
    private function matchesIdentityTokens(
        DriverLead $lead,
        Collection $phones,
        Collection $emails,
        Collection $names,
    ): bool {
        if ($lead->phone_normalized && $phones->contains($lead->phone_normalized)) {
            return true;
        }
        if ($lead->email_normalized && $emails->contains($lead->email_normalized)) {
            return true;
        }
        $canonical = LeadNormalizer::canonicalName($lead->name);

        return $canonical !== null && $names->contains($canonical);
    }

    private function scopeProcessBoards(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where('board_name', 'ilike', '%HR Process%')
                ->orWhere('board_name', 'ilike', '%Process%JM%')
                ->orWhere('board_name', 'ilike', '%Process%BP%')
                ->orWhere('board_name', 'ilike', '%Process%WF%')
                ->orWhere('board_name', 'ilike', '%Process%JDM%')
                ->orWhere('board_name', 'ilike', '%JM%Process%')
                ->orWhere('board_name', 'ilike', '%BP%Process%')
                ->orWhere('board_name', 'ilike', '%WF%Process%')
                ->orWhere('board_name', 'ilike', '%JDM%Process%');
        });
    }

    private function scopeNonProcessBoards(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->whereNull('board_name')
                ->orWhere(function (Builder $inner) {
                    $inner->where('board_name', 'not ilike', '%HR Process%')
                        ->where('board_name', 'not ilike', '%Process%JM%')
                        ->where('board_name', 'not ilike', '%Process%BP%')
                        ->where('board_name', 'not ilike', '%Process%WF%')
                        ->where('board_name', 'not ilike', '%Process%JDM%')
                        ->where('board_name', 'not ilike', '%JM%Process%')
                        ->where('board_name', 'not ilike', '%BP%Process%')
                        ->where('board_name', 'not ilike', '%WF%Process%')
                        ->where('board_name', 'not ilike', '%JDM%Process%');
                });
        });
    }
}
