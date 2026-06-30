<?php

use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ImageController;
use App\Http\Controllers\Api\PipelineCallbackController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::get('/documents', [UploadController::class, 'index']);
Route::post('/documents', [UploadController::class, 'store']);
Route::get('/documents/{documentId}', [UploadController::class, 'show']);
Route::get('/documents/{documentId}/review', [ReviewController::class, 'show']);
Route::get('/documents/{documentId}/preview', [ReviewController::class, 'preview']);
Route::put('/documents/{documentId}/document-review', [ReviewController::class, 'updateDocumentReview']);
Route::post('/documents/{documentId}/blocks/reorder', [ReviewController::class, 'reorderBlocks']);
Route::delete('/documents/{documentId}/blocks/{blockId}', [ReviewController::class, 'deleteBlock']);
Route::post('/documents/{documentId}/blocks/merge', [ReviewController::class, 'mergeBlocks']);
Route::post('/documents/{documentId}/blocks/{blockId}/split', [ReviewController::class, 'splitBlock']);
Route::post('/documents/{documentId}/blocks', [ReviewController::class, 'createBlock']);
Route::patch('/documents/{documentId}/blocks/{blockId}', [ReviewController::class, 'update']);
Route::patch('/documents/{documentId}/blocks/{blockId}/layout', [ReviewController::class, 'updateLayout']);
Route::patch('/documents/{documentId}/blocks/{blockId}/size', [ReviewController::class, 'updateBlockSize']);
Route::post('/documents/{documentId}/blocks/{blockId}/reprocess', [ReviewController::class, 'reprocess']);
Route::post('/documents/{documentId}/pages/{pageNo}/reprocess', [ReviewController::class, 'reprocessPage']);
Route::post('/documents/{documentId}/export', [ExportController::class, 'store']);
Route::post('/documents/{documentId}/retry-correction', [ExportController::class, 'retryCorrection']);
Route::get('/documents/{documentId}/images/{filename}', [ImageController::class, 'show']);
Route::get('/documents/{documentId}/pages/{pageNo}/image', [ImageController::class, 'showPage']);

Route::post('/internal/pipeline-callback', [PipelineCallbackController::class, 'receive'])
    ->name('pipeline.callback');
