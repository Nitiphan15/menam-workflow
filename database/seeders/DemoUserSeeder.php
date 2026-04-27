<?php

namespace Database\Seeders;
// database/seeders/DemoUserSeeder.php
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DemoUserSeeder extends Seeder
{
    public function run()
    {
        $depId = DB::table('departments')->insertGetId([
            'code' => 'RD', 'name' => 'R&D Department', 'created_at' => now(), 'updated_at' => now()
        ]);

        $roleIdMng = DB::table('department_roles')->insertGetId([
            'department_id' => $depId, 'code' => 'MNG', 'name' => 'Manager', 'level_no' => 3,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now()
        ]);

        $roleIdStaff = DB::table('department_roles')->insertGetId([
            'department_id' => $depId, 'code' => 'STF', 'name' => 'Staff', 'level_no' => 1,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now()
        ]);

        $mngId = DB::table('users')->insertGetId([
            'name' => 'Alice Manager',
            'email' => 'alice@example.com',
            'password' => Hash::make('password'),
            'department_id' => $depId,
            'is_active' => 1,
            'created_at' => now(), 'updated_at' => now()
        ]);

        $staffId = DB::table('users')->insertGetId([
            'name' => 'Bob Staff',
            'email' => 'bob@example.com',
            'password' => Hash::make('password'),
            'department_id' => $depId,
            'supervisor_user_id' => $mngId,
            'is_active' => 1,
            'created_at' => now(), 'updated_at' => now()
        ]);

        // Pivot: Manager
        DB::table('department_role_users')->insert([
            'department_role_id' => $roleIdMng,
            'user_id' => $mngId,
            'is_primary' => 1,
            'start_date' => Carbon::today(),
            'created_at' => now(), 'updated_at' => now()
        ]);

        // Pivot: Staff
        DB::table('department_role_users')->insert([
            'department_role_id' => $roleIdStaff,
            'user_id' => $staffId,
            'is_primary' => 1,
            'start_date' => Carbon::today(),
            'created_at' => now(), 'updated_at' => now()
        ]);
    }
}
