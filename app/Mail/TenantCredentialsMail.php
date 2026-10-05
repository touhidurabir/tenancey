<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TenantCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Sent synchronously by App\Jobs\Provisioning\SendTenantCredentials (never queued on its own,
     * so the plaintext password is never written to the queue).
     *
     * @param  array{name: string, email: string, phone: ?string, password: string, must_change_password?: bool}  $admin
     */
    public function __construct(public Tenant $tenant, public array $admin)
    {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.$this->tenant->name.' workspace is ready',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.tenant-credentials',
            with: ['loginUrl' => $this->tenant->url('/login')],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
