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

        if (!$schema->hasTable('grating_employees')) {
            return;
        }

        $schema->table('grating_employees', function (Blueprint $table) use ($schema) {
            if (!$schema->hasColumn('grating_employees', 'is_student')) {
                $table->boolean('is_student')->default(false)->after('responsible_work');
            }
            if (!$schema->hasColumn('grating_employees', 'student_start_date')) {
                $table->date('student_start_date')->nullable()->after('is_student');
            }
            if (!$schema->hasColumn('grating_employees', 'student_months')) {
                $table->unsignedSmallInteger('student_months')->nullable()->after('student_start_date');
            }
            if (!$schema->hasColumn('grating_employees', 'student_end_date')) {
                $table->date('student_end_date')->nullable()->after('student_months');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(SqlServerDb::connectionName());

        if (!$schema->hasTable('grating_employees')) {
            return;
        }

        $schema->table('grating_employees', function (Blueprint $table) use ($schema) {
            foreach (['student_end_date', 'student_months', 'student_start_date', 'is_student'] as $column) {
                if ($schema->hasColumn('grating_employees', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
