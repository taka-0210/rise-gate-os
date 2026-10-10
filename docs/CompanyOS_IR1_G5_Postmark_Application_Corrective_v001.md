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

## Continuation: DNS verification and downstream preparation — 2026-10-10 JST

This section is a later disposition. Earlier Application Corrective observations and frozen contracts above are retained; their historical flags are not rewritten as current remote observations.

### Human Evidence carried forward

- Dedicated Server: `Company OS Production`, non-secret Server ID `21093973`, type Live. Stream: `Account Lifecycle`, exact ID `account-lifecycle`, Transactional. Initial Server unchanged. Account sending approval remains unconfirmed/test mode as reported; Live Server type is not sending authorization.
- Adopted Sending Domain: `mail.company-os.jp`; From: `no-reply@mail.company-os.jp`; Return-Path: `pm-bounces.mail.company-os.jp`.
- Human reports adding only DKIM TXT and Return-Path CNAME in the `company-os.jp` zone, TTL 3600. No intentional existing-record edit/deletion. No Verify, send or Token operation.
- Wildcard dependency was accepted by Human for the system-mail-only namespace. Known existing use was not identified; absence of all unknown integrations was not claimed. Future corporate-mail clients must not use this namespace as their SMTP/IMAP/POP endpoint.
- Independent Postmark-value comparison by Human: **NOT REPORTED / UNKNOWN** for both records. Before/after full DNS inventory comparison: **NOT REPORTED / UNKNOWN**. Exact input reported by Human is not substituted for independent comparison.

### Latest read-only observation

| Record | Xserver ns1–ns5 | Cloudflare DNS-over-HTTPS | Google DNS-over-HTTPS |
| --- | --- | --- | --- |
| `20261008224642pm._domainkey.mail.company-os.jp` TXT | One RR each, TTL 3600, values mutually equal | One RR, equal to ns1 | Status 0, zero target TXT RRs; not equal |
| `pm-bounces.mail.company-os.jp` CNAME | One RR each, TTL 3600, values mutually equal | One RR, equal to ns1 | One RR, equal to ns1 |

TXT segments were concatenated in memory; CNAME comparison normalizes case and final dot. No record values, fingerprints, raw DNS packets or HTTP response bodies are printed or stored. Only comparison outcomes/counts are retained. Source-value equality is deliberately UNKNOWN because the Human-held source was not provided to this process.

Both `company-os.jp` and `app.company-os.jp` return one A RR and zero CNAME RRs through ns1, 1.1.1.1 and 8.8.8.8. These counts are consistent with earlier observations; this does not establish exact before/after value equality or all-zone non-mutation. There is no pre-change full-zone snapshot available to the assistant. No zone transfer or Production HTTP/SSH/SMTP connection was attempted.

**DNS Read-only Verification: PARTIAL.** Google DKIM non-agreement persists. Cache, propagation or any other cause is not established. Do not re-add, edit, delete or rotate DNS/Domain records to resolve this observation. A later read-only recheck needs no new mutation gate. Human source-value/inventory comparisons may be completed while this observation remains pending.

### Production-free dependency plan completed

1. **Sender verification:** retain exact Domain/From/Return-Path binding. Once DNS resolver agreement and Human value comparison are established, present the existing Postmark Verify gate, limited to DKIM and Return-Path verification for this Domain. That operation remains unapproved. A successful Domain verification does not prove mailbox existence, Account Email Verification, sending approval or delivery readiness. No extra individual Sender Signature is automatically required or created.
2. **Account Email Verification UNKNOWN:** keep the previously identified pending condition. Use available non-mutating Account/Profile evidence or a Human support response to establish whether a distinct requirement exists and, if required, whether it is satisfied. Existing login/MFA/Sender activation alone is not promoted to confirmation. No automatic email resend, profile change or new gate solely for reading status. Resolve at Credential/sending readiness review as already agreed; do not block unrelated local design work.
3. **Credential binding:** future intake must bind only the dedicated Server API Token to Server `21093973`; Application uses `MAIL_MAILER=postmark`, `ACCOUNT_MAIL_MAILER=postmark`, `POSTMARK_MESSAGE_STREAM_ID=account-lifecycle` and the adopted From identity. Never inherit the unsafe Legacy mailer or use an Account API Token. The process must accept secrets without terminal/history/Evidence output and return only sanitized validation outcomes. No Token display/copy/intake or authenticated Provider request is authorized now. A token's mere presence is not proof of Server ownership or runtime readiness.
4. **Shared State Corrective:** original 172-key frozen allowlist and initial/Corrective1 attempts remain immutable/exhausted. Required `rk11` remains required on NewTarget and is target-bound to `postmark`; source-only requiredness reconciliation follows the approved design, not an unreviewed fallback. A future separate generation must bind its candidate manifest, additional Postmark/Webhook keys, source/storage inventory, seed/checksum/final delta, owner/readability and `0600` environment permission. It cannot create state until the required Provider/Sender/DNS/Credential dependencies and existing backup/restore constraints are satisfied.
5. **Application/Candidate:** reuse the 106-test / 1,476-assertion focused corrective Evidence above. Current correction is not part of Frozen RC `924af91188cc60d33ff87c91b94ecc1d539566e6`. Candidate review must bind the application delta, lockfile and additive ledger migration. Do not silently refreeze, apply a migration, or alter old deployment manifests.
6. **Queue / ledger / Webhook:** local dedupe, 429 retry, ambiguous no-resend, authenticated Webhook reconciliation and JST tests are already evidenced. Actual Queue worker supervision, jobs/failed-jobs/schema, worker timeout/retry_after, and runtime permissions remain unverified. Webhook stays disabled until its HTTPS route, Basic Auth, current provider IP allowlist, ServerID/Stream and test-ledger fixture are explicitly bound. Provider Webhook Test/Verify, send and real delivery smoke require their existing runtime gates; no live runtime PASS is inferred from local tests.

