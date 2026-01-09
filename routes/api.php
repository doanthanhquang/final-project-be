<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Authentication routes
Route::post('/register', [UserController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/google-signin', [GoogleAuthController::class, 'googleSignIn']);
Route::post('/refresh', [AuthController::class, 'refresh']);

// Protected routes
Route::middleware(['bearer.auth'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Email routes (mock API)
    // Route::get('/mailboxes', [EmailController::class, 'getMailboxes']);
    // Route::get('/mailboxes/{mailboxId}/emails', [EmailController::class, 'getEmails']);
    // Route::get('/emails/{emailId}', [EmailController::class, 'getEmailDetail']);
});
