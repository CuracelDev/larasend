<?php

namespace App\Services;

use App\Enums\SourceProvider;
use App\Exceptions\SuppressionRemovalException;
use App\Models\Suppression;
use Illuminate\Support\Str;
use Throwable;

class SuppressionRemovalService
{
    public function __construct(
        private SesV2Client $ses,
        private CloudflareApiClient $cloudflare,
    ) {}

    public function remove(Suppression $suppression): void
    {
        $source = $suppression->source;

        if ($source === null) {
            $suppression->delete();

            return;
        }

        if ($source->provider === SourceProvider::Ses) {
            try {
                $this->ses->deleteSuppressedDestination($source, $suppression->email);
            } catch (Throwable $exception) {
                throw new SuppressionRemovalException(
                    'Amazon SES could not remove this address. The local blocker was preserved. '.$exception->getMessage(),
                    previous: $exception,
                );
            }

            $suppression->delete();

            return;
        }

        try {
            $upstreamSuppressions = $this->cloudflare->listSuppressions($source);
        } catch (Throwable $exception) {
            throw new SuppressionRemovalException(
                'Cloudflare could not confirm whether this address is still suppressed. The local blocker was preserved. '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $email = Str::lower($suppression->email);
        $stillSuppressed = collect($upstreamSuppressions)
            ->contains(fn (array $upstream): bool => Str::lower($upstream['email']) === $email);

        if ($stillSuppressed) {
            throw new SuppressionRemovalException(
                'Cloudflare still lists this address upstream and does not provide a suppression-removal API. Remove it in Cloudflare first, then retry.',
            );
        }

        $suppression->delete();
    }
}
