<?php

namespace App\Jobs;

use App\Enums\SourceProvider;
use App\Models\Source;
use App\Services\CloudflareApiClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cloudflare has no delivery-event webhooks; suppressions accumulate on the
 * account-level list instead. This pull-only sync mirrors that list into
 * Larasend so suppressed recipients are blocked before a send is attempted.
 */
#[UniqueFor(3900)]
class SyncCloudflareSuppressions implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 75;

    public function handle(CloudflareApiClient $cloudflare): void
    {
        Source::query()
            ->where('provider', SourceProvider::Cloudflare)
            ->whereNotNull('cloudflare_api_token')
            ->whereNotNull('cloudflare_account_id')
            ->with('project')
            ->each(function (Source $source) use ($cloudflare): void {
                try {
                    $this->syncSource($cloudflare, $source);
                } catch (Throwable $exception) {
                    // One bad token must not abort the run for other sources.
                    report($exception);
                }
            });
    }

    private function syncSource(CloudflareApiClient $cloudflare, Source $source): void
    {
        $project = $source->project;

        if (! $project) {
            return;
        }

        $suppressions = $cloudflare->listStableSuppressions($source);
        $syncedEmails = [];

        foreach ($suppressions as $suppression) {
            $email = Str::lower(trim($suppression['email']));

            if ($email === '') {
                continue;
            }

            $values = [
                'workspace_id' => $project->workspace_id,
                'source_id' => $source->id,
                'email_id' => null,
                'reason' => $this->mapReason($suppression['reason']),
                'event_type' => 'provider_sync',
                'expires_at' => $suppression['expires_at'] ? Carbon::parse($suppression['expires_at']) : null,
            ];

            $existing = $project->suppressions()->firstOrCreate(
                ['email' => $email],
                $values,
            );

            if ($existing->source_id !== $source->id || $existing->event_type !== 'provider_sync') {
                continue;
            }

            $existing->update($values);
            $syncedEmails[] = $email;
        }

        $providerSyncSuppressions = $project->suppressions()
            ->where('source_id', $source->id)
            ->where('event_type', 'provider_sync');

        if ($syncedEmails === []) {
            $providerSyncSuppressions->delete();

            return;
        }

        $providerSyncSuppressions->whereNotIn('email', $syncedEmails)->delete();
    }

    private function mapReason(string $reason): string
    {
        return match (true) {
            str_contains($reason, 'complaint') || str_contains($reason, 'spam') => 'complaint',
            default => 'hard_bounce',
        };
    }
}
