<?php

namespace App\Support;

use RuntimeException;

class ControlMail
{
    /**
     * @return array{email_verified_at: mixed, email_verification_required_at: mixed}
     */
    public function verificationAttributes(bool $isInitialOwner = false): array
    {
        $requiresVerification = ! $isInitialOwner && $this->isConfigured();

        return [
            'email_verified_at' => $requiresVerification ? null : now(),
            'email_verification_required_at' => $requiresVerification ? now() : null,
        ];
    }

    public function isConfigured(): bool
    {
        $mailer = $this->mailer();

        return $mailer !== null
            && ! in_array($mailer, ['array', 'larasend', 'log'], true)
            && array_key_exists($mailer, (array) config('mail.mailers', []));
    }

    public function mailer(): ?string
    {
        $mailer = trim((string) config('larasend.control_mailer', ''));

        return $mailer !== '' ? $mailer : null;
    }

    public function mailerOrFail(): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('A dedicated control email mailer is not configured.');
        }

        return (string) $this->mailer();
    }
}
