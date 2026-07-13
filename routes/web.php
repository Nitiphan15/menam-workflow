<?php

use Illuminate\Support\Facades\Route;
//PR
use App\Http\Controllers\FormPR\RequestController;
use App\Http\Controllers\FormPR\WorkflowReviewController;
use App\Http\Controllers\FormPR\MyActionController;
//PP
use App\Http\Controllers\FormPP\ProductionPlanController;
use App\Http\Controllers\FormPP\PlannerController;
use App\Http\Controllers\FormPP\ReviseController;
use App\Http\Controllers\FormPP\ViewController;
use App\Http\Controllers\FormPP\PpDocboxController;
//PA
use App\Http\Controllers\FormPA\PaMasterController;
use App\Http\Controllers\FormPA\PaEvaluationController;
use App\Http\Controllers\FormPA\PeriodController;
//WOCR
use App\Http\Controllers\FormWOCR\OriginatorController;
use App\Http\Controllers\FormWOCR\PlannerWOCRController;
use App\Http\Controllers\FormWOCR\MngPlannerController;
use App\Http\Controllers\FormWOCR\WocrDocboxController;
use App\Http\Controllers\FormWOCR\WocrViewController;
use App\Http\Controllers\FormWOCR\WocrReviseController;
use App\Models\FormWOCR\WocrDataFile;
//Login
use App\Http\Controllers\AuthController;
//Admin
use App\Http\Controllers\Admin\UserAdminController;
use App\Http\Controllers\Admin\DepartmentAdminController;
use App\Http\Controllers\Admin\RoleAdminController;
use App\Http\Controllers\Admin\DeptManagerAdminController;
use App\Http\Controllers\Admin\DepartmentRoleAdminController;
use App\Http\Controllers\Admin\UserDeptRoleAdminController;
use App\Http\Controllers\Admin\UserDepartmentAssignmentController;
use App\Http\Controllers\Admin\UserPermissionController;
use App\Http\Controllers\Admin\ActivityLogController;
//WR
use App\Http\Controllers\FormWR\IncomeController;
//Profile
use App\Http\Controllers\Profile\ProfileController;
//PS
use App\Http\Controllers\FormPS\PsGanttController;
//ISR
use App\Http\Controllers\FormISR\InspectionController;
use App\Http\Controllers\FormISR\InspectionDecisionController;
//FormSSC
use App\Http\Controllers\FormSSC\ShotblastSpareController;
//FormMP
use App\Http\Controllers\FormMP\FormMPController;
//FormVC
use App\Http\Controllers\FormVC\VariableCostAccountMasterController;
use App\Http\Controllers\FormVC\VariableCostController;
//FormCCR
use App\Http\Controllers\FormCCR\CostCenterReportController;
use App\Http\Controllers\FormAccounting\LossProvisionController;
use App\Http\Controllers\FormAccounting\CustomerPaymentTermController;
//FormPKG
use App\Http\Controllers\FormPKG\PackagingUsageController;
use App\Http\Controllers\FormPKG\PackagingMasterController;
//FormExam
use App\Http\Controllers\FormExam\ExamController;
use App\Http\Controllers\FormExam\FormExamMasterController;
use App\Http\Controllers\FormExam\ExamImportController;
//LIS
use App\Http\Controllers\FormLIS\InquiryController;
//Rick
use App\Http\Controllers\FormRisk\ProductionRiskController;
//DIE
use App\Http\Controllers\FormDIE\DieTrackingController;
//DP
use App\Http\Controllers\FormDP\DeliveryPlanController;
use App\Http\Controllers\FormDP\DeliveryPlanInquiryController;
use App\Http\Controllers\FormGP\GratingPerformanceController;
use App\Http\Controllers\FormDP\ProductionStatusTrackingController;
use App\Http\Controllers\FormDP\TruckMasterController;
//Forecast
use App\Http\Controllers\FormFC\ForecastRmController;
use App\Http\Controllers\FormFC\ForecastRmDivisionController;
use App\Http\Controllers\FormFC\PlannerPartMasterController;
use App\Http\Controllers\FormFC\PlannerForecastController;
use App\Http\Controllers\FormFC\DivisionPartMasterController;
use App\Http\Controllers\FormFC\DivisionGroupController;
use App\Http\Controllers\FormFC\DivisionGroupMasterController;
use App\Http\Controllers\FormMLA\MachineLoadController;


//Autocomplete
use App\Http\Controllers\AutoComplete\DepartmentRoleLookupController;
use App\Http\Controllers\AutoComplete\UserLookupController;
use App\Http\Controllers\AutoComplete\PartnumberLookupController;
use App\Http\Controllers\AutoComplete\MfgLookupController;
//Weekly Order
use App\Http\Controllers\FormWOS\SalesInquiryController;
use App\Http\Controllers\FormWOS\SalesUnitSummaryController;
use App\Http\Controllers\FormWOS\CustomerOrderInvoiceComparisonController;
use App\Http\Controllers\FormWOS\DeadstockReportController;
use App\Http\Controllers\FormWOS\OrderDueDateController;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\TestMail;

