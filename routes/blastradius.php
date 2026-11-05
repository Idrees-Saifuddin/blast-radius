<?php

use BlastRadius\Laravel\Http\ReportController;
use BlastRadius\Laravel\Http\RequestsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ReportController::class, 'show'])->name('blastradius.report');
Route::get('/requests', [RequestsController::class, 'index'])->name('blastradius.requests');
Route::get('/requests/{id}', [RequestsController::class, 'show'])->whereNumber('id')->name('blastradius.request');
