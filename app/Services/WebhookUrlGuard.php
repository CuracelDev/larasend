<?php

namespace App\Services;

use App\Exceptions\UnsafeWebhookUrlException;
use App\Exceptions\WebhookDnsResolutionException;
use Throwable;

class WebhookUrlGuard
{
    public function isSafe(string $url): bool
    {
        try {
            $this->requestOptions($url);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Pin the request to an address from the validated DNS response. This keeps
     * the original hostname for TLS and Host header verification while avoiding
     * a second, attacker-controlled DNS lookup during delivery.
     *
     * @return array<string, mixed>
     */
    public function requestOptions(string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeWebhookUrlException('The webhook URL is malformed.');
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new UnsafeWebhookUrlException('The webhook URL must use HTTP or HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeWebhookUrlException('Webhook URLs cannot contain credentials.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new UnsafeWebhookUrlException('The webhook URL must use a public hostname.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! $this->isPublicIp($host)) {
                throw new UnsafeWebhookUrlException('The webhook URL cannot use a private or reserved IP address.');
            }

            return [];
        }

        $addresses = $this->resolveHost($host);

        if ($addresses === []) {
            throw new WebhookDnsResolutionException('The webhook hostname could not be resolved.');
        }

        if (! collect($addresses)->every(fn (string $address): bool => $this->isPublicIp($address))) {
            throw new UnsafeWebhookUrlException('The webhook hostname resolves to a private or reserved IP address.');
        }

        $address = collect($addresses)
            ->first(fn (string $resolvedAddress): bool => filter_var($resolvedAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
            ?? $addresses[0];
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        $curlAddress = str_contains($address, ':') ? "[{$address}]" : $address;

        return [
            'curl' => [
                CURLOPT_RESOLVE => ["{$host}:{$port}:{$curlAddress}"],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function resolveHost(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        return collect($records)
            ->map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function isPublicIp(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
