<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WordController;

Route::get('/', [WordController::class, 'index']);

// Document conversion routes
Route::post('/convert', [WordController::class, 'convert']);
Route::post('/store', [WordController::class, 'store']);

// Document management routes for CKEditor 5
Route::get('/documents/{id}', function ($id) {
    $document = \App\Models\Document::findOrFail($id);
    return response()->json($document);
});

Route::put('/documents/{id}', function (\Illuminate\Http\Request $request, $id) {
    $document = \App\Models\Document::findOrFail($id);
    
    $validated = $request->validate([
        'title' => 'required|string|max:255',
        'content' => 'required|string'
    ]);
    
    $document->update($validated);
    
    return response()->json([
        'message' => 'Document updated successfully',
        'document' => $document
    ]);
});

// Async document upload and conversion
Route::post('/upload', [WordController::class, 'upload']);

// Regulations routes
Route::prefix('regulations')->group(function () {
    Route::get('/', [\App\Http\Controllers\RegulationController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\RegulationController::class, 'store']);
    Route::get('/search', [\App\Http\Controllers\RegulationController::class, 'search']);
    Route::get('/{id}', [\App\Http\Controllers\RegulationController::class, 'show']);
    Route::put('/{id}', [\App\Http\Controllers\RegulationController::class, 'update']);
    Route::delete('/{id}', [\App\Http\Controllers\RegulationController::class, 'destroy']);
    Route::get('/{id}/sections', [\App\Http\Controllers\RegulationController::class, 'getSections']);
    Route::put('/sections/{sectionId}', [\App\Http\Controllers\RegulationController::class, 'updateSection']);
});