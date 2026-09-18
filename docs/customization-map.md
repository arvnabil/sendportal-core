# Customization Map — sendportal-core to ACTiV
**Date:** 2026-09-18

This document outlines the strategic decision matrix for transforming the `arvnabil/sendportal-core`
fork into the foundation for the ACTiV Marketing Cloud SaaS.

---

## 1. What We REUSE (Keep As-Is)

The following components from SendPortal Core are solid, scalable, and require no modification.
They provide the core email engine functionality.

- **Email Provider Adapters**: All 7 adapter classes (`SesMailAdapter`, `MailgunMailAdapter`, etc.).
- **Adapter Contracts & Factories**: `MailAdapterInterface` and `MailAdapterFactory`.
- **Message Content Generation**: `MergeContentService` (base functionality), `MergeSubjectService`.
- **Message Dispatch Core**: `RelayMessage`, `MarkAsSent`, `ResolveEmailService`.
- **Tracking Options**: `MessageTrackingOptions`, `MessageOptions` value objects.
- **Tenant Isolation Base**: `BaseTenantRepository` and `BaseEloquentRepository`.
- **Pipeline Architecture**: `CampaignDispatchService`, `StartCampaign`, `CompleteCampaign`.
- **Lookup Models**: `EmailServiceType`, `CampaignStatus`, `UnsubscribeEventType`.
- **Entity Models**: `EmailService`, `Template`, `Tag`, `Message`, `MessageUrl`, `MessageFailure`.
- **Schema**: All 21 original migrations provide a solid base schema.

---

## 2. What We EXTEND

These components will be kept but need modification within the `sendportal-core` fork to
meet ACTiV's enterprise requirements.

| Component | Required Extension |
|-----------|--------------------|
| **`Campaign` Model** | Add `SoftDeletes` trait. |
| **`Subscriber` Model** | Add `SoftDeletes` trait. Support for dynamic Custom Fields. |
| **`Message` Model** | Add `SoftDeletes` trait. Add `reply_to` tracking field. |
| **`Template` Model** | Add `type` field (HTML vs MJML vs Plain Text) and `thumbnail_url`. |
| **`MergeContentService`** | Extend tag replacement to support Subscriber Custom Fields. |
| **`CreateMessages` Pipeline**| Replace the in-memory `$sentItems` array with a database-backed deduplication table to prevent OOM errors on large lists. |
| **`CampaignDispatchCommand`**| Modify to push campaign processing to a per-workspace Queue channel rather than running synchronously. |
| **`EmailWebhookService`** | Fix the `complained_at` assignment bug (currently sets `unsubscribed_at` incorrectly). |
| **Webhook Controllers** | Apply signature verification middleware to ensure events actually come from the providers. |
| **`QuotaService`** | Extend to enforce plan-level limits per Organization, not just SES limits. |

---

## 3. What We REPLACE

These components from SendPortal Core will be completely discarded and overwritten.

| Component | Replacement |
|-----------|-------------|
| **Frontend Framework** | Discard Bootstrap 4, jQuery, and Blade views. Replace with **React, Inertia.js, and Tailwind CSS**. |
| **Build System** | Discard Laravel Mix (Webpack). Replace with **Vite**. |
| **Subscriber Deduplication**| Discard in-memory array deduplication. Replace with a Redis or DB-backed dedup tracking mechanism. |
| **URL Hashing** | Discard MD5 hashing in `generateMessageUrlHash()`. Replace with **SHA-256**. |
| **Controllers** | Discard UI controllers (`DashboardController`, `CampaignsController`, etc.) and replace with Inertia-driven controllers. |

---

## 4. What ACTiV Must BUILD NEW

These features do not exist in SendPortal Core and must be built from scratch to turn the
email engine into a multi-tenant SaaS platform.

### A. In the `sendportal-core` Engine
- **Microsoft 365 Adapter (`M365MailAdapter`)**: OAuth2 SMTP integration for enterprise clients.
- **Sending Domain Management**: Models and migrations for verifying domains, SPF, DKIM, and DMARC status.
- **Custom Fields System**: Models and migrations for defining and storing arbitrary subscriber attributes.
- **Webhook Security**: Provider-specific HMAC signature verification middleware.
- **Database Consistency**: Migration to add a `UNIQUE(workspace_id, email)` constraint to the subscribers table.

### B. In the Host Application (`activ-marketing`)
- **Authentication**: User login, registration, password reset, and 2FA.
- **Organization Management**: The `Workspace` concept in SendPortal will map 1:1 to an `Organization` model in ACTiV.
- **Roles and Permissions**: Owner, Admin, and Member roles per Organization.
- **Subscription and Billing**: Stripe/Paddle integration (e.g., Laravel Cashier) with plan tiers.
- **Usage Tracking**: Monthly email volume and active subscriber counting for billing limits.
- **API Key Management**: Secure, hashed API tokens scoped per Organization.
- **Customer Settings**: Global defaults per Organization (e.g., default from-name, timezone).
- **SaaS Admin Dashboard**: Super-admin interface for managing Organizations, billing, and system health.
- **Queue Infrastructure**: Laravel Horizon configuration with isolated queues per Organization to prevent noisy-neighbor issues.
- **Outbound Webhooks**: System to dispatch events (e.g., `subscriber.bounced`) back to customer endpoints.
