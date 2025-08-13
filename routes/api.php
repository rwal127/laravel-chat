<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\User\MessageController;
use App\Http\Controllers\User\AttachmentController;
use App\Http\Controllers\User\ConversationController;

Route::prefix('v1')->group(function () {
    // Public auth endpoints
    Route::post('/auth/login', [AuthController::class, 'login'])->name('api.auth.login');

    // Protected endpoints via Sanctum bearer tokens
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

        // Conversations
        Route::get('/conversations', [ConversationController::class, 'index'])->name('api.conversations.index');
        Route::post('/conversations', [ConversationController::class, 'store'])->name('api.conversations.store');
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('api.conversations.show');

        // Messages
        Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index'])->name('api.messages.index');
        Route::post('/messages', [MessageController::class, 'store'])->name('api.messages.store');
        Route::patch('/messages/{message}', [MessageController::class, 'update'])
            ->middleware('can:update,message')
            ->name('api.messages.update');
        Route::delete('/messages/{message}', [MessageController::class, 'destroy'])
            ->middleware('can:delete,message')
            ->name('api.messages.destroy');

        // Attachments (private media)
        Route::get('/attachments/{attachment}/inline', [AttachmentController::class, 'inline'])
            ->middleware('can:view,attachment')
            ->name('api.attachments.inline');
        Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
            ->middleware('can:view,attachment')
            ->name('api.attachments.download');
    });
});