### Next review and continuing blockers

Next review combines a further authorized read-only DNS recheck with Human comparison outcomes. No recurring schedule was created and no mutation is authorized. If those conditions pass, present **ONE POSTMARK DOMAIN AUTHENTICATION VERIFY / HUMAN ONLY**: check only this Domain's DKIM/Return-Path, report booleans/statuses, stop on failure without retry/edit/rotation, and do not send mail or touch Token/Webhook/Account approval. This is preparation of the already-required Verify boundary, not its execution or approval.

G5 overall remains **OPEN / DEPLOY NO-GO**. Account verification UNKNOWN, usable backup/restore, public-entry disposition, Shared State creation, storage final delta, Candidate/release binding, runtime Queue/Webhook/ledger readiness, SSL and existing security/regression findings remain unresolved. `PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE` is unchanged.

This continuation changes documentation only. No Production/Provider management operation, DNS mutation, Postmark Verify, send, Token operation, Shared State change, operational migration, Deploy, public entry or SSL change was performed. DNS inquiries were the only external target-related reads. No new runtime regression is claimed; existing validated code Evidence is reused.

## Later diagnostic disposition — 2026-10-10 JST

The preceding zero-TXT observations remain historical observations; this section does not rewrite them or infer their exact cause.

### Google diagnostic and TXT presentation reconciliation

- Fixed URL with HTTP `Cache-Control: no-cache`, fresh URL with documented ignored `random_padding` and DNSSEC checking on (`do=1, cd=0`), and a checking-disabled diagnostic control (`cd=1`) all returned HTTP 200 / DNS status 0 / TXT count 1 / TTL 3600. Question matched the exact Hostname/type; TC=false; AD=false. No negative SOA was present in these TXT replies. HTTP Age was absent; Cache-Control was `private, max-age=3600`. These observations do not prove which cache, if any, produced earlier zero-TXT results.
- The local comparison initially returned false despite a positive TXT response. Format diagnosis established Google returned an unquoted TXT value (224 characters, zero quotes/backslashes), while Cloudflare returned a quoted presentation (226 characters, two quotes, zero backslashes). The authority returned one 224-character segment. Google raw text and Cloudflare unquoted text each equalled the authority exactly. The previous quote-only extraction produces an empty string for the Google presentation; this is a **local comparison limitation**, not evidence of a current DNS value mismatch. This cannot explain or retroactively invalidate earlier reported TXT counts of zero.
- Final comparison accepts the observed plain-string format or concatenated quoted-string format; ambiguous escaped presentations stop rather than silently changing bytes. Values are compared only in memory and are not printed, hashed into Evidence or stored.
- Final authority ns1–ns5 results: DKIM TXT and Return-Path CNAME each one RR, correct type, TTL 3600, all values equal. Google and Cloudflare each returned status 0 / one matching RR / value equal to ns1 with DNSSEC checking enabled. Google DKIM TTL was 3541; other final reported TTLs were 3600. A resolver's remaining TTL need not equal the authority's configured TTL.
- DNSSEC context, not a DNSSEC PASS: Google DS query for `company-os.jp` returned status 0, no DS RR, authority types NSEC3/RRSIG/SOA, SOA TTL/minimum 900/900. DNSKEY query returned status 0, no DNSKEY RR, SOA TTL/minimum 1800/3600. AD=false / CD=false. No SERVFAIL or checking-dependent difference was observed. These are consistent with no published DNSSEC chain at this zone, but are not an independent cryptographic validation. Their negative-cache TTL metadata does not establish the missing historical TXT reply's TTL or cause. `cd=1` was a diagnostic control only, never an acceptance condition or production setting.

