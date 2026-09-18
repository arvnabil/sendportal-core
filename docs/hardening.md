# Phase 2: Core Hardening

The ACTiV Marketing Cloud phase 2 audit addresses four critical structural and security issues identified in SendPortal Core.

## 1. Tenant / Workspace Isolation

SendPortal Core suffered from critical Insecure Direct Object Reference (IDOR) vulnerabilities where entities were not strictly scoped to the active workspace. This allowed cross-tenant data access if IDs were enumerated.

**Implemented Fixes:**
- **Campaign Validation:** `CampaignStoreRequest` now validates that `email_service_id` and `template_id` strictly belong to the current `workspace_id`.
- **Template Validation:** `CampaignTemplateUpdateRequest` enforces template ownership during campaign updates.
- **Subscriber Validation:** `SubscriberRequest` ensures associated tags belong exclusively to the active workspace.

## 2. Complaint Tracking

When a subscriber complained (marked email as spam), SendPortal was incorrectly treating this solely as an unsubscribe event rather than recording the complaint timestamp.

**Implemented Fixes:**
- **Complaint Timestamp:** Modified `EmailWebhookService::handleComplaint()` to correctly record the `complained_at` timestamp on the message entity before processing the unsubscribe action.
- **Message Updates:** Ensured that subsequent queries and metrics can reliably distinguish between standard unsubscribes and active spam complaints.

## 3. Subscriber Uniqueness

SendPortal Core lacked a database-level uniqueness constraint for subscribers across workspaces, relying instead on application-level validation which is susceptible to race conditions.

**Implemented Fixes:**
- **Schema Hardening:** Created a migration to add a composite unique index on `(workspace_id, email)` within the `sendportal_subscribers` table.
- **Data Integrity:** This strictly enforces that an email address can only exist once per tenant, preventing duplicate subscriber records and potential data corruption.

## 4. Webhook Security

Webhook endpoints were previously processing payloads asynchronously without verifying the provider signatures, leading to potential spoofing and Queue exhaustion (DoS) attacks.

**Implemented Fixes:**
- **Mailgun Integration:** Moved `checkWebhookValidity()` to `MailgunWebhooksController` to verify cryptographically signed payloads before returning HTTP 200 and dispatching events.
- **SendGrid Integration:** Implemented Twilio EventWebhook validation using ECDSA public keys in `SendgridWebhooksController`.
- **Postmark Integration:** Added `X-Postmark-Secret` header validation in `PostmarkWebhooksController` to authenticate payloads.
- **Graceful Failures:** Invalid payloads are now immediately rejected with a 403 Unauthorized status, protecting the queue from malicious payloads.
