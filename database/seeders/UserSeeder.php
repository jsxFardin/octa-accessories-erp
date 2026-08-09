<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * One user per role, so every screen can be opened as the persona it was designed for and
 * the permission middleware is exercised from the first day rather than the first audit.
 */
class UserSeeder extends Seeder
{
    /** @var list<array{name: string, email: string, role: string, department: ?string}> */
    private const USERS = [
        ['name' => 'System Implementer', 'email' => 'admin@maheenlabel.test', 'role' => 'super_admin', 'department' => null],
        ['name' => 'Managing Director', 'email' => 'md@maheenlabel.test', 'role' => 'md', 'department' => null],
        ['name' => 'Rehana Haque', 'email' => 'merchandiser@maheenlabel.test', 'role' => 'merchandiser', 'department' => null],
        ['name' => 'Kamrul Islam', 'email' => 'sales@maheenlabel.test', 'role' => 'sales_manager', 'department' => null],
        ['name' => 'Tanvir Ahmed', 'email' => 'designer@maheenlabel.test', 'role' => 'designer', 'department' => 'STUDIO'],
        ['name' => 'Shirin Akter', 'email' => 'engineer@maheenlabel.test', 'role' => 'engineer', 'department' => 'STUDIO'],
        ['name' => 'Mizanur Rahman', 'email' => 'planner@maheenlabel.test', 'role' => 'planner', 'department' => 'WEAV'],
        ['name' => 'Abdul Karim', 'email' => 'supervisor@maheenlabel.test', 'role' => 'production_supervisor', 'department' => 'WEAV'],
        ['name' => 'Jahangir Alam', 'email' => 'operator@maheenlabel.test', 'role' => 'operator', 'department' => 'WEAV'],
        ['name' => 'Nasima Begum', 'email' => 'store@maheenlabel.test', 'role' => 'store_keeper', 'department' => 'STORE'],
        ['name' => 'Rafiqul Haque', 'email' => 'storemanager@maheenlabel.test', 'role' => 'store_manager', 'department' => 'STORE'],
        ['name' => 'Salma Khatun', 'email' => 'qc@maheenlabel.test', 'role' => 'qc_inspector', 'department' => 'QC'],
        ['name' => 'Imran Hossain', 'email' => 'quality@maheenlabel.test', 'role' => 'quality_manager', 'department' => 'QC'],
        ['name' => 'Farhana Yasmin', 'email' => 'lab@maheenlabel.test', 'role' => 'lab_technician', 'department' => 'LAB'],
        ['name' => 'Anwar Hossain', 'email' => 'compliance@maheenlabel.test', 'role' => 'compliance_officer', 'department' => null],
        ['name' => 'Shahid Ullah', 'email' => 'purchase@maheenlabel.test', 'role' => 'purchase_officer', 'department' => 'STORE'],
        ['name' => 'Nazrul Islam', 'email' => 'purchasemanager@maheenlabel.test', 'role' => 'purchase_manager', 'department' => 'STORE'],
        ['name' => 'Belal Uddin', 'email' => 'dispatch@maheenlabel.test', 'role' => 'dispatch_officer', 'department' => 'DISP'],
        ['name' => 'Sohel Mia', 'email' => 'driver@maheenlabel.test', 'role' => 'driver', 'department' => 'DISP'],
        ['name' => 'Ruma Chowdhury', 'email' => 'accounts@maheenlabel.test', 'role' => 'accounts', 'department' => null],
        ['name' => 'External Auditor', 'email' => 'auditor@maheenlabel.test', 'role' => 'read_only', 'department' => null],
    ];

    public function run(): void
    {
        $roles = Role::query()->pluck('id', 'name');
        $unitId = DB::table('factory_units')->where('code', 'ML-1')->value('id');
        $departments = DB::table('departments')->pluck('id', 'code');
        $sequence = 1;

        foreach (self::USERS as $seed) {
            /** @var User $user */
            $user = User::query()->updateOrCreate(
                ['email' => $seed['email']],
                [
                    'name' => $seed['name'],
                    'password' => 'password',
                    'is_active' => true,
                    // The floor runs in Bangla by default (README §4).
                    'locale' => in_array($seed['role'], ['operator', 'driver'], true) ? 'bn' : 'en',
                    'email_verified_at' => now(),
                ],
            );

            DB::table('user_roles')->updateOrInsert(
                ['user_id' => $user->id, 'role_id' => $roles[$seed['role']]],
                [],
            );

            // The employee row is what carries the factory-unit scope (06-rbac §4) and the
            // badge number the shop-floor terminal logs in with.
            DB::table('employees')->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'factory_unit_id' => $unitId,
                    'department_id' => $seed['department'] === null ? null : $departments[$seed['department']],
                    'code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
                    'name' => $seed['name'],
                    'designation' => str_replace('_', ' ', ucfirst($seed['role'])),
                    'card_no' => 'BADGE-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
                    'is_active' => true,
                ],
            );

            $sequence++;
        }

        $this->command->info(count(self::USERS).' users seeded. Password for all: password');
    }
}
