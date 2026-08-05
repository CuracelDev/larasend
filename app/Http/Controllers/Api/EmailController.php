<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendEmailRequest;
use App\Jobs\SendQueuedEmail;
use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Services\EmailSendService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('larasend_project');

        $emails = $project->emails()
            ->with(['recipients', 'events'])
            ->latest()
            ->when($request->string('status')->isNotEmpty(), fn ($query) => $query->where('status', $request->string('status')))
            ->paginate(max(1, min($request->integer('per_page', 25), 100)));

        return response()->json([
            'data' => $emails->map(fn (Email $email) => $this->serializeEmail($email)),
            'meta' => [
                'current_page' => $emails->currentPage(),
                'last_page' => $emails->lastPage(),
                'per_page' => $emails->perPage(),
                'total' => $emails->total(),
            ],
        ]);
    }

    public function store(SendEmailRequest $request, EmailSendService $emailSendService): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('larasend_project');
        /** @var Source|null $source */
        $source = $request->attributes->get('larasend_source');

        $payload = $request->validated();
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));

        if (strlen($idempotencyKey) > 255) {
            return response()->json(['message' => 'The Idempotency-Key header may not exceed 255 characters.'], 422);
        }

        $idempotencyKey = $idempotencyKey !== '' ? $idempotencyKey : null;
        $idempotencyHash = $idempotencyKey !== null
            ? hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))
            : null;

        if ($idempotencyKey !== null) {
            $existing = $project->emails()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                if (! hash_equals((string) $existing->idempotency_hash, (string) $idempotencyHash)) {
                    return response()->json(['message' => 'This Idempotency-Key was already used with a different request.'], 409);
                }

                if ($existing->status === 'queued') {
                    SendQueuedEmail::dispatch($existing->id);
                }

                return response()->json([
                    'id' => $existing->public_id,
                    'object' => 'email',
                    'replayed' => true,
                ], 202);
            }
        }

        try {
            $email = $emailSendService->send($project, $source, $payload, $idempotencyKey, $idempotencyHash);
        } catch (QueryException $exception) {
            if ($idempotencyKey === null || ! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $email = $project->emails()->where('idempotency_key', $idempotencyKey)->first();

            if (! $email) {
                throw $exception;
            }

            if (! hash_equals((string) $email->idempotency_hash, (string) $idempotencyHash)) {
                return response()->json(['message' => 'This Idempotency-Key was already used with a different request.'], 409);
            }

            if ($email->status === 'queued') {
                SendQueuedEmail::dispatch($email->id);
            }

            return response()->json([
                'id' => $email->public_id,
                'object' => 'email',
                'replayed' => true,
            ], 202);
        }

        return response()->json([
            'id' => $email->public_id,
            'object' => 'email',
        ], 202);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), ['23000', '23505'], true);
    }

    public function show(Request $request, string $email): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('larasend_project');

        $model = $project->emails()
            ->with(['recipients', 'events', 'attachments', 'source', 'template'])
            ->where('public_id', $email)
            ->firstOrFail();

        return response()->json(['data' => $this->serializeEmail($model, detailed: true)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEmail(Email $email, bool $detailed = false): array
    {
        $data = [
            'id' => $email->public_id,
            'object' => 'email',
            'status' => $email->status,
            'from' => trim(($email->from_name ? $email->from_name.' ' : '').'<'.$email->from_email.'>'),
            'subject' => $email->subject,
            'to' => $email->recipients->where('type', 'to')->pluck('email')->values(),
            'created_at' => $email->created_at->toIso8601String(),
            'sent_at' => $email->sent_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data += [
                'html' => $email->html,
                'text' => $email->text,
                'headers' => $email->headers,
                'tags' => $email->tags,
                'ses_message_id' => $email->ses_message_id,
                'attachments' => $email->attachments->map(fn ($attachment) => [
                    'filename' => $attachment->filename,
                    'content_type' => $attachment->content_type,
                    'size' => $attachment->size,
                ]),
                'events' => $email->events->map(fn ($event) => [
                    'type' => $event->event_type,
                    'recipient' => $event->recipient,
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                ]),
            ];
        }

        return $data;
    }
}
