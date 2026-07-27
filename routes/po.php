<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Po\PoController;
use App\Http\Controllers\Po\PoApprovalController;
use App\Http\Controllers\Po\PoLookupController;
use App\Http\Controllers\Po\PoExportController;

Route::middleware(['auth', 'permission.any:PO,POPUR'])
    ->prefix('po')
    ->name('po.')
    ->group(function () {
        Route::get('/', [PoController::class, 'index'])->name('index');
        Route::get('/my-actions', [PoController::class, 'myActions'])->name('myActions');

        Route::middleware('permission.any:POPUR')->group(function () {
            Route::get('/create', [PoController::class, 'create'])->name('create');
            Route::post('/', [PoController::class, 'store'])->name('store');

            Route::post('/erp-connect', [PoExportController::class, 'connectErp'])->name('erp.connect');
            Route::post('/erp-session', [PoExportController::class, 'saveErpSession'])->name('erpSession.save');
            Route::delete('/erp-session', [PoExportController::class, 'clearErpSession'])->name('erpSession.clear');

            Route::post('/submit-department', [PoApprovalController::class, 'submitDepartment'])->name('submitDepartment');
            Route::post('/remind-department-head', [PoApprovalController::class, 'remindDepartmentHead'])->name('remindDepartmentHead');
            Route::post('/notify-selected-step-three', [PoApprovalController::class, 'notifySelectedStepThree'])->name('notifySelectedStepThree');
            Route::post('/{id}/submit', [PoApprovalController::class, 'submit'])->name('submit');
            Route::post('/{id}/reopen', [PoApprovalController::class, 'reopen'])->name('reopen');
            Route::post('/{id}/cancel', [PoApprovalController::class, 'cancel'])->name('cancel');

            Route::get('/{id}/edit', [PoController::class, 'edit'])->name('edit');
            Route::put('/{id}', [PoController::class, 'update'])->name('update');

            Route::get('/lookup/suppliers', [PoLookupController::class, 'suppliers'])->name('lookup.suppliers');
            Route::get('/lookup/items', [PoLookupController::class, 'items'])->name('lookup.items');
            Route::get('/lookup/units', [PoLookupController::class, 'units'])->name('lookup.units');

            Route::get('/export/list', [PoExportController::class, 'list'])->name('export.list');
            Route::post('/print/selected', [PoExportController::class, 'printSelected'])->name('print.selected');
            Route::get('/print/department', [PoExportController::class, 'printDepartment'])->name('print.department');
        });

        Route::get('/{id}/attachments/{attachmentId}', [PoController::class, 'attachment'])->name('attachments.show');
        Route::post('/{id}/approve', [PoApprovalController::class, 'approve'])->name('approve');
        Route::post('/{id}/reject', [PoApprovalController::class, 'reject'])->name('reject');
        Route::post('/{id}/send-back', [PoApprovalController::class, 'sendBack'])->name('sendBack');
        Route::get('/{id}/print', [PoExportController::class, 'print'])->name('print');
        Route::get('/{id}', [PoController::class, 'show'])->name('show');
    });
