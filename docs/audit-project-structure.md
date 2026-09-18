# Phase 0 — SendPortal Core Audit Report
## ACTiV Marketing Cloud Foundation Analysis

**Repository:** `arvnabil/sendportal-core` (fork of `mettle/sendportal-core`)
**Audited:** 2026-09-18
**Auditor:** Senior Laravel Software Architect / Security Engineer
**Branch:** `master` (SHA `6e6ce11`)

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Repository Overview](#2-repository-overview)
3. [Dependency Analysis](#3-dependency-analysis)
4. [Architectural Patterns](#4-architectural-patterns)
5. [Directory & Component Map](#5-directory--component-map)
6. [Component Deep-Dives](#6-component-deep-dives)
7. [The Workspace / Tenant Model](#7-the-workspace--tenant-model)
8. [Email Sending Flow (Full Trace)](#8-email-sending-flow-full-trace)
9. [Webhook Tracking Flow](#9-webhook-tracking-flow)
10. [Template & Content Merge Flow](#10-template--content-merge-flow)
11. [Security Audit Findings](#11-security-audit-findings)
12. [Reuse / Extend / Override / Replace / Build-New Matrix](#12-reusage-matrix)
13. [ACTiV Architectural Risks](#13-activ-architectural-risks)
14. [What Must Be Built New in ACTiV App](#14-what-must-be-built-new-in-activ-app)

---

## 1. Executive Summary

SendPortal Core is a **Laravel package** (type `library`) providing email marketing engine functionality. It is **not a standalone application** — it is designed to be installed into a host Laravel application.

**What it is good at:**
- Clean email sending pipeline (Campaign → Messages → Dispatch → Provider Adapter)
- Multi-provider adapter pattern (SES, SMTP, Mailgun, Postmark, Sendgrid, Mailjet, Postal)
- Webhook ingestion and tracking (opens, clicks, bounces, complaints, deliveries)
- Repository pattern with workspace (tenant) isolation
- Laravel Pipeline for campaign dispatch stages

**Critical gaps for ACTiV SaaS:**
- **No authentication system** — the package is auth-agnostic by design
- **Workspace model is assumed external** — it references `Workspace` but never defines it
- **No User model** — user management is entirely delegated to the host app
- **No billing, subscriptions, or usage tracking**
- **No organization/multi-tenancy hierarchy** — single-level `workspace_id` only
- **No API authentication** — API routes exist but have no auth middleware in the package
- **No role/permissions system**
- **Settings stored as encrypted JSON blob** — not queryable, not scalable
- **Campaign dispatch runs inline in the scheduler** — not queue-backed by default
- **No Microsoft 365 / OAuth2 email provider support**
- **Legacy UI** — Bootstrap 4 + jQuery + Blade, not React/Inertia/Tailwind

---

## 2. Repository Overview

```
sendportal-core/
├── composer.json              # Package manifest (type: library)
├── package.json               # Frontend (Laravel Mix / Webpack)
├── phpunit.xml.dist           # Test config
├── config/config.php          # Minimal config placeholder
├── database/
│   ├── factories/             # 5 model factories
│   └── migrations/            # 21 migration files (2017-2021)
├── public/                    # Compiled CSS/JS assets (Bootstrap 4, jQuery)
├── resources/
│   ├── lang/                  # en, de, lv translations
│   ├── sass/                  # SCSS source files
│   └── views/                 # Blade templates
├── src/                       # PHP source — namespace: Sendportal\Base
│   ├── Adapters/              # Email provider adapters (8)
│   ├── Console/               # Artisan commands
│   ├── Events/                # Domain events
│   ├── Exceptions/            # Custom exceptions
│   ├── Facades/               # Laravel facades
│   ├── Factories/             # MailAdapterFactory
│   ├── Http/                  # Controllers, Requests, Resources
│   ├── Interfaces/            # Contracts/interfaces
│   ├── Listeners/             # Event listeners
│   ├── Models/                # 13 Eloquent models
│   ├── Pipelines/             # Campaign pipeline stages (3)
│   ├── Presenters/            # View presenters
│   ├── Providers/             # 5 Service providers
│   ├── Repositories/          # Repository pattern (interface + MySQL + Postgres)
│   ├── Routes/                # Route definitions (Web + API)
│   ├── Rules/                 # Custom validation rules
│   ├── Services/              # Domain services
│   ├── Traits/                # Reusable traits
│   ├── View/                  # Blade view composers
│   └── SendportalBaseServiceProvider.php
└── tests/
    ├── Feature/
    └── Unit/
```

**Technology baseline:**
- PHP `^8.2|^8.3`
- Laravel `^10.0|^11.0`
- Frontend: Laravel Mix (webpack), Bootstrap 4, jQuery 3.6, Chart.js
- Database: MySQL or PostgreSQL (dual repository implementations)
- Package type: `library`

---

## 3. Dependency Analysis

### Production PHP Dependencies

| Package | Version | Purpose | ACTiV Decision |
|---------|---------|---------|----------------|
| `aws/aws-sdk-php-laravel` | `^3.9` | Amazon SES | Keep |
| `doctrine/dbal` | `^4.0` | DB schema introspection | Keep |
| `illuminate/support` | `^10|^11` | Laravel framework | Keep |
| `kriswallsmith/buzz` | `^1.2` | HTTP client (Mailgun) | Review — Guzzle preferred |
| `mailgun/mailgun-php` | `^4.2` | Mailgun | Keep |
| `mailjet/mailjet-apiv3-php` | `^1.6` | Mailjet | Keep |
| `nyholm/psr7` | `^1.8` | PSR-7 HTTP | Keep |
| `postal/postal` | `^2.0` | Postal mail server | Low priority |
| `rap2hpoutre/fast-excel` | `^5.4` | CSV import/export | Keep |
| `sendgrid/sendgrid` | `^8.1` | SendGrid | Keep |
| `wildbit/postmark-php` | `^6.0` | Postmark | Keep |

**Missing for ACTiV:** Microsoft 365 / OAuth2 SMTP — must BUILD NEW.

### Dev Dependencies

| Package | Purpose |
|---------|---------|
| `orchestra/testbench ^9.0` | Laravel package testing |
| `phpunit/phpunit ^11.0` | Test runner |
| `roave/security-advisories` | CVE checking |

### Frontend (package.json)

- Laravel Mix (webpack) — **legacy**, must replace with Vite
- Bootstrap 4, jQuery 3.6, Chart.js, Font Awesome — **replace with Tailwind + React**

---

## 4. Architectural Patterns

All verified from source code inspection:

### Repository Pattern (Confirmed)
- `BaseTenantRepository` — abstract, 453 lines, all CRUD scoped to `workspace_id`
- `BaseEloquentRepository` — non-tenant variant
- Interface-driven with MySQL/Postgres dual implementations
- IoC binding: `SendportalAppServiceProvider` auto-selects MySQL vs Postgres at runtime

### Adapter / Strategy Pattern (Confirmed)
- `MailAdapterInterface` — single `send()` contract
- 7 concrete adapters: SES, SMTP, Mailgun, SendGrid, Postmark, Mailjet, Postal
- `MailAdapterFactory` resolves the correct adapter from `EmailService` model

### Laravel Pipeline Pattern (Confirmed)
```php
Pipeline::send($campaign)->through([
    StartCampaign::class,
    CreateMessages::class,
    CompleteCampaign::class,
]);
```

### Event-Driven Architecture (Confirmed)
- `MessageDispatchEvent` → `MessageDispatchHandler` (queues the send job)
- Per-provider webhook events → per-provider listeners → `EmailWebhookService`
- `SubscriberAddedEvent` defined but no listeners registered (extension hook)

### Service Layer (Confirmed)
- `CampaignDispatchService` — campaign lifecycle
- `DispatchMessage` — single message orchestration
- `MergeContentService` — template + tag merge + CSS inlining
- `EmailWebhookService` — central tracking event processor
- `QuotaService` — SES quota enforcement

### Resolver / Callback Pattern (Confirmed)
- `ResolverService` stores callable resolvers:
  - `workspace` → `resolveCurrentWorkspaceId()`
  - `sidebar` → sidebar HTML injection
  - `header` → header HTML injection
- Host app **MUST** register the workspace resolver

### Facade (Confirmed)
- `Helper` facade — `isPro()` — checks if `Sendportal\Pro` namespace exists

---

## 5. Directory & Component Map

### Models (13)

| Model | Table | Key Fields |
|-------|-------|-----------|
| `BaseModel` | — | `$booleanFields[]` helper |
| `Campaign` | `sendportal_campaigns` | `workspace_id`, `status_id`, `template_id`, `email_service_id`, `subject`, `content`, `from_name`, `from_email`, `is_open_tracking`, `is_click_tracking`, `send_to_all`, `save_as_draft`, `scheduled_at` |
| `CampaignStatus` | `sendportal_campaign_statuses` | Consts: DRAFT=1, QUEUED=2, SENDING=3, SENT=4, CANCELLED=5 |
| `EmailService` | `sendportal_email_services` | `workspace_id`, `name`, `type_id`, `settings` (encrypted JSON) |
| `EmailServiceType` | `sendportal_email_service_types` | SES=1, SendGrid=2, Mailgun=3, Postmark=4, Mailjet=5, SMTP=6, Postal=7 |
| `Message` | `sendportal_messages` | `hash` (UUID), `workspace_id`, `subscriber_id`, `source_type`, `source_id`, `recipient_email`, `subject`, `message_id`, `open_count`, `click_count`, all tracking timestamps |
| `MessageFailure` | `sendportal_message_failures` | `message_id`, `severity`, `description`, `failed_at` |
| `MessageUrl` | `sendportal_message_urls` | `source_type`, `source_id`, `hash`, `url`, `click_count` |
| `Subscriber` | `sendportal_subscribers` | `workspace_id`, `hash` (UUID), `email`, `first_name`, `last_name`, `meta` (JSONB), `unsubscribed_at`, `unsubscribe_event_id` |
| `Subscription` | (pivot context) | Thin model |
| `Tag` | `sendportal_tags` | `workspace_id`, `name` |
| `Template` | `sendportal_templates` | `workspace_id`, `name`, `content` |
| `UnsubscribeEventType` | `sendportal_unsubscribe_event_types` | Bounce=1, Complaint=2, ManualAdmin=3, ManualSubscriber=4 |

**CRITICAL NOTE:** There is NO `Workspace` model in this package. The package assumes the host app provides a `Workspace` model and the resolver callback.

### Repositories

```
Repositories/
├── BaseEloquentRepository.php
├── BaseTenantRepository.php             # tenantKey = 'workspace_id'
├── EmailServiceTenantRepository.php
├── MessageUrlRepository.php
├── TagTenantRepository.php
├── TemplateTenantRepository.php
├── Campaigns/
│   ├── CampaignTenantRepositoryInterface.php
│   ├── MySqlCampaignTenantRepository.php
│   └── PostgresCampaignTenantRepository.php
├── Messages/
│   ├── MessageTenantRepositoryInterface.php
│   ├── MySqlMessageTenantRepository.php
│   └── PostgresMessageTenantRepository.php
└── Subscribers/
    ├── SubscriberTenantRepositoryInterface.php
    ├── MySqlSubscriberTenantRepository.php
    └── PostgresSubscriberTenantRepository.php
```

### Services

```
Services/
├── Campaigns/
│   ├── CampaignDispatchService.php      # Pipeline orchestrator
│   └── CampaignStatisticsService.php   # Aggregate stats
├── Content/
│   ├── MergeContentService.php         # Template + CSS inlining
│   └── MergeSubjectService.php
├── Messages/
│   ├── DispatchMessage.php             # Single message dispatch
│   ├── DispatchTestMessage.php
│   ├── MarkAsSent.php
│   ├── MessageOptions.php              # Value object
│   ├── MessageTrackingOptions.php      # Value object
│   ├── RelayMessage.php                # Calls adapter.send()
│   └── ResolveEmailService.php
├── Webhooks/
│   └── EmailWebhookService.php         # Central tracker
├── Helper.php                          # isPro()
├── QuotaService.php                    # SES quota
├── ResolverService.php                 # Callable resolver registry
└── Sendportal.php                      # Host app facade entry point
```

### Adapters (8)

| Adapter | Provider |
|---------|----------|
| `SesMailAdapter` | Amazon SES — uses `aws-sdk`, `ThrottlesSending`, `getSendQuota()` |
| `SmtpAdapter` | Generic SMTP — uses Laravel mail transport |
| `MailgunMailAdapter` | Mailgun — `mailgun-php` + `buzz` |
| `SendgridMailAdapter` | SendGrid |
| `PostmarkMailAdapter` | Postmark |
| `MailjetAdapter` | Mailjet |
| `PostalAdapter` | Postal |
| `BaseMailAdapter` | Abstract base |

### Pipelines (3 stages)

| Stage | Action |
|-------|--------|
| `StartCampaign` | Sets status → SENDING |
| `CreateMessages` | Iterates subscribers in chunks of 1000, creates Message records, fires `MessageDispatchEvent` per message |
| `CompleteCampaign` | Sets status → SENT |

### Events & Listeners

**Events:**
- `MessageDispatchEvent` — per message, triggers queued send
- `SubscriberAddedEvent` — on import, no default listeners (host app hook)
- 6x `{Provider}WebhookReceived` events

**Listeners:**
- `MessageDispatchHandler` — dispatches `DispatchMessage` job to queue
- 6x `Handle{Provider}Webhook` — parse payload → `EmailWebhookService`

### Controllers

```
Http/Controllers/
├── Api/
│   ├── CampaignsController          GET/POST/PUT/DELETE /v1/campaigns
│   ├── CampaignDispatchController   POST /v1/campaigns/{id}/send
│   ├── SubscribersController        CRUD /v1/subscribers
│   ├── TagsController               CRUD /v1/tags
│   ├── SubscriberTagsController     /v1/subscribers/{id}/tags
│   ├── TagSubscribersController     /v1/tags/{id}/subscribers
│   ├── TemplatesController          CRUD /v1/templates
│   ├── WorkspacesController         GET /v1/workspaces
│   ├── PingController               GET /v1/ping
│   └── Webhooks/
│       ├── SesWebhooksController    POST /v1/webhooks/aws
│       ├── MailgunWebhooksController POST /v1/webhooks/mailgun
│       ├── PostmarkWebhooksController POST /v1/webhooks/postmark
│       ├── SendgridWebhooksController POST /v1/webhooks/sendgrid
│       ├── MailjetWebhooksController  POST /v1/webhooks/mailjet
│       └── PostalWebhooksController   POST /v1/webhooks/postal
├── Auth/
│   ├── RegisterController
│   └── ProfileController
├── Campaigns/ (7 controllers)
├── EmailServices/ (2 controllers)
├── Subscribers/ (2 controllers)
├── Subscriptions/ (unsubscribe public page)
├── Tags/ (1 controller)
├── Webview/ (online viewer)
├── Workspaces/
│   ├── SwitchWorkspaceController
│   └── PendingInvitationController
├── DashboardController
├── MessagesController
└── TemplatesController
```

### Providers (5)

| Provider | Role |
|----------|------|
| `SendportalBaseServiceProvider` | Root — boots all sub-providers, migrations, views, translations, schedule |
| `SendportalAppServiceProvider` | IoC bindings — repos + QuotaService + Helper |
| `EventServiceProvider` | Event → Listener mapping |
| `RouteServiceProvider` | Registers route macros |
| `FormServiceProvider` | Blade form components |
| `ResolverProvider` | `sendportal.resolver` singleton |

### Console Commands

- `CampaignDispatchCommand` — runs every minute via scheduler (`withoutOverlapping()`). Finds QUEUED campaigns past `scheduled_at`. Calls `CampaignDispatchService`.

---

## 6. Component Deep-Dives

### 6.1 The Workspace Gap (MOST CRITICAL FINDING)

The package uses `workspace_id` throughout but:
- **No `Workspace` model defined in this package**
- No `workspaces` table migration
- `SwitchWorkspaceController` calls `$user->onWorkspace()` and `$user->switchToWorkspace()` — must exist on host User model
- `ResolverService` requires host app to call `Sendportal::setCurrentWorkspaceIdResolver(callable)` before any request

**This is by design** — the package is workspace-aware but workspace-definition-agnostic.

### 6.2 Email Service Credential Storage

```php
// Settings encrypted at model layer
public function setSettingsAttribute(array $data): void {
    $this->attributes['settings'] = encrypt(json_encode($data));
}
public function getSettingsAttribute(string $value): array {
    return json_decode(decrypt($value), true);
}
```

Credentials are encrypted with Laravel's APP_KEY. Good for security, but:
- APP_KEY rotation destroys all stored credentials
- Settings are not queryable
- All-or-nothing encryption (no field-level)

### 6.3 In-Memory Dedup — Critical Scalability Bug

```php
// CreateMessages.php
protected $sentItems = []; // GROWS UNBOUNDED IN MEMORY

protected function canSendToSubscriber($campaignId, $subscriberId): bool
{
    $key = $campaignId . '-' . $subscriberId;
    if (in_array($key, $this->sentItems, true)) { return false; }
    $this->sentItems[] = $key;
    return true;
}
```

For 100k subscribers: ~100k strings in RAM. For 1M subscribers: PHP OOM crash. Code has `@todo` acknowledging this.

### 6.4 Complaint Handler Bug

```php
// EmailWebhookService::handleComplaint()
public function handleComplaint(string $messageId, Carbon $timestamp): void
{
    // BUG: sets unsubscribed_at but NOT complained_at
    if (! $message->complained_at) {
        $message->unsubscribed_at = $timestamp; // WRONG FIELD
        $message->save();
    }
    $this->unsubscribe($messageId, UnsubscribeEventType::COMPLAINT);
}
```

The `complained_at` field is defined in the schema and model but never set.

### 6.5 isPro() Feature Detection

```php
public function isPro(): bool
{
    return class_exists('Sendportal\\Pro\\SendportalProServiceProvider');
}
```

Feature gate based on class existence. Clean extension point for ACTiV.

---

## 7. The Workspace / Tenant Model

### Current SP Model

```
[Host App User] --many-to-many--> [Workspace] (defined in HOST app)
                                       |
                         workspace_id on all entities:
                         Campaigns, Subscribers, Tags,
                         EmailServices, Templates, Messages
```

### ACTiV Mapping

```
Organization (ACTiV tenant)  ≡  Workspace (SendPortal)
    id                       ≡  workspace_id
```

**Decision:** Keep `workspace_id` column name in the core package. In ACTiV, `organizations.id` IS the `workspace_id`. No renaming needed. `BaseTenantRepository::$tenantKey = 'workspace_id'` remains valid.

---

## 8. Email Sending Flow (Full Trace)

```
[Scheduler: every minute]
  └── CampaignDispatchCommand
        └── Finds QUEUED campaigns where scheduled_at <= now()
        └── CampaignDispatchService::handle($campaign)
              └── Pipeline: StartCampaign → CreateMessages → CompleteCampaign
                    StartCampaign: status = SENDING
                    CreateMessages:
                      if send_to_all: chunkById(1000) all workspace subscribers
                      else: foreach tag → chunkById(1000) tag subscribers
                        foreach subscriber:
                          canSendToSubscriber() — in-memory dedup ⚠️
                          new Message() → save to DB
                          event(MessageDispatchEvent($message))
                            → MessageDispatchHandler (queued)
                              → DispatchMessage::handle()
                                  MergeSubjectService::handle() → save merged subject
                                  MergeContentService::handle() → template + CSS inline
                                  ResolveEmailService::handle() → load EmailService
                                  RelayMessage::handle()
                                    MailAdapterFactory::adapter($emailService)
                                    adapter->send(from, to, subject, content) → MessageId
                                  MarkAsSent::handle($message, $messageId)
                    CompleteCampaign: status = SENT
```

---

## 9. Webhook Tracking Flow

```
POST /v1/webhooks/{provider}  (NO AUTHENTICATION ⚠️)
  └── {Provider}WebhooksController::handle()
        └── fires {Provider}WebhookReceived event
              └── Handle{Provider}Webhook listener
                    └── parses provider payload
                    └── EmailWebhookService::{type}()
                          handleDelivery()   → delivered_at
                          handleOpen()       → opened_at, open_count++
                          handleClick()      → clicked_at, click_count++, upsert MessageUrl
                          handleComplaint()  → unsubscribed_at (BUG: complained_at not set)
                          handlePermanentBounce() → bounced_at + unsubscribe
                          handleFailure()    → MessageFailure record
```

---

## 10. Template & Content Merge Flow

**Supported variables:** `{{email}}`, `{{first_name}}`, `{{last_name}}`, `{{unsubscribe_url}}`, `{{webview_url}}`

Both `{{ tag }}` and `{{tag}}` formats are normalized via `NormalizeTags` trait.

```
MergeContentService::handle($message)
  └── load Campaign with template
  └── str_ireplace('{{content}}', $campaign->content, $template->content)
  └── compileTags() — normalize spacing variants
  └── mergeSubscriberTags() — email, first_name, last_name
  └── mergeUnsubscribeLink() → route('sendportal.subscriptions.unsubscribe', hash)
  └── mergeWebviewLink()    → route('sendportal.webview.show', hash)
  └── inlineStyles() — CssToInlineStyles::convert() (Tijs Verkoyen)
```

---

## 11. Security Audit Findings

### CRITICAL

| ID | Finding | Location |
|----|---------|---------|
| C1 | **Webhook endpoints have NO authentication** — fake events can open/click/bounce/unsubscribe | `ApiRoutes::sendportalPublicApiRoutes()` |
| C2 | **APP_KEY rotation destroys all provider credentials** | `EmailService::getSettingsAttribute()` |
| C3 | **In-memory subscriber dedup causes OOM for large campaigns** → crash → duplicate sends | `CreateMessages::$sentItems` |
| C4 | **No CSRF documentation** — host app must exclude webhook routes | Route config |

### HIGH

| ID | Finding | Location |
|----|---------|---------|
| H1 | **No rate limiting** on any API endpoint | `ApiRoutes.php` |
| H2 | **Auth delegated to host** — package applies no auth middleware | All API routes |
| H3 | **Complaint handler bug** — `complained_at` never set | `EmailWebhookService::handleComplaint()` |
| H4 | **Subscriber `meta` has no schema/size validation** | `Subscriber::$fillable` |
| H5 | **`source_type` is a full class name string** — namespace changes silently break tracking | `Message`, `EmailWebhookService` |

### MEDIUM

| ID | Finding | Location |
|----|---------|---------|
| M1 | MD5 used for URL hash dedup | `generateMessageUrlHash()` |
| M2 | Unsubscribe via GET — bots can trigger | `SubscriptionsController` |
| M3 | No soft deletes on any model | All models |
| M4 | Campaign content stored as plain mediumtext — XSS if rendered unescaped | `sendportal_campaigns` |
| M5 | No sending domain verification — any from_email can be used | Entire package |
| M6 | No `(workspace_id, email)` unique constraint — duplicate subscribers possible | `sendportal_subscribers` |

---

## 12. Reusage Matrix

### REUSE AS-IS

- All 7 email adapter classes
- `MailAdapterInterface`, `MailAdapterFactory`
- `MergeContentService` (extend for custom fields)
- `MergeSubjectService`, `RelayMessage`, `MarkAsSent`
- `ResolveEmailService`, `MessageTrackingOptions`, `MessageOptions`
- `EmailWebhookService` (after bug fix)
- All webhook listeners (after adding signature verification)
- `QuotaService` (extend for plan limits)
- `BaseTenantRepository`, `BaseEloquentRepository`
- All repository interfaces
- `CampaignDispatchService` (pipeline pattern)
- `StartCampaign`, `CompleteCampaign` pipelines
- `SubscriberAddedEvent` (host app hook)
- `EmailService`, `EmailServiceType`, `CampaignStatus` models
- `Template`, `Tag`, `Message`, `MessageUrl`, `MessageFailure` models
- `UnsubscribeEventType` model
- All 21 migrations (as base schema)
- `SendportalBaseServiceProvider` (extend)
- Route macros

### EXTEND

| Component | Extension |
|-----------|-----------|
| `Campaign` model | Add soft deletes |
| `Subscriber` model | Add custom fields support, soft deletes |
| `Message` model | Add soft deletes, `reply_to` tracking |
| `Template` model | Add `type` field (HTML/MJML), thumbnail |
| `MergeContentService` | Add custom field variable merge |
| `CreateMessages` pipeline | Replace `$sentItems[]` with DB-backed dedup |
| `CampaignDispatchCommand` | Per-workspace queue channel |
| `EmailWebhookService` | Fix `complained_at` bug; add signature hooks |
| Webhook controllers | Add signature verification middleware |
| `QuotaService` | Add plan-level quota per organization |

### OVERRIDE

| Component | Override |
|-----------|---------|
| `RegisterController` | ACTiV organization-based registration |
| `ProfileController` | ACTiV user management |
| `DashboardController` | Multi-org ACTiV dashboard |
| `SwitchWorkspaceController` | Organization switching |
| All Blade views | React + Inertia components |
| `package.json` + webpack | Vite + React + Tailwind |

### REPLACE

| Component | Replacement |
|-----------|------------|
| Bootstrap 4 + jQuery + Blade | React + Inertia + Tailwind CSS |
| Laravel Mix | Vite |
| In-memory subscriber dedup | Database-backed `MessageDedup` |
| MD5 URL hash | SHA-256 |
| `resources/sass/` | Tailwind config |

### BUILD NEW (in sendportal-core fork)

| Feature | Notes |
|---------|-------|
| `M365MailAdapter` | Microsoft 365 OAuth2 SMTP |
| Sending Domain model + migrations | Domain, SPF/DKIM/DMARC status |
| Custom Fields model + migrations | Subscriber custom attributes |
| Webhook signature verification | Per-provider HMAC middleware |
| Database-backed message dedup | Replace `$sentItems[]` |
| Fix `complained_at` bug | `EmailWebhookService::handleComplaint()` |
| SHA-256 URL hashing | Replace MD5 |
| Soft deletes on all models | `SoftDeletes` trait |
| `(workspace_id, email)` unique index | Migration |

---

## 13. ACTiV Architectural Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Workspace/Organization semantic mismatch | Data cross-contamination between orgs | 1 Organization = 1 Workspace ID; enforce with middleware |
| No workspace resolver registered | All requests get null workspace_id | Write integration test; fail-fast if resolver not registered |
| Large campaign OOM crash | Duplicate sends, data corruption | Replace in-memory dedup BEFORE production |
| Webhook spoofing | Fake tracking events, mass unsubscribes | Implement provider signature verification |
| APP_KEY rotation | All credentials lost | Build credential re-encryption utility |
| Scheduler-based dispatch | Blocking for large campaigns | Move to dedicated Horizon queues |
| No soft deletes | Permanent data loss | Add SoftDeletes before production |
| No rate limiting | API abuse | Add throttle middleware in ACTiV layer |

---

## 14. What Must Be Built New in ACTiV Application

### Core SaaS (`arvnabil/activ-marketing`)

| Feature | Notes |
|---------|-------|
| Authentication | Email/password + 2FA; consider Fortify |
| Organization model | `organizations` table; this IS the workspace |
| Organization membership | `organization_users` pivot; invite flow |
| Roles & Permissions | `spatie/laravel-permission`; Owner/Admin/Member |
| Subscription / Billing | Laravel Cashier + Stripe; plan tiers |
| Usage tracking | Monthly email volume, subscriber count per org |
| Plan enforcement | Middleware checks quota before dispatch |
| API Keys | Hashed tokens, per-org scoping |
| Sending Domains | DNS verification; SPF/DKIM/DMARC status |
| Customer Settings | Default from-name, reply-to, timezone |
| ACTiV UI | React + Inertia + Tailwind |
| Dashboard | Campaign stats, subscriber growth, delivery rates |
| SaaS Admin | Super-admin org/billing management |
| Workspace Resolver | Register `Sendportal::setCurrentWorkspaceIdResolver()` |
| Laravel Horizon | Queue monitoring, per-org queues |
| Docker / Nginx | Production infrastructure |
| Outbound Webhooks | Event delivery to customer endpoints |
| Future: Automation | Drip campaigns (SP Pro reference exists) |
| Future: Segmentation | Advanced audience targeting |
| Future: A/B Testing | Campaign variants |
| Future: Visual Builder | Drag-drop MJML builder |
