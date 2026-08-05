<?php

namespace App\Jobs;

use App\Models\Email;
use App\Models\InboundEmail;
use App\Models\Project;
use App\Models\Source;
use App\Models\Thread;
use App\Models\WebhookLog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class PruneExpiredEmailData implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Source::query()
            ->whereNotNull('retention_days')
            ->where('retention_days', '>', 0)
            ->with('project')
            ->lazyById()
            ->each(fn (Source $source) => $this->pruneSource($source));

        Project::query()
            ->whereHas('sources', fn ($query) => $query->whereNotNull('retention_days')->where('retention_days', '>', 0))
            ->withMax('sources', 'retention_days')
            ->lazyById()
            ->each(function (Project $project): void {
                $retentionDays = (int) $project->sources_max_retention_days;
                $cutoff = now()->subDays($retentionDays);

                $project->webhookEndpoints()
                    ->each(fn ($endpoint) => $endpoint->deliveries()->where('delivered_at', '<', $cutoff)->delete());
            });
    }

    private function pruneSource(Source $source): void
    {
        $cutoff = now()->subDays($source->retention_days);

        Email::query()
            ->where('source_id', $source->id)
            ->where('created_at', '<', $cutoff)
            ->lazyById()
            ->each(function (Email $email): void {
                $this->deleteMime($email->mime_disk, $email->mime_path);
                $email->events()->delete();
                $email->delete();
            });

        InboundEmail::query()
            ->where('source_id', $source->id)
            ->where('received_at', '<', $cutoff)
            ->lazyById()
            ->each(function (InboundEmail $inbound): void {
                $this->deleteMime($inbound->mime_disk, $inbound->mime_path);
                $inbound->delete();
            });

        WebhookLog::query()
            ->where('source_id', $source->id)
            ->where('created_at', '<', $cutoff)
            ->delete();

        if ($source->project) {
            Thread::query()
                ->where('project_id', $source->project->id)
                ->where('last_activity_at', '<', $cutoff)
                ->whereDoesntHave('emails')
                ->whereDoesntHave('inboundEmails')
                ->whereDoesntHave('notes')
                ->delete();
        }
    }

    private function deleteMime(?string $disk, ?string $path): void
    {
        if (blank($disk) || blank($path)) {
            return;
        }

        $filesystem = Storage::disk($disk);

        if ($filesystem->exists($path) && ! $filesystem->delete($path)) {
            throw new \RuntimeException("Unable to delete retained MIME object {$disk}:{$path}.");
        }
    }
}
