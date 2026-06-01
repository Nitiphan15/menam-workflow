<?php

namespace App\Http\Controllers\FormDP;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TruckMasterController extends Controller
{
    private function conn()
    {
        return DB::connection('sqlsrv_menam');
    }

    public function trucks()
    {
        $rows = $this->conn()
            ->table('delivery_plan_truck_master_dev')
            ->orderBy('id')
            ->get();

        $drivers = $this->driverOptions();
        $driverMap = $this->truckDriverMap();

        return view('formdp.masters.trucks', compact('rows', 'drivers', 'driverMap'));
    }

    public function saveTruck(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'plate_no' => ['required', 'string', 'max:50'],
            'driver_staff_id' => ['nullable', 'integer'],
            'driver_name' => ['nullable', 'string', 'max:100'],
            'driver_phone' => ['nullable', 'string', 'max:50'],
            'max_load_ton' => ['nullable', 'numeric', 'min:0'],
            'car_length' => ['nullable', 'numeric', 'min:0'],
            'remark' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);

        $driverStaffId = (int) ($data['driver_staff_id'] ?? 0);
        $driver = $driverStaffId > 0
            ? $this->conn()
                ->table('delivery_plan_truck_staff_master_dev')
                ->where('id', $driverStaffId)
                ->where('role_type', 'DRIVER')
                ->first()
            : null;

        $payload = [
            'plate_no' => trim($data['plate_no']),
            'driver_name' => $driver ? $this->staffName($driver) : trim((string) ($data['driver_name'] ?? '')),
            'driver_phone' => $driver ? trim((string) ($driver->phone ?? '')) : trim((string) ($data['driver_phone'] ?? '')),
            'max_load' => ((float) ($data['max_load_ton'] ?? 0)) * 1000,
            'car_length' => $data['car_length'] ?? null,
            'remark' => trim((string) ($data['remark'] ?? '')),
            'status' => $data['status'] ?? 'ACTIVE',
        ];

        $truckId = $this->saveRow('delivery_plan_truck_master_dev', $payload, $id);
        $this->syncTruckDriverMap($truckId, $driverStaffId);

