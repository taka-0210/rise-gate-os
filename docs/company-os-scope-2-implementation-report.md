# Company OS Ver.1 Scope 2 Implementation / Final Close Report

- Scope: 既存UserのAccount管理・回復
- Repository: `C:\xampp\htdocs\rise-gate-os`
- Branch: `master`
- P0基準HEAD: `22edec70ba9e5079c5d309995210e0ba936acd6a`
- Scope 1基準Commit: `15751f290f366aace117cfd976190d581c1b2cae`（P0基準HEADに包含）
- Scope 2実装Commit: `7ee3c28b6dc3c32f0d2e9233cfe3d38aec5b93fa`
- Timezone: `Asia/Tokyo`
- Production deploy: 未実施
- 次Scope: 未着手

## 1. Close判定

Scope 2のコード実装とRepository内受入は完了した。S2-DC-01〜25は `Done 24 / Conditional 1 / Not Done 0` と判定する。

`Conditional` はS2-DC-17の実運用Mail配信である。Laravelの非Fake `array` transportを管理Test Inboxとして、Mail本文生成、URL取得、Browser/HTTPによるリンク消費まで検証した。一方、Production用SMTP等のProvider設定と外部Mailboxへの実送受信はRelease運用事項であり、本Scopeでは実施していない。コードDoneとProduction有効化を分離する。

Scope 1のContract、Version、Authorization、AI Context、Approval / Apply / Attempt / Item Result、update-only Undoには仕様変更を加えていない。全回帰でScope 1 Testsを含めて成功した。

## 2. Scope 2でできるようになったこと

### Login / Logout

- 既存Laravel Web Guard、password hashed cast、Session再生成、CSRF、Logoutを継承した。
- 一般LoginとSystem Admin Loginの双方へ、失敗時だけ加算するRate Limitを追加した。
- 初期値は正規化Email＋IPで5回/分、IP全体で30回/分。永久Lockは行わない。
- Login成功時に資格情報世代をSessionへ固定し、資格情報更新後の古いSessionを次Requestで拒否する。
- `/register`のBootstrap処理は保持し、Userが存在する通常運用ではLogin画面にSignupのような導線を表示しない。

### 本人Profile

- Organization / Workspace選択を要求しない本人用 `/account` を追加した。
- 現在のName、Email、確認状態、pending Email、有効期限、Password変更導線を表示する。
- 本人が直接更新できるFieldはNameだけ。Email、Passwordは独立した専用Actionを経由する。
- `role`、`company_role`、`is_active`、`email_verified_at`、User ID、Membership等をProfile Requestへ混入しても更新しない。
- Name / Emailが全Organization共通のUser情報であることをUIへ明記した。

### Password変更 / 回復

- 本人Password変更は現在Password、新Password、確認入力を必須とした。
- Forgotの公開応答は、存在User・不存在User・停止Userで同じ文言とStatus方針にした。
- Reset Tokenは既存Brokerのhash保存を継承し、資格情報世代を追加して古いTokenを拒否する。
- 期限切れ、改ざん、別User、使用済み、世代不一致、停止Userを拒否する。
- ResetはTransactionとrow lockで単回消費し、Password更新・Token削除・Session失効を一体化した。
- Password変更 / Resetは`email_verified_at`、Membership、Role、AI Access Keyを変更しない。

### 現在Email確認 / Email変更

- 現在Email確認とEmail変更を別目的のRequestとして保存する。
- 現在Email確認は、本人Session、対象User、現在Email、資格情報世代、期限、最新Request、署名、Token hashを照合する。
- Email変更開始は現在Passwordを必須とし、候補Emailへだけ確認Mailを送る。
- 確認完了までは旧`users.email`をLogin / Resetの正本として維持する。
- 候補Emailはtrim＋lowercaseで新規保存する。既存Emailの一括lowercase化・User統合は行わない。
- 再送、別候補、取消、Password更新、Email確定により古いRequestを無効化する。
- 確定時はUser / Requestをlockし、一意性を再検証して、Emailと`email_verified_at`を原子的に更新する。
- 同じ正規化候補を二Userが要求しても、確認成功は最大一Userとなる。
- 確定後は全Web Sessionとremember-meを失効し、旧Emailへ変更通知を試行する。
- 旧Email通知失敗で、確認済みの変更を再適用・rollbackしない。

