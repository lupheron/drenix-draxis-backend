<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class PhoneNormalizer
{
    /**
     * @return array{digits: string, e164: string, e164_no_plus: string}
     */
    public function normalize(string $raw): array
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        // US default: 10-digit local → prepend country code 1
        if (strlen($digits) === 10) {
            $digits = '1'.$digits;
        }

        if (strlen($digits) < 10 || strlen($digits) > 15) {
            throw ValidationException::withMessages([
                'phone' => ['The phone must contain between 10 and 15 digits.'],
            ]);
        }

        return [
            'digits' => $digits,
            'e164' => '+'.$digits,
            'e164_no_plus' => $digits,
        ];
    }
}
