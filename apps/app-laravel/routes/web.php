<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'laravel-monolith',
    'time' => now()->toIso8601String(),
]));

Route::view('/', 'app');
Route::view('/documents/{documentId}/review', 'app')->where('documentId', '[A-Za-z0-9_\-]+');
