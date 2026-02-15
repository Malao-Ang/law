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