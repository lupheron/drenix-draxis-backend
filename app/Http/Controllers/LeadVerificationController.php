<?php

namespace App\Http\Controllers;

use App\Models\AccessAccount;
use App\Models\Admin;
use App\Models\User;
use App\Services\LeadSocialVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadVerificationController extends Controller
{
    public function __construct(
        private readonly LeadSocialVerificationService $verifier,
    ) {}

    public function verifySocials(Request $request): JsonResponse
    {
        if (! $this->canVerify($request)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->validate(['phone' => 'required|string|min:10|max:20']);
        $digits = preg_replace('/\D/', '', $request->phone) ?? '';

        if (strlen($digits) < 10) {
            return response()->json([
                'message' => 'The phone field must be at least 10 digits.',
                'errors' => ['phone' => ['The phone field must be at least 10 digits.']],
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->verifier->verify($digits),
        ]);
    }

    private function canVerify(Request $request): bool
    {
        $user = $request->user();

        if ($user instanceof Admin) {
            return true;
        }

        if ($user instanceof AccessAccount) {
            return $user->isActive();
        }

        if ($user instanceof User) {
            return filled($user->username) && filled($user->getAuthPassword());
        }

        return false;
    }
}
