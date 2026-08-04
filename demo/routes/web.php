<?php

use App\Http\Controllers\ServiceNowTableController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ServiceNowTableController::class, 'index'])->name('tables.index');
Route::get('/tables/{table}', [ServiceNowTableController::class, 'show'])->name('tables.show');
Route::get('/tables/{table}/{sysId}', [ServiceNowTableController::class, 'record'])->name('tables.record');
