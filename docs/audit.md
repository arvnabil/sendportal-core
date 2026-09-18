# SendPortal Core — Technical Audit
**Repository:** `arvnabil/sendportal-core` · Branch: `master` (SHA `6e6ce11`)
**Date:** 2026-09-18

---

## What This Package Is

SendPortal Core is a **Laravel package** (`type: library`), not a standalone application. It installs into a host Laravel app via Composer and provides:

- Email campaign creation and scheduling
- Multi-provider email sending (SES, SMTP, Mailgun, Postmark, SendGrid, Mailjet, Postal)
- Subscriber/contact management with tags (lists)
- Open / click / bounce / complaint tracking via provider webhooks
- HTML email template merging with CSS inlining
- A basic Blade + Bootstrap 4 UI
- A REST API (campaigns, subscribers, tags, templates)

It is **NOT** a SaaS platform. It has no authentication, no billing, no roles, no organization hierarchy.

---

## Package Identity

| Item | Value |
|------|-------|
| Composer name | `mettle/sendportal-core` (not yet renamed) |
| Namespace | `Sendportal\Base` |
| PHP support | `^8.2 or ^8.3` |
| Laravel support | `^10.0 or ^11.0` |
| Database | MySQL + PostgreSQL (dual repo implementations) |
| Frontend | Bootstrap 4, jQuery 3.6, Laravel Mix (webpack) |
| Test framework | PHPUnit 11 + Orchestra Testbench |
| Root provider | `Sendportal\Base\SendportalBaseServiceProvider` |

---

## Production Dependencies

| Package | Purpose | ACTiV Status |
|---------|---------|--------------|
| `aws/aws-sdk-php-laravel ^3.9` | Amazon SES | Keep |
| `doctrine/dbal ^4.0` | DB schema introspection | Keep |
| `illuminate/support ^10 or ^11` | Laravel | Keep |
| `kriswallsmith/buzz ^1.2` | HTTP client (Mailgun) | Review (prefer Guzzle) |
| `mailgun/mailgun-php ^4.2` | Mailgun sending | Keep |
| `mailjet/mailjet-apiv3-php ^1.6` | Mailjet | Keep |
| `nyholm/psr7 ^1.8` | PSR-7 | Keep |
| `postal/postal ^2.0` | Postal server | Low priority |
| `rap2hpoutre/fast-excel ^5.4` | CSV import/export | Keep |
| `sendgrid/sendgrid ^8.1` | SendGrid | Keep |
| `wildbit/postmark-php ^6.0` | Postmark | Keep |

**Missing for ACTiV:** Microsoft 365 / OAuth2 SMTP — not present, must be built.

---

## Database Schema Summary

All tables use the `sendportal_` prefix. 21 migrations (2017–2021).

| Table | Purpose |
|-------|---------|
| `sendportal_email_service_types` | Provider type lookup (SES=1, SendGrid=2, Mailgun=3, Postmark=4, Mailjet=5, SMTP=6, Postal=7) |
| `sendportal_email_services` | Provider config per workspace; credentials stored as encrypted JSON blob in mediumtext |
| `sendportal_campaign_statuses` | Status lookup (Draft=1, Queued=2, Sending=3, Sent=4, Cancelled=5) |
| `sendportal_campaigns` | Campaign: name, subject, content, from, tracking flags, scheduling, status |
| `sendportal_templates` | HTML wrapper templates with `{{content}}` placeholder |
| `sendportal_tags` | Contact lists / audience segments |
| `sendportal_subscribers` | Contacts: email, name, meta (JSONB), unsubscribed_at, hash (UUID) |
| `sendportal_tag_subscriber` | Subscriber <-> Tag pivot |
| `sendportal_campaign_tag` | Campaign <-> Tag pivot (target audience) |
| `sendportal_messages` | One row per email sent; all tracking timestamps, open/click counts |
| `sendportal_message_urls` | Per-URL aggregate click counts |
| `sendportal_message_failures` | Failed send log |
| `sendportal_unsubscribe_event_types` | Reason lookup (Bounce, Complaint, Manual Admin, Manual Subscriber) |

**No `workspaces` table** exists in this package. `workspace_id` is a bare integer on every entity — no FK to any workspaces table. The host application owns the Workspace model.

---

## Critical Security Findings

