<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed example application users.
     */
    public function run(): void
    {
        $users = [
            [
                'firstname' => 'Maria',
                'lastname' => 'Lopez',
                'middlename' => 'Santos',
                'position' => 'Operations Manager',
                'address' => '101 Ayala Ave, Makati City',
                'user_type' => 'manager',
                'finger_print' => 'FP-MGR-001',
                'email' => 'maria.lopez@example.com',
            ],
            [
                'firstname' => 'Anton',
                'lastname' => 'Reyes',
                'middlename' => 'Villanueva',
                'position' => 'Production Supervisor',
                'address' => '55 Shaw Blvd, Mandaluyong',
                'user_type' => 'supervisor',
                'finger_print' => 'FP-SUP-004',
                'email' => 'anton.reyes@example.com',
            ],
            [
                'firstname' => 'Lara',
                'lastname' => 'Santos',
                'middlename' => 'Cruz',
                'position' => 'QA Analyst',
                'address' => '21 Katipunan Ave, Quezon City',
                'user_type' => 'qa',
                'finger_print' => 'FP-QA-019',
                'email' => 'lara.santos@example.com',
            ],
            [
                'firstname' => 'Paolo',
                'lastname' => 'Castro',
                'middlename' => 'Miguel',
                'position' => 'Warehouse Packer Lead',
                'address' => 'Cavite Economic Zone, Rosario',
                'user_type' => 'packers',
                'finger_print' => 'FP-PAC-212',
                'email' => 'paolo.castro@example.com',
            ],
            [
                'firstname' => 'Jessa',
                'lastname' => 'Lim',
                'middlename' => 'Uy',
                'position' => 'Machine Operator',
                'address' => '789 JP Rizal, Marikina',
                'user_type' => 'operator',
                'finger_print' => 'FP-OPR-338',
                'email' => 'jessa.lim@example.com',
            ],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                array_merge($user, ['password' => Hash::make('secret123')])
            );
        }
    }
}
