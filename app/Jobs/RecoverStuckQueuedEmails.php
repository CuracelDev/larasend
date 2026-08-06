<?php

namespace App\Jobs;

use App\Models\Email;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RecoverStuckQueuedEmails implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const MINIMUM_AGE_MINUTES = 2;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(): void
    {
        $cutoff = now()->subMinutes(self::MINIMUM_AGE_MINUTES);

        Email::query()
            ->select('id')
            ->where('status', 'queued')
            ->where('created_at', '<', $cutoff)
            ->lazyById()
            ->each(fn (Email $email) => SendQueuedEmail::dispatch($email->id));
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
