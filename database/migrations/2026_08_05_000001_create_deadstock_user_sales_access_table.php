<?php

use App\Support\SqlServerDb;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROLE_NAMES = [
        'DS_IMPORT' => 'Deadstock - Import ตาม Sales Mapping',
        'DS_IMPORT_ALL' => 'Deadstock - Import ทุก Sales',
        'DS_MANAGE_ALL' => 'Deadstock - จัดการและ Import ทุก Sales',
    ];

    private const SALES_MAPPINGS = [
        'dilok_s' => 'D1',
        'kwanruan_i' => 'D1',
        'preeyapan_t' => 'D2',
        'nittaya_t' => 'D2',
        'pakawadee_r' => 'D3',
        'thanutcha_t' => 'D3',
        'tutliya_p' => 'D5',
        'surasak_l' => 'D6',
        'kanyika_k' => 'D6',
        'sirinapa_s' => 'D7',
        'monnaphat_w' => 'D7',
        'sathit_m' => 'D8',
        'suthasinee_k' => 'D8',
        'woradecha_w' => 'D9',
        'laddawan_p' => 'D9',
    ];

    private const FULL_ACCESS_USERNAMES = [
        'assadaporn_m',
        'thanin_p',
    ];

    public function up(): void
    {
        $schema = Schema::connection(SqlServerDb::connectionName());

        if (! $schema->hasTable('ds_user_sales_access')) {
            $schema->create('ds_user_sales_access', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('salesperson_key', 50);
                $table->boolean('can_import')->default(true);
                $table->boolean('can_edit')->default(true);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'salesperson_key'], 'ds_user_sales_access_unique');
                $table->index(['salesperson_key', 'is_active'], 'ds_sales_access_scope_idx');
            });
        }

        $this->seedRolesAndMappings();
    }

    public function down(): void
    {
        $roleIds = SqlServerDb::table('dept_roles')
            ->whereIn('code', array_keys(self::ROLE_NAMES))
            ->pluck('id');

        if ($roleIds->isNotEmpty()) {
            SqlServerDb::table('user_dept_roles')->whereIn('role_id', $roleIds->all())->delete();
            SqlServerDb::table('dept_roles')->whereIn('id', $roleIds->all())->delete();
        }

        Schema::connection(SqlServerDb::connectionName())->dropIfExists('ds_user_sales_access');
    }

    private function seedRolesAndMappings(): void
    {
        foreach (self::ROLE_NAMES as $code => $name) {
            if (! SqlServerDb::table('dept_roles')->where('code', $code)->exists()) {
                SqlServerDb::table('dept_roles')->insert([
                    'code' => $code,
                    'name' => $name,
                    'is_active' => 1,
                ]);
            }
        }

        $users = SqlServerDb::table('users')
            ->whereIn('username', array_unique(array_merge(
                array_keys(self::SALES_MAPPINGS),
                self::FULL_ACCESS_USERNAMES
            )))
            ->get(['id', 'username', 'department_id'])
            ->keyBy(fn ($user) => mb_strtolower(trim((string) $user->username), 'UTF-8'));

        $now = now();

        foreach (self::SALES_MAPPINGS as $username => $salespersonKey) {
            $user = $users->get($username);
            if (! $user) {
                continue;
            }

            SqlServerDb::table('ds_user_sales_access')->updateOrInsert(
                [
                    'user_id' => (int) $user->id,
                    'salesperson_key' => $salespersonKey,
                ],
                [
                    'can_import' => 1,
                    'can_edit' => 1,
                    'is_active' => 1,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $this->assignRole($user, 'DS_IMPORT');
        }

        foreach (self::FULL_ACCESS_USERNAMES as $username) {
            $user = $users->get($username);
            if ($user) {
                $this->assignRole($user, 'DS_MANAGE_ALL');
            }
        }
    }

    private function assignRole(object $user, string $roleCode): void
    {
        $roleId = SqlServerDb::table('dept_roles')->where('code', $roleCode)->value('id');
        if (! $roleId) {
            return;
        }

        $assignment = [
            'user_id' => (int) $user->id,
            'department_id' => $user->department_id,
            'role_id' => (int) $roleId,
        ];

        if (! SqlServerDb::table('user_dept_roles')->where($assignment)->exists()) {
            SqlServerDb::table('user_dept_roles')->insert($assignment);
        }
    }
};
