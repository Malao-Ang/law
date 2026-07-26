<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'laravel-monolith',
    'time' => now()->toIso8601String(),
]));

Route::view('/', 'app');
Route::view('/database', 'app');
Route::view('/admin', 'app');
Route::view('/admin/laws', 'app');
Route::view('/admin/upload', 'app');
Route::view('/admin/ocr-queue', 'app');
Route::view('/admin/relations', 'app');
Route::view('/upload', 'app');
Route::view('/documents/{documentId}/review', 'app')->where('documentId', '[A-Za-z0-9_\-]+');
Route::view('/documents/{documentId}/compose', 'app')->where('documentId', '[A-Za-z0-9_\-]+');
Route::view('/documents/{documentId}/preview', 'app')->where('documentId', '[A-Za-z0-9_\-]+');
Route::view('/documents/{documentId}/rag', 'app')->where('documentId', '[A-Za-z0-9_\-]+');
Route::view('/documents/{documentId}/result', 'app')->where('documentId', '[A-Za-z0-9_\-]+');
Route::view('/documents/{documentId}/permissions', 'app')->where('documentId', '[A-Za-z0-9_\-]+');
Route::view('/documents/{documentId}/law-info', 'app')->where('documentId', '[A-Za-z0-9_\-]+');
Route::view('/documents/{documentId}/relations', 'app')->where('documentId', '[A-Za-z0-9_\-]+');
Route::view('/law/{documentId}', 'app')->where('documentId', '[A-Za-z0-9_\-]+');
