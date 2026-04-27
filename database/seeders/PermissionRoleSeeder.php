<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Permission::firstOrCreate(['name' => 'pr']);
        Permission::firstOrCreate(['name' => 'pradmin']);

        // สร้าง roles และ assign permissions
        $roleUser = Role::firstOrCreate(['name' => 'user']);
        $roleAdmin = Role::firstOrCreate(['name' => 'admin']);

        $roleUser->givePermissionTo('pr');
        $roleAdmin->givePermissionTo(['pr', 'pradmin']);
    }
}
