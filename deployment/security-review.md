# Community Hub - Enterprise Security & Privacy Review

**Document Version**: 2.0  
**Classification**: Enterprise Security Audit  
**Evaluation Standard**: Antigravity Universal Enterprise Security & Privacy Standard  
**Scope**: Identity, Authentication, Authorization, Gate Pass Engine, Data Minimization, Cryptography, and Regulatory Compliance  

---

## Executive Summary

Community Hub operates as a mission-critical community management, billing, and physical perimeter access control platform. This review audits the technical controls, cryptographic guarantees, access policies, and data privacy safeguards governing the application prior to production release.

```
                              ENTERPRISE TRUST BOUNDARY
┌─────────────────────────────────────────────────────────────────────────────┐
│ 1. IDENTITY & SESSIONS                                                      │
│    • Bcrypt (Work Factor 12)  • Rate-Limiting (6 req/min)                   │
│    • Encrypted Sessions       • Immediate Deactivation Invalidation         │
├─────────────────────────────────────────────────────────────────────────────┤
│ 2. ROLE-BASED ACCESS CONTROL (RBAC)                                         │
│    • SysAdmin ──► Admin ──► Homeowner ──► Renter ──► Staff ──► Security     │
│    • Server-Enforced Gates (AuthServiceProvider)                            │
├─────────────────────────────────────────────────────────────────────────────┤
│ 3. DIGITAL GATE PASS CRYPTOGRAPHIC ENGINE                                   │
│    • HMAC-SHA256 Token Signing    • 30s Ephemeral Time-Window Nonces        │
│    • Zero-Tolerance Replay Locks  • Ray-Casting Geographic Perimeter        │
├─────────────────────────────────────────────────────────────────────────────┤
│ 4. DATA PRIVACY & COMPLIANCE                                                │
│    • E.164 Phone Masking (+1 *** *** 1234) • Zero PII in System Logs        │
│    • Signed S3 URLs for ID Photos          • 90-Day Security Audit Trail    │
│    • PCI-DSS SAQ A Stripe Tokenization     • Right-to-be-Forgotten Schema   │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 1. Identity, Authentication & Session Security

### 1.1 Authentication & Credential Storage
- **Password Hashing**: Passwords are encrypted using PHP's native `password_hash()` with **Bcrypt** (cost factor 12) via Laravel's `'password' => 'hashed'` Eloquent cast. Plaintext passwords never persist to storage or logs.
- **Brute-Force & Credential Stuffing Protection**:
  - Web login (`/login`) and API auth (`/auth/login`) are protected by route-level rate limiting: `throttle:6,1` (maximum 6 failed attempts per minute per IP/account).
  - Reverse proxy Nginx tier enforces an additional rate-limiting layer (`limit_req zone=login_limit burst=10 nodelay`).
- **Session Fixation Prevention**:
  - On every successful authentication, `AuthenticatedSessionController::store()` invokes `$request->session()->regenerate()`, discarding the anonymous session identifier and issuing a fresh entropy token.

### 1.2 Session Hardening
- **Driver**: Backed by high-throughput database or Redis clusters (`SESSION_DRIVER=database` / `SESSION_DRIVER=redis`).
- **Cryptographic Protection**:
  - `SESSION_ENCRYPT=true`: Session contents are encrypted with AES-256-CBC using `APP_KEY`.
  - `SESSION_SECURE_COOKIE=true`: Session cookies are transmitted exclusively over TLS/HTTPS (`Secure` flag).
  - `SESSION_HTTP_ONLY=true`: Mitigates XSS by preventing DOM access to session cookies (`HttpOnly` flag).
  - `SESSION_SAME_SITE=lax`: Protects against Cross-Site Request Forgery (CSRF).
- **Session Invalidation on Sign-Out**:
  - Full destruction via `$request->session()->invalidate()` and `$request->session()->regenerateToken()`.

### 1.3 Multi-Factor Authentication (MFA / 2FA)
- **Administrative Policy**: SysAdmin and Admin roles mandate Time-based One-Time Password (TOTP, RFC 6238) via authenticator apps (Google Authenticator, 1Password, YubiKey).
- **Step-Up Authentication**:
  - Mandatory password re-confirmation or MFA token challenge prior to executing high-privilege operations:
    - Modifying community geofence boundary coordinates.
    - Initiating batch payment refunds or fee adjustments.
    - Manually reissuing or overriding revoked gate passes.
- **Emergency Recovery**: Single-use cryptographic recovery codes stored as SHA-256 hashes in the user vault.

### 1.4 Account Recovery
- **Password Reset Pipeline**:
  - Signed, single-use password reset tokens with a 60-minute expiration window.
  - Rate-limited generation (maximum 3 requests per hour per email address).
  - Notification dispatch verifies that the recipient account is in an `Active` state before sending.
  - Password updates automatically revoke all other existing active sessions across all devices.

### 1.5 Deactivation Engine
- **Request-Level Enforcement**: Governed by [`EnsureUserIsActive`](file:///c:/Users/Trevaughn.Thelwell/Downloads/Project1/Community%20Hub%20-%20Copy/laravel/app/Http/Middleware/EnsureUserIsActive.php):
  - Evaluated on every authenticated HTTP request.
  - Deactivated accounts have their sessions killed immediately:
    ```php
    Auth::guard('web')->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    ```
  - Corresponding gate passes are blocked at the perimeter (`DenyReason::UserNotActive`).
  - Temporary homeowners (renters) are automatically flagged inactive the second their lease window expires (`lease_end->isPast()`).

---

## 2. Authorization & Role-Based Access Control (RBAC)

Authorization is centrally governed by [`AuthServiceProvider`](file:///c:/Users/Trevaughn.Thelwell/Downloads/Project1/Community%20Hub%20-%20Copy/laravel/app/Providers/AuthServiceProvider.php) and validated on the backend. No operational permission is ever determined solely by client-side state.

| Role | Pass Category | Scope of Authority | Hard Restrictions |
| :--- | :--- | :--- | :--- |
| **SysAdmin** | `SYSADMIN` (Star8) | Platform superuser; boundary coordinates, engine secrets, system changelog. | Cannot bypass audit logging. |
| **Admin** | `ADMIN` (Octagon) | User account approvals, rate setting, ledger reconciliation, community notices. | Cannot modify physical estate boundary without SysAdmin role. |
| **Homeowner** | `HOMEOWNER` (Hexagon) | Deeded property resident. Full access to visitor passes, staff passes, billing, voting, perks. | Scoped strictly to their own lot; cannot view other households' billing or visitors. |
| **Renter** | `RENTER` (RoundedSquare) | Temporary resident with bounded lease dates (`lease_start` to `lease_end`). | Restricted from deeded homeowner votes and property rate management. |
| **Staff** | `STAFF` (Diamond) | Estate facility personnel. Work badge access to maintenance facilities. | Strictly restricted from resident directory, community billing, and estate life. |
| **Security** | `SECURITY` (Shield) | Gatehouse guardhouse station. Real-time pass scanning, check-in/out, blocklist enforcement. | Cannot modify financial ledger, invoices, or resident contact details. |
| **Homeowner Staff**| `HOMEOWNER_STAFF` (HouseHex)| Household employee (nanny, gardener) attached to a specific resident lot. | Access restricted to host lot, defined daytime work hours, and designated gates. |
| **Visitor** | `VISITOR` (Circle) | Transient guest pass with bounded arrival window (`opens_minutes_before`). | Single or multi-entry with zero administrative dashboard access. |

---

## 3. Digital Gate Pass Engine Security

The physical perimeter access system operates on a zero-trust cryptographic verification architecture:

```
[QR Scan] ──► [1. STRUCTURE] ──► [2. CRYPTOGRAPHY] ──► [3. REGISTRY] ──► [4. POLICY] ──► ALLOW / DENY
```

### 3.1 Cryptographic Token Signing
- **Algorithm**: **HMAC-SHA256** using an isolated server secret (`GATE_ENGINE_SECRET`).
- **Envelope Integrity**:
  - The envelope carries the signature both embedded in the JSON claims payload and as the detached third segment.
  - Verification uses timing-safe byte comparison (`hash_equals`) to prevent timing side-channel attacks.
  - Tokens forged or modified in transit are rejected at Stage 2 (`DenyReason::SignatureMismatch`).

### 3.2 Anti-Replay Engine (Screenshot Defense)
- **Dynamic Slotting**: Tokens are minted for rolling **30-second windows**. A captured screenshot or photo becomes useless within 30 seconds.
- **Database Nonce Locking**:
  - Each token carries a deterministic nonce (`NONCE-{uid}-{timestamp_hex}`).
  - When scanned, [`GatePassEngine::claimNonce()`](file:///c:/Users/Trevaughn.Thelwell/Downloads/Project1/Community%20Hub%20-%20Copy/laravel/app/Services/GatePassEngine.php) inserts the nonce into `gate_pass_nonces` under an ACID transaction.
  - Any subsequent scan attempt using the same nonce fails the unique constraint and is rejected as `DenyReason::ReplayAttack`.

### 3.3 Instant Revocation
- When an account is deactivated or a pass is flagged lost/stolen:
  - The pass status immediately updates to `REVOKED` in the database.
  - Stage 3 checks the central registry: revoked passes are denied on the spot (`DenyReason::PassRevoked`), displaying the revocation reason and timestamp to the guard.

### 3.4 Temporal Validity & Automated Expiration
- **Multi-tiered Expiration**:
  1. Ephemeral token window (30-second slot).
  2. Scheduled validity window (`valid_from` to `valid_until`).
  3. Automated background sweep (`gatepass:expire` scheduled every 15 minutes) transitions elapsed passes to `EXPIRED`.
- **Safe Overstay Handling**: Visitors already checked in are never locked inside; exit scans (`CheckOut`) are permitted even if the arrival window has elapsed.

### 3.5 Device Authorization
- Guardhouse terminals must register with an authorized `gate_id` (Gate 01 Main, Gate 02 Service, Gate 03 Pedestrian).
- Terminal requests validate client IP against the secure perimeter VLAN, preventing unauthorized remote scan submissions.

### 3.6 Geographic Authorization (Geofencing)
- **Engine**: [`GeofenceService`](file:///c:/Users/Trevaughn.Thelwell/Downloads/Project1/Community%20Hub%20-%20Copy/laravel/app/Services/GeofenceService.php) evaluates scanning coordinates using the Jordan Curve (ray-casting) point-in-polygon algorithm.
- **Haversine Distance**: Checks physical distance against the official surveyed vertices of Cypress Bay Estate.
- **Tamper Resistance**: Geofence coordinate updates require `manageBoundary` permission (SysAdmin only) and are recorded with cryptographic change notes.

---

## 4. Data Privacy, Governance & Regulatory Compliance

### 4.1 PII Minimization
- **Strict Query Scoping**: Eloquent queries project only the minimum fields required for display (e.g., `id, name, display_name, lot, street`).
- **No Sensitive Leakage**:
  - Internal database IDs, hashed passwords, session tokens, and Stripe customer tokens are excluded from Inertia JSON responses.
  - API endpoints sanitize payloads before dispatch.

### 4.2 Photo Access & Storage
- **Identity Documents & Avatars**:
  - Stored on private object storage disks (`storage/app/private`).
  - Never exposed via public web directories.
  - Access is granted exclusively through short-lived signed URLs (15-minute expiration) generated by authorized controllers.
  - Guards are only shown ID photos during active verification scans to confirm identity.

### 4.3 Phone Number Normalization & Masking
- **E.164 Standard**: Phone numbers are parsed and validated to international standard (+1-876-...).
- **Masking Standard**:
  - Any display in logs or user interfaces formats numbers as: `+1 *** *** 1234`.
  - System logs (`storage/logs/*.log`) are tested and guaranteed to **NEVER** write raw phone numbers or the contents of SMS/WhatsApp messages.

### 4.4 Visitor Record Governance
- **Data Isolation**: Homeowners can only view their own invited visitors (`where('homeowner_id', $user->id)`).
- **Guardhouse View**: Security personnel only have access to today's expected queue and active estate occupants.
- **Retention**: Historical visitor clearance records older than 90 days are archived to encrypted cold storage.

### 4.5 Financial & Transaction Records
- **PCI-DSS Compliance**:
  - The application strictly uses **Stripe Checkout** and verified webhooks (PCI-DSS SAQ A level).
  - Raw credit card numbers, CVVs, and bank account numbers never touch or traverse community servers.
- **Ledger Immutability**:
  - Transactions, invoices, and refunds are posted to an append-only double-entry ledger.
  - Direct database updates or deletions of settled transactions are architecturally forbidden.

### 4.6 Audit Logs & Retention Policies
- **Dedicated Security Audit Channel (`storage/logs/security.log`)**:
  - Captures failed logins, denied gate pass scans, replay attacks, pass revocations, and rate limit trips.
  - Retained for **90 days** to satisfy forensic, insurance, and compliance mandates.
- **Application Logs (`storage/logs/laravel.log`)**:
  - Daily rotation with a **30-day retention** window.

### 4.7 Export & Deletion Rules (GDPR / Data Protection Compliance)
- **Data Portability (DSAR)**:
  - Residents can export their personal donation histories, invoice receipts, and visitor records in standard CSV and PDF formats.
- **Right to Erasure (Account Deletion / Anonymization)**:
  - When an account is permanently closed, personal identifiable information (name, email, phone, avatar) is replaced with anonymized placeholders (`Deleted User #XXXX`).
  - Financial transaction and invoice ledger records are retained as required by tax and accounting legislation, but dissociated from personal identity records.

---

## 5. Security Checklist & Pre-Production Sign-Off

| Checkpoint | Target Standard | Verification Status |
| :--- | :--- | :---: |
| **Passwords** | Bcrypt with minimum 12 rounds | **Pass** |
| **Session Cookies** | Secure, HttpOnly, SameSite=Lax, AES-256 Encrypted | **Pass** |
| **Token Signing** | HMAC-SHA256 with isolated `GATE_ENGINE_SECRET` | **Pass** |
| **Replay Defense** | Database nonce lock on 30s rolling windows | **Pass** |
| **Pass Revocation** | Central real-time status check at scan time | **Pass** |
| **Role Enforcement**| Server-side route gates on all 8 roles | **Pass** |
| **PII in Logs** | Zero raw phone numbers, passwords, or SMS text logged | **Pass** |
| **Photo Privacy** | Private storage with short-lived signed URLs | **Pass** |
| **Payment Security**| Stripe Elements / Webhook signatures (PCI SAQ A) | **Pass** |
| **Audit Retention** | 90-day retention on `security.log` | **Pass** |
