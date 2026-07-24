<?php

namespace App\Services\Admin;

use App\Models\Users\User;
use App\Services\OrgCore;
use App\Support\SqlServerDb;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use RuntimeException;

class BulkUserImportService
{
    private const REQUIRED_COLUMNS = [
        'name',
        'user_code',
        'department',
        'position',
    ];

    private const HEADER_ALIASES = [
        'name' => ['name', 'fullname', 'ชื่อ', 'ชื่อสกุล', 'ชื่อ-นามสกุล'],
        'user_code' => ['usercode', 'employeecode', 'empcode', 'รหัสพนักงาน', 'รหัส'],
        'department' => ['department', 'departmentcode', 'dept', 'แผนก', 'ฝ่าย'],
        'position' => ['position', 'positioncode', 'departmentrole', 'role', 'ตำแหน่ง'],
        'email' => ['email', 'อีเมล'],
        'password' => ['password', 'รหัสผ่าน'],
    ];

    /**
     * @return array{total:int, imported:int, dry_run:bool, rows:array<int, array<string, mixed>>}
     */
    public function import(
        string $path,
        string $defaultPassword,
        string $emailDomain,
        bool $dryRun = false,
        ?string $delimiter = null
    ): array {
        $rows = $this->readFile($path, $delimiter);
        $prepared = $this->prepareRows($rows, $defaultPassword, $emailDomain);

        if ($dryRun) {
            return [
                'total' => count($prepared),
                'imported' => 0,
                'dry_run' => true,
                'rows' => $prepared,
            ];
        }

        $createdUsers = SqlServerDb::transaction(function () use ($prepared) {
            $created = [];

            foreach ($prepared as $row) {
                $attributes = [
                    'user_code' => $row['user_code'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'phone' => null,
                    'password' => Hash::make($row['password']),
                    'department_id' => $row['department_id'],
                    'is_active' => 1,
                ];

                // Compatible with installations that already have username login enabled.
                if (method_exists(User::class, 'uniqueUsernameForName')) {
                    $attributes['username'] = User::uniqueUsernameForName($row['name']);
                }

                $user = User::create($attributes);

                SqlServerDb::table('department_role_users')->insert([
                    'user_id' => $user->id,
                    'department_role_id' => $row['position_id'],
                    'is_primary' => 1,
                    'start_date' => now()->toDateString(),
                    'end_date' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $created[] = $user;
            }

            // Run this after every position has been inserted so supervisors in the
            // same file can also be discovered regardless of spreadsheet row order.
            foreach ($created as $user) {
                OrgCore::ensureUserSupervisor($user);
            }

            return $created;
        });

        return [
            'total' => count($prepared),
            'imported' => count($createdUsers),
            'dry_run' => false,
            'rows' => $prepared,
        ];
    }

    /**
     * Convert the first spreadsheet row into canonical field names.
     *
     * @param  array<int, array<int, mixed>>  $sheetRows
     * @return array<int, array<string, string>>
     */
    public function normalizeSpreadsheetRows(array $sheetRows): array
    {
        if ($sheetRows === []) {
            throw new RuntimeException('The import file is empty.');
        }

        $headerRow = array_shift($sheetRows);
        $headerMap = [];

        foreach ($headerRow as $index => $header) {
            $canonical = $this->canonicalHeader((string) $header);
            if ($canonical !== null && !in_array($canonical, $headerMap, true)) {
                $headerMap[$index] = $canonical;
            }
        }

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, array_values($headerMap)));
        if ($missing !== []) {
            throw new RuntimeException(
                'Missing required columns: ' . implode(', ', $missing)
            );
        }

        $result = [];
        foreach ($sheetRows as $offset => $cells) {
            $row = ['_row' => (string) ($offset + 2)];
            foreach ($headerMap as $index => $field) {
                $row[$field] = $this->cellToString($cells[$index] ?? '');
            }

            $hasValue = collect(self::REQUIRED_COLUMNS)
                ->contains(fn ($field) => ($row[$field] ?? '') !== '');

            if ($hasValue) {
                $result[] = $row;
            }
        }

        if ($result === []) {
            throw new RuntimeException('The import file has no data rows.');
        }

        return $result;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readFile(string $path, ?string $delimiter): array
    {
        $resolvedPath = realpath($path);
        if ($resolvedPath === false || !is_file($resolvedPath)) {
            throw new RuntimeException("Import file not found: {$path}");
        }

        $extension = strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx', 'xls'], true)) {
            throw new RuntimeException('Supported file types are .csv, .xlsx and .xls.');
        }

        $reader = IOFactory::createReaderForFile($resolvedPath);
        $reader->setReadDataOnly(true);

        if ($reader instanceof Csv) {
            $reader->setInputEncoding('UTF-8');
            $reader->setDelimiter($delimiter ?: $this->detectDelimiter($resolvedPath));
            $reader->setEnclosure('"');
        }

        $sheetRows = $reader->load($resolvedPath)
            ->getActiveSheet()
            ->toArray(null, true, true, false);

        return $this->normalizeSpreadsheetRows($sheetRows);
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function prepareRows(array $rows, string $defaultPassword, string $emailDomain): array
    {
        $emailDomain = strtolower(trim($emailDomain));
        if (!filter_var("user@{$emailDomain}", FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Invalid email domain: {$emailDomain}");
        }

        if ($defaultPassword !== '' && mb_strlen($defaultPassword) < 6) {
            throw new RuntimeException('The default password must contain at least 6 characters.');
        }

        $departments = SqlServerDb::table('departments')
            ->where('is_active', 1)
            ->get(['id', 'code', 'name']);
        $positions = SqlServerDb::table('department_roles')
            ->where('is_active', 1)
            ->get(['id', 'department_id', 'code', 'name']);

        $departmentLookup = $this->buildLookup($departments, ['code', 'name']);
        $positionLookups = [];
        foreach ($positions->groupBy('department_id') as $departmentId => $departmentPositions) {
            $positionLookups[(int) $departmentId] = $this->buildLookup(
                $departmentPositions,
                ['code', 'name']
            );
        }

        $errors = [];
        $prepared = [];
        $seenCodes = [];
        $seenEmails = [];

        foreach ($rows as $row) {
            $rowNumber = (int) $row['_row'];
            $name = trim($row['name'] ?? '');
            $userCode = trim($row['user_code'] ?? '');
            $departmentText = trim($row['department'] ?? '');
            $positionText = trim($row['position'] ?? '');

            foreach ([
                'name' => $name,
                'user_code' => $userCode,
                'department' => $departmentText,
                'position' => $positionText,
            ] as $field => $value) {
                if ($value === '') {
                    $errors[] = "Row {$rowNumber}: {$field} is required.";
                }
            }

            if ($name === '' || $userCode === '' || $departmentText === '' || $positionText === '') {
                continue;
            }

            $codeKey = $this->lookupKey($userCode);
            if (isset($seenCodes[$codeKey])) {
                $errors[] = "Row {$rowNumber}: duplicate employee code {$userCode} in the file.";
            }
            $seenCodes[$codeKey] = true;

            $department = $this->resolveLookup(
                $departmentLookup,
                $departmentText,
                "Row {$rowNumber}: department"
            );
            if (is_string($department)) {
                $errors[] = $department;
                continue;
            }

            $position = $this->resolveLookup(
                $positionLookups[(int) $department->id] ?? [],
                $positionText,
                "Row {$rowNumber}: position"
            );
            if (is_string($position)) {
                $errors[] = $position . " in department {$departmentText}.";
                continue;
            }

            $email = strtolower(trim($row['email'] ?? ''));
            if ($email === '') {
                $email = $this->emailFromUserCode($userCode, $emailDomain);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Row {$rowNumber}: invalid email {$email}.";
            }

            $emailKey = $this->lookupKey($email);
            if (isset($seenEmails[$emailKey])) {
                $errors[] = "Row {$rowNumber}: duplicate email {$email} in the file.";
            }
            $seenEmails[$emailKey] = true;

            $password = (string) ($row['password'] ?? '');
            if ($password === '') {
                $password = $defaultPassword;
            }
            if (mb_strlen($password) < 6) {
                $errors[] = "Row {$rowNumber}: password is required and must contain at least 6 characters.";
            }

            $prepared[] = [
                'row' => $rowNumber,
                'user_code' => $userCode,
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'department_id' => (int) $department->id,
                'department' => "{$department->name} ({$department->code})",
                'position_id' => (int) $position->id,
                'position' => "{$position->name} ({$position->code})",
            ];
        }

        $existingCodes = $this->existingValues('user_code', array_column($prepared, 'user_code'));
        $existingEmails = $this->existingValues('email', array_column($prepared, 'email'));

        foreach ($prepared as $row) {
            if (isset($existingCodes[$this->lookupKey($row['user_code'])])) {
                $errors[] = "Row {$row['row']}: employee code {$row['user_code']} already exists.";
            }
            if (isset($existingEmails[$this->lookupKey($row['email'])])) {
                $errors[] = "Row {$row['row']}: email {$row['email']} already exists.";
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(
                "Import validation failed; no users were created:\n- " . implode("\n- ", array_unique($errors))
            );
        }

        return $prepared;
    }

    /**
     * @param  iterable<int, object>  $records
     * @param  array<int, string>  $fields
     * @return array<string, array<int, object>>
     */
    private function buildLookup(iterable $records, array $fields): array
    {
        $lookup = [];
        foreach ($records as $record) {
            foreach ($fields as $field) {
                $key = $this->lookupKey((string) ($record->{$field} ?? ''));
                if ($key !== '') {
                    $lookup[$key][(int) $record->id] = $record;
                }
            }
        }

        return $lookup;
    }

    /**
     * @param  array<string, array<int, object>>  $lookup
     * @return object|string
     */
    private function resolveLookup(array $lookup, string $value, string $label)
    {
        $matches = array_values($lookup[$this->lookupKey($value)] ?? []);
        if (count($matches) === 1) {
            return $matches[0];
        }
        if ($matches === []) {
            return "{$label} \"{$value}\" was not found or is inactive";
        }

        return "{$label} \"{$value}\" is ambiguous; use its code instead";
    }

    /**
     * @param  array<int, string>  $values
     * @return array<string, bool>
     */
    private function existingValues(string $column, array $values): array
    {
        $existing = [];
        foreach (array_chunk(array_values(array_unique($values)), 500) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            foreach (SqlServerDb::table('users')->whereIn($column, $chunk)->pluck($column) as $value) {
                $existing[$this->lookupKey((string) $value)] = true;
            }
        }

        return $existing;
    }

    private function canonicalHeader(string $header): ?string
    {
        $normalized = $this->normalizeHeader($header);
        foreach (self::HEADER_ALIASES as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if ($normalized === $this->normalizeHeader($alias)) {
                    return $canonical;
                }
            }
        }

        return null;
    }

    private function normalizeHeader(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', trim($value)) ?? '';
        $value = mb_strtolower($value);

        return preg_replace('/[\s_\-]+/u', '', $value) ?? '';
    }

    private function lookupKey(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return mb_strtolower($value);
    }

    private function cellToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function emailFromUserCode(string $userCode, string $domain): string
    {
        $local = Str::lower($userCode);
        $local = preg_replace('/[^a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+/i', '.', $local) ?? '';
        $local = trim($local, '.');

        return "{$local}@{$domain}";
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        $firstLine = $handle ? (string) fgets($handle) : '';
        if (is_resource($handle)) {
            fclose($handle);
        }

        $counts = [
            ',' => substr_count($firstLine, ','),
            ';' => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
        ];
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
