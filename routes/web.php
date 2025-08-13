<?php

use App\Http\Controllers\User\ProfileController;
use App\Http\Controllers\User\AvatarController;
use App\Http\Controllers\User\ContactController;
use App\Http\Controllers\User\ConversationController;
use App\Http\Controllers\User\MessageController;
use App\Http\Controllers\User\AttachmentController;
use App\Http\Controllers\User\ReadReceiptController;
use App\Http\Controllers\User\TypingController;
use App\Http\Controllers\User\UserController;
use App\Http\Controllers\Broadcasting\UserAuthController;
use App\Events\TestPusherPing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// TEMP: Public test route to verify broadcasting without auth
Route::get('/test-broadcast', function (Request $request) {
    $msg = $request->query('msg', 'ping');
    event(new TestPusherPing($msg));
    return response()->json(['ok' => true, 'message' => $msg]);
});

Route::middleware('auth')->group(function () {
    // Chat UI
    Route::get('/chat', function () { return view('chat.index'); })->name('chat.index');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Avatar management
    Route::patch('/profile/avatar', [AvatarController::class, 'update'])->name('user.avatar.update');
    Route::delete('/profile/avatar', [AvatarController::class, 'destroy'])->name('user.avatar.destroy');

    // Users
    Route::get('/users/search', [UserController::class, 'search'])->name('users.search');

    // Contacts
    Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::delete('/contacts/{contactUser}', [ContactController::class, 'destroy'])->name('contacts.destroy');

    // Conversations (dialogs)
    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::post('/conversations', [ConversationController::class, 'store'])->name('conversations.store');
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');

    // Messages
    Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::post('/messages', [MessageController::class, 'store'])->name('messages.store');
    Route::patch('/messages/{message}', [MessageController::class, 'update'])->name('messages.update');
    Route::delete('/messages/{message}', [MessageController::class, 'destroy'])->name('messages.destroy');

    // Attachments
    Route::post('/conversations/{conversation}/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('/attachments/{attachment}/inline', [AttachmentController::class, 'inline'])->name('attachments.inline');
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])->name('attachments.download');

    // Read receipts
    Route::post('/conversations/{conversation}/read', [ReadReceiptController::class, 'read'])->name('conversations.read');

    // Typing indicator
    Route::post('/conversations/{conversation}/typing/start', [TypingController::class, 'start'])->name('typing.start');
    Route::post('/conversations/{conversation}/typing/stop', [TypingController::class, 'stop'])->name('typing.stop');

    // Pusher user authentication (Watchlist)
    Route::post('/pusher/user-auth', UserAuthController::class)->name('pusher.user-auth');
    // Some clients may default to this path; map to the same controller
    Route::post('/broadcasting/user-auth', UserAuthController::class)->name('pusher.user-auth.alias');
});

require __DIR__.'/auth.php';
