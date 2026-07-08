<?php

use App\Support\SqlServerDb;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(SqlServerDb::connectionName());

        if (!$schema->hasTable('grating_projects')) {
            $schema->create('grating_projects', function (Blueprint $table) {
                $table->id();
                $table->string('project_name', 500)->unique();
                $table->string('salesorder', 80)->nullable()->index();
                $table->unsignedSmallInteger('sort_order')->default(100);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(SqlServerDb::connectionName());

        if ($schema->hasTable('grating_projects')) {
            $schema->drop('grating_projects');
        }
    }
};
