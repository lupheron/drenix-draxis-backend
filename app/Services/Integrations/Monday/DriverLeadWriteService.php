<?php

namespace App\Services\Integrations\Monday;

use App\Models\DriverLead;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DriverLeadWriteService
{
    public function move(User $employee, array $ids, string $targetBoard): array
    {
        $this->assertHrEmployee($employee);

        $allowed = $this->allowedBoardNames($employee);
        if ($allowed === []) {
            throw new AccessDeniedHttpException('No personal Monday boards configured for this employee.');
        }

        if (! in_array($targetBoard, $allowed, true)) {
            throw new AccessDeniedHttpException('Target board is not mapped to this employee.');
        }

        $leads = $this->scopedLeads($employee, $ids, $allowed);
        $client = $this->clientFor($employee);
        $target = $this->resolveBoard($client, $employee->company, $targetBoard);
        $group = $this->defaultGroup($client, $target['id']);

        $moved = 0;

        foreach ($leads as $lead) {
            if ((string) $lead->board_name === $targetBoard) {
                $moved++;
                continue;
            }

            if (! $lead->monday_item_id) {
                throw ValidationException::withMessages([
                    'ids' => ["Lead {$lead->id} has no Monday item id."],
                ]);
            }

            $client->moveItemToBoard(
                (string) $lead->monday_item_id,
                $target['id'],
                $group['id'],
            );

            $lead->fill([
                'board_id' => $target['id'],
                'board_name' => $targetBoard,
                'group_id' => $group['id'],
                'group_title' => $group['title'],
            ])->save();

            $moved++;
        }

        return ['moved' => $moved];
    }

    public function delete(User $employee, array $ids): array
    {
        $this->assertHrEmployee($employee);

        $allowed = $this->allowedBoardNames($employee);
        if ($allowed === []) {
            throw new AccessDeniedHttpException('No personal Monday boards configured for this employee.');
        }

        $leads = $this->scopedLeads($employee, $ids, $allowed);
        $client = $this->clientFor($employee);
        $deleted = 0;

        foreach ($leads as $lead) {
            if ($lead->monday_item_id) {
                try {
                    $client->archiveItem((string) $lead->monday_item_id);
                } catch (\Throwable $e) {
                    Log::warning('Monday archive_item failed', [
                        'lead_id' => $lead->id,
                        'monday_item_id' => $lead->monday_item_id,
                        'error' => $e->getMessage(),
                    ]);

                    throw new HttpException(502, 'Failed to archive lead on Monday: '.$e->getMessage());
                }
            }

            $lead->delete();
            $deleted++;
        }

        return ['deleted' => $deleted];
    }

    /**
     * @return list<string>
     */
    public function allowedBoardNames(User $employee): array
    {
        $map = $this->employeeBoardMap($employee);
        if ($map === null) {
            return [];
        }

        return array_values(array_unique(array_filter(array_merge(
            $map['new_leads'] ?? [],
            $map['follow_up'] ?? [],
        ))));
    }

    private function assertHrEmployee(User $employee): void
    {
        if (strtolower((string) $employee->department) !== 'hr') {
            throw new AccessDeniedHttpException('Driver lead writes are available to HR employees only.');
        }
    }

    private function employeeBoardMap(User $employee): ?array
    {
        $company = strtoupper((string) $employee->company);
        $maps = config("integrations.companies.{$company}.monday.user_board_map", []);
        $full = trim("{$employee->first_name} {$employee->last_name}");

        foreach ($maps as $name => $boards) {
            if (strcasecmp((string) $name, $full) === 0) {
                return $boards;
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $allowedBoards
     * @return Collection<int, DriverLead>
     */
    private function scopedLeads(User $employee, array $ids, array $allowedBoards): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw ValidationException::withMessages([
                'ids' => ['At least one lead id is required.'],
            ]);
        }

        $leads = DriverLead::query()
            ->where('company', strtoupper((string) $employee->company))
            ->whereIn('id', $ids)
            ->get();

        if ($leads->count() !== count($ids)) {
            throw new AccessDeniedHttpException('One or more leads were not found in your company scope.');
        }

        foreach ($leads as $lead) {
            if (! in_array((string) $lead->board_name, $allowedBoards, true)) {
                throw new AccessDeniedHttpException(
                    'Lead is not on your New leads / Follow up boards.'
                );
            }
        }

        return $leads;
    }

    private function clientFor(User $employee): MondayClient
    {
        $company = strtoupper((string) $employee->company);
        $token = config("integrations.companies.{$company}.monday.api_token");

        if (! $token) {
            throw new RuntimeException('Monday API token not configured.');
        }

        return new MondayClient($token);
    }

    /**
     * @return array{id: string, name: string}
     */
    private function resolveBoard(MondayClient $client, string $company, string $boardName): array
    {
        $existing = DriverLead::query()
            ->where('company', strtoupper($company))
            ->where('board_name', $boardName)
            ->whereNotNull('board_id')
            ->orderByDesc('updated_at')
            ->first();

        if ($existing?->board_id) {
            return [
                'id' => (string) $existing->board_id,
                'name' => $boardName,
            ];
        }

        foreach ($client->listBoards() as $board) {
            if (strcasecmp((string) ($board['name'] ?? ''), $boardName) === 0) {
                return [
                    'id' => (string) $board['id'],
                    'name' => $boardName,
                ];
            }
        }

        throw ValidationException::withMessages([
            'target_board' => ["Monday board \"{$boardName}\" was not found."],
        ]);
    }

    /**
     * @return array{id: string, title: string}
     */
    private function defaultGroup(MondayClient $client, string $boardId): array
    {
        $groups = $client->getBoardGroups($boardId);
        $first = $groups[0] ?? null;

        if (! $first || $first['id'] === '') {
            throw new RuntimeException("Monday board {$boardId} has no groups.");
        }

        return $first;
    }
}
