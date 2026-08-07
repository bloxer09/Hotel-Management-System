<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $otpCode;
    public string $recipientName;
    public string $purpose;

    public function __construct(string $otpCode, string $recipientName = 'User', string $purpose = 'Password Reset')
    {
        $this->otpCode = $otpCode;
        $this->recipientName = $recipientName;
        $this->purpose = $purpose;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your OTP Verification Code for {$this->purpose}",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: "
            <div style=\"font-family: Arial, sans-serif; max-width: 520px; margin: 0 auto; padding: 24px; background: #0f172a; color: #f8fafc; border-radius: 16px;\">
                <h2 style=\"color: #38bdf8; margin-top: 0;\">Verification Code</h2>
                <p style=\"font-size: 14px; color: #cbd5e1;\">Hello <strong>{$this->recipientName}</strong>,</p>
                <p style=\"font-size: 14px; color: #cbd5e1;\">You requested a verification code for <strong>{$this->purpose}</strong>. Please use the following 6-digit OTP code:</p>
                <div style=\"background: #1e293b; border: 1px solid #334155; padding: 18px; text-align: center; border-radius: 12px; margin: 20px 0;\">
                    <span style=\"font-family: monospace; font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #10b981;\">{$this->otpCode}</span>
                </div>
                <p style=\"font-size: 12px; color: #94a3b8;\">This code is valid for 10 minutes. If you did not request this code, please ignore this email.</p>
            </div>
            ",
        );
    }
}
