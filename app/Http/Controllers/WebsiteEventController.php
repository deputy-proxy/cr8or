<?php

namespace App\Http\Controllers;

use App\Models\Enterprise;
use App\Services\EnterpriseEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class WebsiteEventController
{
    /** @var list<string> */
    private const EVENT_TYPES = ['page_view', 'content_view', 'form_submit', 'cta_click', 'sign_up', 'conversion'];

    public function __invoke(Request $request, EnterpriseEventRecorder $recorder): JsonResponse
    {
        $origin = trim((string) $request->header('Origin'));
        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
        $port = parse_url($origin, PHP_URL_PORT);
        $path = parse_url($origin, PHP_URL_PATH);

        if ($origin === '' || $host === '' || $scheme !== 'https' || ! in_array($port, [null, 443], true) || ! in_array($path, [null, '', '/'], true)) {
            return response()->json(['message' => 'A valid HTTPS Origin is required.'], 400);
        }

        $enterprise = Enterprise::query()->where('website_domain', $host)->first();
        if ($enterprise === null) {
            return response()->json(['message' => 'No Enterprise is configured for this website origin.'], 404);
        }

        if ($request->isMethod('OPTIONS')) {
            return $this->respond($origin, [], 204);
        }

        if (! $request->isJson()) {
            return $this->respond($origin, ['message' => 'Content-Type must be application/json.'], 415);
        }

        if (strlen($request->getContent()) > 8192) {
            return $this->respond($origin, ['message' => 'Event payload is too large.'], 413);
        }

        $data = $request->json()->all();
        if (array_diff(array_keys($data), ['event_id', 'event_type', 'occurred_at', 'payload']) !== []) {
            return $this->respond($origin, ['message' => 'Unsupported event fields.'], 422);
        }

        $validator = Validator::make($data, [
            'event_id' => ['required', 'uuid'],
            'event_type' => ['required', 'string', 'in:'.implode(',', self::EVENT_TYPES)],
            'occurred_at' => ['required', 'date'],
            'payload' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->respond($origin, ['message' => 'Invalid website event.', 'errors' => $validator->errors()], 422);
        }

        $payload = $data['payload'];
        $allowedKeys = ['page_path', 'page_title', 'element_id', 'form_id', 'campaign_source', 'campaign_medium', 'campaign_name'];
        if (array_diff(array_keys($payload), $allowedKeys) !== []) {
            return $this->respond($origin, ['message' => 'Unsupported event payload fields.'], 422);
        }

        foreach ($payload as $key => $value) {
            $limit = match ($key) {
                'page_path' => 512,
                'page_title' => 200,
                default => 100,
            };
            if (! is_string($value) || mb_strlen($value) > $limit) {
                return $this->respond($origin, ['message' => 'Invalid event payload value.'], 422);
            }
        }

        try {
            $occurredAt = Carbon::parse($data['occurred_at']);
        } catch (\Throwable) {
            return $this->respond($origin, ['message' => 'Invalid event timestamp.'], 422);
        }

        if ($occurredAt->isFuture() || $occurredAt->lt(now()->subDays(30))) {
            return $this->respond($origin, ['message' => 'Event timestamp must be within the previous 30 days and not in the future.'], 422);
        }

        $eventType = (string) $data['event_type'];
        $recorder->record(
            organizationId: (int) $enterprise->organization_id,
            enterpriseId: (int) $enterprise->id,
            source: 'gtm',
            eventType: $eventType,
            description: Str::headline($eventType),
            occurredAt: $occurredAt,
            payload: $payload,
            sourceEventId: (string) $data['event_id'],
            receivedAt: Carbon::now(),
        );

        return $this->respond($origin, ['accepted' => true], 202);
    }

    /** @param array<string, mixed> $body */
    private function respond(string $origin, array $body, int $status): JsonResponse
    {
        return response()->json($body, $status)
            ->withHeaders([
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type',
                'Access-Control-Max-Age' => '600',
                'Vary' => 'Origin',
            ]);
    }
}
