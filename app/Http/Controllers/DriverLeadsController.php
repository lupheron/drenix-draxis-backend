<?php

namespace App\Http\Controllers;

use App\Services\DriverLeadDatabaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverLeadsController extends Controller
{
    public function __construct(
        private readonly DriverLeadDatabaseService $service,
    ) {}

    /**
     * Search driver history by name and/or phone and/or email.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'in:JM,WF,BP'],
        ]);

        $result = $this->service->search(
            $validated['name'] ?? null,
            $validated['phone'] ?? null,
            $validated['email'] ?? null,
            $validated['company'] ?? 'JM',
        );

        return response()->json($result);
    }

    /**
     * Browse / filter all stored leads (table + card views).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company' => ['nullable', 'string', 'in:JM,WF,BP'],
            'status' => ['nullable', 'string', 'max:100'],
            'board' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->service->list(
            $validated['company'] ?? 'JM',
            $validated['status'] ?? null,
            $validated['board'] ?? null,
            $validated['page'] ?? 1,
            $validated['per_page'] ?? 50,
        );

        return response()->json([
            'data' => $this->service
                ->formatLeads(collect($page->items()), $validated['company'] ?? 'JM')
                ->all(),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
                'status' => $validated['status'] ?? null,
                'board' => $validated['board'] ?? null,
            ],
        ]);
    }
}
