<?php

declare(strict_types=1);

namespace Sendportal\Base\Http\Controllers\Api\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Sendportal\Base\Events\Webhooks\SendgridWebhookReceived;
use Sendportal\Base\Http\Controllers\Controller;
use Sendportal\Base\Models\EmailService;
use Sendportal\Base\Models\Message;
use SendGrid\EventWebhook\EventWebhook;

class SendgridWebhooksController extends Controller
{
    public function handle(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        $events = collect($payload);

        if ($events->isEmpty()) {
            return response('OK (not processed)');
        }

        if (! $this->checkWebhookValidity($request, $events)) {
            Log::error('SendGrid webhook failed verification check.', ['payload' => $payload]);
            return response('Unauthorized', 403);
        }

        Log::info('SendGrid webhook received');

        foreach ($events as $event) {
            event(new SendgridWebhookReceived($event));
        }

        return response('OK');
    }

    private function checkWebhookValidity(Request $request, \Illuminate\Support\Collection $events): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        $firstEvent = $events->first();
        if (!$firstEvent) {
            return false;
        }

        $messageId = Arr::get($firstEvent, 'sg_message_id');
        if (!$messageId) {
            return false;
        }
        
        $messageId = trim(Str::before($messageId, '.'));

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

        $signature = $request->header('X-Twilio-Email-Event-Webhook-Signature');
        $timestamp = $request->header('X-Twilio-Email-Event-Webhook-Timestamp');

        if (!$signature || !$timestamp) {
            return false;
        }

        $verifier = new EventWebhook();
        
        try {
            $publicKey = $verifier->convertPublicKeyToECDSA($signingKey);
            return $verifier->verifySignature($publicKey, $request->getContent(), $signature, $timestamp);
        } catch (\Exception $e) {
            return false;
        }
    }
}
