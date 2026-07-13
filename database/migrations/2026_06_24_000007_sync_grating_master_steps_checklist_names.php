<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('database.workflow_connection', 'sqlsrv_menam');

        if (!Schema::connection($connection)->hasTable('grating_steps')) {
            return;
        }

        $table = DB::connection($connection)->table('grating_steps');
        $old = (clone $table)->where('step_code', 'GRIND_HAIRLINE')->first();
        $newExists = (clone $table)->where('step_code', 'CUT_GRIND_GRATING')->exists();

        if ($old && !$newExists) {
            (clone $table)
                ->where('step_code', 'GRIND_HAIRLINE')
                ->update([
                    'step_code' => 'CUT_GRIND_GRATING',
                    'step_name' => 'ตัด-ขัด เกรตติ้ง / ขัด Hair line',
                    'updated_at' => now(),
                ]);
        } elseif ($old) {
            (clone $table)
                ->where('step_code', 'GRIND_HAIRLINE')
                ->update([
                    'step_name' => 'ตัด-ขัด เกรตติ้ง / ขัด Hair line',
                    'active' => false,
                    'updated_at' => now(),
                ]);
        }

        $now = now();
        $steps = [
            ['step_code' => 'WELD_ASSEMBLY', 'step_name' => 'เชื่อมประกอบ', 'target_kg_per_hour' => null, 'sort_order' => 10, 'is_field_work' => false],
            ['step_code' => 'CLEAN_WELD', 'step_name' => 'ล้างรอยเชื่อม', 'target_kg_per_hour' => null, 'sort_order' => 20, 'is_field_work' => false],
            ['step_code' => 'PASSIVATE', 'step_name' => 'แช่ passivate', 'target_kg_per_hour' => null, 'sort_order' => 30, 'is_field_work' => false],
            ['step_code' => 'EPQ', 'step_name' => 'EPQ', 'target_kg_per_hour' => null, 'sort_order' => 40, 'is_field_work' => false],
            ['step_code' => 'SPOT_GRATING', 'step_name' => 'spot แผ่นเกรตติ้ง', 'target_kg_per_hour' => null, 'sort_order' => 50, 'is_field_work' => false],
            ['step_code' => 'CUT_GRIND_GRATING', 'step_name' => 'ตัด-ขัด เกรตติ้ง / ขัด Hair line', 'target_kg_per_hour' => null, 'sort_order' => 60, 'is_field_work' => false],
            ['step_code' => 'CUT_GRIND_FLATBAR', 'step_name' => 'ตัด-ขัด แฟลตบาร์', 'target_kg_per_hour' => null, 'sort_order' => 70, 'is_field_work' => false],
            ['step_code' => 'LASER', 'step_name' => 'เลเซอร์', 'target_kg_per_hour' => null, 'sort_order' => 80, 'is_field_work' => false],
            ['step_code' => 'PACK', 'step_name' => 'แพ็ค', 'target_kg_per_hour' => null, 'sort_order' => 90, 'is_field_work' => false],
            ['step_code' => 'FIELD', 'step_name' => 'ออกหน้างาน', 'target_kg_per_hour' => null, 'sort_order' => 100, 'is_field_work' => true],
        ];

        foreach ($steps as $step) {
            DB::connection($connection)->table('grating_steps')->updateOrInsert(
                ['step_code' => $step['step_code']],
                array_merge($step, [
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }

    public function down(): void
    {
        // Keep master data because it may have been edited after deployment.
    }
};
