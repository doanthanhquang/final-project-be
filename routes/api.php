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
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']); // OAuth callback (no auth required)
Route::post('/refresh', [AuthController::class, 'refresh']);

// Protected routes
Route::middleware(['bearer.auth'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/email-provider/status', [EmailController::class, 'getProviderStatus']);

    // Gmail OAuth routes (for connecting email provider after sign-in)
    Route::get('/auth/google/authorize', [GoogleAuthController::class, 'authorize']);
    Route::post('/auth/google/disconnect', [GoogleAuthController::class, 'disconnect']);

    // Email routes
    Route::get('/mailboxes', [EmailController::class, 'getMailboxes']);
    Route::get('/mailboxes/{mailboxId}/emails', [EmailController::class, 'getEmails']);
    Route::get('/emails/{emailId}', [EmailController::class, 'getEmailDetail']);
    Route::post('/emails/send', [EmailController::class, 'sendEmail']);
    Route::post('/emails/{emailId}/reply', [EmailController::class, 'replyEmail']);
    Route::post('/emails/{emailId}/forward', [EmailController::class, 'forwardEmail']);
    Route::post('/emails/{emailId}/modify', [EmailController::class, 'modifyEmail']);
    Route::get('/emails/{emailId}/attachments/{attachmentId}', [EmailController::class, 'getAttachment']);
    Route::get('/emails/search', [EmailController::class, 'searchEmails']);

    // Email provider management
    Route::post('/email-provider/connect', [EmailController::class, 'connectProvider']);
    Route::post('/email-provider/disconnect', [EmailController::class, 'disconnectProvider']);
});
