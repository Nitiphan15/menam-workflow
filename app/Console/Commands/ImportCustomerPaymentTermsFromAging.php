<?php

namespace App\Console\Commands;

use App\Models\FormAccounting\BillingPlan;
use App\Models\FormAccounting\CustomerPaymentTerm;
use App\Models\FormAccounting\CustomerPaymentTermHistory;
use App\Models\FormAccounting\PaymentSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportCustomerPaymentTermsFromAging extends Command
{
    protected $signature = 'accounting:import-payment-terms
        {file : Path to Aging Excel file}
        {--sheet= : Sheet name, default first sheet}
        {--source=auto : pgsqlw, pgsqlp, or auto}
        {--start-row=8 : First data row}
        {--dry-run : Preview without writing}';

    protected $description = 'Import customer payment terms from Aging Excel and lookup customer name by ERP customernumber.';

    private const ERP_SOURCES = ['pgsqlw', 'pgsqlp'];

    public function handle(): int
    {
        $file = (string) $this->argument('file');

        if (!is_file($file)) {
            $this->error('File not found: ' . $file);
            return self::FAILURE;
        }

        $sheet = $this->loadSheet($file);
        $customers = $this->extractCustomers($sheet, (int) $this->option('start-row'));

        if ($customers === []) {
            $this->warn('No customer rows found.');
            return self::SUCCESS;
        }

        $rows = $this->buildImportRows($customers);
        $this->line('Found customers: ' . count($rows));

        $this->table(
            ['Source', 'Code', 'Name', 'Days', 'Detail', 'Billing', 'Payment'],
            array_slice(array_map(fn($row) => [
                $row['erp_source'],
                $row['customer_code'],
                mb_strimwidth($row['customer_name'], 0, 36, '...'),
                $row['credit_days'],
                mb_strimwidth((string) $row['credit_term_detail'], 0, 22, '...'),
                mb_strimwidth((string) $row['billing_plan_name'], 0, 28, '...'),
                mb_strimwidth((string) $row['payment_schedule_name'], 0, 28, '...'),
            ], $rows), 0, 15)
        );

        if ($this->option('dry-run')) {
            $this->info('Dry run only. Nothing was written.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $billingPlan = $row['billing_plan_name'] !== ''
                    ? $this->firstOrCreateBillingPlan($row['billing_plan_name'])
                    : null;
                $paymentSchedule = $row['payment_schedule_name'] !== ''
                    ? $this->firstOrCreatePaymentSchedule($row['payment_schedule_name'])
                    : null;

                $payload = [
                    'erp_source' => $row['erp_source'],
                    'customer_code' => $row['customer_code'],
                    'customer_name' => $row['customer_name'],
                    'erp_terms' => $row['erp_terms'],
                    'credit_days' => $row['credit_days'],
                    'credit_term_code' => $row['credit_term_code'],
                    'credit_term_detail' => $row['credit_term_detail'],
                    'billing_plan_id' => $billingPlan?->id,
                    'payment_schedule_id' => $paymentSchedule?->id,
                    'override_payment_day' => $row['override_payment_day'],
                    'remark' => $row['remark'],
                    'is_active' => true,
                ];

                $term = CustomerPaymentTerm::where('erp_source', $row['erp_source'])
                    ->where('customer_code', $row['customer_code'])
                    ->first();

                if ($term) {
                    $before = $term->getAttributes();
                    $term->update($payload);
                    $this->writeHistory($term, 'import_update', $before, $term->fresh()->getAttributes());
                } else {
                    $term = CustomerPaymentTerm::create($payload);
                    $this->writeHistory($term, 'import_create', null, $term->getAttributes());
                }
            }
        });

        $this->info('Imported customer payment terms: ' . count($rows));

        return self::SUCCESS;
    }

    private function loadSheet(string $file)
    {
        $spreadsheet = IOFactory::load($file);
        $sheetName = $this->option('sheet');

        if ($sheetName) {
            $sheet = $spreadsheet->getSheetByName((string) $sheetName);
            if (!$sheet) {
                throw new \RuntimeException('Sheet not found: ' . $sheetName);
            }

            return $sheet;
        }

        return $spreadsheet->getSheet(0);
    }

    private function extractCustomers($sheet, int $startRow): array
    {
        $customers = [];
        $currentCode = null;

        for ($row = $startRow; $row <= $sheet->getHighestRow(); $row++) {
            $columnA = $this->clean($sheet->getCell('A' . $row)->getFormattedValue());

            if (preg_match('/^(D[A-Z0-9]{2}-[0-9A-Z]+)\b/u', $columnA, $matches)) {
                $currentCode = strtoupper($matches[1]);
                $customers[$currentCode] ??= [
                    'code' => $currentCode,
                    'excel_name' => trim(mb_substr($columnA, mb_strlen($matches[0]))),
                    'credit_days' => [],
                    'credit_details' => [],
                    'billing_plans' => [],
                    'payment_schedules' => [],
                ];
                continue;
            }

            if (!$currentCode || str_starts_with($columnA, 'รวม')) {
                continue;
            }

            $creditDays = $this->clean($sheet->getCell('N' . $row)->getFormattedValue());
            $creditDetail = $this->clean($sheet->getCell('O' . $row)->getFormattedValue());
            $billingPlan = $this->clean($sheet->getCell('R' . $row)->getFormattedValue());
            $paymentSchedule = $this->clean($sheet->getCell('S' . $row)->getFormattedValue());

            if ($creditDays !== '') {
                $customers[$currentCode]['credit_days'][] = $creditDays;
            }
            if ($creditDetail !== '') {
                $customers[$currentCode]['credit_details'][] = $creditDetail;
            }
            if ($billingPlan !== '') {
                $customers[$currentCode]['billing_plans'][] = $billingPlan;
            }
            if ($paymentSchedule !== '') {
                $customers[$currentCode]['payment_schedules'][] = $paymentSchedule;
            }
        }

        return $customers;
    }

    private function buildImportRows(array $customers): array
    {
        $rows = [];

        foreach ($customers as $customer) {
            $code = $customer['code'];
            $erp = $this->lookupCustomer($code);
            $creditDetail = $this->mostFrequent($customer['credit_details']);
            $billingPlan = $this->mostFrequent($customer['billing_plans']);
            $paymentSchedule = $this->mostFrequent($customer['payment_schedules']);
            $creditDays = (int) preg_replace('/\D+/', '', $this->mostFrequent($customer['credit_days']) ?: '0');

            $rows[] = [
                'erp_source' => $erp['source'],
                'customer_code' => $code,
                'customer_name' => $erp['name'] ?: ($customer['excel_name'] ?: $code),
                'erp_terms' => $erp['terms'],
                'credit_days' => $creditDays,
                'credit_term_code' => mb_strlen($creditDetail) <= 20 ? $creditDetail : null,
                'credit_term_detail' => $creditDetail ?: null,
                'billing_plan_name' => $billingPlan,
                'payment_schedule_name' => $paymentSchedule,
                'override_payment_day' => $this->extractPaymentDay($paymentSchedule),
                'remark' => $this->buildRemark($customer),
            ];
        }

        return $rows;
    }

    private function lookupCustomer(string $code): array
    {
        $sourceOption = (string) $this->option('source');
        $sources = $sourceOption === 'auto' ? self::ERP_SOURCES : [$sourceOption];

        foreach ($sources as $source) {
            if (!in_array($source, self::ERP_SOURCES, true)) {
                continue;
            }

            $row = DB::connection($source)
                ->table('customer')
                ->select('customernumber', 'name', 'terms')
                ->whereRaw('UPPER(TRIM(customernumber)) = ?', [strtoupper($code)])
                ->first();

            if ($row) {
                return [
                    'source' => $source,
                    'name' => trim((string) $row->name),
                    'terms' => trim((string) ($row->terms ?? '')),
                ];
            }
        }

        return [
            'source' => in_array($sourceOption, self::ERP_SOURCES, true) ? $sourceOption : 'pgsqlw',
            'name' => '',
            'terms' => '',
        ];
    }

    private function firstOrCreateBillingPlan(string $name): BillingPlan
    {
        $existing = BillingPlan::where('name_th', $name)->first();
        if ($existing) {
            return $existing;
        }

        [$from, $to] = $this->extractBillingDays($name);

        return BillingPlan::create([
            'code' => $this->makeCode('BILL', $name),
            'name_th' => $name,
            'billing_day_from' => $from,
            'billing_day_to' => $to,
            'is_cash' => str_contains($name, 'เงินสด'),
            'is_active' => true,
        ]);
    }

    private function firstOrCreatePaymentSchedule(string $name): PaymentSchedule
    {
        $existing = PaymentSchedule::where('name_th', $name)->first();
        if ($existing) {
            return $existing;
        }

        return PaymentSchedule::create([
            'code' => $this->makeCode('PAY', $name),
            'name_th' => $name,
            'schedule_type' => $this->paymentScheduleType($name),
            'payment_day' => $this->extractPaymentDay($name),
            'is_active' => true,
        ]);
    }

    private function paymentScheduleType(string $name): string
    {
        if (str_contains($name, 'สิ้นเดือน')) {
            return 'end_of_month';
        }

        if (str_contains($name, 'พร้อมส่ง') || str_contains($name, 'ทันที')) {
            return 'immediate';
        }

        if ($this->extractPaymentDay($name) !== null) {
            return 'fixed_day';
        }

        return 'custom';
    }

    private function extractPaymentDay(string $name): ?int
    {
        if (preg_match('/วันที่\s*([0-9]{1,2})/u', $name, $matches)) {
            $day = (int) $matches[1];
            return $day >= 1 && $day <= 31 ? $day : null;
        }

        return null;
    }

    private function extractBillingDays(string $name): array
    {
        if (preg_match('/([0-9]{1,2})\s*-\s*([0-9]{1,2})/u', $name, $matches)) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        if (preg_match('/วันที่\s*([0-9]{1,2})/u', $name, $matches)) {
            $day = (int) $matches[1];
            return [$day, $day];
        }

        return [null, null];
    }

    private function mostFrequent(array $values): string
    {
        $values = array_values(array_filter(array_map(fn($value) => $this->clean($value), $values), fn($value) => $value !== ''));
        if ($values === []) {
            return '';
        }

        $counts = array_count_values($values);
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function buildRemark(array $customer): ?string
    {
        $parts = [];

        foreach ([
            'credit_details' => 'credit detail',
            'billing_plans' => 'billing',
            'payment_schedules' => 'payment',
        ] as $key => $label) {
            $unique = array_values(array_unique(array_filter($customer[$key])));
            if (count($unique) > 1) {
                $parts[] = 'Excel has multiple ' . $label . ' values: ' . implode(' | ', $unique);
            }
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    private function writeHistory(CustomerPaymentTerm $term, string $action, ?array $before, ?array $after): void
    {
        CustomerPaymentTermHistory::create([
            'customer_payment_term_id' => $term->id,
            'before_data' => $before,
            'after_data' => $after,
            'action' => $action,
            'changed_by' => Auth::id(),
            'changed_at' => now(),
        ]);
    }

    private function makeCode(string $prefix, string $name): string
    {
        return $prefix . '_' . strtoupper(substr(md5($name), 0, 12));
    }

    private function clean($value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }
}
