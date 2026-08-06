<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\SourceProvider;
use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Models\WebhookLog;
use App\Services\InboundEmailIngestor;
use App\Services\Providers\CloudflareInboundProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class CloudflareInboundController extends Controller
{
    /**
     * Raw messages above this size are rejected. Mirrors what a small
     * self-hosted install can comfortably buffer in one request.
     */
    private const MAX_MESSAGE_BYTES = 26_214_400; // 25 MiB

    public function __invoke(
        Request $request,
        string $token,
        InboundEmailIngestor $ingestor,
        CloudflareInboundProvisioner $provisioner,
    ): JsonResponse {
        $source = Source::query()
            ->where('webhook_token', $token)
            ->where('provider', SourceProvider::Cloudflare)
            ->firstOrFail();

        $isStreamingRequest = Str::startsWith((string) $request->header('Content-Type'), 'message/rfc822');
        $validated = Validator::make($isStreamingRequest ? [
            'from' => $request->header('Larasend-Envelope-From'),
            'to' => $request->header('Larasend-Envelope-To'),
            'raw' => $request->getContent(),
        ] : $request->all(), [
            'from' => ['required', 'string', 'max:320'],
            'to' => ['required', 'string', 'max:320'],
            'raw' => ['required', 'string'],
        ])->validate();

        $mime = $isStreamingRequest
            ? $validated['raw']
            : base64_decode($validated['raw'], strict: true);

        if ($mime === false || $mime === '') {
            return response()->json(['message' => 'Raw message is not valid.'], 422);
        }

        if (strlen($mime) > self::MAX_MESSAGE_BYTES) {
            return response()->json(['message' => 'Message too large.'], 413);
        }

        $recipientDomain = Str::lower(Str::after($validated['to'], '@'));
        $allowedRecipient = $recipientDomain !== '' && $source->project->domains()
            ->whereNotNull('inbound_enabled_at')
            ->get(['id', 'project_id', 'domain', 'inbound_domain', 'inbound_enabled_at'])
            ->contains(function ($domain) use ($provisioner, $recipientDomain, $source): bool {
                $inboundDomain = $domain->inbound_domain
                    ?: $provisioner->adoptLegacyDomain($source, $domain);

                return $inboundDomain !== null && $recipientDomain === Str::lower($inboundDomain);
            });

        if (! $allowedRecipient) {
            return response()->json(['message' => 'Recipient domain is not enabled for this source.'], 422);
        }

        $log = WebhookLog::create([
            'source_id' => $source->id,
            'provider' => 'cloudflare',
            'message_type' => 'inbound_email',
            'status' => 'received',
            'payload' => [
                'from' => $validated['from'],
                'to' => $validated['to'],
                'raw_bytes' => strlen($mime),
            ],
        ]);

        try {
            $inbound = $ingestor->ingest($source, $validated['from'], $validated['to'], $mime);
        } catch (Throwable $exception) {
            report($exception);
            $log->forceFill(['status' => 'failed', 'error' => $exception->getMessage()])->save();

            // A 5xx makes the Worker throw, which defers the message so the
            // sending server retries instead of the email being lost.
            return response()->json(['message' => 'Ingestion failed.'], 500);
        }

        $log->forceFill(['status' => 'processed'])->save();

        return response()->json(['id' => $inbound->public_id], 202);
    }
}