### Session / Token / Mail / Audit

- `users.credential_generation`を資格情報の世代として追加した。
- Password変更、Reset、Email確定、既存System Admin writerによるEmail / Password変更・停止で世代を進める。
- 対象UserのDB Sessionを削除し、remember tokenを更新する。他UserのSessionは変更しない。
- DB以外のSession driverでも世代照合する。Migration前の世代未記録Sessionは世代1だけを継承し、世代更新後は拒否する。
- Account Mail Jobは`ShouldBeEncrypted`かつafter-commitでQueueへ投入し、3回retryする。
- Token、宛先、確認URLをQueue payloadへ平文保存しないことをTestした。
- Account Mailは`ACCOUNT_MAIL_MAILER`を明示設定する。`log` transportとProductionの`array` transportは拒否する。
- URLはUser入力Hostではなく、信頼済み`APP_URL`とrelative signed routeから生成する。
- Auditは対象User ID、actor ID、event、outcome、時刻、非秘密metadataだけを保存する。

## 3. KEEP / REFACTOR / ADD / DEPRECATE

| 分類 | 内容 |
|---|---|
| KEEP | Laravel Web Guard、hashed cast、User ID、`users.email` unique、`email_verified_at`、password broker/table、Session、Logout、Organization / Workspace / Project relation、Scope 1全体 |
| REFACTOR | 一般/Admin LoginのThrottle、資格情報writer共通失効、Profile入口、Session世代照合、Login上のBootstrap表記 |
| ADD | 本人Profile、本人Password変更、Forgot / Reset、現在Email確認、pending Email変更、暗号化Mail Job、最小Account Audit、受入Tests |
| DEPRECATE | `/register`を通常のOwner Signupのように見せる導線。Bootstrap Route / 処理自体は保持 |
| RENAME | なし |

## 4. Phase記録

### S2-P0｜対象Repoと接点の固定

#### できたこと

- Repository / Branch / HEAD / origin、AGENTS、skill、正本6資料を確認した。
- Scope 1 commit `15751f2...`がP0 HEADに包含されることを確認した。
- Auth / Session / Broker / Mail / Queue / `email_verified_at` / MFA / SSO / Account writerを実コードから調査した。
- 現在DBを匿名集計し、User 3件、Session 4件、完全一致Email重複0、大文字小文字無視Email重複0を確認した。
- P0時点のworktreeがcleanであることを確認した。

#### Test / 検証

- P0 fresh baseline: **333 tests / 2363 assertions**、117.42秒、成功。
- 過去のScope 1結果を現在結果として流用していない。
- PHP 8.2.12 / Laravel 12.63.0。
- 実運用設定: SQLite、database Session、database Queue、log Mail、`Asia/Tokyo`。
- Test設定: SQLite memory、array Session / Mail、sync Queue、bcrypt rounds 4。

#### 決定

- S2-PD-07: 資格情報世代＋DB Session削除＋remember token更新を採用。
- S2-PD-11: 既存Emailのbulk正規化・統合は不要。新しい候補だけlowercase保存。
- S2-PD-17: 既存Tableを保持し、2 column＋2 tableの追加Migrationに限定。

### S2-P1｜LoginとAccount通知の土台

#### できたこと / 変更

- 一般/Admin Login Rate Limit、Mail / Token Rate Limitを設定化した。
- Account Mailer gate、暗号化after-commit Job、Mailable、信頼済みURL生成を追加した。
- 追加Migration、Account Email Request、Account Security Event、資格情報世代Middlewareを追加した。
- LoginへForgot、全認証画面へAccount導線を追加し、Bootstrap表示を初回だけにした。

#### Test / 検証

- 一般/Admin Loginのidentity閾値、正規化、IP全体閾値、decay後の再試行。
- Mailの1回/分、5回/時、Token入口の制限。
- log Mailer fail-closed、Production array禁止の実装確認。
- encrypted database Queue payloadにEmail / Token / URL平文がないことを確認。

### S2-P2｜本人Profile・Password管理と回復

#### できたこと / 変更

- Profile表示、Name allowlist更新、本人Password変更、Forgot / Resetを追加した。
- Password変更 / Resetと既存System Admin writerを共通失効Serviceへ接続した。
- Session世代不一致、世代未記録legacy Session、remember-me、DB Sessionを失効対象にした。

