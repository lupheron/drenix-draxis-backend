<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyLeadSocialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $raw = (string) $this->input('phone', '');
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        $this->merge([
            'phone' => $raw,
            'phone_digits' => $digits,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string'],
            'phone_digits' => ['required', 'string', 'min:10', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_digits.required' => 'The phone field must contain digits.',
            'phone_digits.min' => 'The phone field must be at least 10 digits.',
            'phone_digits.max' => 'The phone field must not exceed 20 digits.',
        ];
    }
}
