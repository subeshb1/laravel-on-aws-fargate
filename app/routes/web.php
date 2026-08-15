<?php

use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StatusController::class, 'index']);
Route::get('/status.json', [StatusController::class, 'json']);
Route::post('/reports', [StatusController::class, 'dispatchReport']);
Route::get('/reports/{report}/download', [StatusController::class, 'downloadReport']);