/*;
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

require __DIR__ . '/po.php';

Route::get('/', function () {
  return redirect('/home');
});

// Lookup / autocomplete endpoints — ต้อง login, จำกัด IP และ rate กัน scrape ข้อมูล

Route::get('/fc/history-detail', [ForecastRmController::class, 'historyDetail'])->name('fc.historyDetail');
Route::get('/fc/sku-autocomplete', [ForecastRmController::class, 'skuAutocomplete'])->name('fc.skuAutocomplete');
Route::get('/fc/division/customer-lookup', [ForecastRmDivisionController::class, 'customerLookup'])
  ->name('fc.division.customer.lookup');
Route::get('/fc/division/part-lookup', [ForecastRmDivisionController::class, 'partLookup'])
  ->name('fc.division.part.lookup');
Route::get('/api/department-roles/by-dept/{dept}', [DepartmentRoleLookupController::class, 'byDepartment'])->name('api.deptroles.bydept');
Route::get('/api/users/search', [UserLookupController::class, 'search'])->name('api.users.search');
Route::get('/api/parts/search', [PartnumberLookupController::class, 'byPartnumber'])->name('api.parts.search');
Route::get('/api/mfgs/search', [MfgLookupController::class, 'byMFG'])->name('api.mfgs.search');
Route::get('/api/wocr/mfgs/search', [MfgLookupController::class, 'byWocrMFG'])->name('api.wocr.mfgs.search');
Route::get('/api/grating-projects/search', [MfgLookupController::class, 'gratingProjects'])
  ->middleware(['auth', 'permission.any:GP'])
  ->name('api.grating-projects.search');
Route::get('/api/wr/items', [IncomeController::class, 'itemsSuggest'])->name('wr.autocomplete.items');
Route::get('/api/wr/po',    [IncomeController::class, 'poSuggest'])->name('wr.autocomplete.po');


Route::middleware(['auth', 'permission.any:DP,DPA'])->group(function () {
  Route::get('/dp/production-status', [ProductionStatusTrackingController::class, 'index'])->name('dp.production-status');
  Route::get('/dp/production-status/export', [ProductionStatusTrackingController::class, 'export'])->name('dp.production-status.export');
  Route::get('/dp/production-status/detail', [ProductionStatusTrackingController::class, 'detail'])->name('dp.production-status.detail');
  Route::post('/dp/production-status/confirm', [ProductionStatusTrackingController::class, 'confirm'])->name('dp.production-status.confirm');
  Route::post('/dp/production-status/confirm-bulk', [ProductionStatusTrackingController::class, 'confirmBulk'])->name('dp.production-status.confirm.bulk');
  Route::get('/dp/production-status/confirm/history', [ProductionStatusTrackingController::class, 'confirmHistory'])->name('dp.production-status.confirm.history');
});

Route::prefix('grating-performance')
  ->name('grating-performance.')
  ->middleware('auth')
  ->group(function () {
    Route::middleware('permission.any:GP')->group(function () {
      Route::get('/', [GratingPerformanceController::class, 'index'])->name('index');
      Route::get('/entries/create', [GratingPerformanceController::class, 'create'])->name('entries.create');
      Route::get('/step-balance', [GratingPerformanceController::class, 'stepBalance'])->name('step-balance');
      Route::post('/entries', [GratingPerformanceController::class, 'storeEntry'])->name('entries.store');
      Route::put('/entries/{entry}', [GratingPerformanceController::class, 'updateEntry'])->name('entries.update');
      Route::delete('/entries/{entry}', [GratingPerformanceController::class, 'destroyEntry'])->name('entries.destroy');
      Route::get('/inquiry', [GratingPerformanceController::class, 'inquiry'])->name('inquiry');
    });

    Route::middleware('permission.any:GPM')->group(function () {
      Route::get('/masters', [GratingPerformanceController::class, 'masters'])->name('masters');
      Route::post('/masters/employees', [GratingPerformanceController::class, 'storeEmployee'])->name('employees.store');
      Route::put('/masters/employees/{employee}', [GratingPerformanceController::class, 'updateEmployee'])->name('employees.update');
      Route::delete('/masters/employees/{employee}', [GratingPerformanceController::class, 'destroyEmployee'])->name('employees.destroy');
      Route::post('/masters/steps/defaults', [GratingPerformanceController::class, 'seedDefaultSteps'])->name('steps.defaults');
      Route::post('/masters/steps', [GratingPerformanceController::class, 'storeStep'])->name('steps.store');
      Route::put('/masters/steps/{step}', [GratingPerformanceController::class, 'updateStep'])->name('steps.update');
      Route::post('/masters/field-activities', [GratingPerformanceController::class, 'storeFieldActivity'])->name('field-activities.store');
      Route::put('/masters/field-activities/{activity}', [GratingPerformanceController::class, 'updateFieldActivity'])->name('field-activities.update');
      Route::post('/masters/projects', [GratingPerformanceController::class, 'storeProject'])->name('projects.store');
      Route::put('/masters/projects/{project}', [GratingPerformanceController::class, 'updateProject'])->name('projects.update');
    });
  });
//Inquiry

Route::get('/dp/line/{id}/history', [DeliveryPlanController::class, 'history'])
  ->name('dp.history');

Route::view('/home', 'home')->name('home');

Route::prefix('/die-tracking')
  ->name('die.')
  ->group(function () {
    Route::get('/',              [DieTrackingController::class, 'index'])->name('index');
    Route::get('/by-workorder',  [DieTrackingController::class, 'byWorkorder'])->name('by-workorder');
    Route::get('/by-date',       [DieTrackingController::class, 'byDate'])->name('by-date');
    Route::get('/by-week',       [DieTrackingController::class, 'byWeek'])->name('by-week');
    Route::get('/compare',       [DieTrackingController::class, 'compare'])->name('compare');
    Route::get('/export',        [DieTrackingController::class, 'export'])->name('export');

    // Master / Profile / Location
    Route::get('/master',                    [DieTrackingController::class, 'master'])->name('master');
    Route::get('/api/master',                [DieTrackingController::class, 'dieMaster'])->name('api.master');
    Route::get('/api/categories',            [DieTrackingController::class, 'categories'])->name('api.categories');
    Route::get('/api/suppliers',             [DieTrackingController::class, 'suppliers'])->name('api.suppliers');
    Route::get('/api/equiptypes',            [DieTrackingController::class, 'equipTypes'])->name('api.equiptypes');
    Route::get('/api/statuses',              [DieTrackingController::class, 'statuses'])->name('api.statuses');
    Route::get('/api/profile/{equipnumber}', [DieTrackingController::class, 'dieProfile'])->name('api.profile');
    Route::get('/api/location',              [DieTrackingController::class, 'currentLocation'])->name('api.location');
    Route::get('/api/material',              [DieTrackingController::class, 'materialTrace'])->name('api.material');

    // Autocomplete suggest endpoints: type ∈ wo|die|heat|coil
    Route::get('/api/suggest/{type}',        [DieTrackingController::class, 'suggest'])
      ->whereIn('type', ['wo', 'die', 'heat', 'coil', 'desc'])
      ->name('api.suggest');

    // WO Detail Sheet
    Route::get('/wo-detail',                 [DieTrackingController::class, 'woDetailPage'])->name('wo-detail');
    Route::get('/api/wo-detail',             [DieTrackingController::class, 'woDetailApi'])->name('api.wo-detail');

    // Customer Claim ในรอบ 1 เดือน (dashboard widget)
    Route::get('/recent-claims',             [DieTrackingController::class, 'recentClaims'])->name('recent-claims');

    // ไดร์ที่อยู่บนเครื่องไหน ณ ปัจจุบัน (dashboard widget)
    Route::get('/current-machines',          [DieTrackingController::class, 'currentMachines'])->name('current-machines');

    // Insights widgets
    Route::get('/top-consumers', [DieTrackingController::class, 'topConsumers'])->name('top-consumers');
    Route::get('/top-output',    [DieTrackingController::class, 'topOutputDies'])->name('top-output');
    Route::get('/idle-dies',     [DieTrackingController::class, 'idleDies'])->name('idle-dies');
  });

Route::prefix('/risk')
  ->name('risk.')
  ->group(function () {
    Route::get('/index', [ProductionRiskController::class, 'index'])->name('index');
    Route::get('/dashboard', [ProductionRiskController::class, 'dashboard'])->name('dashboard');
    Route::get('/export-excel', [ProductionRiskController::class, 'exportExcel'])->name('exportExcel');
  });

Route::prefix('/wos')
  ->name('wos.')
  ->group(function () {
    Route::get('/sales-weekly', [SalesInquiryController::class, 'index'])->name('sales_weekly');
    Route::get('/sales-weekly/dashboard', [SalesInquiryController::class, 'dashboard'])->name('sales_weekly.dashboard');
    Route::get('/sales-weekly/dashboard/pdf', [SalesInquiryController::class, 'dashboardPdf'])->name('sales_weekly.dashboard.pdf');
    Route::get('/sales-weekly/status', [SalesInquiryController::class, 'status'])->name('sales_weekly.status');
    Route::get('/sales-weekly/hash', [SalesInquiryController::class, 'hash'])->name('sales_weekly.hash');
    Route::get('/sales-weekly/data', [SalesInquiryController::class, 'data'])->name('sales_weekly.data');
    Route::get('/sales-weekly/{salesId}', [SalesInquiryController::class, 'detail'])->name('sales_weekly.detail');
    Route::get('/sales-weekly-export', [SalesInquiryController::class, 'export'])
      ->name('sales_weekly.export');

    Route::get('/order-due-date', [OrderDueDateController::class, 'index'])
      ->name('order_due_date');
    Route::get('/order-due-date/dashboard', [OrderDueDateController::class, 'dashboard'])
      ->name('order_due_date.dashboard');
    Route::get('/order-due-date/dashboard/pdf', [OrderDueDateController::class, 'dashboardPdf'])
      ->name('order_due_date.dashboard.pdf');
    Route::get('/order-due-date/dashboard/excel', [OrderDueDateController::class, 'exportExcel'])
      ->name('order_due_date.dashboard.excel');
    Route::get('/order-due-date/detail', [OrderDueDateController::class, 'detail'])
      ->name('order_due_date.detail');
    Route::get('/order-due-date/detail/pdf', [OrderDueDateController::class, 'detailPdf'])
      ->name('order_due_date.detail.pdf');

    Route::get('/sales-unit-summary', [SalesUnitSummaryController::class, 'index'])
      ->name('sales_unit_summary');
    Route::get('/sales-unit-summary/dashboard', [SalesUnitSummaryController::class, 'dashboard'])
      ->name('sales_unit_summary.dashboard');
    Route::get('/sales-unit-summary/dashboard/pdf', [SalesUnitSummaryController::class, 'dashboardPdf'])
      ->name('sales_unit_summary.dashboard.pdf');
    Route::get('/sales-unit-summary/detail', [SalesUnitSummaryController::class, 'detail'])
      ->name('sales_unit_summary.detail');
    Route::get('/sales-unit-summary/detail/group', [SalesUnitSummaryController::class, 'detailByGroup'])
      ->name('sales_unit_summary.detail_group');

    Route::get('/customer-order-invoice', [CustomerOrderInvoiceComparisonController::class, 'index'])
      ->name('customer_order_invoice.index');
    Route::get('/customer-order-invoice/{customerId}', [CustomerOrderInvoiceComparisonController::class, 'detail'])
      ->whereNumber('customerId')
      ->name('customer_order_invoice.detail');
  });

Route::prefix('/deadstock')
  ->name('deadstock.')
  ->group(function () {
    // เปิดให้ guest ดูได้ (อ่านอย่างเดียว)
    Route::get('/dashboard', [DeadstockReportController::class, 'dashboard'])->name('dashboard');
    Route::get('/review', [DeadstockReportController::class, 'review'])->name('review');
    Route::get('/review/export', [DeadstockReportController::class, 'exportReview'])->name('review.export');
    Route::get('/review/summary-pdf', [DeadstockReportController::class, 'exportSummaryPdf'])->name('review.summary_pdf');

    // ต้อง login ก่อนจึงจะทำ action ที่เปลี่ยนข้อมูล/ส่งเมลได้
    Route::middleware('auth')->group(function () {
      Route::post('/review/{item}/save', [DeadstockReportController::class, 'saveReview'])->name('review.save');
      Route::post('/review/import', [DeadstockReportController::class, 'importReviewExcel'])->name('review.import');
      Route::get('/review/import-template', [DeadstockReportController::class, 'downloadReviewImportTemplate'])->name('review.import_template');
      Route::post('/review/{month}/compare', [DeadstockReportController::class, 'compareMonth'])->name('review.compare');
      Route::get('/manual', [DeadstockReportController::class, 'manual'])->name('manual');
      Route::post('/manual/send', [DeadstockReportController::class, 'send'])->name('send');
    });
  });

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');


Route::prefix('fc')
  ->name('fc.')
  ->group(function () {
    Route::get('/', [ForecastRmController::class, 'index'])->name('index');
    Route::get('/forecast-detail', [ForecastRmController::class, 'forecastDetail'])
      ->name('forecastDetail');
    Route::get('/supplier-shortage', [ForecastRmController::class, 'supplierShortage'])->name('supplierShortage');
    Route::get('/supplier-shortage/export', [ForecastRmController::class, 'exportSupplierShortage'])->name('supplierShortage.export');
    Route::post('/manual-order/save', [ForecastRmController::class, 'saveManualOrder'])
      ->name('manualOrder.save');

    Route::get('/{sku}', [ForecastRmController::class, 'show'])
      ->where('sku', '[Rr][^/]+')
      ->name('show');

    Route::get('/input', [ForecastRmController::class, 'input'])->name('input');
    Route::post('/input/save', [ForecastRmController::class, 'saveInput'])->name('input.save');
    Route::post('/input/import', [ForecastRmController::class, 'importInput'])->name('input.import');

    Route::get('/division', [ForecastRmDivisionController::class, 'index'])->name('division');
    Route::get('/division/documents', [ForecastRmDivisionController::class, 'documents'])->name('division.documents');
    Route::get('/division/documents/export', [ForecastRmDivisionController::class, 'exportDocuments'])->name('division.documents.export');
    Route::get('/division/approvals', [ForecastRmDivisionController::class, 'approvalList'])->name('division.approvals');
    Route::get('/division/approval', [ForecastRmDivisionController::class, 'approval'])->name('division.approval');
    //Route::post('/division/generate', [ForecastRmDivisionController::class, 'generate'])->name('division.generate');
    Route::post('/division/manual-save', [ForecastRmDivisionController::class, 'saveManual'])->name('division.manual-save');
    Route::post('/division/approval-save', [ForecastRmDivisionController::class, 'saveApproval'])->name('division.approval-save');
    Route::post('/division/approval-reject', [ForecastRmDivisionController::class, 'rejectApproval'])->name('division.approval-reject');
    Route::post('/division/generate-fg', [ForecastRmDivisionController::class, 'generateFgForecast'])->name('division.generate');
    Route::get('/division/so-detail', [ForecastRmDivisionController::class, 'soDetail'])->name('division.soDetail');
    Route::get('/division-part-master', [DivisionPartMasterController::class, 'index'])->name('divisionPartMaster.index');
    Route::post('/division-part-master/save', [DivisionPartMasterController::class, 'save'])->name('divisionPartMaster.save');
    Route::get('/division-group', [DivisionGroupController::class, 'index'])->name('divisionGroup.index');
    Route::post('/division-group/save', [DivisionGroupController::class, 'save'])->name('divisionGroup.save');


    Route::get('/po-detail', [ForecastRmController::class, 'poDetail'])->name('poDetail');
    Route::get('/wip-detail', [ForecastRmController::class, 'wipDetail'])->name('wipDetail');
    Route::get('/so-detail', [ForecastRmController::class, 'soDetail'])->name('soDetail');
    Route::get('/export-excel', [ForecastRmController::class, 'exportExcel'])->name('exportExcel');
    Route::get('/fg-detail', [ForecastRmController::class, 'fgDetail'])->name('fgDetail');
    Route::get('/onhand-detail', [ForecastRmController::class, 'onhandDetail'])->name('onhandDetail');

    Route::get('/planner/master', [PlannerPartMasterController::class, 'index'])->name('planner.master');
    Route::get('/planner/master/rm-lookup', [PlannerPartMasterController::class, 'rmLookup'])->name('planner.master.rm-lookup');
    Route::post('/planner/master/save', [PlannerPartMasterController::class, 'save'])->name('planner.master.save');
    Route::post('/planner/master/import', [PlannerPartMasterController::class, 'import'])->name('planner.master.import');

    Route::get('/planner', [PlannerForecastController::class, 'index'])->name('planner.index');
    Route::post('/planner/generate', [PlannerForecastController::class, 'generate'])->name('planner.generate');
    Route::post('/planner/manual-save', [PlannerForecastController::class, 'saveManual'])->name('planner.manual-save');
    Route::get('/master', [PlannerPartMasterController::class, 'index'])->name('planner.master');
    Route::post('/master/save', [PlannerPartMasterController::class, 'save'])->name('planner.master.save');

    Route::get('/division-part-master', [DivisionPartMasterController::class, 'index'])->name('divisionPartMaster.index');
    Route::post('/division-part-master/save', [DivisionPartMasterController::class, 'save'])->name('divisionPartMaster.save');
  });



Route::prefix('mp')
  ->name('mp.')
  ->group(function () {
    Route::get('/packaging/usage', [PackagingUsageController::class, 'index'])
      ->name('packaging.usage');

    Route::get('/packaging/usage/export', [PackagingUsageController::class, 'export'])
      ->name('packaging.usage.export');
  });

Route::prefix('machine-load')
  ->name('machine-load.')
  ->group(function () {
    Route::get('/dashboard', [MachineLoadController::class, 'dashboard'])->name('dashboard');
    Route::get('/plan-dashboard', [MachineLoadController::class, 'planDashboard'])->name('plan-dashboard');
    Route::get('/inquiry', [MachineLoadController::class, 'inquiry'])->name('inquiry');
    Route::get('/export', [MachineLoadController::class, 'export'])->name('export');
    Route::get('/settings', [MachineLoadController::class, 'settings'])->name('settings');
    Route::post('/settings/work-centers', [MachineLoadController::class, 'storeWorkCenter'])->name('settings.work-centers.store');
    Route::put('/settings/work-centers/{id}', [MachineLoadController::class, 'updateWorkCenter'])->whereNumber('id')->name('settings.work-centers.update');
    Route::delete('/settings/work-centers/{id}', [MachineLoadController::class, 'destroyWorkCenter'])->whereNumber('id')->name('settings.work-centers.destroy');
    Route::post('/settings/holidays', [MachineLoadController::class, 'storeHoliday'])->name('settings.holidays.store');
    Route::delete('/settings/holidays/{id}', [MachineLoadController::class, 'destroyHoliday'])->whereNumber('id')->name('settings.holidays.destroy');
  });


Route::prefix('pkg/packaging')
  ->name('pkg.packaging.')
  ->group(function () {
    Route::get('/usage', [PackagingUsageController::class, 'index'])->name('usage');

    Route::get('/usage/export', [PackagingUsageController::class, 'export'])->name('usage.export');
  });

Route::get('/packaging/analysis', [PackagingUsageController::class, 'analysis'])
  ->name('pkg.packaging.analysis');

Route::get('/packaging/analysis/export', [PackagingUsageController::class, 'analysisExport'])
  ->name('pkg.packaging.analysis.export');


Route::prefix('dp')
  ->name('dp.')
  ->group(function () {
    Route::get('/inquiry', [DeliveryPlanInquiryController::class, 'inquiry'])->name('inquiry');
    Route::get('/inquiry/{ordId}/history', [DeliveryPlanInquiryController::class, 'history'])
      ->whereNumber('ordId')
      ->name('inquiry.history');
    Route::get('/history/db/{ord_id}', [DeliveryPlanInquiryController::class, 'historyDb'])->name('history.db');
    Route::get('/dashboard/truck-board', [DeliveryPlanInquiryController::class, 'truckBoard'])->name('dashboard.truck-board');
  });

Route::middleware(['auth'])
  ->prefix('dp')
  ->name('dp.')
  ->group(function () {

    // ใช้ได้เฉพาะ DP
    Route::middleware(['can:DP'])->group(function () {
      Route::get('/', [DeliveryPlanController::class, 'index'])->name('index');
      Route::get('/day/{date}', [DeliveryPlanController::class, 'index'])->name('day');
      Route::post('/day', [DeliveryPlanController::class, 'store'])->name('store');

      Route::post('/assign/{lineId}', [DeliveryPlanController::class, 'assignVehicle'])->name('assign');
      Route::post('/day/{date}/clear', [DeliveryPlanController::class, 'clearDay'])->name('clearDay');
      Route::post('/reset', [DeliveryPlanController::class, 'resetAll'])->name('resetAll');
      Route::post('/line/{id}/update', [DeliveryPlanController::class, 'updateLine'])->name('updateLine');
      Route::get('/line/{id}/history', [DeliveryPlanController::class, 'history'])->name('history');
      Route::post('/inquiry/{ordId}/postpone', [DeliveryPlanInquiryController::class, 'postponePlan'])
        ->whereNumber('ordId')
        ->name('inquiry.postpone');
      Route::post('/inquiry/bulk-postpone', [DeliveryPlanInquiryController::class, 'bulkPostponePlans'])
        ->name('inquiry.bulk-postpone');
      Route::post('/inquiry/{ordId}/duplicate', [DeliveryPlanInquiryController::class, 'duplicatePlan'])
        ->whereNumber('ordId')
        ->name('inquiry.duplicate');

      Route::get('/customer-lookup', [DeliveryPlanController::class, 'customerLookup'])->name('customerLookup');
      Route::get('/so-lookup', [DeliveryPlanController::class, 'soLookup'])->name('soLookup');
      Route::get('/so-lines', [DeliveryPlanController::class, 'soLines'])->name('soLines');
      Route::get('/sales-lookup', [DeliveryPlanController::class, 'salesLookup'])->name('salesLookup');
      Route::get('/mfg-lookup', [DeliveryPlanController::class, 'mfgLookup'])->name('mfgLookup');
      Route::get('/duplicate-check', [DeliveryPlanController::class, 'duplicateCheck'])->name('duplicateCheck');
      Route::get('/part-lookup', [DeliveryPlanController::class, 'partLookup'])->name('partLookup');
      Route::post('/inquiry/sync-saleorder', [DeliveryPlanInquiryController::class, 'syncSaleOrderFromErp'])
        ->name('inquiry.sync-saleorder');
      Route::post('/{ordId}/void', [DeliveryPlanInquiryController::class, 'voidPlan'])
        ->whereNumber('ordId')
        ->name('void');
    });

    // ใช้ได้ทั้ง DP และ DPA
    Route::middleware(['permission.any:DP,DPA'])->group(function () {
      Route::get('/inquiry/export', [DeliveryPlanInquiryController::class, 'export'])->name('inquiry.export');
      Route::get('/inquiry/export-pdf', [DeliveryPlanInquiryController::class, 'exportPdf'])->name('inquiry.export-pdf');

      Route::get('/truck-capacity', [DeliveryPlanInquiryController::class, 'truckCapacity'])->name('truck.capacity');
      Route::get('/truck-staff/options', [DeliveryPlanInquiryController::class, 'truckStaffOptions'])
        ->name('truck.staff.options');

      Route::get('/truck-staff/defaults', [DeliveryPlanInquiryController::class, 'truckStaffDefaults'])
        ->name('truck.staff.defaults');
    });

    Route::middleware(['permission.any:DPEMAIL,DPMAIL'])->group(function () {
      Route::post('/inquiry/send-plan-mail', [DeliveryPlanInquiryController::class, 'sendPlanMail'])
        ->name('inquiry.send-plan-mail');
      Route::get('/inquiry/export-plan-pdf', [DeliveryPlanInquiryController::class, 'downloadPlanPdf'])
        ->name('inquiry.export-plan-pdf');
    });

    // ใช้ได้เฉพาะ DPA
    Route::middleware(['can:DPA'])->group(function () {
      Route::post('/inquiry/truck-assign/{ordId}', [DeliveryPlanInquiryController::class, 'assignTruck'])
        ->name('inquiry.truck.assign');
      Route::post('/inquiry/truck-unassign-bulk', [DeliveryPlanInquiryController::class, 'bulkUnassignTruck'])
        ->name('inquiry.truck.unassign.bulk');
      Route::post('/inquiry/truck-unassign/{ordId}', [DeliveryPlanInquiryController::class, 'unassignTruck'])
        ->whereNumber('ordId')
        ->name('inquiry.truck.unassign');
      Route::post('/inquiry/special-dispatch/{ordId}', [DeliveryPlanInquiryController::class, 'markSpecialDispatch'])
        ->whereNumber('ordId')
        ->name('inquiry.special-dispatch');
      Route::post('/inquiry/special-dispatch/{ordId}/close', [DeliveryPlanInquiryController::class, 'closeSpecialDispatch'])
        ->whereNumber('ordId')
        ->name('inquiry.special-dispatch.close');
      Route::post('/inquiry/special-dispatch/{ordId}/cancel', [DeliveryPlanInquiryController::class, 'cancelSpecialDispatch'])
        ->whereNumber('ordId')
        ->name('inquiry.special-dispatch.cancel');
      Route::post('/inquiry/special-dispatch-cancel-bulk', [DeliveryPlanInquiryController::class, 'bulkCancelSpecialDispatch'])
        ->name('inquiry.special-dispatch.cancel.bulk');
      Route::post('/inquiry/special-dispatch/{ordId}/reopen', [DeliveryPlanInquiryController::class, 'reopenSpecialDispatch'])
        ->whereNumber('ordId')
        ->name('inquiry.special-dispatch.reopen');

      Route::get('/dashboard/logistics-summary', [DeliveryPlanInquiryController::class, 'logisticsSummary'])
        ->name('dashboard.logistics-summary');
      Route::get('/dashboard/truck-board/export/print', [DeliveryPlanInquiryController::class, 'printAssignedTruckBoard'])
        ->name('dashboard.truck-board.export.print');
      Route::get('/dashboard/truck-board/export/pdf', [DeliveryPlanInquiryController::class, 'downloadAssignedTruckBoardPdf'])
        ->name('dashboard.truck-board.export.pdf');
      Route::get('/dashboard/truck-board/export/excel', [DeliveryPlanInquiryController::class, 'downloadAssignedTruckBoardExcel'])
        ->name('dashboard.truck-board.export.excel');
      Route::get('/dashboard/truck-board/trip/print', [DeliveryPlanInquiryController::class, 'printTruckTrip'])
        ->name('dashboard.truck-board.trip.print');
      Route::get('/dashboard/truck-board/trip/pdf', [DeliveryPlanInquiryController::class, 'downloadTruckTripPdf'])
        ->name('dashboard.truck-board.trip.pdf');
      Route::get('/dashboard/truck-board/trip/excel', [DeliveryPlanInquiryController::class, 'downloadTruckTripExcel'])
        ->name('dashboard.truck-board.trip.excel');
      Route::post('/dashboard/truck-board/trip/close', [DeliveryPlanInquiryController::class, 'closeTruckTrip'])
        ->name('dashboard.truck-board.trip.close');
      Route::post('/dashboard/truck-board/send-mail', [DeliveryPlanInquiryController::class, 'sendAssignedTruckBoardMail'])
        ->name('dashboard.truck-board.send-mail');

      Route::get('/master/trucks', [TruckMasterController::class, 'trucks'])->name('master.trucks');
      Route::post('/master/trucks', [TruckMasterController::class, 'saveTruck'])->name('master.trucks.store');
      Route::post('/master/trucks/{id}', [TruckMasterController::class, 'saveTruck'])->whereNumber('id')->name('master.trucks.update');
      Route::post('/master/trucks/{id}/delete', [TruckMasterController::class, 'deleteTruck'])->whereNumber('id')->name('master.trucks.delete');

      Route::get('/master/drivers', [TruckMasterController::class, 'drivers'])->name('master.drivers');
      Route::post('/master/drivers', [TruckMasterController::class, 'saveDriver'])->name('master.drivers.store');
      Route::post('/master/drivers/{id}', [TruckMasterController::class, 'saveDriver'])->whereNumber('id')->name('master.drivers.update');
      Route::post('/master/drivers/{id}/delete', [TruckMasterController::class, 'deleteDriver'])->whereNumber('id')->name('master.drivers.delete');

      Route::get('/master/helpers', [TruckMasterController::class, 'helpers'])->name('master.helpers');
      Route::post('/master/helpers', [TruckMasterController::class, 'saveHelper'])->name('master.helpers.store');
      Route::post('/master/helpers/{id}', [TruckMasterController::class, 'saveHelper'])->whereNumber('id')->name('master.helpers.update');
      Route::post('/master/helpers/{id}/delete', [TruckMasterController::class, 'deleteHelper'])->whereNumber('id')->name('master.helpers.delete');
    });
  });


Route::middleware(['auth', 'can:PR'])
  ->prefix('pr')
  ->name('pr.')
  ->group(function () {
    // สร้างใบ PR
    Route::get('/create',  [RequestController::class, 'create'])->name('create');
    Route::post('/store',  [RequestController::class, 'store'])->name('store');

    // My Task
    Route::get('/my-actions', [MyActionController::class, 'index'])->name('my_actions');

    // Review
    Route::get('/{id}/review', [WorkflowReviewController::class, 'show'])->name('review');

    // Action
    Route::post('/{id}/approve', [WorkflowReviewController::class, 'approve'])->name('approve');
    Route::post('/{id}/reject', [WorkflowReviewController::class, 'reject'])->name('reject');
  });


// Profile routes
Route::middleware('auth')->group(function () {
  Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
  Route::put('/profile/update', [ProfileController::class, 'update'])->name('profile.update');
  Route::get('/profile/change-password', [ProfileController::class, 'changePassword'])->name('profile.change-password');
  Route::put('/profile/update-password', [ProfileController::class, 'updatePassword'])->name('profile.update-password');
});

Route::prefix('ps')->group(function () {
  Route::get('/gantt',      [PsGanttController::class, 'index'])->name('ps.gantt');
  Route::get('/gantt/data', [PsGanttController::class, 'data'])->name('ps.gantt.data');
});

Route::prefix('lis')
  ->name('lis.')
  ->group(function () {
    Route::get('/create', [InquiryController::class, 'create'])->name('create');  // หน้า 1
    Route::post('/',       [InquiryController::class, 'store'])->name('store');

    Route::get('/',        [InquiryController::class, 'index'])->name('index');  // หน้า 2
  });



Route::prefix('exam')
  ->name('exam.')
  ->group(function () {
    Route::get('/', [ExamController::class, 'selectType'])->name('select');
    Route::get('/start/{examType}', [ExamController::class, 'start'])->name('start');
    Route::post('/submit', [ExamController::class, 'submit'])->name('submit');
    Route::get('/result/{id}',   [ExamController::class, 'result'])->name('result');
  });

Route::middleware(['auth', 'can:EXAM'])
  ->prefix('exam/master')
  ->name('exam.master.')
  ->group(function () {


    // หน้า import (ของฟอร์มนี้)
    Route::get('/{formExam}/import', [ExamImportController::class, 'create'])->name('import.create');

    // รับไฟล์ import
    Route::post('/{formExam}/import', [ExamImportController::class, 'store'])->name('import.store');

    // download template
    Route::get('/{formExam}/import/template', [ExamImportController::class, 'downloadTemplate'])->name('import.template');

    // ฟอร์มข้อสอบ (Forms)
    Route::get('/',                       [FormExamMasterController::class, 'index'])->name('index');
    Route::get('/create',                 [FormExamMasterController::class, 'create'])->name('create');
    Route::post('/',                      [FormExamMasterController::class, 'store'])->name('store');
    Route::get('/{formExam}',             [FormExamMasterController::class, 'edit'])->name('edit');
    Route::post('/{formExam}',            [FormExamMasterController::class, 'update'])->name('update');

    // Exam Types (Pre/Post)
    Route::get('/{formExam}/exam-types',  [FormExamMasterController::class, 'examTypes'])->name('examtypes.index');
    Route::put('/{formExam}/exam-types/{examType}', [FormExamMasterController::class, 'updateExamType'])->name('examtypes.update');

    // Categories
    Route::get('/{formExam}/categories',  [FormExamMasterController::class, 'categories'])->name('categories.index');
    Route::post('/{formExam}/categories', [FormExamMasterController::class, 'storeCategory'])->name('categories.store');
    Route::delete('/categories/{category}', [FormExamMasterController::class, 'destroyCategory'])->name('categories.destroy');
    Route::put('categories/{category}',   [FormExamMasterController::class, 'updateCategory'])->name('categories.update');

    // Questions
    Route::get('/{formExam}/questions',   [FormExamMasterController::class, 'questions'])->name('questions.index');
    Route::get('/{formExam}/questions/create', [FormExamMasterController::class, 'createQuestion'])->name('questions.create');

    Route::post('/{formExam}/questions', [FormExamMasterController::class, 'storeQuestion'])->name('questions.store');

    Route::get('/{formExam}/questions/{question}/edit', [FormExamMasterController::class, 'editQuestion'])->name('questions.edit');

    Route::post('/{formExam}/questions/{question}', [FormExamMasterController::class, 'updateQuestion'])->name('questions.update');

    // Sessions (Results)
    Route::get('/{formExam}/sessions',    [FormExamMasterController::class, 'sessions'])->name('sessions.index');
    Route::get('/sessions/{session}',     [FormExamMasterController::class, 'sessionDetail'])->name('sessions.detail');

    // Export Excel
    Route::get('{formExam}/sessions/export', [FormExamMasterController::class, 'sessionsExport'])->name('sessions.export');

    Route::post('/{formExam}/deactivate', [FormExamMasterController::class, 'deactivate'])->name('deactivate');
    Route::post('/{formExam}/activate', [FormExamMasterController::class, 'activate'])->name('activate');

    Route::post('/{formExam}/questions/{question}/deactivate', [FormExamMasterController::class, 'deactivateQuestion'])->name('questions.deactivate');

    Route::post('/{formExam}/questions/{question}/activate', [FormExamMasterController::class, 'activateQuestion'])->name('questions.activate');

    Route::post('/{formExam}/questions/{question}/copy', [FormExamMasterController::class, 'copyQuestion'])->name('questions.copy');
  });

Route::prefix('isr')
  ->name('isr.')
  ->group(function () {
    Route::get('/', [InspectionController::class, 'index'])->name('index');
    Route::get('/workcenter-tests', [InspectionController::class, 'workcenterTests'])->name('workcenter-tests');

    Route::post('/manual-decision', [InspectionDecisionController::class, 'store'])
      ->middleware('auth')
      ->name('manual.store');
  });

Route::prefix('ssc')
  ->name('ssc.')
  ->group(function () {

    Route::get('/reports/shotblast-spares', [ShotblastSpareController::class, 'index'])->name('index');

    Route::get('/reports/shotblast-spares/export', [ShotblastSpareController::class, 'export'])->name('export');
  });

Route::prefix('mp')
  ->name('mp.')
  ->group(function () {

    Route::get('/index', [FormMPController::class, 'index'])
      ->name('index');

    Route::get('/export', [FormMPController::class, 'exportExcel'])
      ->name('export');
  });

Route::prefix('variable-cost')
  ->name('variable-cost.')
  ->middleware(['auth', 'permission.any:VC,VCA,VCL,VCPD,VCP,VCS,VCM'])
  ->group(function () {
    Route::get('/', [VariableCostController::class, 'index'])->name('index');
    Route::get('/summary', [VariableCostController::class, 'summary'])->name('summary');
    Route::get('/monthly', [VariableCostController::class, 'monthly'])->name('monthly');
    Route::get('/matrix', [VariableCostController::class, 'matrix'])->name('matrix');
    Route::get('/accounts', [VariableCostController::class, 'accounts'])->name('accounts');
    Route::get('/yearly', [VariableCostController::class, 'yearly'])->name('yearly');
    Route::get('/details', [VariableCostController::class, 'details'])->name('details');
    Route::get('/export', [VariableCostController::class, 'export'])->name('export');
    Route::post('/truck-weight-log', [VariableCostController::class, 'storeTruckWeightLog'])->middleware('permission.any:VCM')->name('truck-weight-log.store');
    Route::put('/truck-weight-log/{id}', [VariableCostController::class, 'updateTruckWeightLog'])->whereNumber('id')->middleware('permission.any:VCM')->name('truck-weight-log.update');
    Route::delete('/truck-weight-log/{id}', [VariableCostController::class, 'destroyTruckWeightLog'])->whereNumber('id')->middleware('permission.any:VCM')->name('truck-weight-log.destroy');
  });

// VC Account Master — เฉพาะ role VC / VCC (VCA ดูได้แค่ข้อมูล group Admin ไม่เห็น master)
Route::prefix('variable-cost')
  ->name('variable-cost.')
  ->middleware(['auth', 'permission.any:VC,VCC'])
  ->group(function () {
    Route::get('/account-master', [VariableCostAccountMasterController::class, 'index'])->name('account-master');
    Route::post('/account-master', [VariableCostAccountMasterController::class, 'update'])->name('account-master.update');
  });

Route::prefix('cost-center')
  ->name('cost-center.')
  ->group(function () {
    Route::get('/', [CostCenterReportController::class, 'index'])->name('index');
    Route::get('/summary', [CostCenterReportController::class, 'summary'])->name('summary');
    Route::get('/detail', [CostCenterReportController::class, 'detail'])->name('detail');
  });

Route::prefix('accounting')
  ->name('accounting.')
  ->group(function () {
    Route::get('/loss-provision', [LossProvisionController::class, 'index'])->name('loss-provision.index');
    Route::get('/loss-provision/yearly', [LossProvisionController::class, 'yearly'])->name('loss-provision.yearly');
    Route::get('/loss-provision/yearly/export', [LossProvisionController::class, 'exportYearly'])->name('loss-provision.yearly.export');
    Route::get('/loss-provision/recovery-analysis', [LossProvisionController::class, 'recoveryAnalysis'])->name('loss-provision.recovery-analysis');
    Route::get('/loss-provision/recovery-dashboard', [LossProvisionController::class, 'recoveryDashboard'])->name('loss-provision.recovery-dashboard');
    Route::get('/loss-provision/recovery-inquiry', [LossProvisionController::class, 'recoveryInquiry'])->name('loss-provision.recovery-inquiry');
    Route::get('/loss-provision/recovery-export', [LossProvisionController::class, 'exportRecovery'])->name('loss-provision.recovery-export');
    Route::get('/loss-provision/export', [LossProvisionController::class, 'export'])->name('loss-provision.export');
    Route::get('/customer-payment-terms/erp-customer-lookup', [CustomerPaymentTermController::class, 'customerLookup'])->name('cpt.customerLookup');
    Route::get('/customer-payment-terms/masters', [CustomerPaymentTermController::class, 'masters'])->name('cpt.masters');
    Route::get('/division-group-master', [DivisionGroupMasterController::class, 'index'])->middleware(['auth', 'permission.any:VC,VCC'])->name('divisionGroup.master');
    Route::post('/division-group-master', [DivisionGroupMasterController::class, 'store'])->middleware(['auth', 'permission.any:VC,VCC'])->name('divisionGroup.master.store');
    Route::put('/division-group-master/{id}', [DivisionGroupMasterController::class, 'update'])->middleware(['auth', 'permission.any:VC,VCC'])->name('divisionGroup.master.update');
    Route::delete('/division-group-master/{id}', [DivisionGroupMasterController::class, 'destroy'])->middleware(['auth', 'permission.any:VC,VCC'])->name('divisionGroup.master.destroy');
    Route::post('/customer-payment-terms/billing-plans', [CustomerPaymentTermController::class, 'storeBillingPlan'])->name('cpt.billing-plans.store');
    Route::put('/customer-payment-terms/billing-plans/{billingPlan}', [CustomerPaymentTermController::class, 'updateBillingPlan'])->name('cpt.billing-plans.update');
    Route::delete('/customer-payment-terms/billing-plans/{billingPlan}', [CustomerPaymentTermController::class, 'destroyBillingPlan'])->name('cpt.billing-plans.destroy');
    Route::post('/customer-payment-terms/payment-schedules', [CustomerPaymentTermController::class, 'storePaymentSchedule'])->name('cpt.payment-schedules.store');
    Route::put('/customer-payment-terms/payment-schedules/{paymentSchedule}', [CustomerPaymentTermController::class, 'updatePaymentSchedule'])->name('cpt.payment-schedules.update');
    Route::delete('/customer-payment-terms/payment-schedules/{paymentSchedule}', [CustomerPaymentTermController::class, 'destroyPaymentSchedule'])->name('cpt.payment-schedules.destroy');
    Route::get('/customer-payment-terms', [CustomerPaymentTermController::class, 'index'])->name('cpt.index');
    Route::post('/customer-payment-terms', [CustomerPaymentTermController::class, 'store'])->name('cpt.store');
    Route::put('/customer-payment-terms/{customerPaymentTerm}', [CustomerPaymentTermController::class, 'update'])->name('cpt.update');
    Route::delete('/customer-payment-terms/{customerPaymentTerm}', [CustomerPaymentTermController::class, 'destroy'])->name('cpt.destroy');
  });

//ฟอร์มวางแผน
Route::middleware(['auth', 'can:PP'])
  ->prefix('pp')
  ->name('pp.')
  ->group(function () {
    Route::get('/index',   [ProductionPlanController::class, 'index'])->name('index');
    Route::post('/store',  [ProductionPlanController::class, 'store'])->name('store');

    Route::get('/index/{id}/', [PlannerController::class, 'showPlanner'])->name('planner');
    Route::post('/action/{id}',  [PlannerController::class, 'planner_action'])->name('planner_action');

    Route::get('/revise/{id}/', [ReviseController::class, 'showRevise'])->name('sales');
    Route::post('/revise/{id}/', [ReviseController::class, 'sales_action'])->name('sales_action');

    Route::get('/view/{id}/', [ViewController::class, 'view'])->name('view');

    Route::get('/pending', [PpDocboxController::class, 'pending'])->name('pending');
    Route::get('/mine',    [PpDocboxController::class, 'mine'])->name('mine');
    Route::get('/all',     [PpDocboxController::class, 'all'])->name('all');
    Route::get('/export',  [PpDocboxController::class, 'export'])->name('export');
  });

Route::middleware(['auth', 'can:PAADMIN'])
  ->prefix('paadmin')
  ->name('paadmin.')
  ->group(function () {
    Route::get('/',                   [PaMasterController::class, 'index'])->name('index');

    // Sections
    Route::post('/sections',          [PaMasterController::class, 'sectionStore'])->name('sections.store');
    Route::put('/sections/{sec}',     [PaMasterController::class, 'sectionUpdate'])->name('sections.update');
    Route::delete('/sections/{sec}',  [PaMasterController::class, 'sectionDestroy'])->name('sections.destroy');
    Route::post('/sections/reorder',  [PaMasterController::class, 'sectionReorder'])->name('sections.reorder');

    // Questions
    Route::post('/questions',         [PaMasterController::class, 'questionStore'])->name('questions.store');
    Route::put('/questions/{q}',      [PaMasterController::class, 'questionUpdate'])->name('questions.update');
    Route::delete('/questions/{q}',   [PaMasterController::class, 'questionDestroy'])->name('questions.destroy');
    Route::post('/questions/reorder', [PaMasterController::class, 'questionReorder'])->name('questions.reorder');


    Route::get('/periods',              [PeriodController::class, 'index'])->name('periods.index');
    Route::post('/periods',             [PeriodController::class, 'store'])->name('periods.store');
    Route::put('/periods/{id}',         [PeriodController::class, 'update'])->name('periods.update');
    Route::put('/periods/{id}/activate', [PeriodController::class, 'activate'])->name('periods.activate');
    Route::delete('/periods/{id}',      [PeriodController::class, 'destroy'])->name('periods.destroy');
  });

/*Route::middleware(['auth', 'can:PA'])
  ->prefix('pa')
  ->name('pa.')
  ->group(function () {
    Route::get('/dashboard', [PaEvaluationController::class, 'dashboard'])->name('dashboard');
    Route::get('/evaluate',  [PaEvaluationController::class, 'index'])->name('index');        // รายชื่อพนักงานในแผนก
    Route::get('/evaluate/{employee}', [PaEvaluationController::class, 'form'])->name('form'); // ฟอร์มประเมิน
    Route::post('/evaluate/{employee}', [PaEvaluationController::class, 'store'])->name('store'); // บันทึกคะแนน

    Route::get('/hr', [PaEvaluationController::class, 'hrIndex'])->name('hr.index');

    // เปิด/สร้างใบ HR แล้วเข้าแบบฟอร์ม
    Route::get('/hr/open/{employee}', [PaEvaluationController::class, 'hrOpen'])->name('hr.open');

    // แบบฟอร์ม HR (ดู/แก้)
    Route::get('/hr/form/{form}', [PaEvaluationController::class, 'hrForm'])->name('hr.form');

    // บันทึก HR (มีอยู่แล้วใน controller ของคุณ)
    Route::post('/hr/{form}', [PaEvaluationController::class, 'storeHr'])->name('hr.store');
  });*/


