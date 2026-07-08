<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if (!$schema->hasTable('user_activity_logs')) {
            $schema->create('user_activity_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();   // NULL = guest
                $table->string('user_name', 150)->nullable();        // snapshot กันกรณี user ถูกลบ
                $table->boolean('is_authenticated')->default(false);
                $table->string('menu_name', 150)->nullable();        // ชื่อเมนูจาก config/menu.php
                $table->string('route_name', 150)->nullable();
                $table->string('url_path', 255)->nullable();
                $table->string('method', 10)->nullable();
                $table->string('action_type', 20)->nullable();       // view/insert/update/delete
                $table->smallInteger('status_code')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->dateTime('accessed_at');

                $table->index('user_id', 'ual_user_idx');
                $table->index('route_name', 'ual_route_idx');
                $table->index('action_type', 'ual_action_idx');
                $table->index('ip_address', 'ual_ip_idx');
                $table->index('accessed_at', 'ual_at_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if ($schema->hasTable('user_activity_logs')) {
            $schema->drop('user_activity_logs');
        }
    }
};
