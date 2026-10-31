<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MadeByClowd\Documentable\Http\Controllers\DedupHandshakeController;
use MadeByClowd\Documentable\Http\Controllers\DirectUploadController;
use MadeByClowd\Documentable\Http\Controllers\DocumentController;
use MadeByClowd\Documentable\Http\Controllers\DocumentTypeController;
use MadeByClowd\Documentable\Http\Controllers\MultipartUploadController;

/*
|--------------------------------------------------------------------------
| Documentable Routes
|--------------------------------------------------------------------------
|
| Loaded only when config('documentable.load_routes') is true. Middleware
| stack is config('documentable.middleware') (default ['api'] — no
| session/auth, see that config key's comment for the failure mode) plus a
| throttle behind the named limiter in config('documentable.throttle'). When
| your app defines no limiter of that name the package registers one at 60
| requests a minute per user or IP; define your own with RateLimiter::for()
| in your AppServiceProvider to change it. Disable this file entirely and
| mount these controllers yourself for full control over prefix/middleware/guard.
|
*/
Route::prefix('documents')
    ->middleware([
        ...config('documentable.middleware', ['api']),
        'throttle:'.config('documentable.throttle', 'documents'),
    ])
    ->group(function () {
        Route::get('/', [DocumentController::class, 'index']);
        Route::post('/', [DocumentController::class, 'store']);
        Route::get('/types', [DocumentTypeController::class, 'index']);
        Route::get('/{document}/url', [DocumentController::class, 'url']);
        Route::get('/{document}/download', [DocumentController::class, 'download']);
        Route::get('/{document}/view', [DocumentController::class, 'view']);
        Route::delete('/{document}', [DocumentController::class, 'destroy']);

        Route::post('/presigned', [DirectUploadController::class, 'presign']);
        Route::post('/presigned/finalize', [DirectUploadController::class, 'finalize']);
        Route::post('/attach', [DirectUploadController::class, 'attach']);

        Route::post('/handshake', [DedupHandshakeController::class, 'handshake'])
            ->middleware('throttle:'.config('documentable.dedup.throttle', 'documents-handshake'));
        Route::post('/handshake/answer', [DedupHandshakeController::class, 'answer'])
            ->middleware('throttle:'.config('documentable.dedup.throttle', 'documents-handshake'));

        Route::post('/multipart/initiate', [MultipartUploadController::class, 'initiate']);
        Route::post('/multipart/part-url', [MultipartUploadController::class, 'partUrl']);
        Route::get('/multipart/parts', [MultipartUploadController::class, 'listParts']);
        Route::get('/multipart/status', [MultipartUploadController::class, 'status']);
        Route::post('/multipart/complete', [MultipartUploadController::class, 'complete']);
        Route::post('/multipart/abort', [MultipartUploadController::class, 'abort']);
    });
