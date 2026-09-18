<?php

declare(strict_types=1);

namespace Sendportal\Base\Http\Controllers\Api\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Sendportal\Base\Events\Webhooks\PostmarkWebhookReceived;
use Sendportal\Base\Http\Controllers\Controller;
use Sendportal\Base\Models\EmailService;
use Sendportal\Base\Models\Message;

class PostmarkWebhooksController extends Controller
{
    public function handle(Request $request): Response
    {
        /** @var array $payload */
        $payload = json_decode($request->getContent(), true) ?? [];

        if (! $this->checkWebhookValidity($request, $payload)) {
            Log::error('Postmark webhook failed verification check.', ['payload' => $payload]);
            return response('Unauthorized', 403);
        }

        Log::info('Postmark webhook received');

        event(new PostmarkWebhookReceived($payload));

        return response('OK');
    }

    private function checkWebhookValidity(Request $request, array $payload): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        $messageId = Arr::get($payload, 'MessageID');
        
        if (!$messageId) {
            return false;
        }

        $messageId = trim($messageId);

        $message = Message::with('source.email_service')->where('message_id', $messageId)->first();

        /** @var EmailService|null $emailservice */
        $emailservice = $message->source->email_service ?? null;

        if (! $emailservice) {
            return false;
        }

        /** @var string|null $signingKey */
        $signingKey = $emailservice->settings['webhook_key'] ?? null;

        if (! $signingKey) {
            return false;
        }

        $headerSecret = $request->header('X-Postmark-Secret');

        if (!$headerSecret) {
            return false;
        }

        return hash_equals($signingKey, (string) $headerSecret);
    }
}