        return back()->with('success', 'บันทึกข้อมูลรถแล้ว');
    }

    public function deleteTruck(int $id)
    {
        $this->softDelete('delivery_plan_truck_master_dev', $id, ['status' => 'INACTIVE']);

        return back()->with('success', 'ปิดการใช้งานรถแล้ว');
    }

    public function drivers()
    {
        return $this->staffPage('DRIVER', 'พนักงานขับรถ', 'formdp.masters.staff');
    }

    public function helpers()
    {
        return $this->staffPage('HELPER', 'เด็กรถ', 'formdp.masters.staff');
    }

    public function saveDriver(Request $request, ?int $id = null)
    {
        return $this->saveStaff($request, 'DRIVER', $id);
    }

    public function saveHelper(Request $request, ?int $id = null)
    {
        return $this->saveStaff($request, 'HELPER', $id);
    }

    public function deleteDriver(int $id)
    {
        return $this->deleteStaff($id);
    }

    public function deleteHelper(int $id)
    {
        return $this->deleteStaff($id);
    }

    private function staffPage(string $roleType, string $title, string $view)
    {
        $rows = $this->conn()
            ->table('delivery_plan_truck_staff_master_dev')
            ->where('role_type', $roleType)
            ->orderBy('id')
            ->get();

        return view($view, compact('rows', 'roleType', 'title'));
    }

    private function saveStaff(Request $request, string $roleType, ?int $id = null)
    {
        $data = $request->validate([
            'prefix_name' => ['nullable', 'string', 'max:50'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'position_name' => ['nullable', 'string', 'max:100'],
            'department_name' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'in:0,1'],
        ]);

        $payload = [
            'role_type' => $roleType,
            'prefix_name' => trim((string) ($data['prefix_name'] ?? '')),
            'first_name' => trim($data['first_name']),
            'last_name' => trim((string) ($data['last_name'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'position_name' => trim((string) ($data['position_name'] ?? '')),
            'department_name' => trim((string) ($data['department_name'] ?? '')),
            'is_active' => (int) ($data['is_active'] ?? 1),
        ];

        $this->saveRow('delivery_plan_truck_staff_master_dev', $payload, $id);

        return back()->with('success', 'บันทึกข้อมูล ' . ($roleType === 'DRIVER' ? 'พนักงานขับรถ' : 'เด็กรถ') . ' แล้ว');
    }

    private function deleteStaff(int $id)
    {
        $this->softDelete('delivery_plan_truck_staff_master_dev', $id, ['is_active' => 0]);

        return back()->with('success', 'ปิดการใช้งานแล้ว');
    }

    private function driverOptions()
    {
        return $this->conn()
            ->table('delivery_plan_truck_staff_master_dev')
            ->where('role_type', 'DRIVER')
            ->where('is_active', 1)
            ->orderBy('id')
            ->get()
            ->map(function ($r) {
                return (object) [
                    'id' => (int) $r->id,
                    'name' => $this->staffName($r),
                    'phone' => trim((string) ($r->phone ?? '')),
                ];
            });
    }

    private function truckDriverMap(): array
    {
        return $this->conn()
            ->table('delivery_plan_truck_staff_map_dev')
            ->where('assign_role', 'DRIVER')
            ->where('is_active', 1)
            ->pluck('staff_id', 'truck_id')
            ->mapWithKeys(fn($staffId, $truckId) => [(int) $truckId => (int) $staffId])
            ->all();
    }

    private function syncTruckDriverMap(int $truckId, int $driverStaffId): void
    {
        if ($truckId <= 0) {
            return;
        }

        $inactivePayload = $this->onlyExistingColumns('delivery_plan_truck_staff_map_dev', ['is_active' => 0]);
        if (!empty($inactivePayload)) {
            $this->conn()
                ->table('delivery_plan_truck_staff_map_dev')
                ->where('truck_id', $truckId)
                ->where('assign_role', 'DRIVER')
                ->update($inactivePayload);
        }

        if ($driverStaffId <= 0) {
            return;
        }

        $payload = $this->onlyExistingColumns('delivery_plan_truck_staff_map_dev', [
            'truck_id' => $truckId,
            'staff_id' => $driverStaffId,
            'assign_role' => 'DRIVER',
            'seq_no' => 1,
            'is_active' => 1,
        ]);

        if (!empty($payload)) {
            $this->conn()->table('delivery_plan_truck_staff_map_dev')->insert($payload);
        }
    }

    private function staffName(object $r): string
    {
        return trim(collect([
            $r->prefix_name ?? null,
            $r->first_name ?? null,
            $r->last_name ?? null,
        ])->filter(fn($x) => trim((string) $x) !== '')->implode(' '));
    }

    private function saveRow(string $table, array $payload, ?int $id = null): int
    {
        $payload = $this->onlyExistingColumns($table, $payload);

        if ($id) {
            $this->conn()->table($table)->where('id', $id)->update($payload);
            return $id;
        }

        return (int) $this->conn()->table($table)->insertGetId($payload);
    }

    private function softDelete(string $table, int $id, array $payload): void
    {
        $payload = $this->onlyExistingColumns($table, $payload);

        if (!empty($payload)) {
            $this->conn()->table($table)->where('id', $id)->update($payload);
        }
    }

    private function onlyExistingColumns(string $table, array $payload): array
    {
        $columns = $this->conn()
            ->table('sys.columns as c')
            ->join('sys.objects as o', 'o.object_id', '=', 'c.object_id')
            ->join('sys.schemas as s', 's.schema_id', '=', 'o.schema_id')
            ->where('s.name', 'dbo')
            ->where('o.name', $table)
            ->pluck('c.name')
            ->map(fn($name) => strtolower((string) $name))
            ->all();

        $columnSet = array_flip($columns);

        return collect($payload)
            ->filter(fn($value, $key) => isset($columnSet[strtolower((string) $key)]))
            ->all();
    }
}
