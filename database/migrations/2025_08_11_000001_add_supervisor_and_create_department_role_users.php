<?php
// database/migrations/2025_08_11_000001_add_supervisor_and_create_department_role_users.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1) เพิ่ม supervisor_user_id ใน users
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('supervisor_user_id')->nullable()->after('department_id');
            $table->foreign('supervisor_user_id')
                  ->references('id')->on('users')
                  ->nullOnDelete()
                  ->cascadeOnUpdate();
        });

        // 2) ตาราง pivot department_role_users
        Schema::create('department_role_users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('department_role_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_primary')->default(0);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();

            $table->foreign('department_role_id')->references('id')->on('department_roles')
                  ->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('user_id')->references('id')->on('users')
                  ->cascadeOnDelete()->cascadeOnUpdate();

            $table->unique(['department_role_id','user_id','start_date'], 'uq_role_user_start');
            $table->index(['department_role_id','start_date','end_date'], 'idx_role_period');
            $table->index(['user_id','start_date','end_date'], 'idx_user_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_role_users');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['supervisor_user_id']);
            $table->dropColumn('supervisor_user_id');
        });
    }
};
