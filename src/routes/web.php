<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WordController;

Route::get('/', [WordController::class, 'index']);
Route::post('/convert', [WordController::class, 'convert']);
Route::post('/store', [WordController::class, 'store']);