<?php

namespace Database\Seeders;

use App\Support\SqlServerDb;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class GratingPerformanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        SqlServerDb::transaction(function () {
            SqlServerDb::table('grating_daily_entries')
                ->where('notes', 'like', 'GP-DEMO%')
                ->delete();

            $employees = [
                ['employee_code' => 'GP-DEMO-A', 'name' => 'สมชาย ใจดี', 'nickname' => 'ชาย', 'responsible_work' => 'ตัด-ขัด เกรตติ้ง / ขัด Hair line, ออกหน้างาน'],
                ['employee_code' => 'GP-DEMO-B', 'name' => 'วิชัย งานไว', 'nickname' => 'ชัย', 'responsible_work' => 'spot แผ่นเกรตติ้ง'],
                ['employee_code' => 'GP-DEMO-C', 'name' => 'มาลี ล้างงาน', 'nickname' => 'ลี', 'responsible_work' => 'ล้างรอยเชื่อม, แช่ passivate'],
                ['employee_code' => 'GP-DEMO-D', 'name' => 'อนันต์ ส่งงาน', 'nickname' => 'นัน', 'responsible_work' => 'แพ็ค, ออกหน้างาน'],
            ];

            foreach ($employees as $employee) {
                SqlServerDb::table('grating_employees')->updateOrInsert(
                    ['employee_code' => $employee['employee_code']],
                    array_merge($employee, [
                        'active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                );
            }

            $steps = [
                ['step_code' => 'WELD_ASSEMBLY', 'step_name' => 'เชื่อมประกอบ', 'target_kg_per_hour' => null, 'sort_order' => 10],
                ['step_code' => 'CLEAN_WELD', 'step_name' => 'ล้างรอยเชื่อม', 'target_kg_per_hour' => null, 'sort_order' => 20],
                ['step_code' => 'PASSIVATE', 'step_name' => 'แช่ passivate', 'target_kg_per_hour' => null, 'sort_order' => 30],
                ['step_code' => 'EPQ', 'step_name' => 'EPQ', 'target_kg_per_hour' => null, 'sort_order' => 40],
                ['step_code' => 'SPOT_GRATING', 'step_name' => 'spot แผ่นเกรตติ้ง', 'target_kg_per_hour' => null, 'sort_order' => 50],
                ['step_code' => 'CUT_GRIND_GRATING', 'step_name' => 'ตัด-ขัด เกรตติ้ง / ขัด Hair line', 'target_kg_per_hour' => null, 'sort_order' => 60],
                ['step_code' => 'CUT_GRIND_FLATBAR', 'step_name' => 'ตัด-ขัด แฟลตบาร์', 'target_kg_per_hour' => null, 'sort_order' => 70],
                ['step_code' => 'LASER', 'step_name' => 'เลเซอร์', 'target_kg_per_hour' => null, 'sort_order' => 80],
                ['step_code' => 'PACK', 'step_name' => 'แพ็ค', 'target_kg_per_hour' => null, 'sort_order' => 90],
                ['step_code' => 'FIELD', 'step_name' => 'ออกหน้างาน', 'target_kg_per_hour' => null, 'sort_order' => 100, 'is_field_work' => true],
            ];

            foreach ($steps as $step) {
                SqlServerDb::table('grating_steps')->updateOrInsert(
                    ['step_code' => $step['step_code']],
                    array_merge([
                        'step_name' => $step['step_name'],
                        'is_field_work' => $step['is_field_work'] ?? false,
                        'target_kg_per_hour' => $step['target_kg_per_hour'],
                        'sort_order' => $step['sort_order'],
                        'active' => true,
                    ], [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                );
            }

            $employeeIds = SqlServerDb::table('grating_employees')
                ->whereIn('employee_code', array_column($employees, 'employee_code'))
                ->pluck('id', 'employee_code');

            $stepIds = SqlServerDb::table('grating_steps')
                ->whereIn('step_code', array_column($steps, 'step_code'))
                ->pluck('id', 'step_code');

            $today = Carbon::now('Asia/Bangkok')->toDateString();
            $yesterday = Carbon::now('Asia/Bangkok')->subDay()->toDateString();

            $entries = [
                [
                    'work_date' => $today,
                    'mfg_no' => 'GP-DEMO-MFG-001',
                    'step' => 'CUT_GRIND_GRATING',
                    'start' => '08:00',
                    'finish' => '10:00',
                    'good' => 120,
                    'bad' => 4,
                    'finished' => false,
                    'is_field_work' => false,
                    'employees' => ['GP-DEMO-A'],
                    'notes' => 'GP-DEMO งานเดี่ยว: สมชายขัด MFG-001',
                ],
                [
                    'work_date' => $today,
                    'mfg_no' => 'GP-DEMO-MFG-002',
                    'step' => 'SPOT_GRATING',
                    'start' => '10:15',
                    'finish' => '12:15',
                    'good' => 100,
                    'bad' => 2,
                    'finished' => true,
                    'is_field_work' => false,
                    'employees' => ['GP-DEMO-A', 'GP-DEMO-B'],
                    'notes' => 'GP-DEMO งานร่วม A+B ยอด 100kg ต้องนับครั้งเดียว',
                ],
                [
                    'work_date' => $today,
                    'mfg_no' => 'GP-DEMO-MFG-003',
                    'step' => 'CLEAN_WELD',
                    'start' => '08:30',
                    'finish' => '09:15',
                    'good' => 300,
                    'bad' => 0,
                    'finished' => true,
                    'is_field_work' => false,
                    'employees' => ['GP-DEMO-C'],
                    'notes' => 'GP-DEMO ล้างรอยเชื่อม',
                ],
                [
                    'work_date' => $yesterday,
                    'mfg_no' => 'GP-DEMO-MFG-004',
                    'step' => 'FIELD',
                    'start' => '13:00',
                    'finish' => '15:30',
                    'good' => 150,
                    'bad' => 6,
                    'finished' => true,
                    'is_field_work' => true,
                    'field_activity' => 'ดูหน้างาน Survey',
                    'field_details' => 'สำรวจพื้นที่ติดตั้งและถ่ายรูปจุดแก้ไขหน้างาน',
                    'employees' => ['GP-DEMO-A', 'GP-DEMO-D'],
                    'notes' => 'GP-DEMO งานออกหน้างาน',
                ],
            ];

            foreach ($entries as $entry) {
                $startedAt = Carbon::parse($entry['work_date'] . ' ' . $entry['start']);
                $finishedAt = Carbon::parse($entry['work_date'] . ' ' . $entry['finish']);

                $entryId = SqlServerDb::table('grating_daily_entries')->insertGetId([
                    'work_date' => $entry['work_date'],
                    'mfg_no' => $entry['mfg_no'],
                    'step_id' => $stepIds[$entry['step']],
                    'started_at' => $startedAt,
                    'finished_at' => $finishedAt,
                    'duration_minutes' => $startedAt->diffInMinutes($finishedAt),
                    'good_qty_kg' => $entry['good'],
                    'bad_qty_kg' => $entry['bad'],
                    'is_finished' => $entry['finished'],
                    'is_field_work' => $entry['is_field_work'],
                    'field_activity' => $entry['field_activity'] ?? null,
                    'field_details' => $entry['field_details'] ?? null,
                    'notes' => $entry['notes'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                SqlServerDb::table('grating_entry_steps')->insert([
                    'entry_id' => $entryId,
                    'step_id' => $stepIds[$entry['step']],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($entry['employees'] as $employeeCode) {
                    SqlServerDb::table('grating_entry_employees')->insert([
                        'entry_id' => $entryId,
                        'employee_id' => $employeeIds[$employeeCode],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }
}
