<?php

use App\Http\Controllers\User\ProfileController;
use App\Http\Controllers\User\AvatarController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Avatar management
    Route::patch('/profile/avatar', [AvatarController::class, 'update'])->name('user.avatar.update');
    Route::delete('/profile/avatar', [AvatarController::class, 'destroy'])->name('user.avatar.destroy');
});

require __DIR__.'/auth.php';
