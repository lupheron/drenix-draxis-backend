<?php

namespace App\Http\Controllers;

use App\Models\AccessAccount;
use App\Models\Admin;
use App\Models\User;
use App\Services\LeadSocialVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        $digits = preg_replace('/\D/', '', (string) $request->phone) ?? '';

        if (strlen($digits) < 10) {
            return response()->json([
                'message' => 'The phone field must be at least 10 digits.',
                'errors' => ['phone' => ['The phone field must be at least 10 digits.']],
            ], 422);
        }

        try {
            $data = $this->verifier->verify($digits);
        } catch (Throwable $e) {
            Log::warning('verifySocials failed', ['error' => $e->getMessage()]);

            // Never 5xx/hang for provider failures — Admin/Client need a usable payload
            $data = [
                'phone' => $digits,
                'whatsapp' => false,
                'whatsapp_status' => 'error',
                'telegram' => false,
                'telegram_status' => 'error',
                'facebook_search_url' => 'https://www.facebook.com/search/top/?q='.urlencode($digits),
                'instagram_search_url' => 'https://www.instagram.com/',
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => $data,
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
