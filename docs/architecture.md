# Architecture Map — sendportal-core
**Date:** 2026-09-18

---

## Package Entry Point

The host application registers `SendportalBaseServiceProvider`, which boots all sub-providers,
loads migrations, views, translations, and schedules the campaign dispatch command.

```
SendportalBaseServiceProvider (root)
├── SendportalAppServiceProvider   — IoC bindings (repositories, quota service, helper)
├── EventServiceProvider           — Event -> Listener mapping
├── RouteServiceProvider           — Registers route macros on Router
├── FormServiceProvider            — Blade form components
└── ResolverProvider               — Binds 'sendportal.resolver' singleton
```

The host app must register one callable on boot:
```php
Sendportal::setCurrentWorkspaceIdResolver(fn() => auth()->user()->current_workspace_id);
```
Without this, every repository query uses a null workspace_id.

---

## Directory Structure

```
src/
├── Adapters/          Email provider adapters (8)
├── Console/           Artisan commands (1: CampaignDispatchCommand)
├── Events/            Domain events (2 app events + 6 webhook events)
├── Factories/         MailAdapterFactory
├── Http/
│   ├── Controllers/   45+ controllers (Web + API + Webhook)
│   └── Requests/      Form request validation
├── Interfaces/        Contracts (4)
├── Listeners/         Event listeners (7)
├── Models/            Eloquent models (13)
├── Pipelines/         Campaign pipeline stages (3)
├── Providers/         Service providers (5 + root)
├── Repositories/      Repository pattern (15 files)
├── Routes/            Route definitions (Web + API)
├── Rules/             Custom validation rules
├── Services/          Domain services (14 files)
├── Traits/            Reusable traits
└── View/              Blade composers
```

---

## Core Patterns

### 1. Repository Pattern — Tenant Scoped
All data access goes through `BaseTenantRepository`. Every query is automatically scoped:

```php
// BaseTenantRepository::getQueryBuilder()
return $this->getNewInstance()->newQuery()->where('workspace_id', $workspaceId);
```

Concrete implementations are bound per DB driver:
- `CampaignTenantRepositoryInterface` → `MySqlCampaignTenantRepository` or `PostgresCampaignTenantRepository`
- Same pattern for Messages and Subscribers

### 2. Adapter Pattern — Email Providers
```
MailAdapterInterface (contract)
    BaseMailAdapter (abstract)
        SesMailAdapter
        SmtpAdapter
        MailgunMailAdapter
        SendgridMailAdapter
        PostmarkMailAdapter
        MailjetAdapter
        PostalAdapter
```
`MailAdapterFactory::adapter(EmailService $service)` resolves the correct adapter from the `type_id`
on the `EmailService` model, passing in the decrypted settings as config.

### 3. Laravel Pipeline — Campaign Dispatch
```php
Pipeline::send($campaign)->through([
    StartCampaign::class,    // status -> SENDING
    CreateMessages::class,   // create Message rows, fire MessageDispatchEvent per subscriber
    CompleteCampaign::class, // status -> SENT
])
```

### 4. Event-Driven Sending
```
CreateMessages fires: MessageDispatchEvent($message)
    -> MessageDispatchHandler (listener)
        -> dispatches DispatchMessage job to queue

DispatchMessage::handle()
    -> MergeSubjectService::handle()   (merge subject tags, persist to DB)
    -> MergeContentService::handle()   (merge template + subscriber vars + CSS inline)
    -> ResolveEmailService::handle()   (load EmailService from campaign)
    -> RelayMessage::handle()          (calls MailAdapterFactory -> adapter.send())
    -> MarkAsSent::handle()            (sets sent_at, stores provider message_id)
```

### 5. Webhook Tracking Pipeline
```
POST /v1/webhooks/{provider}   [NO AUTH]
    -> {Provider}WebhooksController
        -> fires {Provider}WebhookReceived event
            -> Handle{Provider}Webhook listener (parses provider payload)
                -> EmailWebhookService::{type}()
                    handleDelivery()         -> sets delivered_at
                    handleOpen()             -> sets opened_at, increments open_count
                    handleClick()            -> sets clicked_at, click_count; upserts MessageUrl
                    handleComplaint()        -> sets unsubscribed_at [BUG: complained_at not set]
                    handlePermanentBounce()  -> sets bounced_at; unsubscribes subscriber
                    handleFailure()          -> creates MessageFailure record
```

### 6. Resolver / Callback Pattern
`ResolverService` stores named callables:
- `workspace` — resolves current workspace ID from host app session
- `sidebar` — injects custom sidebar HTML
- `header` — injects custom header HTML

### 7. Feature Flagging
```php
// Helper::isPro()
return class_exists('Sendportal\\Pro\\SendportalProServiceProvider');
```
Pro features (Automations) are gated by namespace existence. ACTiV can use the same pattern for
plan-gated features.

---

## Data Flow Diagrams

### Campaign Send Flow

