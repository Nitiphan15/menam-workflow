<?php

namespace Tests\Unit;

use App\Services\Admin\BulkUserImportService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BulkUserImportServiceTest extends TestCase
{
    public function test_it_accepts_thai_headers_and_ignores_blank_rows(): void
    {
        $service = new BulkUserImportService();

        $rows = $service->normalizeSpreadsheetRows([
            ['ชื่อ', 'รหัสพนักงาน', 'แผนก', 'ตำแหน่ง'],
            ['สมชาย ใจดี', 'EMP001', 'IT', 'Staff'],
            ['', '', '', ''],
        ]);

        $this->assertSame([
            [
                '_row' => '2',
                'name' => 'สมชาย ใจดี',
                'user_code' => 'EMP001',
                'department' => 'IT',
                'position' => 'Staff',
            ],
        ], $rows);
    }

    public function test_it_accepts_english_header_aliases_and_optional_credentials(): void
    {
        $service = new BulkUserImportService();

        $rows = $service->normalizeSpreadsheetRows([
            ['Full Name', 'Employee_Code', 'Dept', 'Role', 'Email', 'Password'],
            ['Jane Doe', 'EMP002', 'HR', 'Manager', 'jane@example.com', 'secret1'],
        ]);

        $this->assertSame('Jane Doe', $rows[0]['name']);
        $this->assertSame('EMP002', $rows[0]['user_code']);
        $this->assertSame('jane@example.com', $rows[0]['email']);
        $this->assertSame('secret1', $rows[0]['password']);
    }

    public function test_it_rejects_a_file_without_all_required_columns(): void
    {
        $service = new BulkUserImportService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required columns: position');

        $service->normalizeSpreadsheetRows([
            ['ชื่อ', 'รหัสพนักงาน', 'แผนก'],
            ['สมชาย ใจดี', 'EMP001', 'IT'],
        ]);
    }
}
