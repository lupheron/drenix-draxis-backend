<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest;
use App\Models\Admin;
use App\Services\AccessRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessRequestsController extends Controller
{
    public function __construct(
        private readonly AccessRequestService $accessRequestService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'access_profile_id' => ['required', 'integer', 'exists:access_profiles,id'],
            'requester_name' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $accessRequest = AccessRequest::create([
            ...$validated,
            'status' => 'pending',
        ]);

        return response()->json([
            'data' => $accessRequest->load('profile'),
            'message' => 'Access request submitted. An admin will review it.',
        ], 201);
    }

    public function pending(): JsonResponse
    {
        $requests = AccessRequest::query()
            ->with(['profile'])
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $requests]);
    }

    public function accepted(): JsonResponse
    {
        $requests = AccessRequest::query()
            ->with(['profile', 'reviewer', 'account'])
            ->whereIn('status', ['approved', 'revoked', 'expired'])
            ->orderByDesc('reviewed_at')
            ->get()
            ->map(fn (AccessRequest $item) => [
                'id' => $item->id,
                'requester_name' => $item->requester_name,
                'reason' => $item->reason,
                'status' => $item->status,
                'profile' => $item->profile,
                'reviewed_by' => $item->reviewer?->only(['id', 'username', 'role']),
                'reviewed_at' => $item->reviewed_at,
                'expires_at' => $item->expires_at,
                'account' => $item->account ? [
                    'id' => $item->account->id,
                    'username' => $item->account->username,
                    'expires_at' => $item->account->expires_at,
                    'revoked_at' => $item->account->revoked_at,
                ] : null,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ]);

        return response()->json(['data' => $requests]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $accessRequest = AccessRequest::with('profile')->find($id);

        if (! $accessRequest) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        try {
            $result = $this->accessRequestService->approve($accessRequest, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $result['request'],
            'credentials' => $result['credentials'],
            'message' => 'Request approved. Share these credentials with the requester personally.',
        ]);
    }

    public function deny(Request $request, int $id): JsonResponse
    {
        $accessRequest = AccessRequest::with('profile')->find($id);

        if (! $accessRequest) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        try {
            $accessRequest = $this->accessRequestService->deny($accessRequest, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $accessRequest,
            'message' => 'Request denied.',
        ]);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $accessRequest = AccessRequest::with(['profile', 'account'])->find($id);

        if (! $accessRequest) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        try {
            $accessRequest = $this->accessRequestService->revoke($accessRequest, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $accessRequest,
            'message' => 'Access revoked.',
        ]);
    }
}
