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
use App\Http\Controllers\Admin\UserPermissionController;
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
//FormPKG
use App\Http\Controllers\FormPKG\PackagingUsageController;
use App\Http\Controllers\FormPKG\PackagingMasterController;
//FormExam
use App\Http\Controllers\FormExam\ExamController;
use App\Http\Controllers\FormExam\FormExamMasterController;
use App\Http\Controllers\FormExam\ExamImportController;
//LIS
use App\Http\Controllers\FormLIS\InquiryController;
//DP
use App\Http\Controllers\FormDP\DeliveryPlanController;
use App\Http\Controllers\FormDP\DeliveryPlanInquiryController;
//Forecast
use App\Http\Controllers\FormFC\ForecastRmController;
use App\Http\Controllers\FormFC\ForecastRmDivisionController;
use App\Http\Controllers\FormFC\PlannerPartMasterController;
use App\Http\Controllers\FormFC\PlannerForecastController;
use App\Http\Controllers\FormFC\DivisionPartMasterController;

//Rick
use App\Http\Controllers\FormRisk\ProductionRiskController;
//Autocomplete
use App\Http\Controllers\AutoComplete\DepartmentRoleLookupController;
use App\Http\Controllers\AutoComplete\UserLookupController;
use App\Http\Controllers\AutoComplete\PartnumberLookupController;
use App\Http\Controllers\AutoComplete\MfgLookupController;
//Weekly Order
use App\Http\Controllers\FormWOS\SalesInquiryController;
use App\Http\Controllers\FormWOS\SalesUnitSummaryController;
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
Route::get('/api/wr/items', [IncomeController::class, 'itemsSuggest'])->name('wr.autocomplete.items');
Route::get('/api/wr/po',    [IncomeController::class, 'poSuggest'])->name('wr.autocomplete.po');

Route::get('/dp/customer-lookup', [DeliveryPlanController::class, 'customerLookup'])
  ->name('dp.customerLookup');
Route::get('/dp/so-lookup', [DeliveryPlanController::class, 'soLookup'])->name('dp.soLookup');
Route::get('/dp/so-lines',  [DeliveryPlanController::class, 'soLines'])->name('dp.soLines');
Route::get('/dp/sales-lookup',    [DeliveryPlanController::class, 'salesLookup'])->name('dp.salesLookup');
Route::get('/dp/mfg-lookup', [DeliveryPlanController::class, 'mfgLookup'])->name('dp.mfgLookup');
Route::get('/dp/part-lookup', [DeliveryPlanController::class, 'partLookup'])->name('dp.partLookup');
//Inquiry

Route::get('/dp/line/{id}/history', [DeliveryPlanController::class, 'history'])
  ->name('dp.history');

Route::view('/home', 'home')->name('home');



Route::prefix('/wos')
  ->name('wos.')
  ->group(function () {
    Route::get('/sales-weekly', [SalesInquiryController::class, 'index'])->name('sales_weekly');
    Route::get('/sales-weekly/{salesId}', [SalesInquiryController::class, 'detail'])->name('sales_weekly.detail');
    Route::get('/sales-weekly-export', [SalesInquiryController::class, 'export'])
      ->name('sales_weekly.export');
  });

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');


