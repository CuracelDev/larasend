<?php

namespace App\Services\Providers;

use App\Models\CloudflareInboundDomain;
use App\Models\Domain;
use App\Models\Source;
use App\Services\CloudflareApiClient;
use App\Services\DnsRecordVerifier;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Turns on inbound email for a domain with zero manual Cloudflare steps:
 * uploads the passthrough Worker, enables Email Routing on the zone, and
 * points the catch-all rule at the Worker. Failures throw with the exact
 * missing piece plus manual instructions, mirroring how automatic domain
 * onboarding degrades on the sending side.
 */
class CloudflareInboundProvisioner
{
    /**
     * The MX records Cloudflare Email Routing expects at the zone apex.
     */
    private const ROUTING_MX = [
        ['priority' => 20, 'target' => 'route1.mx.cloudflare.net'],
        ['priority' => 59, 'target' => 'route2.mx.cloudflare.net'],
        ['priority' => 99, 'target' => 'route3.mx.cloudflare.net'],
    ];

    public function __construct(
        private CloudflareApiClient $apiClient,
        private DnsRecordVerifier $dnsVerifier,
    ) {}

    public function enable(Source $source, Domain $domain): void
    {
        $zone = $this->apiClient->findZone($source, $domain->domain);

        if ($zone === null) {
            throw new RuntimeException(
                "No Cloudflare zone found for \"{$domain->domain}\". The domain must use Cloudflare DNS on the account the API token belongs to.",
            );
        }

        [$claim, $claimWasCreated] = $this->reserveZone($source, $domain, $zone['name']);

        $workerName = $this->workerName($source);
        $catchAllConfigured = false;

        try {
            try {
                $this->apiClient->uploadWorker($source, $workerName, $this->workerCode(), [
                    'LARASEND_INBOUND_URL' => route('webhooks.inbound.cloudflare', $source->webhook_token),
                ]);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    'Could not deploy the inbound Worker: '.$exception->getMessage()
                        .' Add the "Workers Scripts: Edit" permission to the API token, or deploy the Worker manually with wrangler.',
                    previous: $exception,
                );
            }

            try {
                $this->apiClient->routeCatchAllToWorker($source, $zone['id'], $workerName);
                $catchAllConfigured = true;
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    'Deployed the Worker but could not configure Email Routing: '.$exception->getMessage()
                        .' Add the "Email Routing Rules: Edit" permission to the API token, or point a routing rule at the "'.$workerName.'" Worker in the Cloudflare dashboard.',
                    previous: $exception,
                );
            }

            $this->ensureRoutingDns($source, $zone['id'], $zone['name']);

            // Replies go out *as* the address that received the mail, which
            // lives on the zone apex — so the apex must also be onboarded for
            // Email Sending. Best-effort: receiving works without it.
            try {
                $this->apiClient->findOrCreateSendingSubdomain($source, $zone['id'], $zone['name']);
            } catch (Throwable $exception) {
                report($exception);
            }

            $domain->forceFill([
                'inbound_enabled_at' => now(),
                'inbound_domain' => Str::lower($zone['name']),
            ])->save();

            $claim->forceFill([
                'domain_id' => $domain->id,
                'activated_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            if ($claimWasCreated && ! $catchAllConfigured && $claim->activated_at === null) {
                $claim->delete();
            }

            throw $exception;
        }
    }

    public function adoptLegacyDomain(Source $source, Domain $domain): ?string
    {
        if ($domain->inbound_enabled_at === null || $domain->inbound_domain !== null) {
            return $domain->inbound_domain;
        }

        $zone = $this->apiClient->findZone($source, $domain->domain);

        if ($zone === null) {
            return null;
        }

        [$claim] = $this->reserveZone($source, $domain, $zone['name']);
        $normalizedZone = Str::lower($zone['name']);

        $domain->forceFill(['inbound_domain' => $normalizedZone])->save();
        $claim->forceFill([
            'domain_id' => $domain->id,
            'activated_at' => $domain->inbound_enabled_at,
        ])->save();

        return $normalizedZone;
    }

    /**
     * Delivery needs the routing MX records at the zone apex. The explicit
     * enable endpoint is gated by a settings-level permission most tokens
     * lack, so it is best-effort only; when the MX records are still missing
     * afterwards they are published directly via DNS (which the token can
     * already edit), and only an unresolvable state throws.
     */
    private function ensureRoutingDns(Source $source, string $zoneId, string $zoneName): void
    {
        try {
            $this->apiClient->enableEmailRouting($source, $zoneId);
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($this->routingMxPresent($zoneName)) {
            return;
        }

        try {
            $this->apiClient->ensureDnsRecords($source, $zoneId, collect(self::ROUTING_MX)
                ->map(fn (array $mx): array => [
                    'type' => 'MX',
                    'name' => $zoneName,
                    'content' => $mx['target'],
                    'priority' => $mx['priority'],
                ])
                ->all());
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'The routing rule is configured, but the zone apex has no Email Routing MX records and they could not be created: '
                    .$exception->getMessage()
                    .' Enable Email Routing on the zone in the Cloudflare dashboard (Compute & AI > Email Service > Email Routing) to finish setup.',
                previous: $exception,
            );
        }
    }

    private function routingMxPresent(string $zoneName): bool
    {
        return $this->dnsVerifier->matches([
            'type' => 'MX',
            'name' => $zoneName,
            'value' => 'route1.mx.cloudflare.net',
        ]);
    }

    public function workerCode(): string
    {
        return File::get(resource_path('cloudflare/inbound-email-worker.js'));
    }

    public function workerName(Source $source): string
    {
        return 'larasend-inbound-'.substr(hash('sha256', $source->webhook_token), 0, 16);
    }

    /**
     * @return array{CloudflareInboundDomain, bool}
     */
    private function reserveZone(Source $source, Domain $domain, string $zone): array
    {
        $normalizedZone = Str::lower($zone);
        $legacyOwner = Domain::query()
            ->whereNotNull('inbound_enabled_at')
            ->whereNull('inbound_domain')
            ->get(['id', 'project_id', 'domain'])
            ->first(fn (Domain $candidate): bool => $this->domainBelongsToZone($candidate->domain, $normalizedZone));

        if ($legacyOwner && $legacyOwner->project_id !== $source->project_id) {
            throw $this->zoneAlreadyOwned($normalizedZone);
        }

        $claim = CloudflareInboundDomain::query()->firstOrCreate(
            ['zone' => $normalizedZone],
            [
                'project_id' => $source->project_id,
                'domain_id' => $domain->id,
            ],
        );

        if ($claim->project_id !== $source->project_id) {
            throw $this->zoneAlreadyOwned($normalizedZone);
        }

        return [$claim, $claim->wasRecentlyCreated];
    }

    private function domainBelongsToZone(string $domain, string $zone): bool
    {
        $normalizedDomain = Str::lower($domain);

        return $normalizedDomain === $zone || Str::endsWith($normalizedDomain, '.'.$zone);
    }

    private function zoneAlreadyOwned(string $zone): RuntimeException
    {
        return new RuntimeException(
            "Cloudflare Email Routing has one catch-all per zone. {$zone} is already connected to another Larasend project.",
        );
    }
}
