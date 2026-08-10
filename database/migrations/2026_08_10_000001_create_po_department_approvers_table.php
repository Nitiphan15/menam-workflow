<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'));

        if ($schema->hasTable('po_department_approvers')) {
            return;
        }

        $schema->create('po_department_approvers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id')->unique();
            $table->unsignedBigInteger('approver_user_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['approver_user_id', 'is_active'], 'po_dept_approver_user_active_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'))
            ->dropIfExists('po_department_approvers');
    }
};
