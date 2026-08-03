# Reloadly Utilities Production Guide

## Architecture

The Laravel backend is the source of truth for Reloadly Utilities catalog data, denomination rules, currency handling, wallet reservations, and provider status. Flutter only renders the normalized catalog and submits the selected `billerId`, amount, optional `amountId`, and subscriber account number.

The integration uses only Reloadly Utility Payments endpoints documented in the official OpenAPI:

- `POST https://auth.reloadly.com/oauth/token`
- `GET /accounts/balance`
- `GET /billers`
- `POST /pay`
- `GET /transactions`
- `GET /transactions/{id}`

## Payment Flow

1. Flutter fetches normalized billers from `GET /api/v1/reloadly/utilities/billers`.
2. The user explicitly selects a country, biller, and, for `FIXED`, a catalog plan.
3. Laravel reloads the biller catalog and validates the amount, currency semantics, and `amountId`.
4. Laravel creates a `payment_intent` and reserves the authenticated client's wallet inside a database transaction with `SELECT ... FOR UPDATE`.
5. Laravel calls Reloadly outside the database transaction, using `payment_intents.provider_reference` as Reloadly `referenceId`.
6. `SUCCESSFUL` confirms the client-wallet debit. `FAILED` releases the reservation. `PROCESSING` and timeouts retain the reservation.

The Super Admin wallet is not part of this flow.

## Idempotency

`POST /api/v1/reloadly/utilities/pay` requires `X-Idempotency-Key`.

- Same authenticated user, key, and canonical request payload: the stored HTTP status and JSON response are replayed.
- Same key with a different payload: `409` with `error: IDEMPOTENCY_KEY_REUSED`.
- Same key while the original request has no stored response: `409` with `error: IDEMPOTENCY_REQUEST_IN_PROGRESS`.

The encrypted response body and status are held in `idempotency_keys`. Retention and deletion must be managed by an operational policy; do not purge active/recent keys ad hoc.

## Reconciliation

The scheduler runs `php artisan utilities:reconcile` every five minutes. The command selects only `reloadly_utilities` intents in `PROCESSING` or `TIMEOUT` and dispatches one unique job per intent to the `reloadly` queue.

Each job uses a per-intent cache lock. It queries Reloadly in this order:

1. `GET /transactions/{id}` when a provider transaction ID is known.
2. `GET /transactions?referenceId={provider_reference}&page=1&size=1` when it is not.

Outcomes:

- `SUCCESSFUL`: confirm the wallet debit and mark the intent `SUCCESS`.
- `FAILED` or `REFUNDED`: release the reservation and mark the intent final.
- `PROCESSING`, not found, authentication error, or provider error: preserve the reservation for a future attempt.

Operations can run a bounded synchronous reconciliation for incident handling:

```bash
php artisan utilities:reconcile --sync --limit=100
```

Run a queue worker in production:

```bash
php artisan queue:work --queue=reloadly
php artisan schedule:work
```

For a multi-node deployment, use a shared atomic cache store for the scheduler, queue uniqueness, and locks.

## Sensitive Data

`payment_intents.subscriber_account_number` uses Laravel encrypted casts. The legacy Utilities history stores only a hash. Provider payload persistence redacts subscriber details and PIN details before writing local JSON. Do not log bearer tokens, subscriber account numbers, PIN values, or raw provider response bodies.

## Required Environment

```dotenv
RELOADLY_MODE=live
RELOADLY_CLIENT_ID=...
RELOADLY_CLIENT_SECRET=...
RELOADLY_AUTH_URL=https://auth.reloadly.com/oauth/token
RELOADLY_UTILITIES_LIVE_URL=https://utilities.reloadly.com
RELOADLY_UTILITIES_SANDBOX_URL=https://utilities-sandbox.reloadly.com
```

Set `RELOADLY_MODE=sandbox` for non-production testing. Credentials and configuration must be cached only after a configuration review:

```bash
php artisan config:cache
```

## Release Checklist

1. Run migrations and confirm `payment_intents` and `idempotency_keys` exist.
2. Configure a shared cache and the `reloadly` queue worker.
3. Run the targeted Reloadly test suite and Flutter analysis/tests.
4. Verify Sandbox catalog and a controlled fixed-plan payment before enabling Live credentials.
5. Monitor structured reconciliation logs and alert on repeated `PROVIDER_ERROR`, `AUTHENTICATION_ERROR`, and stale `TIMEOUT` intents.

## Rollback

Do not roll back database migrations while reserved or processing intents exist. First stop new Utilities payments, leave reconciliation running until pending intents reach a final state, then deploy the previous application version. A provider result must always be reconciled before a wallet reservation is manually released.

## Flutter Clone Migration

`edgpay-master2-strict` contains the Utilities migration. `edgpay-master` is intentionally not overwritten. Migrate only these Utilities files after reviewing local changes:

- `lib/models/reloadly_utility_models.dart`
- `lib/services/reloadly_service.dart`
- `lib/features/reloadly/presentation/pages/reloadly_utilities_page.dart`

Then run `flutter analyze` and `flutter test` in the target clone.
