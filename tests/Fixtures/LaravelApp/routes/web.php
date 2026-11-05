<?php

use Fixture\App\Http\Controllers\PingController;
use Fixture\App\Http\Controllers\PurchaseController;
use Fixture\App\Services\NullStockGateway;
use Fixture\App\Services\PurchaseService;
use Illuminate\Support\Facades\Route;

Route::post('/api/purchases', [PurchaseController::class, 'store']);
Route::put('/api/purchases/{id}', [PurchaseController::class, 'update']);
Route::get('/ping', PingController::class);
Route::get('/closure', function () {
    $service = new PurchaseService(new NullStockGateway);
    $service->updateStock(2);
});
