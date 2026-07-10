<?php

use App\Support\SqlServerDb;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = SqlServerDb::connectionName();
        $schema = Schema::connection($connection);

        if (!$schema->hasColumn('users', 'username')) {
            $schema->table('users', function (Blueprint $table) {
                $table->string('username', 80)->nullable();
            });
        }

        $used = [];
        $users = SqlServerDb::table('users')
            ->orderBy('id')
            ->get(['id', 'name', 'username']);

        foreach ($users as $user) {
            $current = trim((string) ($user->username ?? ''));
            if ($current !== '') {
                $used[strtolower($current)] = true;
                continue;
            }

            $base = $this->usernameFromName((string) ($user->name ?? ''));
            $username = $base;
            $suffix = 2;

            while (isset($used[strtolower($username)])) {
                $username = $base . $suffix;
                $suffix++;
            }

            $used[strtolower($username)] = true;

            SqlServerDb::table('users')
                ->where('id', $user->id)
                ->update(['username' => $username]);
        }

        SqlServerDb::connection()->statement("
            IF NOT EXISTS (
                SELECT 1
                FROM sys.indexes
                WHERE name = 'users_username_unique'
                  AND object_id = OBJECT_ID('users')
            )
            CREATE UNIQUE INDEX users_username_unique
                ON users(username)
                WHERE username IS NOT NULL
        ");
    }

    public function down(): void
    {
        $connection = SqlServerDb::connectionName();
        $schema = Schema::connection($connection);

        SqlServerDb::connection()->statement("
            IF EXISTS (
                SELECT 1
                FROM sys.indexes
                WHERE name = 'users_username_unique'
                  AND object_id = OBJECT_ID('users')
            )
            DROP INDEX users_username_unique ON users
        ");

        if ($schema->hasColumn('users', 'username')) {
            $schema->table('users', function (Blueprint $table) {
                $table->dropColumn('username');
            });
        }
    }

    private function usernameFromName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstName = strtolower(preg_replace('/[^a-z0-9]+/i', '', $parts[0] ?? ''));
        $lastInitial = strtolower(preg_replace('/[^a-z0-9]+/i', '', mb_substr($parts[1] ?? '', 0, 1)));

        $username = $lastInitial !== '' ? "{$firstName}_{$lastInitial}" : $firstName;

        return $username !== '' ? $username : 'user';
    }
};