**Current public DNS propagation/value-agreement subcheck: PASS.** Google non-agreement is no longer observed. **Overall DNS Verification remains PARTIAL**, because Human comparison of Postmark vs Xserver values and before/after inventory remains NOT REPORTED / UNKNOWN. No source-value or whole-zone non-impact PASS is inferred from resolver equality.

### Contract and blocking-condition review

The original `postmark-production-mail-contract.json` and Production Mail Design require provider-generated DNS authentication and separate operation approvals; neither names Google DNS equality as a mandatory Product/Security condition. The previous continuation did use resolver agreement as a local review prerequisite for presenting Verify. The current diagnostic satisfies that agreement without weakening the condition.

For a future recurrence, all-authority agreement plus independent resolver agreement and Human source-value confirmation could justify reviewing Google disagreement as a separately tracked observability Pending for a **Verify-only** operation, provided no contradictory validation/security failure is found. That is a recommendation, not a changed acceptance contract, global propagation PASS or authorization to send. Changing a retained Verify prerequisite requires Human + ChatGPT Review; no fallback or bypass was implemented. This reclassification is unnecessary for the current agreeing observations.

Next required input remains the already-requested Human source-value and inventory comparison outcomes. No new Human Gate is added. Postmark Verify, sending, Token operations, Shared State and Deploy remain unapproved. Account Email Verification UNKNOWN, backup/restore blockers and `PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE` remain unchanged. **G5 OPEN / DEPLOY NO-GO**.

