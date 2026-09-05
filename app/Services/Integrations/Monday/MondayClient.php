<?php

namespace App\Services\Integrations\Monday;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MondayClient
{
    private const REQUEST_GAP_MS = 500;

    private const MAX_ATTEMPTS = 5;

    private int $consecutive429s = 0;

    public function __construct(
        private readonly string $apiToken,
    ) {}

    public function query(string $graphql, array $variables = [], int $attempt = 1): array
    {
        $this->pace();

        $payload = ['query' => $graphql];

        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        $response = Http::timeout(120)->withHeaders([
            'Authorization' => $this->apiToken,
            'Content-Type' => 'application/json',
            'API-Version' => '2024-10',
        ])->post('https://api.monday.com/v2', $payload);

        if ($response->status() === 429) {
            $this->consecutive429s++;

            if ($attempt >= self::MAX_ATTEMPTS) {
                throw new RuntimeException("Monday rate limited (429) after {$attempt} retries");
            }

            $retryAfter = (int) ($response->header('Retry-After') ?: 0);
            $waitMs = $retryAfter > 0
                ? $retryAfter * 1000
                : (int) (60_000 * (2 ** (max(1, $this->consecutive429s) - 1)));

            Log::warning('Monday 429 — backing off', [
                'attempt' => $attempt,
                'wait_ms' => $waitMs,
            ]);

            usleep($waitMs * 1000);

            return $this->query($graphql, $variables, $attempt + 1);
        }

        $this->consecutive429s = 0;

        if (! $response->successful()) {
            throw new RuntimeException('Monday API failed: '.$response->body());
        }

        $json = $response->json();

        if (! empty($json['errors'])) {
            throw new RuntimeException('Monday GraphQL errors: '.json_encode($json['errors']));
        }

        return $json['data'] ?? [];
    }

    public function listBoards(): array
    {
        $boards = [];
        $page = 1;

        do {
            $data = $this->query(<<<'GQL'
                query ($page: Int!) {
                  boards(limit: 100, page: $page, state: active) {
                    id
                    name
                    type
                  }
                }
            GQL, ['page' => $page]);

            $chunk = $data['boards'] ?? [];
            $boards = array_merge($boards, $chunk);
            $page++;
        } while (count($chunk) === 100 && $page <= 100);

        return $boards;
    }

    public function getBoardColumns(string $boardId): array
    {
        $data = $this->query(<<<'GQL'
            query ($boardId: [ID!]) {
              boards(ids: $boardId) {
                columns {
                  id
                  title
                  type
                }
              }
            }
        GQL, [
            'boardId' => [$boardId],
        ]);

        return $data['boards'][0]['columns'] ?? [];
    }

    public function getBoardItems(string $boardId): array
    {
        $items = [];
        $cursor = null;

        do {
            $data = $this->query(<<<'GQL'
                query ($boardId: [ID!], $cursor: String) {
                  boards(ids: $boardId) {
                    items_page(limit: 500, cursor: $cursor) {
                      cursor
                      items {
                        id
                        name
                        created_at
                        updated_at
                        group {
                          id
                          title
                        }
                        column_values {
                          id
                          text
                          type
                          value
                        }
                      }
                    }
                  }
                }
            GQL, [
                'boardId' => [$boardId],
                'cursor' => $cursor,
            ]);

            $page = $data['boards'][0]['items_page'] ?? null;

            if (! $page) {
                break;
            }

            $items = array_merge($items, $page['items'] ?? []);
            $cursor = $page['cursor'] ?? null;
        } while ($cursor);

        return $items;
    }

    /**
     * @return list<array{id: string, title: string}>
     */
    public function getBoardGroups(string $boardId): array
    {
        $data = $this->query(<<<'GQL'
            query ($boardId: [ID!]) {
              boards(ids: $boardId) {
                groups {
                  id
                  title
                }
              }
            }
        GQL, [
            'boardId' => [$boardId],
        ]);

        $groups = $data['boards'][0]['groups'] ?? [];

        return array_values(array_map(fn (array $g) => [
            'id' => (string) ($g['id'] ?? ''),
            'title' => (string) ($g['title'] ?? ''),
        ], $groups));
    }

    public function moveItemToBoard(string $itemId, string $boardId, string $groupId): array
    {
        $data = $this->query(<<<'GQL'
            mutation ($itemId: ID!, $boardId: ID!, $groupId: String!) {
              move_item_to_board(item_id: $itemId, board_id: $boardId, group_id: $groupId) {
                id
              }
            }
        GQL, [
            'itemId' => $itemId,
            'boardId' => $boardId,
            'groupId' => $groupId,
        ]);

        return $data['move_item_to_board'] ?? [];
    }

    public function archiveItem(string $itemId): array
    {
        $data = $this->query(<<<'GQL'
            mutation ($itemId: ID!) {
              archive_item(item_id: $itemId) {
                id
              }
            }
        GQL, [
            'itemId' => $itemId,
        ]);

        return $data['archive_item'] ?? [];
    }

    private function pace(): void
    {
        usleep(self::REQUEST_GAP_MS * 1000);
    }
}
