<?php

namespace App\Services;

use App\Enums\SourceProvider;
use App\Exceptions\SuppressionProviderUnavailableException;
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
                report($exception);

                throw new SuppressionProviderUnavailableException(
                    'Amazon SES could not remove this address right now. The local blocker was preserved. Try again later.',
                    previous: $exception,
                );
            }

            $suppression->delete();

            return;
        }

        try {
            $upstreamSuppressions = $this->cloudflare->listStableSuppressions($source);
        } catch (Throwable $exception) {
            report($exception);

            throw new SuppressionProviderUnavailableException(
                'Cloudflare could not verify this address right now. The local blocker was preserved. Try again later.',
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
