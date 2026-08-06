<?php

namespace App\Support;

use RuntimeException;

class ControlMail
{
    /** @var array<int, string> */
    private const UNSAFE_TRANSPORTS = ['array', 'larasend', 'log'];

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

        if ($mailer === null) {
            return false;
        }

        return $this->hasSafeTransport($mailer, []);
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

    /**
     * Validate the complete configured mailer graph, including nested
     * failover and round-robin mailers. A control path must never fall back
     * to Larasend or a non-delivering test/log transport.
     *
     * @param  array<string, true>  $visited
     */
    private function hasSafeTransport(string $mailer, array $visited): bool
    {
        if (isset($visited[$mailer]) || in_array($mailer, self::UNSAFE_TRANSPORTS, true)) {
            return false;
        }

        $mailers = (array) config('mail.mailers', []);
        $configuration = $mailers[$mailer] ?? null;

        if (! is_array($configuration)) {
            return false;
        }

        $transport = $configuration['transport'] ?? null;

        if (! is_string($transport) || $transport === '' || in_array($transport, self::UNSAFE_TRANSPORTS, true)) {
            return false;
        }

        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return true;
        }

        $children = $configuration['mailers'] ?? null;

        if (! is_array($children) || $children === []) {
            return false;
        }

        $visited[$mailer] = true;

        foreach ($children as $child) {
            if (! is_string($child) || ! $this->hasSafeTransport($child, $visited)) {
                return false;
            }
        }

        return true;
    }
}