Route::prefix('fc')
  ->name('fc.')
  ->group(function () {
    Route::get('/', [ForecastRmController::class, 'index'])->name('index');

    Route::get('/{sku}', [ForecastRmController::class, 'show'])
      ->where('sku', '[Rr][^/]+')
      ->name('show');

    Route::get('/input', [ForecastRmController::class, 'input'])->name('input');
    Route::post('/input/save', [ForecastRmController::class, 'saveInput'])->name('input.save');
    Route::post('/input/import', [ForecastRmController::class, 'importInput'])->name('input.import');

    Route::get('/division', [ForecastRmDivisionController::class, 'index'])->name('division');
    //Route::post('/division/generate', [ForecastRmDivisionController::class, 'generate'])->name('division.generate');
    Route::post('/division/manual-save', [ForecastRmDivisionController::class, 'saveManual'])->name('division.manual-save');
    Route::post('/division/generate-fg', [ForecastRmDivisionController::class, 'generateFgForecast'])->name('division.generate');
    Route::get('/division/so-detail', [ForecastRmDivisionController::class, 'soDetail'])->name('division.soDetail');
    Route::get('/division-part-master', [DivisionPartMasterController::class, 'index'])->name('divisionPartMaster.index');
    Route::post('/division-part-master/save', [DivisionPartMasterController::class, 'save'])->name('divisionPartMaster.save');

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


Route::prefix('pkg/packaging')
  ->name('pkg.packaging.')
  ->group(function () {
    Route::get('/usage', [PackagingUsageController::class, 'index'])->name('usage');

    Route::get('/usage/export', [PackagingUsageController::class, 'export'])->name('usage.export');
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

      Route::get('/customer-lookup', [DeliveryPlanController::class, 'customerLookup'])->name('customerLookup');
    });

    // ใช้ได้ทั้ง DP และ DPA
    Route::middleware(['permission.any:DP,DPA'])->group(function () {
      Route::get('/inquiry', [DeliveryPlanInquiryController::class, 'inquiry'])->name('inquiry');
      Route::get('/inquiry/{ordId}/history', [DeliveryPlanInquiryController::class, 'history'])
        ->whereNumber('ordId')
        ->name('inquiry.history');
      Route::get('/history/db/{ord_id}', [DeliveryPlanInquiryController::class, 'historyDb'])->name('history.db');
      Route::get('/inquiry/export', [DeliveryPlanInquiryController::class, 'export'])->name('inquiry.export');

      Route::post('/{ordId}/void', [DeliveryPlanInquiryController::class, 'voidPlan'])->name('void');
      Route::get('/truck-capacity', [DeliveryPlanInquiryController::class, 'truckCapacity'])->name('truck.capacity');
      Route::get('/truck-staff/options', [DeliveryPlanInquiryController::class, 'truckStaffOptions'])
        ->name('truck.staff.options');

      Route::get('/truck-staff/defaults', [DeliveryPlanInquiryController::class, 'truckStaffDefaults'])
        ->name('truck.staff.defaults');
    });

    Route::middleware(['can:DPEMAIL'])->group(function () {
      Route::post('/inquiry/send-plan-mail', [DeliveryPlanInquiryController::class, 'sendPlanMail'])
        ->name('inquiry.send-plan-mail');
    });

    // ใช้ได้เฉพาะ DPA
    Route::middleware(['can:DPA'])->group(function () {
      Route::post('/inquiry/truck-assign/{ordId}', [DeliveryPlanInquiryController::class, 'assignTruck'])
        ->name('inquiry.truck.assign');

      Route::get('/dashboard/truck-board', [DeliveryPlanInquiryController::class, 'truckBoard'])->name('dashboard.truck-board');
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
Route::middleware(['auth', 'can:WOCR'])
  ->prefix('wocr')
  ->name('wocr.')
  ->group(function () {
    Route::get('/index',   [OriginatorController::class, 'index'])->name('index');
    Route::post('/store',  [OriginatorController::class, 'store'])->name('store');

    //Route::get('/index',   [OriginatorController::class, 'index'])->name('index');
    Route::get('/planner/{id}/', [PlannerWOCRController::class, 'showPlanner'])->name('planner');
    Route::post('/planner/{id}',  [PlannerWOCRController::class, 'planner_action'])->name('planner_action');

    Route::get('/mng-planner/{id}/', [MngPlannerController::class, 'showPlanner'])->name('mng_planner');
    Route::post('/mng-planner/{id}',  [MngPlannerController::class, 'planner_action'])->name('mng_planner_action');

    Route::get('/revise/{id}/', [WocrReviseController::class, 'showRevise'])->name('revise');
    Route::post('/revise/{id}/', [WocrReviseController::class, 'originator_action'])->name('revise_action');

    Route::get('/view/{id}/', [WocrViewController::class, 'view'])->name('view');

    Route::get('/pending', [WocrDocboxController::class, 'pending'])->name('pending');
    Route::get('/mine',    [WocrDocboxController::class, 'mine'])->name('mine');
    Route::get('/all',     [WocrDocboxController::class, 'all'])->name('all');

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

    // เพิ่มสมาชิก
    Route::get('/users/register',  [UserAdminController::class, 'index'])->name('users.register');
    Route::post('/users',           [UserAdminController::class, 'store'])->name('users.store');
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

    // แก้ไขข้อมูลส่วนตัว
    Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile/update', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/profile/change-password', [ProfileController::class, 'changePassword'])->name('profile.change-password');
    Route::put('/profile/update-password', [ProfileController::class, 'updatePassword'])->name('profile.update-password');

    // เพิ่มสิทธิ์ให้ User
    Route::get('user-permissions', [UserPermissionController::class, 'index'])->name('user-permissions.index');
    Route::get('user-permissions/{user}/edit', [UserPermissionController::class, 'edit'])->name('user-permissions.edit');
    Route::put('user-permissions/{user}', [UserPermissionController::class, 'update'])->name('user-permissions.update');
  });


// API สำหรับ autocomplete
//Route::get('/api/users/search', [UserLookupController::class,'search'])->middleware('auth');
//Route::get('/api/departments/{department}/roles', [DepartmentRoleLookupController::class,'byDepartment'])->middleware('auth');