Route::middleware(['auth', 'can:PA'])
  ->prefix('pa')
  ->name('pa.')
  ->group(function () {
    Route::get('/dashboard', [PaEvaluationController::class, 'dashboard'])->name('dashboard');
    Route::get('/evaluate',  [PaEvaluationController::class, 'index'])->name('index');        // รายชื่อพนักงานในแผนก
    Route::get('/evaluate/{employee}', [PaEvaluationController::class, 'form'])->name('form'); // ฟอร์มประเมิน
    Route::post('/evaluate', [PaEvaluationController::class, 'store'])->name('store');
    Route::post('/evaluate/{employee}', [PaEvaluationController::class, 'store'])->whereNumber('employee')->name('store');
  });

Route::middleware(['auth', 'can:WR'])
  ->prefix('wr')
  ->name('wr.')
  ->group(function () {
    Route::get('/', [IncomeController::class, 'index'])->name('index');
    Route::get('/formwr/export', [IncomeController::class, 'export'])->name('export');
  });



Route::middleware(['auth', 'can:PAHR'])
  ->prefix('pa')
  ->name('pa.')
  ->group(function () {
    Route::get('/hr', [PaEvaluationController::class, 'hrIndex'])->name('hr.index');
    // เปิด/สร้างใบ HR แล้วเข้าแบบฟอร์ม
    Route::get('/hr/open/{employee}', [PaEvaluationController::class, 'hrOpen'])->name('hr.open');
    // แบบฟอร์ม HR (ดู/แก้)
    Route::get('/hr/form/{form}', [PaEvaluationController::class, 'hrForm'])->name('hr.form');
    // บันทึก HR 
    Route::post('/hr/{form}', [PaEvaluationController::class, 'storeHr'])->name('hr.store');
  });

