<?php

namespace App\Http\Controllers\FormAccounting;

use App\Http\Controllers\Controller;
use App\Models\FormAccounting\BillingPlan;
use App\Models\FormAccounting\CustomerPaymentTerm;
use App\Models\FormAccounting\CustomerPaymentTermHistory;
use App\Models\FormAccounting\PaymentSchedule;
use App\Services\FormAccounting\ErpCustomerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerPaymentTermController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status', 'active');

        $terms = CustomerPaymentTerm::with(['billingPlan', 'paymentSchedule'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($sub) use ($search) {
                    $sub->where('customer_name', 'like', '%' . $search . '%')
                        ->orWhere('customer_code', 'like', '%' . $search . '%')
                        ->orWhere('erp_terms', 'like', '%' . $search . '%');
                });
            })
            ->when($status === 'active', fn($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn($query) => $query->where('is_active', false))
            ->orderBy('customer_name')
            ->paginate(30)
            ->withQueryString();

        return view('formaccounting.customer_payment_terms.index', [
            'terms' => $terms,
            'billingPlans' => BillingPlan::where('is_active', true)->orderBy('name_th')->get(),
            'paymentSchedules' => PaymentSchedule::where('is_active', true)->orderBy('name_th')->get(),
            'filters' => ['q' => $search, 'status' => $status],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);

        DB::transaction(function () use ($data) {
            $term = CustomerPaymentTerm::create($data);
            $this->writeHistory($term, 'create', null, $term->getAttributes());
        });

        return back()->with('success', 'บันทึกเงื่อนไขรับชำระลูกค้าเรียบร้อย');
    }

    public function update(Request $request, CustomerPaymentTerm $customerPaymentTerm)
    {
        $data = $this->validatedData($request, $customerPaymentTerm);

        DB::transaction(function () use ($customerPaymentTerm, $data) {
            $before = $customerPaymentTerm->getAttributes();
            $customerPaymentTerm->update($data);
            $this->writeHistory($customerPaymentTerm, 'update', $before, $customerPaymentTerm->fresh()->getAttributes());
        });

        return back()->with('success', 'แก้ไขเงื่อนไขรับชำระลูกค้าเรียบร้อย');
    }

    public function destroy(CustomerPaymentTerm $customerPaymentTerm)
    {
        DB::transaction(function () use ($customerPaymentTerm) {
            $before = $customerPaymentTerm->getAttributes();
            $customerPaymentTerm->update(['is_active' => false]);
            $this->writeHistory($customerPaymentTerm, 'disable', $before, $customerPaymentTerm->fresh()->getAttributes());
        });

        return back()->with('success', 'ปิดการใช้งานเงื่อนไขรับชำระลูกค้าเรียบร้อย');
    }

    public function customerLookup(Request $request, ErpCustomerService $service)
    {
        return response()->json([
            'results' => $service->lookup($request->query('q'), 20),
        ]);
    }

    public function masters(Request $request)
    {
        return view('formaccounting.customer_payment_terms.masters', [
            'billingPlans' => BillingPlan::orderByDesc('is_active')->orderBy('name_th')->get(),
            'paymentSchedules' => PaymentSchedule::orderByDesc('is_active')->orderBy('name_th')->get(),
        ]);
    }

    public function storeBillingPlan(Request $request)
    {
        BillingPlan::create($this->validatedBillingPlan($request));

        return back()->with('success', 'บันทึกเงื่อนไขแผนรับชำระเรียบร้อย');
    }

    public function updateBillingPlan(Request $request, BillingPlan $billingPlan)
    {
        $billingPlan->update($this->validatedBillingPlan($request, $billingPlan));

        return back()->with('success', 'แก้ไขเงื่อนไขแผนรับชำระเรียบร้อย');
    }

    public function destroyBillingPlan(BillingPlan $billingPlan)
    {
        $billingPlan->update(['is_active' => false]);

        return back()->with('success', 'ปิดการใช้งานเงื่อนไขแผนรับชำระเรียบร้อย');
    }

    public function storePaymentSchedule(Request $request)
    {
        PaymentSchedule::create($this->validatedPaymentSchedule($request));

        return back()->with('success', 'บันทึกเงื่อนไขการจ่ายชำระเรียบร้อย');
    }

    public function updatePaymentSchedule(Request $request, PaymentSchedule $paymentSchedule)
    {
        $paymentSchedule->update($this->validatedPaymentSchedule($request, $paymentSchedule));

        return back()->with('success', 'แก้ไขเงื่อนไขการจ่ายชำระเรียบร้อย');
    }

    public function destroyPaymentSchedule(PaymentSchedule $paymentSchedule)
    {
        $paymentSchedule->update(['is_active' => false]);

        return back()->with('success', 'ปิดการใช้งานเงื่อนไขการจ่ายชำระเรียบร้อย');
    }

    private function validatedData(Request $request, ?CustomerPaymentTerm $term = null): array
    {
        $source = trim((string) $request->input('erp_source'));
        $customerCode = trim((string) $request->input('customer_code'));
        $uniqueCustomer = Rule::unique('customer_payment_terms', 'customer_code')
            ->where(fn($query) => $query->where('erp_source', $source));

        if ($term) {
            $uniqueCustomer->ignore($term->id);
        }

        $data = $request->validate([
            'erp_source' => ['required', Rule::in(['pgsqlw', 'pgsqlp'])],
            'customer_code' => [
                'required',
                'string',
                'max:255',
                $uniqueCustomer,
            ],
            'customer_name' => ['required', 'string', 'max:255'],
            'erp_terms' => ['nullable', 'string', 'max:100'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'credit_days' => ['required', 'integer', 'min:0', 'max:999'],
            'grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'credit_term_code' => ['nullable', 'string', 'max:20'],
            'credit_term_detail' => ['nullable', 'string', 'max:500'],
            'billing_plan_id' => ['nullable', 'integer', Rule::exists('mst_billing_plans', 'id')],
            'payment_schedule_id' => ['nullable', 'integer', Rule::exists('mst_payment_schedules', 'id')],
            'override_payment_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'remark' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['erp_source'] = $source;
        $data['customer_code'] = $customerCode;
        $data['grace_days'] = (int) ($data['grace_days'] ?? 0);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function validatedBillingPlan(Request $request, ?BillingPlan $billingPlan = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('mst_billing_plans', 'code')->ignore($billingPlan?->id)],
            'name_th' => ['required', 'string', 'max:200'],
            'name_en' => ['nullable', 'string', 'max:200'],
            'billing_day_from' => ['nullable', 'integer', 'min:1', 'max:31'],
            'billing_day_to' => ['nullable', 'integer', 'min:1', 'max:31'],
            'is_cash' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['code'] = strtoupper(trim($data['code']));
        $data['is_cash'] = $request->boolean('is_cash');
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function validatedPaymentSchedule(Request $request, ?PaymentSchedule $paymentSchedule = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('mst_payment_schedules', 'code')->ignore($paymentSchedule?->id)],
            'name_th' => ['required', 'string', 'max:200'],
            'schedule_type' => ['required', Rule::in(['end_of_month', 'fixed_day', 'immediate', 'custom'])],
            'payment_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['code'] = strtoupper(trim($data['code']));
        $data['is_active'] = $request->boolean('is_active', true);
        if ($data['schedule_type'] !== 'fixed_day') {
            $data['payment_day'] = null;
        }

        return $data;
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
}
