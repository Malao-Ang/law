<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\MinioTestController;
use App\Http\Controllers\Api\EsignCallbackController;
use App\Http\Controllers\Api\EsignController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\DocumentFileController;
use App\Http\Controllers\Api\ImageController;
use App\Http\Controllers\Api\LawSearchController;
use App\Http\Controllers\Api\LawSuggestController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\PermissionDirectoryController;
use App\Http\Controllers\Api\PermissionGroupController;
use App\Http\Controllers\Api\PipelineCallbackController;
use App\Http\Controllers\Api\PdfExportController;
use App\Http\Controllers\Api\RelatedDocumentsZipController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\WordExportController;
use App\Http\Middleware\NotInProduction;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/lookups', LookupController::class);

Route::get('/auth/config', [AuthController::class, 'config']);
Route::post('/auth/dev-login', [AuthController::class, 'devLogin']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/auth/me', [AuthController::class, 'me']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::post('/auth/active-unit', [AuthController::class, 'activeUnit']);

// Dedicated unit master routes precede the generic single-sequence master controller.
foreach (['units', 'master-data/unit'] as $unitPrefix) {
    Route::prefix($unitPrefix)->controller(\App\Http\Controllers\Api\UnitController::class)->group(function (): void {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{code}', 'show');
        Route::put('/{code}', 'update');
        Route::patch('/{code}', 'update');
        Route::patch('/{code}/active', 'setActive');
        Route::delete('/{code}', 'destroy');
    });
}
Route::post('/units/sync-faculties', [\App\Http\Controllers\Api\UnitController::class, 'syncFaculties']);
Route::get('/units/{code}/members', [\App\Http\Controllers\Api\UnitController::class, 'members']);
Route::put('/units/{code}/members/{username}', [\App\Http\Controllers\Api\UnitController::class, 'updateMember']);
Route::delete('/units/{code}/members/{username}', [\App\Http\Controllers\Api\UnitController::class, 'removeMember']);
Route::get('/users/search', \App\Http\Controllers\Api\UserSearchController::class);

Route::get('/reports/summary', [ReportController::class, 'summary']);
Route::post('/laws/search', [LawSearchController::class, 'search']);
Route::get('/laws/facets', [LawSearchController::class, 'facets']);
Route::post('/laws/suggest', [LawSuggestController::class, 'suggest']);
Route::get('/permission-directory', PermissionDirectoryController::class)->middleware('auth.session');
Route::get('/permission-groups', [PermissionGroupController::class, 'index'])->middleware('auth.session');
Route::post('/permission-groups', [PermissionGroupController::class, 'store'])->middleware('auth.session');
Route::get('/permission-groups/{groupId}', [PermissionGroupController::class, 'show'])->middleware('auth.session');
Route::put('/permission-groups/{groupId}', [PermissionGroupController::class, 'update'])->middleware('auth.session');
Route::delete('/permission-groups/{groupId}', [PermissionGroupController::class, 'destroy'])->middleware('auth.session');
Route::prefix('master-data/{kind}')->group(function (): void {
    Route::get('/', [MasterDataController::class, 'index']);
    Route::post('/', [MasterDataController::class, 'store'])->middleware('auth.session');
    Route::patch('/reorder', [MasterDataController::class, 'reorder'])->middleware('auth.session');
    Route::get('/{code}', [MasterDataController::class, 'show']);
    Route::put('/{code}', [MasterDataController::class, 'update'])->middleware('auth.session');
    Route::patch('/{code}/active', [MasterDataController::class, 'setActive'])->middleware('auth.session');
    Route::delete('/{code}', [MasterDataController::class, 'destroy'])->middleware('auth.session');
});

Route::get('/documents', [UploadController::class, 'index'])->middleware('auth.session');
Route::post('/documents', [UploadController::class, 'store'])->middleware('auth.session');
Route::get('/documents/{documentId}', [UploadController::class, 'show']);
Route::post('/documents/{documentId}/transfer', [UploadController::class, 'transfer'])->middleware('auth.session');
Route::delete('/documents/{documentId}', [UploadController::class, 'destroy'])->middleware('auth.session');
Route::get('/documents/{documentId}/review', [ReviewController::class, 'show']);
Route::get('/documents/{documentId}/versions', [ReviewController::class, 'versions']);
Route::get('/documents/{documentId}/preview', [ReviewController::class, 'preview']);
Route::get('/documents/{documentId}/active-children', [ReviewController::class, 'activeChildren']);
Route::get('/documents/{documentId}/incoming-relations', [ReviewController::class, 'incomingRelations']);
Route::put('/documents/{documentId}/document-review', [ReviewController::class, 'updateDocumentReview'])->middleware('auth.session');
Route::patch('/documents/{documentId}/workflow-progress', [ReviewController::class, 'updateWorkflowProgress'])->middleware('auth.session');
Route::patch('/documents/{documentId}/rag-skip', [ReviewController::class, 'updateRagSkipped'])->middleware('auth.session');
Route::post('/documents/{documentId}/blocks/reorder', [ReviewController::class, 'reorderBlocks'])->middleware('auth.session');
Route::delete('/documents/{documentId}/blocks/{blockId}', [ReviewController::class, 'deleteBlock'])->middleware('auth.session');
Route::post('/documents/{documentId}/blocks/merge', [ReviewController::class, 'mergeBlocks'])->middleware('auth.session');
Route::post('/documents/{documentId}/blocks/restore', [ReviewController::class, 'restoreBlocks'])->middleware('auth.session');
Route::post('/documents/{documentId}/blocks/{blockId}/split', [ReviewController::class, 'splitBlock'])->middleware('auth.session');
Route::post('/documents/{documentId}/blocks', [ReviewController::class, 'createBlock'])->middleware('auth.session');
Route::patch('/documents/{documentId}/blocks/{blockId}', [ReviewController::class, 'update'])->middleware('auth.session');
Route::patch('/documents/{documentId}/blocks/{blockId}/layout', [ReviewController::class, 'updateLayout'])->middleware('auth.session');
Route::patch('/documents/{documentId}/blocks/{blockId}/size', [ReviewController::class, 'updateBlockSize'])->middleware('auth.session');
Route::post('/documents/{documentId}/blocks/{blockId}/reprocess', [ReviewController::class, 'reprocess'])->middleware('auth.session');
Route::post('/documents/{documentId}/pages/{pageNo}/reprocess', [ReviewController::class, 'reprocessPage'])->middleware('auth.session');
Route::post('/documents/{documentId}/export', [ExportController::class, 'store'])->middleware('auth.session');
Route::get('/documents/{documentId}/export-pdf/preview', [PdfExportController::class, 'preview']);
Route::post('/documents/{documentId}/export-pdf', [PdfExportController::class, 'store'])->middleware('auth.session');
Route::post('/documents/{documentId}/export-pdf-original', [\App\Http\Controllers\Api\OriginalPdfExportController::class, 'store'])->middleware('auth.session');
Route::post('/documents/{documentId}/export-word', [WordExportController::class, 'store'])->middleware('auth.session');
Route::post('/documents/{documentId}/esign/upload', [EsignController::class, 'upload'])->middleware('auth.session');
Route::post('/documents/{documentId}/esign/send', [EsignController::class, 'send'])->middleware('auth.session');
Route::post('/documents/{documentId}/esign/cancel', [EsignController::class, 'cancel'])->middleware('auth.session');
Route::get('/documents/{documentId}/esign/signed-pdf', [EsignController::class, 'signedPdf']);
Route::get('/documents/{documentId}/esign/preview-pdf', [EsignController::class, 'previewPdf']);
Route::get('/documents/{documentId}/file', [DocumentFileController::class, 'show']);
Route::get('/documents/{documentId}/related-download.zip', RelatedDocumentsZipController::class);
Route::get('/documents/{documentId}/related/{targetDocumentId}/file', [DocumentFileController::class, 'showRelated']);
Route::get('/documents/{documentId}/images/{filename}', [ImageController::class, 'show']);
Route::get('/documents/{documentId}/pages/{pageNo}/image', [ImageController::class, 'showPage']);

// MinIO test endpoints (dev/debug only) — 404 in production.
Route::middleware(NotInProduction::class)->prefix('test/minio')->group(function (): void {
    Route::get('/bucket', [MinioTestController::class, 'bucket']);
    Route::get('/', [MinioTestController::class, 'index']);
    Route::post('/upload', [MinioTestController::class, 'upload']);
    Route::post('/presign', [MinioTestController::class, 'presign']);
});

Route::post('/internal/pipeline-callback', [PipelineCallbackController::class, 'receive'])
    ->name('pipeline.callback');

Route::match(['GET', 'POST'], '/esign/callback/{documentId}', [EsignCallbackController::class, 'receive'])
    ->where('documentId', '[A-Za-z0-9_\-]+')
    ->name('esign.callback');