```
[Scheduler: every 1 min]
    CampaignDispatchCommand
        Finds QUEUED campaigns where scheduled_at <= now()
        foreach campaign:
            CampaignDispatchService::handle($campaign)
                Pipeline: StartCampaign -> CreateMessages -> CompleteCampaign
                    CreateMessages:
                        if send_to_all:
                            chunk all workspace subscribers (1000/chunk)
                        else:
                            foreach tag -> chunk tag subscribers (1000/chunk)
                        foreach subscriber in chunk:
                            [WARNING] in-memory dedup check ($sentItems[])
                            INSERT sendportal_messages
                            event(MessageDispatchEvent)
                                [QUEUED JOB] DispatchMessage::handle()
                                    merge content + send via provider
                                    UPDATE sent_at, message_id
```

### Template Merge

```
MergeContentService::handle($message)
    load Campaign with template relation
    if template: str_ireplace('{{content}}', campaign.content, template.content)
    else: use campaign.content directly
    replace {{email}}, {{first_name}}, {{last_name}}
    replace {{unsubscribe_url}} -> route('sendportal.subscriptions.unsubscribe', hash)
    replace {{webview_url}}     -> route('sendportal.webview.show', hash)
    CssToInlineStyles::convert() — converts <style> blocks to inline attributes
```

Supported merge variables: `{{email}}`, `{{first_name}}`, `{{last_name}}`,
`{{unsubscribe_url}}`, `{{webview_url}}`. Both `{{tag}}` and `{{ tag }}` formats work.

---

## Routes

### Authenticated Web Routes (prefix: `/`)
```
GET    /                              Dashboard
GET/POST /campaigns                   Campaign CRUD
GET    /campaigns/{id}/preview        Preview
PUT    /campaigns/{id}/send           Queue for dispatch
GET    /campaigns/{id}/report         Reports (opens, clicks, bounces, unsubscribes)
GET/POST /campaigns/{id}/test         Send test email
CRUD   /subscribers
CRUD   /tags
CRUD   /templates
CRUD   /email-services
GET    /messages, /messages/draft     Message log
```

### Public Web Routes (no auth)
```
GET  /subscriptions/unsubscribe/{hash}   Unsubscribe page
GET  /subscriptions/subscribe/{hash}     Re-subscribe page
PUT  /subscriptions/subscriptions/{hash} Update preferences
GET  /webview/{hash}                     Online email viewer
```

### Authenticated API Routes (prefix: `/api/v1/`)
```
apiResource /campaigns
POST        /campaigns/{id}/send
apiResource /subscribers
apiResource /tags
apiResource /subscribers/{id}/tags  (nested)
apiResource /tags/{id}/subscribers  (nested)
apiResource /templates
```

### Public API Routes — Webhooks (no auth, no signature check)
```
POST /api/v1/webhooks/aws
POST /api/v1/webhooks/mailgun
POST /api/v1/webhooks/postmark
POST /api/v1/webhooks/sendgrid
POST /api/v1/webhooks/mailjet
POST /api/v1/webhooks/postal
GET  /api/v1/ping
```

---

## Model Relationships

```
EmailServiceType  <--(type_id)--   EmailService
                                       |
Campaign  --(email_service_id)---> EmailService
Campaign  --(template_id)-------> Template
Campaign  --(status_id)---------> CampaignStatus
Campaign  <---(many-to-many)----> Tag  (via sendportal_campaign_tag)
Tag       <---(many-to-many)----> Subscriber  (via sendportal_tag_subscriber)

Campaign  ---(morphMany)----------> Message  (source_type = Campaign::class)
Subscriber --------(hasMany)------> Message
Message   -------(hasMany)--------> MessageFailure
Campaign  -------(implicit)-------> MessageUrl  (source_type + source_id)

Subscriber --(unsubscribe_event_id)--> UnsubscribeEventType
```

---

## Scheduler

`CampaignDispatchCommand` runs every minute via `withoutOverlapping()` constraint.
Registered in `SendportalBaseServiceProvider::boot()` via `$schedule->command()`.

**Warning:** Campaign message creation is synchronous in the scheduler process.
For campaigns with >10k subscribers, this blocks the scheduler for minutes.

---

## Console Commands

| Command | Schedule | Action |
|---------|----------|--------|
| `sendportal:campaigns:dispatch` | Every minute | Find QUEUED campaigns past scheduled_at, dispatch via pipeline |

---

## Key Interfaces

| Interface | Implementations |
|-----------|----------------|
| `MailAdapterInterface` | 7 adapters (SES, SMTP, Mailgun, Postmark, Sendgrid, Mailjet, Postal) |
| `CampaignTenantRepositoryInterface` | MySql + Postgres |
| `MessageTenantRepositoryInterface` | MySql + Postgres |
| `SubscriberTenantRepositoryInterface` | MySql + Postgres |
| `QuotaServiceInterface` | `QuotaService` (SES quota check) |
| `BaseTenantInterface` | `BaseTenantRepository` |

---

## Known Bugs

| ID | Location | Description |
|----|---------|-------------|
| B1 | `EmailWebhookService::handleComplaint()` line 119 | Sets `unsubscribed_at` instead of `complained_at` |
| B2 | `CreateMessages::$sentItems` | Unbounded in-memory array causes OOM for large campaigns |
| B3 | `MessageUrl::generateMessageUrlHash()` | MD5 collision risk |
