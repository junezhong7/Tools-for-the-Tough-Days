# Tools-for-the-Tough-Days

Tools for the Tough Days is a PHP/MySQL site with Stripe Checkout subscriptions, server-side auth, and Azure Web App container deployment.

## Stripe Subscription Activation

Subscription checkout is created in [checkout.php](checkout.php) and finalized in [webhooks/stripe.php](webhooks/stripe.php).

Flow:

1. Authenticated checkout writes a local `subscriptions` row with `status = 'pending'`.
2. Stripe redirects the customer to Checkout.
3. Stripe sends `checkout.session.completed` to the webhook.
4. The webhook resolves the local user from `stripe_customer_id`, `stripe_checkout_session_id`, `metadata.user_id`, or `client_reference_id`.
5. The webhook updates the pending row with the Stripe subscription id, current status, and billing period dates.
6. The dashboard reads those values from [api/dashboard.php](api/dashboard.php) and shows the current status plus renewal or end date.

## Required Environment Variables

Set these in Azure Web App application settings:

- `STRIPE_SECRET_KEY`
- `STRIPE_WEBHOOK_SECRET`
- `SITE_URL`
- `INTRO_COUPON_ID`
- `STRIPE_PRICE_INDIVIDUAL_MONTHLY`
- `STRIPE_PRICE_INDIVIDUAL_YEARLY`
- `STRIPE_PRICE_STARTER_ONLY`
- `STRIPE_PRICE_GROWTH_ONLY`
- `STRIPE_PRICE_TEAM_ONLY`
- `STRIPE_PRICE_STARTER_BUNDLE`
- `STRIPE_PRICE_GROWTH_BUNDLE`
- `STRIPE_PRICE_TEAM_BUNDLE`
- `STRIPE_PRICE_SESSIONS_3`
- `STRIPE_PRICE_SESSIONS_6`
- `AZURE_STORAGE_ACCOUNT`
- `AZURE_STORAGE_CONTAINER`
- `AZURE_STORAGE_PREFIX`
- `AZURE_STORAGE_ACCOUNT_KEY`
- `RESOURCE_TOKEN_SECRET`
- `RESOURCE_URL_TTL_SEC`
- `SMTP_HOST`
- `SMTP_PORT`
- `SMTP_USERNAME`
- `SMTP_PASSWORD`
- `MAIL_FROM`
- `MAIL_REPLY_TO`
- `SUBSCRIPTION_EMAIL_SCOPE` (`include_renewals` to send renewal emails, anything else for initial subscription only)
- `TEST_RECIPIENTS` (optional comma/space separated monitor recipients)

Database connection settings must also be configured for [lib/db.php](lib/db.php).

## Database Setup

For a new environment, run [sql/schema.sql](sql/schema.sql).

For an existing environment that already has subscription data, run [sql/2026-04-28-add-checkout-session-unique-index.sql](sql/2026-04-28-add-checkout-session-unique-index.sql) before deploying the updated webhook. It clears duplicate `stripe_checkout_session_id` values on older duplicate rows and then adds the unique index needed to keep one Checkout session mapped to one local subscription record.

## Stripe Webhook Configuration

Create a Stripe webhook endpoint pointing to:

```text
https://<your-domain>/webhooks/stripe.php
```

Subscribe to these events:

- `checkout.session.completed`
- `customer.subscription.updated`
- `customer.subscription.deleted`
- `invoice.payment_succeeded`
- `invoice.payment_failed`

## Azure Deployment

Build and push the image to Azure Container Registry:

```powershell
az acr build --registry <acr-name> --image tools-for-the-tough-days:<tag> .
```

Create the Web App for Containers if needed:

```powershell
az webapp create --resource-group <resource-group> --plan <app-service-plan> --name <webapp-name> --deployment-container-image-name <acr-name>.azurecr.io/tools-for-the-tough-days:<tag>
```

Point the app at the ACR image:

```powershell
az webapp config container set --resource-group <resource-group> --name <webapp-name> --container-image-name <acr-name>.azurecr.io/tools-for-the-tough-days:<tag> --container-registry-url https://<acr-name>.azurecr.io
```

Set application settings:

```powershell
az webapp config appsettings set --resource-group <resource-group> --name <webapp-name> --settings STRIPE_SECRET_KEY=<value> STRIPE_WEBHOOK_SECRET=<value> SITE_URL=https://<your-domain>
```

## End-to-End Verification

1. Log in with a normal user account.
2. Start a Stripe subscription checkout.
3. Complete payment with a Stripe test card.
4. Confirm the webhook call succeeds in Stripe Dashboard.
5. Open the dashboard and verify the subscription card shows the live status and `Renews on` or `Access ends on` date.
6. Confirm a payment row appears in the payment history after `invoice.payment_succeeded`.

## Transactional Email (SMTP)

The app now sends transactional emails for:

- user registration success
- subscription activation (`checkout.session.completed`)
- subscription renewals when `SUBSCRIPTION_EMAIL_SCOPE=include_renewals`

SMTP uses STARTTLS on port 587 and AUTH LOGIN credentials from environment variables.

If Stripe cannot deliver webhooks from the public internet during testing, use Stripe CLI locally and forward events to the app URL once the site is reachable.

## Notes

- `stripe_checkout_session_id` is now unique in `subscriptions`, which prevents duplicate local rows for the same Checkout session.
- First-time Stripe customers no longer depend on `users.stripe_customer_id` already being present when the webhook arrives.