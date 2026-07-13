<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('database.workflow_connection', 'sqlsrv_menam');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('grating_field_activities')) {
            $schema->create('grating_field_activities', function (Blueprint $table) {
                $table->id();
                $table->string('activity_code', 40)->unique();
                $table->string('activity_name', 160);
                $table->unsignedSmallInteger('sort_order')->default(100);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        $now = now();
        $activities = [
            ['activity_code' => 'SURVEY', 'activity_name' => 'ดูหน้างาน Survey', 'sort_order' => 10],
            ['activity_code' => 'MEASURE', 'activity_name' => 'วัดงาน', 'sort_order' => 20],
            ['activity_code' => 'INSTALL', 'activity_name' => 'ติดตั้งงาน รวมถึงการแก้ไขงาน', 'sort_order' => 30],
            ['activity_code' => 'DELIVER', 'activity_name' => 'ส่งงาน', 'sort_order' => 40],
            ['activity_code' => 'OTHER', 'activity_name' => 'อื่นๆ', 'sort_order' => 50],
        ];

        foreach ($activities as $activity) {
            DB::connection($connection)->table('grating_field_activities')->updateOrInsert(
                ['activity_code' => $activity['activity_code']],
                array_merge($activity, [
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }

    public function down(): void
    {
        Schema::connection(config('database.workflow_connection', 'sqlsrv_menam'))
            ->dropIfExists('grating_field_activities');
    }
};
