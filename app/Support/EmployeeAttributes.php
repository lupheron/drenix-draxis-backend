<?php

namespace App\Support;

use Illuminate\Validation\Rule;

class EmployeeAttributes
{
    public const COMPANIES = ['JM', 'WF', 'BP'];

    public const DEPARTMENTS = ['hr', 'safety'];

    public const DEPARTMENT_ALIASES = [
        'human resources' => 'hr',
        'human-resources' => 'hr',
        'hr' => 'hr',
        'safety' => 'safety',
    ];

    public static function normalizeDepartment(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $key = strtolower(trim($value));

        return self::DEPARTMENT_ALIASES[$key] ?? $key;
    }

    public static function companyRule(bool $required = true): array
    {
        $rules = ['string', Rule::in(self::COMPANIES)];

        return $required ? array_merge(['required'], $rules) : array_merge(['sometimes', 'required'], $rules);
    }

    public static function departmentRule(bool $required = true): array
    {
        $rules = ['string', Rule::in(self::DEPARTMENTS)];

        return $required ? array_merge(['required'], $rules) : array_merge(['sometimes', 'required'], $rules);
    }
}
