<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class JmEmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $employees = [
            ['Alex', 'Chester', 'hr', 'Head of HR', 8500],
            ['Winston', 'Smith', 'hr', 'Recruiter', 5500],
            ['Isaac', 'Taylor', 'hr', 'Recruiter', 5200],
            ['Alfred', 'Brooks', 'hr', 'Recruiter', 5200],
            ['Henry', 'Safety Department', 'safety', 'Safety Manager', 6000],
        ];

        foreach ($employees as [$first, $last, $department, $position, $salary]) {
            $exists = DB::table('users')
                ->where('company', 'JM')
                ->where('first_name', $first)
                ->where('last_name', $last)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('users')->insert([
                'first_name' => $first,
                'last_name' => $last,
                'birth_date' => '1990-01-15',
                'joined_at' => '2023-06-01',
                'shift' => 'morning',
                'salary' => $salary,
                'grade' => 'A1',
                'status' => 'normal',
                'phone' => null,
                'email' => null,
                'address' => null,
                'city' => null,
                'state' => null,
                'country' => 'USA',
                'gender' => null,
                'department' => $department,
                'position' => $position,
                'company' => 'JM',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Client Portal QA login (HR recruiter with Monday leads)
        $winston = \App\Models\User::query()
            ->where('company', 'JM')
            ->where('first_name', 'Winston')
            ->where('last_name', 'Smith')
            ->first();

        if ($winston) {
            $winston->username = 'winston.smith';
            $winston->password = 'ClientPortal@123';
            $winston->save();
        }
    }
}