### CRITICAL — Must fix before any production traffic

**C1 — Webhook endpoints have no authentication**
All webhook routes (`/v1/webhooks/aws`, `/mailgun`, `/postmark`, `/sendgrid`, `/mailjet`, `/postal`) are
registered with zero middleware. Any HTTP client can POST fake events to open, click, bounce, or
unsubscribe any subscriber.
- File: `src/Routes/ApiRoutes.php`
- Fix needed: Per-provider HMAC signature verification middleware.

**C2 — APP_KEY rotation destroys all provider credentials**
`EmailService::settings` is encrypted with Laravel's `encrypt()` using APP_KEY. Key rotation without
re-encryption makes all provider credentials permanently unreadable.
- File: `src/Models/EmailService.php`
- Fix needed: Credential re-encryption artisan command before any key rotation.

**C3 — In-memory subscriber dedup causes OOM and duplicate sends**
`CreateMessages` uses `$this->sentItems[]` (a PHP array) for dedup. At 100k+ subscribers this causes
PHP OOM crash, potentially re-dispatching the campaign and sending duplicates.
- File: `src/Pipelines/Campaigns/CreateMessages.php` (line 18, 111–124)
- Code itself has a `@todo` comment flagging this.
- Fix needed: Database-backed dedup.

**C4 — No CSRF exemption guidance for webhook routes**
If host app CSRF middleware is not correctly configured to exclude webhook endpoints, all provider
webhooks return 419.

### HIGH — Fix before launch

**H1 — `complained_at` field is never set (tracking bug)**
`EmailWebhookService::handleComplaint()` sets `unsubscribed_at` instead of `complained_at`.
The `complained_at` column exists in the schema and model but is never written to.
- File: `src/Services/Webhooks/EmailWebhookService.php` (line 119)

**H2 — No rate limiting on any API route**
No `throttle` middleware on any endpoint. Must be enforced at the ACTiV application layer.

**H3 — No auth middleware in the package**
All API routes (campaigns, subscribers, tags, templates) have no auth. Host app must apply auth.
ACTiV must verify this is enforced.

**H4 — `source_type` stored as full PHP class name**
`sendportal_messages.source_type` stores `Sendportal\Base\Models\Campaign` as a literal string.
Namespace changes silently break all tracking queries and webhook handlers.

### MEDIUM — Fix before scale

**M1 — No `(workspace_id, email)` unique constraint on subscribers**
Duplicate contacts per workspace are possible.

**M2 — MD5 used for URL hash deduplication**
`generateMessageUrlHash()` uses MD5. Replace with SHA-256.

**M3 — Unsubscribe via GET request**
Email prefetch scanners and link-scanning bots can trigger mass unsubscribes.

**M4 — No soft deletes on any model**
All deletes are permanent. No audit trail, no recovery.

**M5 — No sending domain verification**
Any `from_email` can be set with no domain ownership verification.

---

## Architectural Risks for ACTiV

| Risk | Impact | Required Action |
|------|--------|----------------|
| Workspace resolver not registered | All queries use wrong workspace | Host app MUST call `Sendportal::setCurrentWorkspaceIdResolver(fn)` |
| In-memory dedup at scale | OOM crash, duplicate sends | Replace before production |
| Scheduler-based dispatch blocks for large lists | Synchronous campaign creation | Move to dedicated Horizon queues |
| Webhook spoofing | Fake tracking, mass unsubscribes | Provider signature verification |
| APP_KEY rotation | All credentials unreadable | Re-encryption utility required |
| No soft deletes | Accidental permanent data loss | Add SoftDeletes to all models |
| `source_type` namespace coupling | Tracking breaks on refactor | Abstract to constant or config |

---

## What the Package Does NOT Have

Required for ACTiV, absent from sendportal-core:

- Authentication and user management
- Workspace / Organization model (only the ID is referenced)
- Roles and permissions
- Multi-tenancy hierarchy
- Subscription / billing
- Usage tracking or plan quotas
- API key authentication
- Sending domain management (SPF/DKIM/DMARC)
- Microsoft 365 / OAuth2 provider
- React / Inertia / Tailwind UI
- Laravel Horizon configuration
- Outbound webhooks to customers
- Email automation / sequences
- Segmentation beyond tags
- A/B testing
- Visual email builder