#### Test / 検証

- A/B User、orgless User、複数Organization User、Request body改ざん、HTML escape。
- 現在Password誤り、確認不一致、hash保存、旧Password拒否。
- Reset Tokenの期限・改ざん・別User・再利用・世代不一致・停止User。
- 対象Userの複数Sessionだけ削除し、他User Sessionを保持。
- 未確認Userを既存画面から一律遮断しないことを確認。

### S2-P3｜Email確認とEmail変更

#### できたこと / 変更

- 現在Email確認、候補Email変更、再送、取消、確定、旧Email通知を追加した。
- pending中は旧Emailを正本とし、最新Requestだけを有効にした。
- Transaction / row lock / DB unique / normalized candidateで同時確定を制御した。

#### Test / 検証

- signed URL、Token hash、期限、改ざん、別User、二重click。
- 再送、別候補、取消、Password更新、世代更新による古いURL拒否。
- 同一候補を二Userが要求した場合の成功最大1件。
- User ID、Organization / Workspace Membership、Role、AI Access Key不変。
- 確定後の新Email Login、旧Email Login拒否、全Session再認証。

### S2-P4｜受入とScope 1回帰

#### Test / 検証結果

- Focused Scope 2: 追加した全Account Tests成功。
- Final full suite: **359 tests / 2621 assertions**、121.78秒、成功。
- Scope 2変更PHPのPint: 成功。
- `git diff --check`: 成功（既存CRLF→LF warningのみ）。
- Repository全体Pint: Scope 2外の既存未整形ファイルを検出。Scope外一括整形は未実施。
- PHP syntax: Scope 2追加・変更PHPすべて成功。
- Frontend build: portable Node.js v24.21.0 / npm 11.19.0、Vite 7.3.6、58 modules、成功。
- npm audit: 0 vulnerabilities。
- 実ブラウザ: Microsoft Edge headless、1280x800と390x844でLogin / Account / Forgot / Resetを操作。
- 390px Accountでhorizontal overflowなし。Login成功、Profile / Email / Password導線、再Login説明を確認。
- Browserは空の隔離SQLite DBと専用Userのみを使用。server / DB / screenshot / node_modules / portable Nodeは検証後削除。
- Mail Test Inbox: Laravel非Fake `array` transportで実際のMail本文を生成し、確認URL取得・消費に成功。

#### Migration Clone Evidence

- 現Repository DBを複製した隔離CloneだけでScope 2 Migrationを検証した。
- Apply成功、同一Migration再実行は`Nothing to migrate`、down成功、re-apply成功。
- 各段階でUser 3件、User IDs `1,3,4`、Organization memberships 4件、Workspace memberships 5件、Projects 17件を保持。
- Clone元DBのSHA-256は前後一致。実DBは変更していない。

### S2-P5｜Close資料・引渡し

- 本ReportへScope、Phase、DC、保証、非保証、運用設定、Migration / rollback、Pendingを固定した。
- Production deployと次Scope実装は行っていない。

## 5. S2-DC-01〜25 最終判定

