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

### 2. No single global secret is sufficient to decrypt user data
- There is **no master decrypt-everything key**.
- Each plan derives its own encryption key from:
    - a secret master key
    - the user-known `plan_id`
- Without the `plan_id`, decryption is cryptographically infeasible.

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
│  
│  plan_id (16 digits, user-only)  
▼  
API  
├─ derivePlanHash(plan_id)  → DB lookup  
├─ derivePlanKey(plan_id)   → per-plan encryption  
└─ provider integration

### plan_id rules
- Exactly **16 digits**
- Numbers only
- May be printed with spaces for humans
- Normalized before cryptographic use

---

## Cryptography

### Plan hash (DB lookup)
- **PBKDF2-HMAC-SHA256**
- **800,000 iterations**
- Keyed with a secret hash key (pepper)
- Versioned (`v1:` prefix)

#### Why 800,000 iterations?

- **OWASP Password Storage Cheat Sheet (2023)** recommends:
    - ≥ **600,000 iterations** for PBKDF2-HMAC-SHA256
- Security guidance increasingly recommends **tuning for time**, not a fixed number:
    - ~1–3 seconds per hash on production hardware
- 800,000 iterations is chosen as a **2025-safe baseline**:
    - Strong resistance to offline brute-force
    - Still operationally viable on modern servers
    - Can be increased further as CPU headroom allows

> Iteration count is an **operational parameter**, and can be increased over time while keeping the same hash versioning strategy.

**Source:**
- OWASP Password Storage Cheat Sheet (2023)  
  https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html

**Purpose:**  
Resist offline brute-force attacks even if the database and hash key are leaked.

---

### Encryption
- **AES-256-GCM**
- 96-bit IV
- 128-bit authentication tag
- Key derived per plan using `HMAC(master_key, plan_id)`

**Purpose:**  
Ensure encrypted values are useless without the user’s `plan_id`.

---

## Threat Model (Explicit)

This system assumes:
- Databases may leak
- Logs may leak
- Backups may leak

This system guarantees:
- No operator can enumerate users
- No operator can decrypt user data without the `plan_id`
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

---