Diagnostic reference: [Google JSON DoH parameters and response flags](https://developers.google.com/speed/public-dns/docs/doh/json). No Application/runtime code or immutable frozen contract changed; this is documentation of read-only investigation only.

## Human Domain Authentication completion disposition — 2026-10-10 JST

Review base HEAD: `3c0b576dfd0f47abda972c95e6f971669cb43cd5`. This additive disposition preserves all preceding observations and immutable contracts. No new DNS or Provider query was performed for this review.

### Formal evaluation

**DNS READ-ONLY VERIFICATION / FORMAL PASS (bounded scope).** Human independently confirmed both Postmark/Xserver values exactly match without sharing or saving values. Human compared the management-plane inventory: existing A 3, MX 1, SPF TXT 1, NS 5 and existing DKIM TXT 4 retained; only the approved TXT and CNAME were added, with no unexpected Hostname/type. Combine these reports with the preceding five-authority / Cloudflare / Google agreement. Existing-record before/after value equality remains **UNVERIFIED**; this is not an all-zone invariance certification. No contrary observation requires reopening this bounded verification.

**ONE POSTMARK DOMAIN AUTHENTICATION VERIFY / FORMAL PASS.** Human reports the exact Sending Domain `mail.company-os.jp`, DKIM Verified, Return-Path Verified and authenticated Domain display. This is Human management-plane Evidence, not an independently retrieved Provider response. Human reports no DNS edit, send, Token operation, Shared State, Migration or Deploy in this step. The adopted From identity remains `no-reply@mail.company-os.jp`; verification does not establish receipt, successful delivery or Account sending approval.

### Production-free downstream reconciliation completed

- Sender/DNS prerequisites are now closed at their stated scope. Do not repeat registration, DNS application or Verify merely to advance another gate.
- Bind future credential validation to dedicated Server `21093973` and Transactional Stream `account-lifecycle`; bind Primary and Account Mailer to `postmark`. Credential must be a Server API Token, not an Account API Token or a Legacy credential. The token's presence/Server ownership is still unverified.
- Existing deployment workflow uses the GitHub `production` Environment. Proposed protected intake destination is its Environment Secret `POSTMARK_API_KEY`; this is a proposal, not a created secret or an implemented transfer path. Existing workflow does not establish Postmark credential transfer to Shared State. No workflow execution/change is authorized by this review.
- Before an executable credential-intake command is offered, locally verify its protected input/output path, fixed endpoint/TLS/no-redirect/timeouts, sanitized status-only projection and absence of secret-bearing logs/artifacts. Authenticated Provider read validation needs its own explicit approval; it must never persist a raw response that could contain tokens. Do not ask Human to paste credentials into chat, shell arguments or Evidence. No such helper is claimed complete in this documentation-only review.
- Shared State remains blocked on candidate/application/migration/environment reconciliation and credential/runtime/backup dependencies. Frozen RC `924af91188cc60d33ff87c91b94ecc1d539566e6`, original 172-key contract and exhausted Shared State attempts are unchanged. `rk11` remains NewTarget-required. No new generation is executed.
- Reuse recorded local Account ledger/dedup/retry/Webhook tests; do not promote them to actual Queue, Webhook, migration, sending or restore readiness. DMARC policy, account sending approval/test mode, runtime Queue and enabled Webhook remain separate unresolved dependencies.

### Next Human Gate proposal — not authorization

**ONE POSTMARK SERVER API TOKEN / SECRET-SAFE INTAKE** is the next credential boundary, not another DNS gate. Proposed Human operation: after approval and protected intake verification, select only `Company OS Production` / Server `21093973`, obtain its Server API Token and store it directly in the approved protected Secret destination. Never report its value. Do not rotate/delete existing tokens, use an Account API Token, change Sender/DNS/Webhook, approve sending, send a message or run deployment. Report only protected storage completion and the non-secret Server ID.

STOP before token access if the selected Server differs, the protected destination/access policy is unconfirmed, a UI action changes other configuration, or secret-safe handling cannot be maintained. No retry/rotation is implicitly authorized. Token-to-Server verification and Shared State consumption remain separately bound and unexecuted.

Account Email Verification remains **UNKNOWN**, independently of Sender authentication. Before approving this credential Gate, Human + ChatGPT must review that retained dependency using non-mutating Account/Profile information or a support response; do not silently waive it or request another verification email automatically. Preparation may continue without resolving it, but this document does not authorize credential access while that readiness review is outstanding.

### Continuing status and verification

**G5 OPEN / DEPLOY NO-GO.** Usable backup / DB restore readiness, storage final delta, Shared State, candidate/release/migration binding, credential binding, actual sending approval, Queue/Webhook/ledger runtime readiness, SSL and previously recorded security/regression findings remain open. `PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE` remains unchanged.

Documentation-only delta: existing 106 tests / 1,476 assertions focused PASS reused; no new test run or full-suite PASS claimed. Validate this delta with `git diff --check`. No new Production/Provider/DNS connection, secret intake, Production mutation, operational migration or Deploy occurred. Unrelated Human files are preserved. The delivery report identifies the resulting documentation commit.

## Credential Intake Readiness Review — 2026-10-10 JST

### Account Email Verification: evidence and recommended disposition

The current official [Account creation guide](https://postmarkapp.com/support/article/how-to-create-and-manage-your-postmark-account), updated September 23, 2026, describes confirming the registration email before login/server setup. The [Sender Signature guide](https://postmarkapp.com/developer/user-guide/managing-your-account/managing-sender-signatures) separately describes sender confirmation. Neither establishes this Account's actual registration-verification state or whether its initial Sender confirmation also satisfied registration verification. Existing Owner login, MFA and verified Sender/Domain remain observations, not proof that the UNKNOWN is closed.

The [Server API](https://postmarkapp.com/developer/api/server-api) documents Server Token authentication, not a distinct account-email-verification parameter. The [Account approval guide](https://postmarkapp.com/support/article/1084-how-does-the-account-approval-process-work) permits API use while account sending approval is pending; that approval is separate from administrative email verification. No claim that an unverified registration email is acceptable follows from this.

**Recommended Decision:** retain Account Email Verification UNKNOWN as account-control/recovery Pending, but do not make it a blanket technical blocker for narrowly approved protected credential intake/read-only Server binding, provided corporate Owner login/MFA/control and no verification warning remain established. Credential ownership/authentication and exact Server binding must still pass. This is a proposed adjustment to the preceding readiness prerequisite, **not an automatic waiver or changed Closed Contract**. Sending/release readiness and recovery risk remain independently reviewed.

Actual risks are loss of administrative recovery/notifications, future account restrictions and uncertainty about Provider prerequisites; DKIM or Token binding does not resolve these. Human may ask Postmark Support, without sending any token/recovery code: "Our Owner login and app-based 2FA work, and our initial Sender Signature and Sending Domain are verified. Does our account require a separate registration-email confirmation? Is it currently satisfied, and does an outstanding requirement restrict Server API Token access or read-only API use?" Use the signed-in support channel if available; report only the conclusion, not personal/account secrets. No support message was sent by Codex. A support response or explicit Profile status can close the UNKNOWN; no automatic resend/change is authorized.

### Intake contract and exact target boundary

New `postmark-credential-intake-readiness-contract.json` is a **proposal / production-free design**, separate from historical immutable contracts. `simulate-postmark-intake-readiness.php` is only a boolean contract-state model; it cannot perform intake, a network request or publication. Frozen RC is unchanged.

1. Human, after a separate approval, selects `Company OS Production` / Server `21093973` and copies only its existing **Server API Token** directly into the approved GitHub `production` Environment Secret `POSTMARK_API_KEY`. No Account API Token, rotation, shell command, chat paste, screenshot or Evidence value. Use a trusted desktop without clipboard history/sync or capture; clear the clipboard after intake. If that cannot be assured, STOP and review an alternative protected input channel. Secret creation/overwrite is a mutation requiring explicit approval; an existing Secret must not be overwritten under an initial-intake Gate. Existing workflow protections/access must be confirmed before approval.
2. A later approved protected runner consumes the Secret in process memory, never argv, a temporary file, output, a dump or artifact. No shell tracing, HTTP verbose logs or debugger. GitHub masking is defense-in-depth, not proof of non-disclosure. No workflow/helper capable of this live step is implemented by this review.
3. Future read-only validation uses one bounded `GET https://api.postmarkapp.com/server`, TLS verification, no redirects, no retry, 15-second timeout and 64 KiB response cap. Token is only in `X-Postmark-Server-Token`. Require HTTP 200, strict response schema, integer ID `21093973` and DeliveryType Live. An Account Token is never tried as a fallback. The official response contains `ApiTokens`: **all raw response fields are sensitive**, must remain memory-only, and only fixed boolean/status outputs may escape. Discard response tokens, names, hook URLs and errors without serializing them. No token fingerprint/hash goes into Evidence. Stream identity/type must be independently checked against `account-lifecycle` in a separately specified authorized read or retained management-plane Evidence; `/server` alone does not validate the Stream.
4. GitHub Secret intake **does not store anything on NewTarget**. Exact approved future destination is `/home/xs377816/company-os.jp/company-os-app/shared/.env`, through a **new Shared State generation and separate mutation Gate**. No new token file or directory is added to topology. Require candidate/environment manifest reconciliation (old Frozen RC lacks the application delta), existing backup/restore prerequisite satisfaction, exact SSH host-key/target identity and no symlink path. Transfer via encrypted SSH stdin from protected runner memory, never command arguments or disk artifacts; actual transport/helper integration remains to be implemented and verified before that Gate.
5. Future publish must preserve other environment keys, use candidate-bound protected staging / same-filesystem atomic publish with `0600`, UID `20046`, GID `1000`, verify metadata and readability without printing contents, and distinguish CLI euid from actual web/worker runtime identity. If runtime identity differs, STOP rather than widen permissions. Actual runtime readability, rollback backup and atomic integration are **NOT VERIFIED** by this simulation.
6. Evidence permits only attempt/generation, contract/manifest identity (not secret hash), result, fixed safe error, HTTP status, exact non-secret Server ID, permission/owner/readability booleans, protected-boundary flags and cleanup state. No token, raw response, response hash, provider error text or exception dump. Failure does not authorize resend, rotation, overwrite, retry or remote cleanup. Preserve attempt status; uncertain publish/residual state returns to Human Review. Cleanup/rollback of a published environment requires its approved Shared State boundary, never deletion of pre-existing shared state.

### Simulation / verified delta

PHPUnit `tests/Unit/PostmarkCredentialIntakeReadinessTest.php`: **1 test / 130 assertions PASS** on PHP 8.2.12. Every one of 12 intake and 11 target conditions rejects missing/false/null/string/integer observations; all-true results are explicitly `SIMULATION_PASS`, never publication authorization. Contract keeps Token access, Production connection, Shared State and Deploy authorization false. PHP syntax and `git diff --check` PASS. No real or mock token value is used. This tests the contract model, not a live transport, secret masking, POSIX permissions or runtime execution. Existing Application focused regression is reused; a full suite is not rerun for this isolated design/model-only delta.

### Next Human review / gate

First review the recommended nonblocking disposition above and proposed protected destination/access policy. If accepted, prepare **ONE POSTMARK SERVER TOKEN PROTECTED INTAKE** limited to selecting the exact Server and directly storing its existing Token in the approved protected Secret; stop on wrong Server, unexpected credential type, existing Secret, verification warning, unsafe clipboard/capture or unconfirmed access protection. Report completion boolean only. No NewTarget connection/storage is part of that gate, and no executable command is offered until the real runner path is implemented/verified. Read-only Provider binding and New Shared State publication are separate approvals, not implied by Human Token handling.

**G5 OPEN / DEPLOY NO-GO.** Account Email Verification UNKNOWN, Backup/Restore blockers and Public Entry Disposition Pending are retained. No Token operation, authenticated Provider call, Production connection, Shared State, Migration or Deploy occurred; only public official documentation was browsed. Return to Human + ChatGPT Review.

## Human-approved disposition and GitHub destination review — 2026-10-10 JST

Human approved removing Account Email Verification UNKNOWN from the blanket blocker for limited Credential Intake. The UNKNOWN remains account-control/recovery Pending and must be reassessed before Production sending. Human approved Server `21093973`, GitHub `production` Environment as first-choice destination, and a separate NewTarget environment-write Gate. Actual Token access/storage remains unapproved. Historical proposal files remain unchanged; `postmark-credential-destination-review.json` records this later disposition and read-only observation.

### Exact GitHub binding / observed blocker

Authenticated GitHub GET queries and remote default-branch workflow source establish:

- Repository `taka-0210/rise-gate-os`, ID `1299275262`, public, default branch `master`. Local origin matches. Current development branch is not substituted for the default branch.
- Environment exact name `production`, ID `18364212084`. **Protection rules: empty. Deployment branch policy: null.** No required reviewer/approval rule or branch/tag restriction is configured.
- Environment Secrets: zero. Repository Secret-name inventory: four existing SSH-related names; `POSTMARK_API_KEY` absent at both levels. Personal repository, not an organization-secret scope. Values were neither requested nor retrieved. Recheck names immediately before any later intake; this observation is not a reservation.
- Remote active workflows: `build-release-candidate.yml` and `deploy-production.yml`, both manual dispatch. Default-branch deploy job references `production`; build does not. Neither current local nor inspected remote default-branch workflow explicitly references `POSTMARK_API_KEY`.
- Actions enabled; allowed actions `all`; default GITHUB_TOKEN permission `read`; PR-review approval false. Collaborator inventory reports `taka-0210` admin. `master` branch-protection GET returned explicit 404 "Branch not protected"; repository rulesets list empty. These observations do not prove an immutable permission boundary or enumerate every historical/other-branch workflow.

**Protected destination readiness: STOP / ENVIRONMENT_PROTECTION_NOT_CONFIGURED.** Environment name alone is not a Human approval boundary. Manual dispatch and read-only GITHUB_TOKEN do not prevent a workflow job from accessing Environment Secrets. Any permitted job referencing this Environment can explicitly request its secrets; future/other-branch workflow changes are relevant, not only today's explicit references. GitHub admin can change controls; Environment storage is not a cryptographic isolation boundary from administrators or approved workflow code. Public repository visibility does not make secret values public, but increases the importance of trusted workflow/branch controls.

Official [Environment protection documentation](https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments) states Environment Secrets become available to referencing jobs after configured protection rules pass. [Managing environments](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/manage-environments) documents required reviewers and prevention of self-review. No GitHub protection or repository setting was changed by this review.

### Proposed minimal next Human decision — protection, not Token intake

Before presenting an actual Intake Gate, decide and approve the GitHub protection corrective: required Human reviewer, explicit allowed reviewed branch/tag policy, administrator-bypass disposition, and workflow-change review boundary. Select an exact release/intake ref only after candidate binding; do not assume current development branch or `master` is already an approved release. Current collaborator inventory has one admin; enabling prevent-self-review without an eligible independent reviewer can deadlock manual execution. Do not invent another reviewer, invite collaborators, disable the control or silently equate ChatGPT Review with a GitHub approver. Human must choose either an eligible independent reviewer arrangement or explicitly accept the single-owner self-approval model with its limitations. A selected-branch policy cannot restrict access to one particular workflow file; reviewed workflow content and least-privilege execution remain necessary.

Human can inspect Settings > Environments > production and repository access/settings without token access or saving changes. A settings change requires separate explicit authorization. Until that protection review and read-only confirmation pass, **no actual Token Intake Gate is ready**.

### Future Human operation and handoff — conditional, not execution instructions

Once approved: trusted browser, exact repository Settings > Environments > production > Environment secrets (not repository Actions secrets), exact new name `POSTMARK_API_KEY`. Select Postmark Servers > Company OS Production (`21093973`) > API Tokens, **Server API Token**, never Account > API Tokens. Copy directly into the Secret form without chat, editor, shell, process argument, screenshot, clipboard history/sync or screen capture; clear clipboard afterwards. Stop if a Secret already exists, Server/type differs, a verification warning appears or desktop capture cannot be controlled. Report only completion boolean. The UI is an authorized secret-entry surface, not a terminal/log; no Token handling is authorized now.

GitHub storage cannot independently prove that the Human selected the correct token. Later approved authenticated read validation must use the Server header and return exact Server ID `21093973` before declaring credential binding PASS. `/server` includes `ApiTokens`; raw response must never escape memory. No fallback to Account Token, no send as a diagnostic, no blind retry.

Future GitHub-to-NewTarget handoff remains a separate candidate-bound manual workflow/helper: trusted reviewed code, narrowly scoped Secret injection, no third-party actions executing in the secret-bearing step, no trace/debug/core dump/artifact, bounded Provider read then encrypted pinned-host SSH stdin to exact protected staging. No secret in argv, remote command text, local file, GITHUB_OUTPUT or GITHUB_ENV; environment masking alone is not acceptance evidence. Publish `.env` only under the separately approved Shared State Gate, verifying atomic publish, `0600`, UID `20046`, GID `1000`, actual runtime readability and unchanged protected state. This live runner/transport path is **not implemented/verified** yet; design confirmation is not a Production handoff PASS.

On suspected wrong Token: STOP without consuming it or running workflows; report fixed status only, preserve non-secret attempt metadata. Do not overwrite the GitHub Secret or expose its contents for diagnosis. If exposure is suspected, separately approve provider revocation/rotation plus protected replacement; if merely mismatched, review the scope and replacement Gate first. Account Token's account-wide authority is not acceptable. No delete, rotate, reissue or emergency mutation is implicit in the Intake approval.

### Verification and return boundary

Read-only GitHub metadata and remote workflow inspection completed; no GitHub settings/Secrets write, workflow execution, Token access, Provider authenticated call, Production connection or mutation. Focused contract-state regression now also checks the approved account disposition cannot bypass the observed unprotected destination. Model testing does not verify real secret entry/transfer. Backup/Restore, public-entry pending and all other G5 blockers remain. **G5 OPEN / DEPLOY NO-GO / CREDENTIAL INTAKE GATE NOT READY**. Return to Human + ChatGPT for the GitHub protection decision only; do not ask Human to access a token yet.

## GitHub Production Environment protection design review — 2026-10-10 JST

Review base HEAD `ddfa308b575bc573eaa236ecff0e2eeed29acc15`. Read-only GitHub queries reconfirmed Repository `1299275262`, public personal repository `taka-0210/rise-gate-os`, default branch `master`, Environment `18364212084` / `production`, empty protection rules and null deployment-branch policy. Collaborator result remains one admin `taka-0210`. Authenticated user projection returned `plan_name=null`: **exact paid/free/legacy plan is UNKNOWN**, not assumed Free. No billing information or secret value was output.

### Feature availability and single-owner authority

Official [Reviewing deployments](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/review-deployments) documents Environment protection for public repositories on current plans, excluding legacy Bronze/Silver/Gold. Official [Environment reference](https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments) documents required reviewers on public repositories, optional prevention of self-review, selected branch/tag restrictions and disabling administrator bypass. Thus the observed public repository meets the public-repository condition; exact plan/UI entitlement remains unverified until Human sees these controls. No plan upgrade is inferred or prescribed.

**Recommended single-owner model (requires Human approval):** Required reviewer `taka-0210`; Prevent self-review OFF explicitly; Allow administrators to bypass OFF. Human manually dispatches, then separately reviews and approves the exact run/commit/inputs. This is one person's two explicit actions, **not independent two-person review**. The recommendation follows the official statement that enabling prevention blocks the initiator's approval; the actual target UI must confirm the setting is available. Do not equate Environment approval with a PR self-approval or claim that a lone reviewer can satisfy a PR they authored. If Human requires independent separation of duties, this model is insufficient: add an eligible independent reviewer through a separately approved access change, or retain STOP. Wait timer, manual dispatch alone and a bot/ChatGPT approval are not substitutes for Human reviewer approval.

Disabling runtime admin bypass does not prevent an administrator from editing/deleting protection settings. Current Codex GitHub authentication has admin capability under the same account; the platform cannot distinguish Human from automation sharing that principal. "Codex must not dispatch/approve" therefore remains an explicit operating boundary, not an IAM guarantee. Technical actor separation would require separately approved credential/access restructuring; it is not silently introduced here.

### Recommended release-control ref and workflow protection

Use one exact **workflow execution branch**, proposed name `production-control-ir1`, containing a Human-reviewed control commit. Allow that branch only in `production` Environment's Selected branches and tags; add **no tag rule**, no wildcard, no `master`, development branch or PR ref rule. This is a proposal, not an existing branch or final commit binding. Do not choose "Protected branches only": GitHub documents that with no protected branches this can admit all branches.

Preserve `.github/workflows/deploy-production.yml`, `workflow_dispatch`, approved input/manifest checks and checkout of approved `rc_sha`. The workflow-control ref is distinct from Frozen RC `924af91188cc60d33ff87c91b94ecc1d539566e6`; it does not replace/refreeze RC, choose a new application artifact or authorize Deploy. No existing workflow or Deploy Contract is modified by this review. The control branch must have a default-branch-discoverable manual workflow before any eventual execution; no execution is tested now.

After separately approving and creating the reviewed control branch, freeze it with an **Active exact-branch ruleset**, empty bypass list, Restrict updates, Restrict deletions and Block force pushes. Restrict creation may be enabled only after initial approved creation; otherwise an empty bypass list would prevent provisioning. Active branch rulesets are documented for public repositories on current Free plans as well as paid plans in [Ruleset rules](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/available-rules-for-rulesets). Verify actual UI entitlement. Do not enable an unsatisfiable self-authored-PR required-review rule to simulate independent review.

Workflow changes are developed outside that frozen ref and reviewed by Human before another separately approved control-ref promotion/protection update. Protect the full control snapshot, including `.github/workflows`, scripts, dependencies and any CODEOWNERS metadata, rather than assuming CODEOWNERS alone blocks changes. CODEOWNERS can request review but is not enforced without a compatible merge-review rule. Future changes to an Active frozen ref require their own gate; admin editing of the ruleset is still a privileged configuration change, not an authorized bypass. No branch/ruleset is created now.

### Actual Secret access scope and readiness constraints

Environment Secrets are job-scoped after Environment approval, **not workflow-filename-scoped**. Any job on an allowed execution ref that references `production` can request these secrets; limiting GITHUB_TOKEN to read does not reduce that capability. A future approved control snapshot must contain only the formally approved Release job as a `production` consumer, including inspection of reusable workflows and repository scripts. Current two inspected workflows expose no Postmark key; deploy references `production`, build does not. A immutable reviewed control snapshot plus exact-ref restriction and per-run Human approval constrains the effective consumer set, but it is not a native Secret ACL for one workflow.

This highlights a dependency requiring separate Review: earlier proposed credential diagnostics/Shared State helper cannot freely consume this Environment Secret under the new "formal Release workflow only" input. Do not add another Environment consumer or execute Deploy as a shortcut for read-only diagnostics. Final Secret-safe validation/handoff must be designed as an explicitly approved non-deploy phase within the formally reviewed release control boundary, or Human must approve a different least-privilege credential-validation boundary. The current workflow has no such phase; preserving the Deploy Contract means it is **not implemented** here. Local unsafe-output checks alone cannot resolve that interface. API/server binding still must pass before any delivery/runtime use.

Mandatory **before real Secret registration**: approve the single-owner risk or independent reviewer model; confirm actual UI features; bind/protect exact workflow control ref and code snapshot; confirm required reviewer, no runtime admin bypass and exact-ref restriction by read-only metadata; confirm trusted consumer inventory; approve the validation/handoff boundary without violating Release-only consumption; reconfirm `POSTMARK_API_KEY` absent in Environment/repository; confirm protected human input and no capture/history. No live trial run containing Production secrets is needed to inspect these prerequisites. Keep absent/unknown checks STOP.

### Proposed Human Gate — protection only, not approved

**ONE GITHUB PRODUCTION PROTECTION CONFIGURATION / HUMAN ONLY** may be authorized once the single-owner decision, exact control commit/ref and release-only validation boundary above are FIXED. It does not include Secret access, workflow execution, Release or Production mutation.

Minimal UI sequence after that approval:

1. Repository Settings > Environments > production: confirm exact repository/environment, select Required reviewers `taka-0210`, leave Prevent self-review unchecked only under the explicitly accepted single-owner model, uncheck Allow administrators to bypass; Save protection rules.
2. Deployment branches and tags: select Selected branches and tags; add exact **Branch** `production-control-ir1` only, after its approved commit/ref exists; do not add a wildcard or tag rule.
3. Repository Settings > Rules > Rulesets: apply the reviewed exact-branch Active freeze configuration, with no bypass actors. Branch creation/promotion is separately bound before freezing; do not improvise a SHA, create a release or move Frozen RC.
4. Return non-secret configuration results for read-only API verification. Do not click Run workflow, approve any pending deployment or access Token tabs/Secret forms.

STOP without proceeding to Token intake if a required control is missing, reviewer is ineligible, independent review is required but unavailable, the exact ref/commit is unbound, protection scope differs, saving would alter unrelated configuration, or the release-only credential-validation boundary remains unresolved. The above is a conditional configuration Gate proposal, **not a ready-to-execute Token Gate**. Today Human need only decide the single-owner model and control-ref/validation design; no account/plan upgrade, collaborator invitation or protection change is auto-authorized.

This delta is documentation-only. Existing 2-test / 138-assertion contract model evidence is reused; no new runtime PASS or full regression is claimed. `git diff --check` validates formatting. No Secret registration, Environment/ruleset/branch change, workflow execution, Production connection or Deploy. Account verification UNKNOWN, Backup/Restore and Public Entry Pending remain. **G5 OPEN / DEPLOY NO-GO.**