| DC | 判定 | 実装 / Evidence |
|---|---|---|
| S2-DC-01 | Done | P0 HEAD / Branch / origin / AGENTS / Scope 1包含 / writer / 重複 / fresh 333 testsを本Reportへ記録 |
| S2-DC-02 | Done | 既存Auth Controller継承、`AccountManagementTest`、`SystemAdminMemberTest`、full suite |
| S2-DC-03 | Done | `AccountLoginLimiter`、named Mail / Token limiter、identity / IP / minute / hour / decay Tests |
| S2-DC-04 | Done | IDなし本人Route、A/B、orgless、複数Org、別User URL拒否Tests |
| S2-DC-05 | Done | Profile Name allowlist、専用Password / Email Action、改ざんField・escape Test |
| S2-DC-06 | Done | `PasswordController`、current password / confirmation / hash / old password Tests |
| S2-DC-07 | Done | Timebox＋同一公開応答、active / missing / inactive比較、Mail件数Test |
| S2-DC-08 | Done | Broker hash＋世代、期限・改ざん・wrong user・reuse・credential change Tests |
| S2-DC-09 | Done | Mail→Reset→新Password Login、inactive保持。既存MFA / SSO実装なしをP0確認 |
| S2-DC-10 | Done | generation middleware、DB Session削除、remember token更新、legacy / stale / 他User保持Tests |
| S2-DC-11 | Done | verify_current Request、signed＋hash、期限・再送・tamper・wrong user・double click Tests |
| S2-DC-12 | Done | current password、pending候補、旧Email Login / Reset正本、cancel / expiry Tests |
| S2-DC-13 | Done | Transaction / locks / normalized candidate / unique conflict、二User同候補Test、旧Email notice |
| S2-DC-14 | Done | resend / candidate / cancel / password / email世代による旧Request失効Tests |
| S2-DC-15 | Done | 同一User ID、複数Org / Workspace relation不変Tests |
| S2-DC-16 | Done | Role / Membership / AI Key不変Test＋Scope 1 permission full regression |
| S2-DC-17 | Conditional | 非Fake array Test Inboxの生成・リンク消費、失敗・retry安全性はDone。外部SMTP / Mailbox実送受信はRelease前条件 |
| S2-DC-18 | Done | encrypted Queue payload Test、hashed token、最小Audit、trusted APP_URL、秘密Field非保存のcode review |
| S2-DC-19 | Done | 既存`email_verified_at`保持、bulk verifiedなし、legacy未確認Userの既存画面利用Test |
| S2-DC-20 | Done | Route / Migration / diff reviewでOwner開始・Invitation・Avatar・Group・Offboarding・新Roleなし |
| S2-DC-21 | Done | additive Migration、実DB Clone apply / rerun / down / reapply、ID / Relation / hash不変 |
| S2-DC-22 | Done | Scope 1 Acceptance / API / Foundation全Testsを含む359 tests成功 |
| S2-DC-23 | Done | focused / full test、syntax、Scope変更Pint、diff check、Vite buildを記録 |
| S2-DC-24 | Done | Edge Desktop / 390px操作・画像目視、overflowなし、Flow説明確認 |
| S2-DC-25 | Done | 本Final Close Report、設定・制約・Pending・切戻しを記録 |

## 6. Test一覧と最終結果

### Scope 2主要Test

- `tests/Feature/AccountManagementTest.php`
  - 本人境界、allowlist、escape、Password変更、Session世代、一般/Admin Login limit、IP-wide limit、Bootstrap、複数Org。
- `tests/Feature/PasswordRecoveryTest.php`
  - 非列挙、hashed/versioned Token、single-use、期限、tamper、wrong user、inactive、暗号化Job payload。
- `tests/Feature/AccountEmailManagementTest.php`
  - 現在Email確認、Email変更、signed/hash、再送、取消、期限、世代、atomic確定、candidate競合、Relation / AI Key不変。
- `tests/Feature/AccountMailIntegrationTest.php`
  - 非Fake array Test Inbox、Link消費、unsafe Mailer、retry、minute/hour/token limit。
- `tests/Feature/SystemAdminMemberTest.php`
  - 既存管理者writerと資格情報世代 / verification失効の統合回帰。

### Final result

- `php artisan test`: **359 passed / 2621 assertions**。
- Scope 2開始前Baselineとの差: **+26 tests / +258 assertions**。
- `npm run build`: 成功。
- Production deploy: 未実施。

## 7. Data / Migration / Rollback / 切戻し

### Upで追加するもの

- `users.credential_generation` unsigned bigint default 1。
- `password_reset_tokens.credential_generation` unsigned bigint default 1。
- `account_email_requests`。
- `account_security_events`。

既存User、User ID、Email、Password hash、`email_verified_at`、Membership、Role、Workspace、Project、AI Access Key、Scope 1履歴をupdate / backfillしない。

### Rollback

Migrationの`down`は、新規2 tableをdropし、新規2 columnをdropする。SchemaとしてはreversibleでClone検証済み。ただし運用開始後のdownは、pending Email RequestとAccount Security Eventを削除するため、業務Data削除に該当する。

本番切戻しの推奨順序:

1. Account操作を停止する。
2. DB backupを取得する。
3. 旧Application releaseへ切り戻す。
4. additive Schemaは原則そのまま残す。
5. `down`が本当に必要な場合だけ、Scope 2新規Dataの保全 / 廃棄を明示承認後に実行する。

Migration自体は既存Sessionを削除しない。Session削除はPassword / Email等の資格情報更新を実行した対象Userだけに限定される。

