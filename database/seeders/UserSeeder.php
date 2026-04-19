<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
        $defaults = [
            [
                'name' => 'Admin',
                'email' => 'admin@endekise.local',
                'role' => 'admin',
            ],
            [
                'name' => 'Head CEO',
                'email' => 'ceo@endekise.local',
                'role' => 'head_ceo',
            ],
            [
                'name' => 'Loan Manager',
                'email' => 'loan.manager@endekise.local',
                'role' => 'loan_manager',
            ],
            [
                'name' => 'Branch Manager',
                'email' => 'branch.manager@endekise.local',
                'role' => 'branch_manager',
            ],
            [
                'name' => 'Savings Manager',
                'email' => 'savings.manager@endekise.local',
                'role' => 'savings_manager',
            ],
            [
                'name' => 'Savings Staff',
                'email' => 'savings.staff@endekise.local',
                'role' => 'savings_employee',
            ],
        ];

        foreach ($defaults as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'role' => $userData['role'],
                    'password' => Hash::make('password'),
                ]
            );
        }
    }
}
