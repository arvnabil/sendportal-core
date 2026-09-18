<?php

namespace Sendportal\Base\Tests\Unit\Services\Webhooks;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sendportal\Base\Models\Message;
use Sendportal\Base\Models\Subscriber;
use Sendportal\Base\Models\UnsubscribeEventType;
use Sendportal\Base\Services\Webhooks\EmailWebhookService;
use Tests\TestCase;

class EmailWebhookServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_complaint_updates_complained_at()
    {
        $subscriber = Subscriber::factory()->create();
        $message = Message::factory()->create([
            'subscriber_id' => $subscriber->id,
            'message_id' => 'test-message-id',
            'complained_at' => null,
            'unsubscribed_at' => null,
        ]);

        $service = new EmailWebhookService();
        $timestamp = Carbon::now();

        $service->handleComplaint('test-message-id', $timestamp);

        $message->refresh();
        $subscriber->refresh();

        $this->assertNotNull($message->complained_at);
        $this->assertEquals($timestamp->toDateTimeString(), $message->complained_at->toDateTimeString());
        $this->assertNull($message->unsubscribed_at);

        $this->assertNotNull($subscriber->unsubscribed_at);
        $this->assertEquals(UnsubscribeEventType::COMPLAINT, $subscriber->unsubscribe_event_id);
    }
}
