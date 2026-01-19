<?php

namespace App\Http\Controllers;

use App\Models\EmailProvider;
use App\Services\GmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmailController extends Controller
{
    public function __construct(
        private GmailService $gmailService
    ) {}

    /**
     * Get active email provider for authenticated user.
     */
    private function getActiveProvider(Request $request): ?EmailProvider
    {
        $user = $request->attributes->get('auth_user');
        if (! $user) {
            return null;
        }

        return EmailProvider::where('user_id', $user->id)
            ->where('connected', true)
            ->where('provider_type', 'gmail')
            ->first();
    }

    /**
     * GET /api/mailboxes
     */
    public function getMailboxes(Request $request)
    {
        $provider = $this->getActiveProvider($request);
        if (! $provider) {
            return response()->json([
                'success' => false,
                'message' => 'No email provider connected',
            ], 400);
        }

        try {
            $mailboxes = $this->gmailService->getMailboxes($provider);

            return response()->json([
                'success' => true,
                'data' => $mailboxes,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch mailboxes: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/mailboxes/:id/emails
     */
    public function getEmails(Request $request, string $mailboxId)
    {
        $provider = $this->getActiveProvider($request);
        if (! $provider) {
            return response()->json([
                'success' => false,
                'message' => 'No email provider connected',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:100',
            'unread_only' => 'nullable|boolean',
            'has_attachments' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $page = $request->input('page', 1);
            $limit = $request->input('limit', 50);
            $filters = $request->only(['unread_only', 'has_attachments']);
            $emails = $this->gmailService->getEmails($provider, $mailboxId, $page, $limit, $filters);

            return response()->json([
                'success' => true,
                'data' => $emails->items(),
                'pagination' => [
                    'current_page' => $emails->currentPage(),
                    'per_page' => $emails->perPage(),
                    'total' => $emails->total(),
                    'last_page' => $emails->lastPage(),
                    'has_more' => $emails->hasMorePages(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch emails: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/emails/:id
     */
    public function getEmailDetail(Request $request, string $emailId)
    {
        $provider = $this->getActiveProvider($request);
        if (! $provider) {
            return response()->json([
                'success' => false,
                'message' => 'No email provider connected',
            ], 400);
        }

        try {
            $email = $this->gmailService->getEmailDetail($provider, $emailId);

            return response()->json([
                'success' => true,
                'data' => $email,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch email: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/emails/send
     */
    public function sendEmail(Request $request)
    {
        $provider = $this->getActiveProvider($request);
        if (! $provider) {
            return response()->json([
                'success' => false,
                'message' => 'No email provider connected',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'to' => 'required|array|min:1',
            'to.*' => 'required|string',
            'subject' => 'required|string',
            'body' => 'nullable|string',
            'cc' => 'nullable|array',
            'cc.*' => 'required|string',
            'bcc' => 'nullable|array',
            'bcc.*' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = [
                'to' => $request->input('to', []),
                'subject' => $request->input('subject'),
                'body' => $request->input('body', ''),
            ];

            if ($request->filled('cc')) {
                $data['cc'] = $request->input('cc');
            }
            if ($request->filled('bcc')) {
                $data['bcc'] = $request->input('bcc');
            }

            $messageId = $this->gmailService->sendEmail($provider, $data);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $messageId,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send email: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/emails/:id/reply
     */
    public function replyEmail(Request $request, string $emailId)
    {
        $provider = $this->getActiveProvider($request);
        if (! $provider) {
            return response()->json([
                'success' => false,
                'message' => 'No email provider connected',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'body' => 'required|string',
            'subject' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $messageId = $this->gmailService->replyEmail(
                $provider,
                $emailId,
                $request->input('body'),
                $request->input('subject')
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $messageId,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reply to email: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/emails/:id/forward
     */
    public function forwardEmail(Request $request, string $emailId)
    {
        $provider = $this->getActiveProvider($request);
        if (! $provider) {
            return response()->json([
                'success' => false,
                'message' => 'No email provider connected',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'to' => 'required|array|min:1',
            'to.*' => 'required|string',
            'message' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $messageId = $this->gmailService->forwardEmail(
                $provider,
                $emailId,
                $request->input('to', []),
                $request->input('message')
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $messageId,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to forward email: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/emails/:id/modify
     */
    public function modifyEmail(Request $request, string $emailId)
    {
        $provider = $this->getActiveProvider($request);
        if (! $provider) {
            return response()->json([
                'success' => false,
                'message' => 'No email provider connected',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'read' => 'nullable|boolean',
            'starred' => 'nullable|boolean',
            'delete' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $this->gmailService->modifyEmail($provider, $emailId, $request->only(['read', 'starred', 'delete']));

            return response()->json([
                'success' => true,
                'message' => 'Email updated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update email: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/attachments/:emailId/:attachmentId
     */
    public function getAttachment(Request $request, string $emailId, string $attachmentId)
    {
        $provider = $this->getActiveProvider($request);
        if (! $provider) {
            return response()->json([
                'success' => false,
                'message' => 'No email provider connected',
            ], 400);
        }

        try {
            $attachment = $this->gmailService->getAttachment($provider, $emailId, $attachmentId);
            // Gmail uses URL-safe base64 encoding, normalize before decoding
            $rawData = str_replace(['-', '_'], ['+', '/'], $attachment['content']);
            $content = base64_decode($rawData);

            return response($content)
                ->header('Content-Type', $attachment['mime_type'])
                ->header('Content-Disposition', 'attachment; filename="'.$attachment['filename'].'"')
                ->header('Content-Length', strlen($content));
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch attachment: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/emails/search
     */
    public function searchEmails(Request $request)
    {
        $provider = $this->getActiveProvider($request);
        if (! $provider) {
            return response()->json([
                'success' => false,
                'message' => 'No email provider connected',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:1',
            'unread_only' => 'nullable|boolean',
            'has_attachments' => 'nullable|boolean',
            'fuzzy' => 'nullable|boolean',
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }
        try {
            $page = $request->input('page', 1);
            $limit = $request->input('limit', 50);
            $fuzzy = $request->input('fuzzy', false);
            $filters = $request->only(['unread_only', 'has_attachments']);

            $emails = $this->gmailService->searchEmails($provider, $request->input('query'), $filters, $page, $limit, $fuzzy);

            return response()->json([
                'success' => true,
                'data' => $emails->items(),
                'pagination' => [
                    'current_page' => $emails->currentPage(),
                    'per_page' => $emails->perPage(),
                    'total' => $emails->total(),
                    'last_page' => $emails->lastPage(),
                    'has_more' => $emails->hasMorePages(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to search emails: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/email-provider/connect
     */
    public function connectProvider(Request $request)
    {
        // For Gmail, connection is handled via OAuth flow
        // This endpoint can be used for IMAP in the future
        return response()->json([
            'success' => false,
            'message' => 'Use OAuth flow to connect Gmail account',
        ], 400);
    }

    /**
     * GET /api/email-provider/status
     */
    public function getProviderStatus(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $provider = EmailProvider::where('user_id', $user->id)
            ->where('connected', true)
            ->first();

        return response()->json([
            'success' => true,
            'connected' => $provider !== null,
            'provider_type' => $provider?->provider_type,
            'connected_at' => $provider?->connected_at?->toIso8601String(),
        ]);
    }
}
