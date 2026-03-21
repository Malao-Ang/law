<?php

use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::post('/documents', [UploadController::class, 'store']);
Route::get('/documents/{documentId}', [UploadController::class, 'show']);
Route::get('/documents/{documentId}/review', [ReviewController::class, 'show']);
Route::patch('/documents/{documentId}/blocks/{blockId}', [ReviewController::class, 'update']);
Route::post('/documents/{documentId}/blocks/{blockId}/reprocess', [ReviewController::class, 'reprocess']);
Route::post('/documents/{documentId}/export', [ExportController::class, 'store']);
