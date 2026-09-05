<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyLeadSocialsRequest;
use App\Models\AccessAccount;
use App\Models\Admin;
use App\Models\User;
use App\Services\LeadSocialVerificationService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class LeadSocialVerificationController extends Controller
{
    public function __construct(
        private readonly LeadSocialVerificationService $verifier,
        private readonly PhoneNormalizer $phones,
    ) {}

    public function __invoke(VerifyLeadSocialsRequest $request): JsonResponse
    {
        if (! $this->canVerify($request->user())) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            // Normalize early so validation errors surface as 422
            $this->phones->normalize((string) $request->input('phone'));
            $data = $this->verifier->verify((string) $request->input('phone'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('verify-socials failed', ['error' => $e->getMessage()]);

            $digits = preg_replace('/\D/', '', (string) $request->input('phone')) ?? '';
            $data = [
                'phone' => $digits,
                'whatsapp' => false,
                'telegram' => false,
                'whatsapp_status' => 'error',
                'telegram_status' => 'error',
                'facebook_search_url' => 'https://www.facebook.com/search/top?q='.urlencode($digits),
                'instagram_search_url' => 'https://www.google.com/search?q='.urlencode('site:instagram.com '.$digits),
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    private function canVerify(mixed $user): bool
    {
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
