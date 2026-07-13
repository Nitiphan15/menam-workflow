<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if (!$schema->hasTable('dp_delivery_confirmation')) {
            $schema->create('dp_delivery_confirmation', function (Blueprint $table) {
                $table->id();
                $table->string('mfg_no', 80);
                $table->string('site', 10)->default('');
                $table->string('so_number', 80)->nullable();
                $table->string('confirmation_status', 20);
                $table->date('original_ship_date')->nullable();
                $table->date('new_delivery_date')->nullable();
                $table->string('remark', 500)->nullable();
                $table->unsignedBigInteger('confirmed_by_id')->nullable();
                $table->string('confirmed_by_login', 80)->nullable();
                $table->string('confirmed_by_name', 150)->nullable();
                $table->dateTime('confirmed_at');
                $table->timestamps();

                $table->index(['mfg_no', 'site', 'confirmed_at'], 'dp_dc_lookup_idx');
                $table->index('confirmed_at', 'dp_dc_at_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if ($schema->hasTable('dp_delivery_confirmation')) {
            $schema->drop('dp_delivery_confirmation');
        }
    }
};