//ฟอร์มแก้ไข
Route::prefix('wocr')
  ->name('wocr.')
  ->group(function () {
    Route::get('/view/{id}/', [WocrViewController::class, 'view'])->name('view');
    Route::get('/all',     [WocrDocboxController::class, 'all'])->name('all');
  });

Route::middleware(['auth', 'can:WOCR'])
  ->prefix('wocr')
  ->name('wocr.')
  ->group(function () {
    Route::get('/index',   [OriginatorController::class, 'index'])->name('index');
    Route::post('/store',  [OriginatorController::class, 'store'])->name('store');
    Route::get('/pending', [WocrDocboxController::class, 'pending'])->name('pending');
    Route::get('/mine',    [WocrDocboxController::class, 'mine'])->name('mine');
    Route::get('/export',  [WocrDocboxController::class, 'export'])->name('export');

    //Route::get('/index',   [OriginatorController::class, 'index'])->name('index');
    Route::get('/planner/{id}/', [PlannerWOCRController::class, 'showPlanner'])->name('planner');
    Route::post('/planner/{id}',  [PlannerWOCRController::class, 'planner_action'])->name('planner_action');

    Route::get('/mng-planner/{id}/', [MngPlannerController::class, 'showPlanner'])->name('mng_planner');
    Route::post('/mng-planner/{id}',  [MngPlannerController::class, 'planner_action'])->name('mng_planner_action');

    Route::get('/revise/{id}/', [WocrReviseController::class, 'showRevise'])->name('revise');
    Route::post('/revise/{id}/', [WocrReviseController::class, 'originator_action'])->name('revise_action');

    Route::get('/wocr/files/{id}/download', function ($id) {
      $f = WocrDataFile::findOrFail($id);;

      if (Storage::disk('public')->exists($f->file_path)) {
        // ให้ browser โหลดจาก public URL แทน
        return redirect(Storage::disk('public')->url($f->file_path));
      }
      if (Storage::exists($f->file_path)) {
        return Storage::download($f->file_path, $f->original_name);
      }
      abort(404);
    })->name('files.download');
  });

