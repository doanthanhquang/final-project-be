<?php

namespace App\Services;

use App\Models\EmailProvider;
use Google\Client;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class GmailService implements EmailServiceInterface
{
    private ?Gmail $gmailClient = null;

    /**
     * Get or create Gmail client for the provider.
     */
    private function getGmailClient(EmailProvider $provider): Gmail
    {
        if ($this->gmailClient !== null) {
            return $this->gmailClient;
        }

        // Refresh token if needed
        $this->refreshTokenIfNeeded($provider);

        $client = new Client;
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        // Set access token
        if ($provider->access_token) {
            $client->setAccessToken($provider->access_token);
        }

        // Set refresh token for token refresh
        if ($provider->refresh_token) {
            $client->refreshToken($provider->refresh_token);
        }

        $this->gmailClient = new Gmail($client);

        return $this->gmailClient;
    }

    public function connect(EmailProvider $provider, array $credentials): bool
    {
        // For Gmail, connection is handled via OAuth callback
        // This method is called after OAuth tokens are obtained
        $provider->refresh_token = $credentials['refresh_token'] ?? null;
        $provider->access_token = $credentials['access_token'] ?? null;
        $provider->access_token_expires_at = isset($credentials['expires_in'])
            ? now()->addSeconds($credentials['expires_in'])
            : now()->addHour();
        $provider->connected = true;
        $provider->connected_at = now();
        $provider->save();

        return true;
    }

    public function disconnect(EmailProvider $provider): bool
    {
        try {
            // Revoke token with Google
            if ($provider->refresh_token) {
                $client = new Client;
                $client->setClientId(config('services.google.client_id'));
                $client->setClientSecret(config('services.google.client_secret'));
                $client->revokeToken($provider->refresh_token);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to revoke Gmail token', ['error' => $e->getMessage()]);
        }

        $provider->refresh_token = null;
        $provider->access_token = null;
        $provider->access_token_expires_at = null;
        $provider->connected = false;
        $provider->save();

        return true;
    }

    public function getMailboxes(EmailProvider $provider): array
    {
        $gmail = $this->getGmailClient($provider);
        $labels = $gmail->users_labels->listUsersLabels('me');

        $mailboxes = [];
        $hasInbox = false;

        foreach ($labels->getLabels() as $label) {
            $labelId = $label->getId();

            // Skip system labels we don't want to show
            if (in_array($labelId, ['CHAT', 'SENT', 'DRAFT', 'SPAM', 'TRASH'])) {
                continue;
            }

            // Track if INBOX is already in the list
            if ($labelId === 'INBOX') {
                $hasInbox = true;
            }

            $mailboxes[] = [
                'id' => $labelId,
                'name' => $label->getName(),
            ];
        }

        // Only add INBOX if it wasn't already in the labels list
        if (! $hasInbox) {
            array_unshift($mailboxes, [
                'id' => 'INBOX',
                'name' => 'Inbox',
            ]);
        }

        return $mailboxes;
    }

    public function getEmails(EmailProvider $provider, string $mailboxId, int $page = 1, int $limit = 50): LengthAwarePaginator
    {
        $gmail = $this->getGmailClient($provider);

        // Build query for label
        $query = "label:{$mailboxId}";
        if ($mailboxId === 'INBOX') {
            $query = 'in:inbox';
        }

        $optParams = [
            'maxResults' => $limit,
            'q' => $query,
        ];

        // Gmail uses pageToken for pagination
        $messages = $gmail->users_messages->listUsersMessages('me', $optParams);
        $messageList = $messages->getMessages();

        $emails = [];
        foreach ($messageList as $messageItem) {
            $message = $gmail->users_messages->get('me', $messageItem->getId(), ['format' => 'metadata']);
            $headers = $message->getPayload()->getHeaders();

            $emails[] = [
                'id' => $message->getId(),
                'subject' => $this->getHeader($headers, 'Subject') ?? '(No Subject)',
                'from' => $this->getHeader($headers, 'From') ?? '',
                'date' => $this->getHeader($headers, 'Date') ?? '',
                'read' => ! in_array('UNREAD', $message->getLabelIds()),
                'has_attachments' => $this->hasAttachments($message->getPayload()),
            ];
        }

        return new LengthAwarePaginator(
            $emails,
            $messages->getResultSizeEstimate() ?? count($emails),
            $limit,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function getEmailDetail(EmailProvider $provider, string $emailId): array
    {
        $gmail = $this->getGmailClient($provider);
        $message = $gmail->users_messages->get('me', $emailId, ['format' => 'full']);
        $payload = $message->getPayload();
        $headers = $payload->getHeaders();

        $bodyHtml = null;
        $bodyText = null;
        $attachments = [];

        // Extract body and attachments
        $this->extractBodyAndAttachments($payload, $bodyHtml, $bodyText, $attachments, $gmail, $emailId);

        return [
            'id' => $message->getId(),
            'subject' => $this->getHeader($headers, 'Subject') ?? '(No Subject)',
            'from' => $this->parseEmailAddress($this->getHeader($headers, 'From') ?? ''),
            'to' => $this->parseEmailAddresses($this->getHeader($headers, 'To') ?? ''),
            'cc' => $this->parseEmailAddresses($this->getHeader($headers, 'Cc') ?? ''),
            'bcc' => $this->parseEmailAddresses($this->getHeader($headers, 'Bcc') ?? ''),
            'date' => $this->getHeader($headers, 'Date') ?? '',
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'attachments' => $attachments,
            'thread_id' => $message->getThreadId(),
        ];
    }

    public function sendEmail(EmailProvider $provider, array $data): string
    {
        $gmail = $this->getGmailClient($provider);

        $message = new Message;
        $rawMessage = $this->createRawMessage($data);
        $message->setRaw(base64_encode($rawMessage));

        $sentMessage = $gmail->users_messages->send('me', $message);

        return $sentMessage->getId();
    }

    public function replyEmail(EmailProvider $provider, string $emailId, string $body, ?string $subject = null): string
    {
        $originalEmail = $this->getEmailDetail($provider, $emailId);
        $gmail = $this->getGmailClient($provider);

        $replyData = [
            'to' => [$originalEmail['from']],
            'subject' => $subject ?? 'Re: '.$originalEmail['subject'],
            'body' => $body,
            'in_reply_to' => $emailId,
            'references' => $originalEmail['thread_id'] ?? $emailId,
        ];

        return $this->sendEmail($provider, $replyData);
    }

    public function forwardEmail(EmailProvider $provider, string $emailId, array $recipients, ?string $message = null): string
    {
        $originalEmail = $this->getEmailDetail($provider, $emailId);
        $gmail = $this->getGmailClient($provider);

        $forwardBody = ($message ? $message."\n\n" : '')."---------- Forwarded message ----------\n";
        $forwardBody .= "From: {$originalEmail['from']['email']}\n";
        $forwardBody .= "Date: {$originalEmail['date']}\n";
        $forwardBody .= "Subject: {$originalEmail['subject']}\n\n";
        $forwardBody .= $originalEmail['body_text'] ?? strip_tags($originalEmail['body_html'] ?? '');

        $forwardData = [
            'to' => $recipients,
            'subject' => 'Fwd: '.$originalEmail['subject'],
            'body' => $forwardBody,
        ];

        return $this->sendEmail($provider, $forwardData);
    }

    public function modifyEmail(EmailProvider $provider, string $emailId, array $actions): bool
    {
        $gmail = $this->getGmailClient($provider);
        $modifyRequest = new \Google\Service\Gmail\ModifyMessageRequest;

        if (isset($actions['read'])) {
            if ($actions['read']) {
                $modifyRequest->setRemoveLabelIds(['UNREAD']);
            } else {
                $modifyRequest->setAddLabelIds(['UNREAD']);
            }
        }

        if (isset($actions['starred'])) {
            if ($actions['starred']) {
                $modifyRequest->setAddLabelIds(['STARRED']);
            } else {
                $modifyRequest->setRemoveLabelIds(['STARRED']);
            }
        }

        if (isset($actions['delete'])) {
            $gmail->users_messages->trash('me', $emailId);

            return true;
        }

        $gmail->users_messages->modify('me', $emailId, $modifyRequest);

        return true;
    }

    public function getAttachment(EmailProvider $provider, string $emailId, string $attachmentId): array
    {
        $gmail = $this->getGmailClient($provider);
        $attachment = $gmail->users_messages_attachments->get('me', $emailId, $attachmentId);

        return [
            'content' => $attachment->getData(),
            'filename' => $attachmentId, // Will be determined from message part
            'mime_type' => $attachment->getMimeType() ?? 'application/octet-stream',
            'size' => strlen(base64_decode($attachment->getData())),
        ];
    }

    public function searchEmails(EmailProvider $provider, string $query, array $filters = [], int $page = 1, int $limit = 50, bool $fuzzy = false): LengthAwarePaginator
    {
        $gmail = $this->getGmailClient($provider);

        if ($fuzzy) {
            // For fuzzy search, fetch a larger set of emails first, then filter client-side
            $optParams = [
                'maxResults' => min(10, $limit * 10), // Fetch more for fuzzy matching
            ];

            // Apply Gmail filters if specified
            $gmailQuery = '';
            if (isset($filters['unread_only']) && $filters['unread_only']) {
                $gmailQuery .= ' is:unread';
            }
            if (isset($filters['has_attachments']) && $filters['has_attachments']) {
                $gmailQuery .= ' has:attachment';
            }

            if (! empty($gmailQuery)) {
                $optParams['q'] = trim($gmailQuery);
            }

            $messages = $gmail->users_messages->listUsersMessages('me', $optParams);
            $messageList = $messages->getMessages() ?? [];

            $emails = [];
            foreach ($messageList as $messageItem) {
                $message = $gmail->users_messages->get('me', $messageItem->getId(), ['format' => 'metadata']);
                $headers = $message->getPayload()->getHeaders();

                $emails[] = [
                    'id' => $message->getId(),
                    'subject' => $this->getHeader($headers, 'Subject') ?? '(No Subject)',
                    'from' => $this->getHeader($headers, 'From') ?? '',
                    'date' => $this->getHeader($headers, 'Date') ?? '',
                    'read' => ! in_array('UNREAD', $message->getLabelIds()),
                    'has_attachments' => $this->hasAttachments($message->getPayload()),
                ];
            }

            // Apply fuzzy search
            $fuzzySearchService = new FuzzySearchService;
            $fuzzyResults = $fuzzySearchService->search($emails, $query);

            // Paginate the fuzzy results
            $total = count($fuzzyResults);
            $offset = ($page - 1) * $limit;
            $paginatedResults = array_slice($fuzzyResults, $offset, $limit);

            return new LengthAwarePaginator(
                $paginatedResults,
                $total,
                $limit,
                $page,
                ['path' => request()->url(), 'query' => request()->query()]
            );
        }

        // Exact search (original behavior)
        $searchQuery = $query;
        if (isset($filters['unread_only']) && $filters['unread_only']) {
            $searchQuery .= ' is:unread';
        }
        if (isset($filters['has_attachments']) && $filters['has_attachments']) {
            $searchQuery .= ' has:attachment';
        }

        $optParams = [
            'maxResults' => $limit,
            'q' => $searchQuery,
        ];

        $messages = $gmail->users_messages->listUsersMessages('me', $optParams);
        $messageList = $messages->getMessages();

        $emails = [];
        foreach ($messageList as $messageItem) {
            $message = $gmail->users_messages->get('me', $messageItem->getId(), ['format' => 'metadata']);
            $headers = $message->getPayload()->getHeaders();

            $emails[] = [
                'id' => $message->getId(),
                'subject' => $this->getHeader($headers, 'Subject') ?? '(No Subject)',
                'from' => $this->getHeader($headers, 'From') ?? '',
                'date' => $this->getHeader($headers, 'Date') ?? '',
                'read' => ! in_array('UNREAD', $message->getLabelIds()),
                'has_attachments' => $this->hasAttachments($message->getPayload()),
            ];
        }

        return new LengthAwarePaginator(
            $emails,
            $messages->getResultSizeEstimate() ?? count($emails),
            $limit,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function refreshTokenIfNeeded(EmailProvider $provider): bool
    {
        if (! $provider->isAccessTokenExpired()) {
            return true;
        }

        if (! $provider->refresh_token) {
            return false;
        }

        try {
            $client = new Client;
            $client->setClientId(config('services.google.client_id'));
            $client->setClientSecret(config('services.google.client_secret'));
            $client->refreshToken($provider->refresh_token);

            $accessToken = $client->getAccessToken();
            $provider->access_token = $accessToken['access_token'];
            $provider->access_token_expires_at = now()->addSeconds($accessToken['expires_in'] ?? 3600);

            // Update refresh token if rotated
            if (isset($accessToken['refresh_token'])) {
                $provider->refresh_token = $accessToken['refresh_token'];
            }

            $provider->save();

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to refresh Gmail token', ['error' => $e->getMessage()]);

            return false;
        }
    }

    // Helper methods

    private function getHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if ($header->getName() === $name) {
                return $header->getValue();
            }
        }

        return null;
    }

    private function hasAttachments(MessagePart $payload): bool
    {
        $parts = $payload->getParts();
        if (! $parts) {
            return false;
        }

        foreach ($parts as $part) {
            if ($part->getFilename()) {
                return true;
            }
            if ($part->getParts()) {
                foreach ($part->getParts() as $subPart) {
                    if ($subPart->getFilename()) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function extractBodyAndAttachments(MessagePart $payload, ?string &$bodyHtml, ?string &$bodyText, array &$attachments, Gmail $gmail, string $messageId): void
    {
        $parts = $payload->getParts();

        if (! $parts) {
            // Single part message
            $mimeType = $payload->getMimeType();
            $body = $payload->getBody();
            if ($body && $body->getData()) {
                $content = base64_decode(str_replace(['-', '_'], ['+', '/'], $body->getData()));

                if ($mimeType === 'text/html') {
                    $bodyHtml = $content;
                } elseif ($mimeType === 'text/plain') {
                    $bodyText = $content;
                }
            }

            return;
        }

        foreach ($parts as $part) {
            $mimeType = $part->getMimeType();
            $filename = $part->getFilename();

            if ($filename) {
                // Attachment
                $attachmentId = $part->getBody()->getAttachmentId();
                $attachments[] = [
                    'id' => $attachmentId,
                    'filename' => $filename,
                    'mime_type' => $mimeType,
                    'size' => $part->getBody()->getSize(),
                ];
            } elseif ($mimeType === 'text/html') {
                $body = $part->getBody();
                if ($body && $body->getData()) {
                    $bodyHtml = base64_decode(str_replace(['-', '_'], ['+', '/'], $body->getData()));
                }
            } elseif ($mimeType === 'text/plain') {
                $body = $part->getBody();
                if ($body && $body->getData()) {
                    $bodyText = base64_decode(str_replace(['-', '_'], ['+', '/'], $body->getData()));
                }
            } elseif ($part->getParts()) {
                // Recursive for multipart/alternative
                $this->extractBodyAndAttachments($part, $bodyHtml, $bodyText, $attachments, $gmail, $messageId);
            }
        }
    }

    private function parseEmailAddress(string $addressString): array
    {
        if (preg_match('/^(.+?)\s*<(.+?)>$/', $addressString, $matches)) {
            return [
                'name' => trim($matches[1], '"'),
                'email' => $matches[2],
            ];
        }

        return [
            'name' => '',
            'email' => trim($addressString),
        ];
    }

    private function parseEmailAddresses(string $addressesString): array
    {
        $addresses = [];
        $parts = explode(',', $addressesString);

        foreach ($parts as $part) {
            $addresses[] = $this->parseEmailAddress(trim($part));
        }

        return $addresses;
    }

    private function createRawMessage(array $data): string
    {
        $boundary = uniqid('boundary_');
        $message = "MIME-Version: 1.0\n";
        $message .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\n";

        if (isset($data['in_reply_to'])) {
            $message .= "In-Reply-To: <{$data['in_reply_to']}>\n";
        }
        if (isset($data['references'])) {
            $message .= "References: <{$data['references']}>\n";
        }

        $message .= 'To: '.implode(', ', array_map(fn ($addr) => is_array($addr) ? $addr['email'] : $addr, $data['to'] ?? []))."\n";
        $message .= 'Subject: '.($data['subject'] ?? '')."\n";
        $message .= "\n";

        $body = $data['body'] ?? '';

        // Plain text part
        $message .= "--{$boundary}\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\n";
        $message .= "Content-Transfer-Encoding: 7bit\n\n";
        $message .= strip_tags($body)."\n";

        // HTML part
        $message .= "--{$boundary}\n";
        $message .= "Content-Type: text/html; charset=UTF-8\n";
        $message .= "Content-Transfer-Encoding: 7bit\n\n";
        $message .= $body."\n";

        $message .= "--{$boundary}--\n";

        return $message;
    }

    /**
     * Apply a Gmail label to an email.
     *
     * @param  EmailProvider  $provider  Email provider
     * @param  string  $emailId  Email ID
     * @param  string  $labelId  Gmail label ID
     * @return bool Success status
     */
    public function applyLabelToEmail(EmailProvider $provider, string $emailId, string $labelId): bool
    {
        try {
            $gmail = $this->getGmailClient($provider);
            $modifyRequest = new \Google\Service\Gmail\ModifyMessageRequest;
            $modifyRequest->setAddLabelIds([$labelId]);
            $gmail->users_messages->modify('me', $emailId, $modifyRequest);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to apply label to email', [
                'email_id' => $emailId,
                'label_id' => $labelId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Remove a Gmail label from an email.
     *
     * @param  EmailProvider  $provider  Email provider
     * @param  string  $emailId  Email ID
     * @param  string  $labelId  Gmail label ID
     * @return bool Success status
     */
    public function removeLabelFromEmail(EmailProvider $provider, string $emailId, string $labelId): bool
    {
        try {
            $gmail = $this->getGmailClient($provider);
            $modifyRequest = new \Google\Service\Gmail\ModifyMessageRequest;
            $modifyRequest->setRemoveLabelIds([$labelId]);
            $gmail->users_messages->modify('me', $emailId, $modifyRequest);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to remove label from email', [
                'email_id' => $emailId,
                'label_id' => $labelId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Create a Gmail label.
     *
     * @param  EmailProvider  $provider  Email provider
     * @param  string  $labelName  Label name
     * @return string Created label ID
     */
    public function createGmailLabel(EmailProvider $provider, string $labelName): string
    {
        try {
            $gmail = $this->getGmailClient($provider);
            $label = new \Google\Service\Gmail\Label;
            $label->setName($labelName);
            $label->setLabelListVisibility('labelShow');
            $label->setMessageListVisibility('show');

            $createdLabel = $gmail->users_labels->create('me', $label);

            return $createdLabel->getId();
        } catch (\Exception $e) {
            // If label already exists, try to find it
            if (str_contains($e->getMessage(), 'already exists') || str_contains($e->getMessage(), 'duplicate')) {
                $labels = $this->listGmailLabels($provider);
                foreach ($labels as $label) {
                    if ($label['name'] === $labelName) {
                        return $label['id'];
                    }
                }
            }

            Log::error('Failed to create Gmail label', [
                'label_name' => $labelName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * List all Gmail labels for the user.
     *
     * @param  EmailProvider  $provider  Email provider
     * @return array Array of labels with id and name
     */
    public function listGmailLabels(EmailProvider $provider): array
    {
        try {
            $gmail = $this->getGmailClient($provider);
            $labels = $gmail->users_labels->listUsersLabels('me');
            $labelList = [];

            foreach ($labels->getLabels() as $label) {
                // Skip system labels
                $labelId = $label->getId();
                if (in_array($labelId, ['CHAT', 'SENT', 'DRAFT', 'SPAM', 'TRASH', 'UNREAD', 'STARRED', 'IMPORTANT'])) {
                    continue;
                }

                $labelList[] = [
                    'id' => $labelId,
                    'name' => $label->getName(),
                ];
            }

            return $labelList;
        } catch (\Exception $e) {
            Log::error('Failed to list Gmail labels', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
