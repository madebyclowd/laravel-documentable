<?php

use Illuminate\Support\Facades\Route;
use MadeByClowd\Documentable\Http\Controllers\DirectUploadController;
use MadeByClowd\Documentable\Http\Controllers\DocumentController;
use MadeByClowd\Documentable\Http\Controllers\MultipartUploadController;

/*
|--------------------------------------------------------------------------
| Documentable Routes
|--------------------------------------------------------------------------
|
| Loaded only when config('documentable.load_routes') is true. Middleware
| stack is config('documentable.middleware') (default ['api'] — no
| session/auth, see that config key's comment for the failure mode) plus a
| throttle behind the named limiter in config('documentable.throttle') —
| define it with RateLimiter::for() in your own AppServiceProvider; the
| package doesn't hardcode a rate. Disable this file entirely and mount
| these controllers yourself for full control over prefix/middleware/guard.
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
        Route::post('/detached', [DocumentController::class, 'storeDetached']);
        Route::get('/{document}/url', [DocumentController::class, 'url']);
        Route::delete('/{document}', [DocumentController::class, 'destroy']);

        Route::post('/presigned', [DirectUploadController::class, 'presign']);
        Route::post('/presigned/finalize', [DirectUploadController::class, 'finalize']);

        Route::post('/multipart/initiate', [MultipartUploadController::class, 'initiate']);
        Route::post('/multipart/part-url', [MultipartUploadController::class, 'partUrl']);
        Route::post('/multipart/complete', [MultipartUploadController::class, 'complete']);
        Route::post('/multipart/abort', [MultipartUploadController::class, 'abort']);
    });
