<?php

namespace App\Services\Integrations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserMatcher
{
    public function findUserByWhitelistName(string $company, string $whitelistName, array $aliases = []): ?object
    {
        $candidates = $this->nameCandidates($whitelistName, $aliases);

        $users = DB::table('users')
            ->where('company', $company)
            ->get();

        foreach ($users as $user) {
            if ($this->ownerMatchesUser($candidates, $user)) {
                return $user;
            }
        }

        return null;
    }

    public function findExtensionByDisplayName(Collection $extensions, string $whitelistName, array $aliases = []): ?array
    {
        $candidates = $this->nameCandidates($whitelistName, $aliases);

        foreach ($extensions as $extension) {
            $display = (string) ($extension['name'] ?? $extension['contact']['firstName'] ?? '');
            $full = trim(implode(' ', array_filter([
                $extension['contact']['firstName'] ?? null,
                $extension['contact']['lastName'] ?? null,
            ])));

            foreach ($candidates as $candidate) {
                if ($this->namesMatch($candidate, $display) || ($full !== '' && $this->namesMatch($candidate, $full))) {
                    return $extension;
                }
            }
        }

        return null;
    }

    public function ownerMatchesUser(array $candidates, object $user): bool
    {
        $full = trim("{$user->first_name} {$user->last_name}");
        $first = (string) $user->first_name;

        foreach ($candidates as $candidate) {
            if ($this->namesMatch($candidate, $full)) {
                return true;
            }

            if ($this->namesMatch($candidate, $first)) {
                return true;
            }
        }

        return false;
    }

    private function nameCandidates(string $whitelistName, array $aliases = []): array
    {
        $names = array_filter(array_unique(array_merge(
            [$whitelistName],
            $aliases,
            [Str::before($whitelistName, ' ')],
        )));

        return array_map(fn ($n) => $this->normalize($n), $names);
    }

    private function namesMatch(string $a, string $b): bool
    {
        $a = $this->normalize($a);
        $b = $this->normalize($b);

        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        if (str_starts_with($b, $a.' ') || str_starts_with($a, $b.' ')) {
            return true;
        }

        return str_contains($b, $a) || str_contains($a, $b);
    }

    private function normalize(string $value): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }
}
