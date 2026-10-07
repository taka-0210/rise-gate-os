# G5 Postmark Application Corrective v001

Human approved `ONE G5 POSTMARK APPLICATION CORRECTIVE / PRODUCTION-FREE` on 2026-10-07 JST.

## Implemented delta

Laravel named Postmark API mailer now has its explicit Message Stream binding. Composer adds Symfony Postmark Mailer 7.4.18, HTTP Client 7.4.20 and HTTP Client Contracts 3.7.3; previously locked packages are unchanged.

All three encrypted, after-commit Account Mail jobs use the Account-only delivery ledger. Invitation and Owner Onboarding still run their existing generation, token, issuer/sponsor and membership checks before sending. Legacy queued Account jobs lacking the new identity field derive the same identity at handling time. A queued job bound to a different Mailer than the current Account Mailer fails closed and cannot send through an old unsafe transport.

HMAC identities, payload and recipient hashes prevent repeated same-generation sends without saving bodies, action URLs, tokens or a plaintext recipient copy. Unique indexes and transactional row claims prevent concurrent workers from sending the same ledger item. A payload mismatch fails closed. Jobs retain three attempts with 60/300/900 second backoff. Explicit 429 rejection is retryable; other explicit 4xx rejections are permanent. Network and 5xx ambiguity is retained as `delivery_unknown`, preventing blind resend.

Laravel/Symfony's generic metadata carries the opaque delivery ID. Postmark parsing is isolated to its failure and Webhook adapters. The generic ledger and Account domain do not call a Postmark SDK. Provider acceptance is distinct from confirmed delivery.

The disabled-by-default `/api/webhooks/postmark/account-mail` route requires HTTPS, Basic Auth, actual peer IP allowlist, exact ServerID and MessageStream. It accepts Delivery, Bounce and SpamComplaint only. Complaint timestamps use Postmark's documented `BouncedAt`. Event receipt deduplication and monotonic status priority prevent duplicate handling and late Delivery from overwriting Complaint. Authenticated metadata can reconcile an accepted-but-unacknowledged send. An unknown MessageID returns 503 for provider retry. No raw payload, subject, recipient, bounce description or credentials are persisted.

`account-mail:status` provides only aggregate ledger counts. Queue process supervision, failed-job monitoring cadence and actual runtime readiness remain Application Release gates.

## Schema and frozen Candidate

The additive migration defines `account_mail_deliveries` and `account_mail_provider_events`. Only ephemeral test databases apply this schema during tests. Operational local/Production DBs were not migrated. This code requires the additive schema before operational use; it cannot be deployed as an environment-only change.

Frozen RC `924af91188cc60d33ff87c91b94ecc1d539566e6` remains unchanged. This Corrective is a later repository delta and is not automatically part of that RC. A separate Human Candidate Review must bind application artifacts, migration manifest and environment contract before release work proceeds.

Existing 172-key Shared State allowlist, attempts and immutable Evidence remain unchanged. Newly added Webhook/Server keys require future candidate-bound allowlist reconciliation. Both old Shared State attempts remain exhausted.

## Verification

Postmark HTTP traffic is simulated with Symfony MockHttpClient; no Provider/Production request or credential is used. Account permission and generation regression uses existing tests. JST Webhook timestamp projection is tested.