### Release前のMigration注意

P4時点の現Repository DBでは、Scope 2 Migration以外に以下がPendingだった。

- `2026_09_09_000001_add_additional_images_to_ai_chat_messages`
- `2026_09_19_000001_add_scope_one_contract_to_ai_proposals`
- `2026_09_19_000001_add_scope_two_account_security`

本番Release前に対象環境の`migrate:status`を確認し、Scope 1を含む累積Migrationとして適用順・backup・release手順を確認する。今回、現Repository DBへMigrationは適用していない。

## 8. 運用設定 / Release Gate

必須確認:

- `APP_URL`: 実際の信頼済みHTTPS URL。
- `APP_TIMEZONE=Asia/Tokyo`。
- `ACCOUNT_MAIL_MAILER`: Production用の実配信Mailer名。未設定、`log`、Productionの`array`は拒否される。
- 対象Mailerのhost / port / credentials / from address。
- Queue worker稼働。Account Mail Jobは暗号化・after-commit・3 tries。
- `APP_KEY`を不用意にrotationしない。Queue payloadとsigned URLへ影響する。
- Rate Limit値を運用要件に合わせて確認する。
- 管理されたTest User / MailboxでForgot、Reset、Verify、Email change、old-email noticeを送受信する。

設定可能値:

- `ACCOUNT_LOGIN_MAX_ATTEMPTS_PER_MINUTE=5`
- `ACCOUNT_LOGIN_MAX_ATTEMPTS_PER_IP_PER_MINUTE=30`
- `ACCOUNT_LOGIN_DECAY_SECONDS=60`
- `ACCOUNT_MAIL_MAX_PER_MINUTE=1`
- `ACCOUNT_MAIL_MAX_PER_HOUR=5`
- `ACCOUNT_EMAIL_TOKEN_EXPIRE_MINUTES=60`
- `ACCOUNT_TOKEN_MAX_ATTEMPTS_PER_MINUTE=10`

## 9. 保証すること / 保証しないこと

### Testと実装で保証すること

- 既存有効UserのLogin / Logout、Profile Name管理、Password変更 / 回復。
- 現在Email確認と安全なEmail変更。
- Account存在・停止・RoleをForgot公開応答から列挙しない。
- Token hash、期限、対象、世代、single-use、最新Request。
- 資格情報更新後の全Web Session / remember-me再認証。
- 本人以外、別User、改ざんURL、stale Sessionの拒否。
- Account操作でMembership / Role / Project権限 / AI Key / Scope 1状態を変更しない。
- JST表示、Desktop / narrow UI、Frontend build。

### 本Scopeで保証しないこと

- 新Owner / Company開始、公開Signup、Staff Invitation、初回Password設定。
- Organization Membership lifecycle、Group、Workspace自動作成 / 自動所属。
- Avatar、Offboarding、Account disable UI、AI Key一括失効。
- 新MFA / SSO、device / session一覧、個別Session revoke UI。
- 外部SMTP Providerと本番Mailboxへの配信成功。
- 管理者によるUser直接作成 / Password変更の廃止。
- Email既存値のbulk lowercase、重複User merge。
- Production deploy。

## 10. Pending Decision更新

### Scope 2内で技術方針を確定

| ID | 状態 | Scope 2結論 |
|---|---|---|
| S2-PD-07 | Resolved for Scope 2 | credential generation＋DB Session削除＋remember rotation。全Web再認証 |
| S2-PD-09 | Resolved for Scope 2 | 5/min identity、30/min IP、Mail 1/min・5/hour、Token 60分。すべて設定化 |
| S2-PD-10 | Resolved for Scope 2 | 確認完了まで旧Email正本、最新要求のみ、取消 / 期限切れは旧Email維持 |
| S2-PD-11 | Resolved for Scope 2 | 重複0。既存値は変更せず、新候補だけlowercase保存 |
| S2-PD-17 | Resolved for Scope 2 | 2 column＋2 tableのadditive Schema |
| S2-PD-19 | Resolved for Scope 2 | portable Node buildと実Edge Desktop / narrow Evidence完了 |

### Release / 運用へ持越し

| ID | 状態 | 持越し内容 |
|---|---|---|
| S2-PD-12 | Pending release gate | Production Mail Provider / credentials / managed mailbox実送受信 |
| S2-PD-18 | Pending operations | Account Security Eventの保持期間、閲覧主体、削除運用 |

