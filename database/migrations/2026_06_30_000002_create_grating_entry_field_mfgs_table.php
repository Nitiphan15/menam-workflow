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

        if (!$schema->hasTable('grating_daily_entries')) {
            return;
        }

        if (!$schema->hasTable('grating_entry_field_mfgs')) {
            $schema->create('grating_entry_field_mfgs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('entry_id')->index();
                $table->string('mfg_no', 80)->index();
                $table->unsignedBigInteger('workorder_id')->nullable()->index();
                $table->string('project', 500)->nullable();
                $table->string('salesorder', 80)->nullable()->index();
                $table->timestamps();

                $table->unique(['entry_id', 'mfg_no'], 'grating_entry_field_mfgs_entry_mfg_unique');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(SqlServerDb::connectionName());

        if ($schema->hasTable('grating_entry_field_mfgs')) {
            $schema->drop('grating_entry_field_mfgs');
        }
    }
};
