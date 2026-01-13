<?php

namespace App\Services;

use App\Models\EmailProvider;
use Illuminate\Pagination\LengthAwarePaginator;

interface EmailServiceInterface
{
    /**
     * Connect the email provider for a user.
     */
    public function connect(EmailProvider $provider, array $credentials): bool;

    /**
     * Disconnect the email provider.
     */
    public function disconnect(EmailProvider $provider): bool;

    /**
     * Get list of mailboxes (folders/labels).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function getMailboxes(EmailProvider $provider): array;

    /**
     * Get paginated list of emails from a mailbox.
     *
     * @return LengthAwarePaginator<array{id: string, subject: string, from: string, date: string, read: bool, has_attachments: bool}>
     */
    public function getEmails(EmailProvider $provider, string $mailboxId, int $page = 1, int $limit = 50): LengthAwarePaginator;

    /**
     * Get full email details.
     *
     * @return array{id: string, subject: string, from: array, to: array, cc: array, bcc: array, date: string, body_html: ?string, body_text: ?string, attachments: array, thread_id: ?string}
     */
    public function getEmailDetail(EmailProvider $provider, string $emailId): array;

    /**
     * Send a new email.
     *
     * @return string Email ID
     */
    public function sendEmail(EmailProvider $provider, array $data): string;

    /**
     * Reply to an email.
     *
     * @return string Email ID
     */
    public function replyEmail(EmailProvider $provider, string $emailId, string $body, ?string $subject = null): string;

    /**
     * Forward an email.
     *
     * @return string Email ID
     */
    public function forwardEmail(EmailProvider $provider, string $emailId, array $recipients, ?string $message = null): string;

    /**
     * Modify email (mark read/unread, delete, star).
     */
    public function modifyEmail(EmailProvider $provider, string $emailId, array $actions): bool;

    /**
     * Get email attachment.
     *
     * @return array{content: string, filename: string, mime_type: string, size: int}
     */
    public function getAttachment(EmailProvider $provider, string $emailId, string $attachmentId): array;

    /**
     * Search emails.
     *
     * @return LengthAwarePaginator<array{id: string, subject: string, from: string, date: string, read: bool}>
     */
    public function searchEmails(EmailProvider $provider, string $query, array $filters = [], int $page = 1, int $limit = 50): LengthAwarePaginator;

    /**
     * Refresh access token if needed.
     */
    public function refreshTokenIfNeeded(EmailProvider $provider): bool;
}