### 後続Scopeへ持越し

- S2-PD-01: Owner開始方式。
- S2-PD-02: Organization Role / Position / 個別Permission。
- S2-PD-03: Staff Invitation発行主体と既存User受諾。
- S2-PD-04: Group lifecycle。
- S2-PD-05: Workspace自動作成 / 自動所属。
- S2-PD-06: Offboarding時Session / AI Key / 他Organization影響。
- S2-PD-08: 新Account導入後のEmail確認必須化。
- S2-PD-13: Avatar storage / image validation / AI生成。
- S2-PD-14: 標準Workspace / Personal Workspace。
- S2-PD-15: MFA / SSO / Recovery制度。
- S2-PD-16: 管理者直接作成 / Password変更の廃止時期。
- S2-PD-20: Account disable / suspension UX。
- S2-PD-21: Invitation期限 / 取消 / 既存User受諾。
- S2-PD-22: 共通Tenant Query / DB整合性。

Scope 2のReset / Email TokenをInvitationや権限付与Tokenへ転用しない。

## 11. 主要ファイルと責務

### Route / Controller / Middleware

- `routes/web.php`: guest Reset、本人Account、Email確認Route。
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php`: 一般Login throttle / audit / generation。
- `app/Http/Controllers/SystemAdmin/AuthenticatedSessionController.php`: Admin Login throttle / audit / generation。
- `app/Http/Controllers/Auth/ProfileController.php`: 本人Profile表示とName allowlist更新。
- `app/Http/Controllers/Auth/PasswordController.php`: 本人Password変更。
- `app/Http/Controllers/Auth/PasswordResetController.php`: Forgot / Reset。
- `app/Http/Controllers/Auth/AccountEmailController.php`: Verify / change / resend / cancel / confirm。
- `app/Http/Middleware/EnsureCredentialSessionIsCurrent.php`: Session世代照合。
- `app/Http/Controllers/SystemAdmin/MemberController.php`: 既存writerの共通失効接続。

### Model / Service / Mail

- `app/Models/User.php`: credential generation、Email Request relation。
- `app/Models/AccountEmailRequest.php`: Email確認 / 変更Request lifecycle。
- `app/Models/AccountSecurityEvent.php`: 最小Account audit。
- `app/Services/AccountLoginLimiter.php`: failure-only Login limit。
- `app/Services/AccountCredentialService.php`: generation / remember / reset / pending / Session失効。
- `app/Services/AccountEmailService.php`: Email Request作成・取消・atomic確定。
- `app/Services/AccountMailDispatcher.php`: trusted URL、Mailer gate、Job dispatch。
- `app/Services/AccountAudit.php`: secret-free audit writer。
- `app/Jobs/SendAccountActionMail.php`: encrypted after-commit delivery / retry。
- `app/Mail/AccountActionMail.php`: Account Mail body。

### Schema / Config / View

- `database/migrations/2026_09_19_000001_add_scope_two_account_security.php`。
- `config/account.php`、`.env.example`、`phpunit.xml`。
- `resources/views/auth/profile.blade.php`。
- `resources/views/auth/forgot-password.blade.php`。
- `resources/views/auth/reset-password.blade.php`。
- `resources/views/auth/login.blade.php`。
- `resources/views/emails/account-action.blade.php`。
- `resources/views/layouts/app.blade.php`。

## 12. 次工程への技術的注意

- InvitationはAccount recovery Tokenと分離し、権限付与の発行者・取消・期限・受諾時再認可を独自に持つ。
- Offboardingは共通Userを停止する操作とOrganization Membershipを停止する操作を分離する。複数Organization所属Userを誤って全社停止しない。
- AI Access Keyは今回のWeb資格情報世代の対象外。Offboarding Scopeで所有・用途・他社影響を判断する。
- Email確認済みを既存Authorization条件へ先回り追加しない。新Account onboardingが完成したScopeで受入条件を定義する。
- Account audit retentionと閲覧権限を決めるまで、削除Jobや管理画面を先行追加しない。
- Scope 1のContract / Version / Attempt / Result / UndoへAccount都合の変更を加えない。
- DeployはGit pushと別工程。Production workflowは手動`workflow_dispatch`のままとし、今回実行しない。
