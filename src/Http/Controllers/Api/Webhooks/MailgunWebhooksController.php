<?php

declare(strict_types=1);

namespace Sendportal\Base\Http\Controllers\Api\Webhooks;

use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Sendportal\Base\Events\Webhooks\MailgunWebhookReceived;
use Sendportal\Base\Http\Controllers\Controller;
use Sendportal\Base\Models\EmailService;
use Sendportal\Base\Models\Message;
use Sendportal\Base\Services\Webhooks\Mailgun\WebhookVerifier;

class MailgunWebhooksController extends Controller
{
    /** @var WebhookVerifier */
    private $verifier;

    public function __construct(WebhookVerifier $verifier)
    {
        $this->verifier = $verifier;
    }

    public function handle(): Response
    {
        /** @var array $payload */
        $payload = json_decode(request()->getContent(), true);

        if (!$this->checkWebhookValidity($payload)) {
            Log::error('Mailgun webhook failed verification check.', ['payload' => $payload]);
            return response('Unauthorized', 403);
        }

        $payload = $this->stripAttachments($payload);

        Log::info('Mailgun webhook received');

        if (Arr::get($payload, 'event-data.event')) {
            event(new MailgunWebhookReceived($payload));

            return response('OK');
        }

        return response('OK (not processed');
    }

    /**
     * Remove attachments from the webhook.
     *
     * This is needed to ensure that the payload can be correctly serialized for the queue.
     */
    protected function stripAttachments(array $payload): array
    {
        unset($payload['event-data.message.attachments']);

        return $payload;
    }

    private function checkWebhookValidity(array $payload): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        $messageId = Arr::get($payload, 'event-data.message.headers.message-id');
        
        if (!$messageId) {
            return false;
        }

        $messageId = strpos($messageId, '<') === 0 ? $messageId : '<' . $messageId . '>';
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

        $signature = $payload['signature'] ?? null;

        if (! $signature) {
            return false;
        }

        return $this->verifier->verify(
            $signingKey,
            $signature['token'],
            (int)$signature['timestamp'],
            $signature['signature']
        );
    }
}