- Focused Account / invitation / onboarding / recovery / email / G5 compatibility: **102 tests / 1,447 assertions PASS**.
- Final Account / G5 / ReleaseHardening combined regression: **106 tests / 1,476 assertions PASS**, 29.91 seconds, including the corrected current-repository migration-count expectation.
- Final Postmark-only verification after the last race-protection change: **10 tests / 44 assertions PASS**.
- PHP syntax: **16 changed PHP files PASS**. Composer validate, Pint, route discovery and `git diff --check`: **PASS**.
- Frozen RC identity: `924af91188cc60d33ff87c91b94ecc1d539566e6`, unchanged. Implementation base HEAD: `bd895dea17aad7fc2e486102b0293fe9a3412544`; the corrective commit is reported in the delivery handoff. This Evidence is part of that commit.
- Full regression (`php artisan test --compact --log-junit ...`): **802 passed / 17 skipped / 12 failed, 7,261 assertions**, 355.96 seconds. JUnit report SHA-256: `102c19d3b259f5642755e09bd5d17d0c8606073381a155b0d8aebbba7d3415d5`. The JUnit aggregate distinguishes 11 assertion failures and one error; CLI groups these as 12 failed.
- Known failures retained: CompanyNavigation stale intended-URL expectation (1), G2 execution tests requiring an already-recorded immutable attempt directory to be absent (7), ScopeNine current-time/execution fixture expectations (3). None of their Product code, fixtures or immutable attempt Evidence was changed. ScopeNine symptoms are non-planned completion rejection, Today missing its expected action, and no completion actor recorded. No further cause is inferred here.
- Corrective-induced failure found and fixed: ReleaseHardening R0 audit's hard-coded repository migration count was 113, whereas the approved additive migration makes it 114. Only the current-repository test expectation was updated; the audit implementation and Frozen RC manifest were not changed. Final Account/G5/Release focused regression below verifies the repair. The full suite was not rerun after this one-line expectation correction; no full-suite PASS is claimed.

Outbound HTTP is bounded by a 10 second idle timeout / 15 second maximum duration and redirects are disabled. This avoids passing the Server Token into a redirected request. Queue `retry_after` and worker timeout must exceed this bounded send plus local work at the runtime gate. No Queue process has been started or reconfigured.

No operational migration, Production/Legacy/NewTarget connection, Postmark Account operation, credential issuance, DNS/public-entry mutation, Shared State generation or Deploy occurred. Test fixtures use mock tokens and ephemeral schemas only. The three unrelated pre-existing untracked Human documents remain untouched.

Composer audit reports 20 advisories in four already-locked packages (Guzzle, Laravel, CommonMark, Flysystem). The three new Symfony packages have no reported advisory in that audit. Existing packages were not upgraded under this Corrective. These findings remain a release security review item; no Deploy readiness is implied.

## Next Human Gate: Postmark Account Provisioning Only

Proposed approval: **ONE POSTMARK ACCOUNT PROVISIONING / HUMAN ONLYをAPPROVEします。**

Scope of the next gate: Human creates a corporate-owned Postmark account, verifies its administrative email and enables MFA. Use a RISE GATE-managed login and keep recovery codes in the corporate password manager. Report only `account_created`, `administrative_email_verified`, `mfa_enabled` booleans. Never share password, recovery codes or API Tokens in chat/Evidence.

Start from the official [Postmark site](https://postmarkapp.com/). Select Sign up / free trial, complete corporate Account registration and email verification, then enable MFA in Account security settings. Provider UI wording may vary. If the signup flow requires plan/payment, sender identity/domain, Server creation or exposes automatically generated Server/API Tokens, stop at that boundary and return for the separate gate. Do not copy or distribute a token.

Later gate creates the dedicated `Company OS Production` Server and Transactional `account-lifecycle` Stream. Postmark may automatically generate a Server API Token during Server creation: that credential creation must be explicitly included in the later approval. No Account API Token enters the Application. Sender Identity (`mail.company-os.jp`, `no-reply@mail.company-os.jp`, `pm-bounces.mail.company-os.jp`) remains a proposal. DNS, credential intake, Webhook enablement, Shared State and non-user delivery smoke are separate gates.

Actual Postmark Webhook verification performs external HTTP requests. It must wait until the explicitly approved HTTPS endpoint, allowlist, Basic Auth and a non-user test-ledger fixture exist. Do not run Provider Verify/Test buttons under this preparation.

## Continuing status

**G5 OPEN / DEPLOY NO-GO**

`PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE`; usable backup, DB restore readiness, storage final delta, Queue runtime, Candidate/refreeze, Release binding, Sender/DNS/SSL and Shared State remain open.

References: [Laravel Postmark API transport](https://laravel.com/docs/12.x/mail), [Postmark Webhook security/retry](https://postmarkapp.com/developer/webhooks/webhooks-overview), [Postmark Spam Complaint payload](https://postmarkapp.com/developer/webhooks/spam-complaint-webhook).
