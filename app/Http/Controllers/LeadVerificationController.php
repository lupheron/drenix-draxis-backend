<?php

namespace App\Http\Controllers;

use App\Models\AccessAccount;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LeadVerificationController extends Controller
{
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

        $whatsapp = $this->checkWhatsApp($digits);
        $telegram = $this->checkTelegram($digits);

        return response()->json([
            'status' => 'success',
            'data' => [
                'phone' => $digits,
                'whatsapp' => $whatsapp,
                'telegram' => $telegram,
                'facebook_search_url' => 'https://www.facebook.com/search/top/?q='.urlencode($digits),
                'instagram_search_url' => 'https://www.instagram.com/',
            ],
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

    private function checkWhatsApp(string $digits): bool
    {
        $whapiToken = config('services.whapi.token');
        if (! $whapiToken) {
            return false;
        }

        try {
            $response = Http::timeout(20)
                ->withToken($whapiToken)
                ->acceptJson()
                ->post('https://gate.whapi.cloud/contacts', [
                    'blocking' => 'no_wait',
                    'contacts' => [$digits],
                ]);

            if ($response->failed()) {
                Log::warning('Whapi contacts check failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            $contact = $response->json('contacts.0') ?? ($response->json('contacts')[0] ?? null);
            $status = strtolower((string) ($contact['status'] ?? ''));

            // Whapi returns "valid" (docs) or "success" (some channel builds)
            return in_array($status, ['valid', 'success'], true);
        } catch (\Throwable $e) {
            Log::warning('Whapi contacts check exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Prefer CheckNumber.ai (TG_LOOKUP_KEY). Fallback: Telegram Gateway (TG_BOT_TOKEN).
     * TG_API_ID / TG_API_HASH alone cannot check phones over HTTP (need MTProto session).
     */
    private function checkTelegram(string $digits): bool
    {
        $lookupKey = config('services.telegram.lookup_key');
        if ($lookupKey) {
            return $this->checkTelegramViaLookupApi($digits, $lookupKey);
        }

        $gatewayToken = config('services.telegram.bot_token');
        if ($gatewayToken) {
            return $this->checkTelegramViaGateway($digits, $gatewayToken);
        }

        return false;
    }

    private function checkTelegramViaLookupApi(string $digits, string $apiKey): bool
    {
        try {
            $response = Http::timeout(20)->get('https://api.checknumber.ai/v1/telegram/check', [
                'api_key' => $apiKey,
                'phone' => $digits,
            ]);

            if (! $response->successful()) {
                Log::warning('CheckNumber.ai telegram check failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return (bool) $response->json('exists');
        } catch (\Throwable $e) {
            Log::warning('CheckNumber.ai telegram check exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function checkTelegramViaGateway(string $digits, string $gatewayToken): bool
    {
        $e164 = str_starts_with($digits, '+') ? $digits : '+'.$digits;

        try {
            $response = Http::timeout(20)
                ->withToken($gatewayToken)
                ->asJson()
                ->post('https://gatewayapi.telegram.org/checkSendAbility', [
                    'phone_number' => $e164,
                ]);

            if ($response->successful() && ($response->json('ok') === true)) {
                return true;
            }

            if ($response->status() === 400 || $response->json('ok') === false) {
                return false;
            }

            Log::warning('Telegram Gateway checkSendAbility failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::warning('Telegram Gateway check exception', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
