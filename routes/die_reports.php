<?php

use App\Http\Controllers\FormDIE\DieDamageController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('die-reports')->name('die-reports.')->group(function () {
    Route::get('/', [DieDamageController::class, 'index'])->name('index');
    Route::get('/reports', [DieDamageController::class, 'reports'])->name('reports');
    Route::get('/export', [DieDamageController::class, 'export'])->name('export');
    Route::get('/create', [DieDamageController::class, 'create'])->name('create');
    Route::post('/lookup', [DieDamageController::class, 'lookup'])->middleware('throttle:20,1')->name('lookup');
    Route::post('/', [DieDamageController::class, 'store'])->name('store');
    Route::get('/{damage}/edit', [DieDamageController::class, 'edit'])->name('edit');
    Route::put('/{damage}', [DieDamageController::class, 'update'])->name('update');
    Route::delete('/{damage}', [DieDamageController::class, 'destroy'])->name('destroy');
});
