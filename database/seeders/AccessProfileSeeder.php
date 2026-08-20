<?php

namespace Database\Seeders;

use App\Models\AccessProfile;
use Illuminate\Database\Seeder;

class AccessProfileSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = [
            ['label' => 'CEO JM', 'company' => 'JM', 'role_type' => 'ceo'],
            ['label' => 'CEO WF', 'company' => 'WF', 'role_type' => 'ceo'],
            ['label' => 'CEO BP', 'company' => 'BP', 'role_type' => 'ceo'],
            ['label' => 'Head HR JM', 'company' => 'JM', 'role_type' => 'head_hr'],
            ['label' => 'Head HR BP', 'company' => 'BP', 'role_type' => 'head_hr'],
            ['label' => 'Head HR WF', 'company' => 'WF', 'role_type' => 'head_hr'],
        ];

        foreach ($profiles as $profile) {
            AccessProfile::query()->updateOrCreate(
                ['label' => $profile['label']],
                $profile,
            );
        }
    }
}
