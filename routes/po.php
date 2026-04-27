<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Po\PoController;
use App\Http\Controllers\Po\PoApprovalController;
use App\Http\Controllers\Po\PoLookupController;
use App\Http\Controllers\Po\PoExportController;

Route::middleware(['auth', 'can:PO'])
    ->prefix('po')
    ->name('po.')
    ->group(function () {
        // pages
        Route::get('/', [PoController::class, 'index'])->name('index');
        Route::get('/my-actions', [PoController::class, 'myActions'])->name('myActions');
        Route::get('/create', [PoController::class, 'create'])->name('create');
        Route::post('/', [PoController::class, 'store'])->name('store');
        Route::get('/{id}', [PoController::class, 'show'])->name('show');
        Route::get('/{id}/edit', [PoController::class, 'edit'])->name('edit');
        Route::put('/{id}', [PoController::class, 'update'])->name('update');

        // workflow actions
        Route::post('/submit-department', [PoApprovalController::class, 'submitDepartment'])->name('submitDepartment');
        Route::post('/remind-department-head', [PoApprovalController::class, 'remindDepartmentHead'])->name('remindDepartmentHead');
        Route::post('/notify-selected-step-three', [PoApprovalController::class, 'notifySelectedStepThree'])->name('notifySelectedStepThree');
        Route::post('/{id}/submit', [PoApprovalController::class, 'submit'])->name('submit');
        Route::post('/{id}/approve', [PoApprovalController::class, 'approve'])->name('approve');
        Route::post('/{id}/reject', [PoApprovalController::class, 'reject'])->name('reject');
        Route::post('/{id}/send-back', [PoApprovalController::class, 'sendBack'])->name('sendBack');
        Route::post('/{id}/cancel', [PoApprovalController::class, 'cancel'])->name('cancel');

        // lookups
        Route::get('/lookup/suppliers', [PoLookupController::class, 'suppliers'])->name('lookup.suppliers');
        Route::get('/lookup/items', [PoLookupController::class, 'items'])->name('lookup.items');
        Route::get('/lookup/units', [PoLookupController::class, 'units'])->name('lookup.units');

        // exports / print
        Route::get('/export/list', [PoExportController::class, 'list'])->name('export.list');
        Route::get('/print/department', [PoExportController::class, 'printDepartment'])->name('print.department');
        Route::get('/{id}/print', [PoExportController::class, 'print'])->name('print');
    });
