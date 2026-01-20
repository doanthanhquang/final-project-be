<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\KanbanConfigController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SemanticSearchController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkflowController;
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
    Route::get('/search/fuzzy', [EmailController::class, 'searchEmails']);
    Route::get('/search/suggestions', [SearchController::class, 'getSuggestions'])->middleware('long.timeout:300');
    Route::post('/search/semantic', [SemanticSearchController::class, 'search']);

    // Email provider management
    Route::post('/email-provider/connect', [EmailController::class, 'connectProvider']);

    // Workflow management routes
    Route::get('/workflow/states', [WorkflowController::class, 'getWorkflowStates']);
    Route::post('/workflow/states/{emailId}', [WorkflowController::class, 'updateWorkflowState']);
    Route::post('/workflow/initialize/{emailId}', [WorkflowController::class, 'initializeEmail']);
    Route::post('/workflow/snooze/{emailId}', [WorkflowController::class, 'snoozeEmail']);
    Route::post('/workflow/unsnooze/{emailId}', [WorkflowController::class, 'unsnoozeEmail']);
    Route::get('/emails/{emailId}/summary', [WorkflowController::class, 'getEmailSummary']);

    // Kanban configuration routes
    Route::get('/kanban/columns', [KanbanConfigController::class, 'getColumns']);
    Route::post('/kanban/columns', [KanbanConfigController::class, 'createColumn']);
    Route::put('/kanban/columns/{columnId}', [KanbanConfigController::class, 'updateColumn']);
    Route::delete('/kanban/columns/{columnId}', [KanbanConfigController::class, 'deleteColumn']);
    Route::post('/kanban/columns/reorder', [KanbanConfigController::class, 'reorderColumns']);
    Route::get('/kanban/gmail-labels', [KanbanConfigController::class, 'getGmailLabels']);
});
