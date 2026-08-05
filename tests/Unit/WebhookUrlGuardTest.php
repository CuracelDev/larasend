<?php

use App\Exceptions\WebhookDnsResolutionException;
use App\Services\WebhookUrlGuard;

it('rejects local and private webhook destinations', function (string $url) {
    expect((new WebhookUrlGuard)->isSafe($url))->toBeFalse();
})->with([
    'localhost' => 'http://localhost/internal',
    'loopback ipv4' => 'http://127.0.0.1/internal',
    'private ipv4' => 'http://10.10.20.30/internal',
    'link local metadata' => 'http://169.254.169.254/latest/meta-data',
    'loopback ipv6' => 'http://[::1]/internal',
    'embedded credentials' => 'https://user:secret@example.com/hook',
]);

it('rejects hostnames when any resolved address is private', function () {
    $guard = new class extends WebhookUrlGuard
    {
        protected function resolveHost(string $host): array
        {
            return ['93.184.216.34', '10.0.0.8'];
        }
    };

    expect($guard->isSafe('https://hooks.example.com/events'))->toBeFalse();
});

it('accepts hostnames only when every resolved address is public', function () {
    $guard = new class extends WebhookUrlGuard
    {
        protected function resolveHost(string $host): array
        {
            return ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'];
        }
    };

    $options = $guard->requestOptions('https://hooks.example.com/events');

    expect($guard->isSafe('https://hooks.example.com/events'))->toBeTrue()
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['hooks.example.com:443:93.184.216.34']);
});

it('treats an empty dns response as retryable rather than unsafe', function () {
    $guard = new class extends WebhookUrlGuard
    {
        protected function resolveHost(string $host): array
        {
            return [];
        }
    };

    expect(fn () => $guard->requestOptions('https://hooks.example.com/events'))
        ->toThrow(WebhookDnsResolutionException::class);
});