Route::middleware(['auth', 'can:ADMINWEB'])
  ->prefix('adminweb')
  ->name('adminweb.')
  ->group(function () {

    // Clear cache ผ่านเว็บ (ไม่ต้อง SSH) — เฉพาะ ADMINWEB
    Route::get('/cache/clear', function () {
        $ok = [];
        $failed = [];

        foreach (['config:clear', 'route:clear', 'view:clear', 'cache:clear'] as $command) {
            try {
                \Illuminate\Support\Facades\Artisan::call($command);
                $ok[] = $command;
            } catch (\Throwable $e) {
                // เช่น file cache ไม่มีโฟลเดอร์ storage/framework/cache/data — ไม่ให้ล้มทั้ง request
                $failed[] = $command . ' (' . $e->getMessage() . ')';
            }
        }

        $message = 'ล้าง cache สำเร็จ: ' . (implode(', ', $ok) ?: '-');
        if ($failed) {
            return back()
                ->with('success', $message)
                ->with('error', 'มีคำสั่งที่ข้ามไป: ' . implode(' | ', $failed));
        }

        return back()->with('success', $message);
    })->name('cache.clear');

    // Composer dump-autoload ผ่านเว็บ (ไม่ต้อง SSH) — เฉพาะ ADMINWEB
    Route::get('/autoload/dump', function () {
        $process = \Symfony\Component\Process\Process::fromShellCommandline(
            'composer dump-autoload -o --no-interaction',
            base_path(),
            // กัน composer ล้มกรณี web user ไม่มี HOME
            ['COMPOSER_HOME' => storage_path('app/composer-home')],
            null,
            300
        );

        try {
            $process->run();
        } catch (\Throwable $e) {
            return back()->with('error', 'รัน composer ไม่สำเร็จ: ' . $e->getMessage());
        }

        // composer พิมพ์สรุปผลทาง stderr และแถม ANSI color codes มาด้วย ต้อง strip ก่อนแสดง
        $output = preg_replace('/\e\[[0-9;]*m/', '', $process->getErrorOutput() . "\n" . $process->getOutput());
        $lines  = array_values(array_filter(array_map('trim', explode("\n", $output))));
        $tail   = implode(' | ', array_slice($lines, -2)) ?: '-';

        if (!$process->isSuccessful()) {
            return back()->with('error', 'dump-autoload ล้มเหลว: ' . $tail);
        }

        return back()->with('success', 'dump-autoload สำเร็จ: ' . $tail);
    })->name('autoload.dump');

    // วินิจฉัย path ของ Deadstock snapshot ที่ฝั่งเซิร์ฟเวอร์มองเห็นจริง — เฉพาะ ADMINWEB
    Route::get('/deadstock/diag', function () {
        $base = rtrim((string) env('DEADSTOCK_MAIL_DAILY_PATH', 'M:\\htdocs\\mail-daily'), '\\/');
        $dir = $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'reports'
            . DIRECTORY_SEPARATOR . 'snapshots';

        $summaryFiles = is_dir($dir) ? (glob($dir . DIRECTORY_SEPARATOR . 'deadstock_*.json') ?: []) : [];
        $itemFiles = is_dir($dir) ? (glob($dir . DIRECTORY_SEPARATOR . 'deadstock_items_*.json') ?: []) : [];
        // นับเฉพาะไฟล์สรุป (ตัด items ออก) แบบเดียวกับที่ dashboard ใช้
        $summaryOnly = array_values(array_filter($summaryFiles, fn($p) => !str_contains(basename($p), 'deadstock_items_')));

        return response()->json([
            'env_DEADSTOCK_MAIL_DAILY_PATH' => env('DEADSTOCK_MAIL_DAILY_PATH'),
            'resolved_base' => $base,
            'snapshot_dir' => $dir,
            'is_dir' => is_dir($dir),
            'is_readable' => is_readable($dir),
            'open_basedir' => ini_get('open_basedir') ?: '(not set)',
            'php_user' => function_exists('get_current_user') ? get_current_user() : null,
            'count_summary_json' => count($summaryOnly),
            'count_items_json' => count($itemFiles),
            'newest_files' => collect(array_merge($summaryOnly, $itemFiles))
                ->sortByDesc(fn($p) => @filemtime($p) ?: 0)
                ->take(8)
                ->map(fn($p) => basename($p) . ' (' . date('Y-m-d H:i', @filemtime($p) ?: 0) . ')')
                ->values(),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    })->name('deadstock.diag');

    // เพิ่มสมาชิก
    Route::get('/deadstock/config', [DeadstockReportController::class, 'config'])->name('deadstock.config');
    Route::post('/deadstock/config/create-baseline', [DeadstockReportController::class, 'createBaselineSnapshot'])->name('deadstock.create_baseline');
    Route::post('/deadstock/config/import-latest', [DeadstockReportController::class, 'importLatestSnapshot'])->name('deadstock.import_latest');
    Route::post('/deadstock/config/sync-current', [DeadstockReportController::class, 'syncCurrentDeadstock'])->name('deadstock.sync_current');

    Route::get('/users/register',  [UserAdminController::class, 'index'])->name('users.register');
    Route::post('/users',           [UserAdminController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [UserAdminController::class, 'edit'])->whereNumber('user')->name('users.edit');
    Route::put('/users/{user}',     [UserAdminController::class, 'update'])->whereNumber('user')->name('users.update');
    Route::patch('/users/{user}/toggle-active', [UserAdminController::class, 'toggleActive'])->whereNumber('user')->name('users.toggleActive');
    Route::delete('/users/{user}',  [UserAdminController::class, 'destroy'])->name('users.destroy');

    // เพิ่มแผนก
    Route::get('/departments/create',      [DepartmentAdminController::class, 'create'])->name('dept.create');
    Route::post('/departments',             [DepartmentAdminController::class, 'store'])->name('dept.store');
    Route::get('/department/{id}/edit',  [DepartmentAdminController::class, 'edit'])->name('dept.edit');
    Route::put('/department/{id}',        [DepartmentAdminController::class, 'update'])->name('dept.update');
    Route::delete('/department/{id}',       [DepartmentAdminController::class, 'destroy'])->name('dept.destroy');

    // เพิ่มสิทธิ์
    Route::get('/roles/create',      [RoleAdminController::class, 'create'])->name('roles.create');
    Route::post('/roles',             [RoleAdminController::class, 'store'])->name('roles.store');
    Route::get('/roles/{id}/edit', [RoleAdminController::class, 'edit'])->name('roles.edit');
    Route::put('/roles/{id}',       [RoleAdminController::class, 'update'])->name('roles.update');
    Route::delete('/roles/{id}',      [RoleAdminController::class, 'destroy'])->name('roles.destroy');

    // หัวหน้าแผนก
    Route::get('/department-managers',       [DeptManagerAdminController::class, 'index'])->name('deptmgr.index');
    Route::post('/department-managers',       [DeptManagerAdminController::class, 'store'])->name('deptmgr.store');
    Route::delete('/department-managers/{id}', [DeptManagerAdminController::class, 'destroy'])->name('deptmgr.destroy');

    // เพิ่มตำแหน่งในแผนก
    Route::get('/department-roles',                  [DepartmentRoleAdminController::class, 'index'])->name('deptroles.index');
    Route::post('/department-roles',                  [DepartmentRoleAdminController::class, 'store'])->name('deptroles.store');
    Route::get('/department-roles/{id}/edit',        [DepartmentRoleAdminController::class, 'edit'])->name('deptroles.edit');
    Route::put('/department-roles/{id}',              [DepartmentRoleAdminController::class, 'update'])->name('deptroles.update');
    Route::delete('/department-roles/{id}',             [DepartmentRoleAdminController::class, 'destroy'])->name('deptroles.destroy');

    // ผูก user กับตำแหน่ง
    Route::get('/user-department-roles',            [UserDeptRoleAdminController::class, 'index'])->name('udr.index');
    Route::post('/user-department-roles',            [UserDeptRoleAdminController::class, 'store'])->name('udr.store');
    Route::delete('/user-department-roles/{id}', [UserDeptRoleAdminController::class, 'destroy'])->name('udr.destroy');
    Route::get('/user-department-assignments', [UserDepartmentAssignmentController::class, 'index'])->name('user-department-assignments.index');
    Route::put('/user-department-assignments/{userId}', [UserDepartmentAssignmentController::class, 'update'])
      ->whereNumber('userId')
      ->name('user-department-assignments.update');

    // แก้ไขข้อมูลส่วนตัว
    Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile/update', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/profile/change-password', [ProfileController::class, 'changePassword'])->name('profile.change-password');
    Route::put('/profile/update-password', [ProfileController::class, 'updatePassword'])->name('profile.update-password');

    // เพิ่มสิทธิ์ให้ User
    Route::get('user-permissions', [UserPermissionController::class, 'index'])->name('user-permissions.index');
    Route::get('user-permissions/{user}/edit', [UserPermissionController::class, 'edit'])->name('user-permissions.edit');
    Route::put('user-permissions/{user}', [UserPermissionController::class, 'update'])->name('user-permissions.update');

    // ประวัติการใช้งาน (Activity Log)
    Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
  });


// API สำหรับ autocomplete
//Route::get('/api/users/search', [UserLookupController::class,'search'])->middleware('auth');
//Route::get('/api/departments/{department}/roles', [DepartmentRoleLookupController::class,'byDepartment'])->middleware('auth');
