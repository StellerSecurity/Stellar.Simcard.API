# Stellar eSIM API

A privacy-first eSIM backend built on a single uncompromising principle:

**If we cannot see it, we cannot leak it.**

This project powers eSIM purchases and provisioning while deliberately preventing the operator from accessing or reconstructing sensitive user data such as SIM identifiers or activation credentials.

This is not a CRM.
This is not an analytics platform.
This is infrastructure designed to know as little as possible.

---

## Core Principles

### 1. Zero-knowledge by design
- **plan_id (SIM-ID)** is never stored in plaintext.
- All database lookups use a **slow, keyed, non-reversible hash**.
- Sensitive provider data is encrypted **per plan**, not globally.

---

### 2. No single global secret is sufficient to decrypt user data.
- There is **no master decrypt-everything key**.
- Each plan derives its own encryption key from:
    - a secret master key
    - the user-known plan_id
- Without the plan_id, decryption is cryptographically infeasible.

---

### 3. Minimal data retention
We intentionally do **not** store:
- ICCID
- Full activation credentials in plaintext
- Account references
- User metadata beyond what is strictly required

What *is* stored:
- A versioned, slow hash of `plan_id`
- Encrypted provider order references
- Provider identifier
- Package code
- Order state

Nothing more.

---

## Architecture Overview

Client (app)
|
|  plan_id (16 digits, user-only)
v
API
├─ derivePlanHash(plan_id)  -> DB lookup
├─ derivePlanKey(plan_id)   -> per-plan encryption
└─ provider integration

### plan_id rules
- Exactly **16 digits**
- Numbers only
- May be printed with spaces for humans
- Normalized before cryptographic use

---

## Cryptography

### Plan hash (DB lookup)
- PBKDF2-HMAC-SHA256
- 300,000 iterations (Iteration count is an operational parameter and may be increased as hardware capacity allows.)
- Keyed with a secret hash key
- Versioned (`v1:` prefix)

Purpose:
Resist offline brute-force attacks even if the database and hash key are leaked.

### Encryption
- AES-256-GCM
- 96-bit IV
- 128-bit authentication tag
- Key derived per plan using HMAC(master_key, plan_id)

Purpose:
Ensure encrypted values are useless without the user’s plan_id.

---

## Threat Model (Explicit)

This system assumes:
- Databases may leak
- Logs may leak
- Backups may leak
- Operators may be compromised
- Governments may request data

This system guarantees:
- No operator can enumerate users
- No operator can decrypt user data without the plan_id
- No meaningful user data can be reconstructed at rest

---

## What this API is **not**

- No user dashboards
- No behavioral analytics
- No tracking identifiers
- No recovery backdoors
- No silent correlation across plans

---

## License

MIT

Do whatever you want.
Just don’t pretend this is a “privacy” system if you remove the hard parts.

---

## Final note

This code exists because most systems fail privacy not due to bad crypto,
but because they **store things they do not need**.

We chose not to.
