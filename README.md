# Stellar eSIM API

A privacy-first Laravel backend for eSIM ordering, provisioning, usage, top-up, and optional Stellar account association.

**If we cannot see it, we cannot leak it.**

Stellar eSIM can be purchased at: https://stellarsecurity.com/stellar-esim

## Privacy model

- The 16-digit `plan_id` is never stored in plaintext.
- Database lookup uses a versioned PBKDF2-HMAC-SHA256 value.
- Provider order references are encrypted using a per-plan key.
- ICCIDs are never stored in plaintext.
- Optional user ownership is stored as a versioned keyed HMAC, not a raw user ID.
- Anonymous eSIMs remain valid and have no user association.
- API responses hide hashes, encrypted fields, and ownership references.

The optional user association is pseudonymous and supports authenticated account experiences without making ownership mandatory. See [SIMCARD_USER_OWNERSHIP.md](SIMCARD_USER_OWNERSHIP.md).

## Architecture

```text
Mobile app / website / commerce service
                |
                | plan_id and authenticated server requests
                v
        Stellar eSIM API
        |- plan hash lookup
        |- per-plan encryption
        |- optional user_ref HMAC
        `- eSIMAccess provider integration
```

The Mobile UI API must resolve the canonical Stellar `user_id` from the user's bearer token. The mobile app must never send a trusted user ID directly to this API.

## `plan_id` rules

- Exactly 16 digits after normalization
- Spaces are accepted in API input and removed before validation
- Never persisted in plaintext
- Required to decrypt provider order data

## Cryptography

### Plan lookup

- PBKDF2-HMAC-SHA256
- 800,000 iterations
- Secret pepper
- Versioned `v1:` format

### Provider data

- AES-256-GCM
- Per-plan key derived from the master key and private plan ID

### Optional user ownership

- HMAC-SHA256
- Separate dedicated secret from Azure Key Vault
- Versioned `v1:` format
- Indexed deterministic lookup
- Raw user IDs are not written to new simcard rows

## Main API routes

All internal routes use HTTP Basic Auth through `stellar.sim.basic`.

```text
GET     /api/v1/sim/plans
POST    /api/v1/sim/order
POST    /api/v1/sim/query
POST    /api/v1/sim/user
PATCH   /api/v1/sim/user
DELETE  /api/v1/sim/user
DELETE  /api/v1/sim/user/all
```

Top-up routes remain under:

```text
/api/v1/topupcontroller/*
```

## Local setup

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan test
php artisan serve
```

Generate the optional user-reference key:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Set it as:

```env
ESIM_USER_REF_HASH_VERSION=1
ESIM_USER_REF_HASH_KEY_V1=...
```

## Azure deployment

Use Azure App Service application settings and Key Vault references for all secrets. After deployment:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
```

Use a shared cache store for distributed throttling when scaling to multiple instances.

## Legacy migration

The old implementation defaulted missing order ownership to `user_id=1`. New code no longer does this. Existing `user_id=1` rows are treated as unverified and skipped by the ownership backfill command.

Dry run:

```bash
php artisan simcards:backfill-user-references
```

Commit verified legacy rows and clear raw identifiers:

```bash
php artisan simcards:backfill-user-references --commit --clear-raw
```

## Tests

```bash
php artisan test
```

Tests cover deterministic keyed user references, key rotation, anonymous ordering, assignment, conflict handling, listing, single detachment, and account-deletion detachment.

## License

MIT
