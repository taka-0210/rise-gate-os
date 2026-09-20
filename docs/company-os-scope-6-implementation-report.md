# Company OS Ver.1 Scope 6 Implementation / Final Close Report

- Scope: Owner Onboarding
- Repository: `C:\xampp\htdocs\rise-gate-os`
- Branch: `master`
- P0基準HEAD / origin: `5f207136eadf9d7e2a7ca03a87bab53092213c97`
- Scope 1〜5: Closedを維持し、P0基準HEADに包含
- Scope 6実装Commit: `26918df0ce7ccf3e9a94abf73791ed6296e9d7bf`
- 実施日 / Timezone: 2026-09-20 / `Asia/Tokyo`
- Production deploy / Production migration: 未実施
- 通常local DB: Scope 5 / Scope 6 Migrationとも未適用、`Pending`
- Scope 7: 未着手

## 1. Close判定

Scope 6の専用Owner開始承認、本人Account作成または既存Account結合、現Email確認、限定的な法的同意Evidence、Organization一式のatomic作成、標準Workspace、Personal Workspace gate、Home / Staff Invitation接続、Mail / Audit、Migration rehearsal、Test、Build、Browser確認を完了した。

S6-DC-01〜30は **Done 28 / Conditional 2 / Not Done 0** と判定する。Conditionalは次の2件で、仕様BlockerやData破壊ではない。

- S6-DC-15: SQLite 2実接続barrierで、一方成功・一方DB lockによるfail-closed・明示retryで同一Organizationへ収束した。二重作成はないが、同時2リクエストが両方即時成功する保証およびProduction DB engine実測は未実施。
- S6-DC-29: Production build、Desktop / CSS 390pxの実Browser描画、Feature Testによる新規/既存/Email/失敗再開/Staff接続は確認した。全Journeyを外部Mailbox込みで実Browser操作したEvidenceはRelease前確認へ残す。

Scope 1〜5のClosed Contractを再設計していない。既存User / Organization / Workspace / ProjectのID・Relation・Historyを削除・Renameせず、Organization RoleからFinancial / Project / AI Permissionを自動grantしていない。Production相当Concurrency、正式法務文書、本番Mail、通常local / Production Migrationは保証対象外としてRelease Gateへ分離する。

## 2. Scope 6でできるようになったこと

### System Adminによる開始承認

- activeなSystem Adminだけが、専用Owner Onboarding案件を発行・再送・取消できる。
- 案件には不変の公開管理番号、hash化Token、世代、期限、対象Email、会社名、発行者、配送状態を保持する。
- 同一request IDは同じpayloadだけを冪等に再利用し、別payloadでの再利用を拒否する。
- 同名候補はSystem Admin画面だけに表示し、別会社として進める場合は理由を必須保存する。同一案件は再送、既存Organizationへの参加はScope 4 Staff Invitationへ案内する。
- System Adminを作成会社へ自動所属させず、Public SignupやBootstrapを緩和しない。

### 本人によるAccount・会社開始

- 新規Userは案内されたEmailに対して本人名・Password・明示同意からAccountを1件作成する。会社は最終確定まで作らない。
- 既存Userは既存PasswordでLoginし、同じAccountを会社開始案件へ結合する。Password、Profile、他社Membership / Permissionを変更しない。
- 最終確定時に、現在のUser active、現在Email一致、Email verified、発行者のactive System Admin、Token世代・期限・状態、現行文書への同意を再確認する。
- TokenはScope 2 Recovery / Email Verification、Scope 4 Staff Invitationとtable・claim・routeを分離した。

### Atomicな最小会社構成

1回の最終Transactionで次を作成する。

- Organization 1件
- 本人のactive Owner Membership 1件
- `organization_role=owner`
- legacy `role=member`
- `company_role=member`
- `permissions=[]`
- shared / active / included標準Workspace 1件
- 本人のWorkspace owner Membership
- `standard_workspace_id`
- 完了結果、pre-Org / Organization成功Audit

S4 `StandardWorkspaceService`を同じDB connectionの外側Transaction内で再利用した。Organization、Membership、Workspace、結果、Auditの各書込点に障害を入れ、全体rollbackを確認した。Account作成は会社Transactionより前の本人資産として保持し、会社作成失敗時にも削除しない。

### 完了後の利用開始

- commit後だけS5 epoch-aware Organization contextと標準WorkspaceをSessionへ選択し、Company Homeへ移動する。
- Session書込・HTTP応答が失敗してもOrganization一式を取り消さず、同じ本人の再試行を同じ結果へ収束させる。
- HomeのStaff Invitation案内は任意で、Group / Position / 決算期 / 請求 / Avatar / Staff招待を開始必須にしない。
- 完了後Membershipが`suspended / left`なら古い開始処理からactiveへ戻さない。

### Personal Workspace

- Scope 6で開始した新Organizationは`personal_workspace_creation_enabled=false`とする。
- UIからPersonal選択肢を隠し、直接POSTも403で拒否する。
- 既存OrganizationはMigration default `true`で従来動作を維持し、既存Personal WorkspaceやRelationを変更しない。

### 法的同意・Release Gate

- Terms / PrivacyそれぞれのVersion、内容SHA-256、User、開始案件、目的、同意日時、`Asia/Tokyo`を保存する。
- 文書Versionまたは内容hashが変われば、最終確定前に現行版への再同意が必要になる。
- `OWNER_ONBOARDING_LEGAL_DOCUMENTS_PUBLISHED=false`を既定とし、正式文書のVersion / hash / URLが揃わない限り顧客入口を503で閉じる。
- 法務文面、契約成立、課金・Subscriptionは実装していない。

## 3. KEEP / REFACTOR / ADD

| 分類 | 対象 | 最終整理 |
| --- | --- | --- |
| KEEP | S2 Authentication / Password / Email Verification / Account Recovery | 本人Accountとcurrent Email確認をそのまま再利用。Owner Tokenで代替しない |
| KEEP | S3 Organization Role / Membership / Audit | Owner/Admin/Member契約、legacy非grant、active Ownerを維持 |
| KEEP | S4 Staff Invitation / Standard Workspace / Avatar | 標準Workspace Serviceを合成し、完了後は任意Staff Invitationへ接続 |
| KEEP | S5 Membership lifecycle / epoch / Cross-Org Project | 停止・終了後のreplay拒否、他社Relation・Project共同参加を維持 |
| KEEP | Bootstrap / Client会社化 / 追加shared Workspace | 別責務の既存経路として維持し、Owner Onboardingへ無理に委譲しない |
| REFACTOR | Login / Email change後redirect | Owner claimがある場合だけ専用Journeyへ復帰する分岐を追加 |
| REFACTOR | Workspace create | OrganizationごとのPersonal作成gateをUIとHTTPへ追加。既存Org defaultは維持 |
| REFACTOR | Company Home | 完了直後の任意Staff Invitation CTAとPersonal表示条件だけを追加 |
| ADD | Owner Onboarding Contract | 案件、operation、audit、claim、admin writer、journey、controller、views |
| ADD | User Legal Consent | Scope 6目的に限定したVersion / hash / JST Evidence |
| ADD | Owner Mail Job | encrypted / after-commit、最新世代・状態・発行者を実行時再認可 |
| ADD | Release configuration | 期限、claim、resend、rate limit、正式文書公開gate |

## 4. Security / Permission / Transaction保証

- 発行・再送・取消は既存`auth + active-user + credential-session + system-admin`に加え、Serviceでもactive System Adminを再確認する。
- 本人側はcurrent User、current Email、verified、global active、claimed User、案件世代・期限・状態、発行者状態、現行同意を利用時に再確認する。
- URL Tokenは暗号学的乱数をhash保存し、Model hidden、Audit / log / DB metadataへ平文Token・URL・Passwordを保存しない。
- Queue Jobは`ShouldBeEncrypted` / `ShouldQueueAfterCommit`。遅延Jobは案件状態・世代・期限・Token hash・発行者を再確認する。
- Mail投入失敗はOrganizationや開始案件のTransactionをrollbackせず、`delivery_status=failed`と秘密を含まないAuditを残す。failed案件はcooldownなしで再送できる。
- 完了Transactionは限定3回retryを持つ。DB unique、案件row lock、completed payload hash、Membership / Workspace制約により二重作成を防ぐ。
- Organization Ownerをlegacy ownerへ写さず、Financial / Project / AI権限を追加grantしない。

## 5. Phase記録

### S6-P0｜基準・互換監査

- できたこと: HEAD / origin / AGENTS / 正本7点 / Scope 1〜5包含 / writer / route / schema / Role / local DBを実コードで再確認。
- Test / Evidence: fresh baseline 401 tests / 3223 assertions / 0 failures。`docs/company-os-scope-6-p0-audit.md`。
- 残課題: なし。
- Pending更新: 正式文書、本番Mail、通常local / Production MigrationをRelease Gateへ維持。
- 判定: S6-C01 / C02未発生。

### S6-P1｜Contract・最小Schema・Service

- できたこと: additive Migration、Owner案件 / operation / audit / consent、Personal gate、Admin writer、claim、legal、journeyを実装。
- Test / Evidence: schema default、actor、idempotency、Token用途分離、fault injection。
- 残課題: なし。
- Pending更新: retention / purgeは運用判断として物理削除を追加せず継続。

### S6-P2｜本人Account・Mail・会社開始

- できたこと: 新規 / 既存User、current Email verified、再送 / 取消、atomic会社確定、Mail enqueue failure recoveryを実装。
- Test / Evidence: focused 13 tests / 173 assertions、Account保持、Session障害再開、旧Token、発行者無効化、法的文書差替え。
- 残課題: 本番Mailbox実送受信はRelease Gate。
- Pending更新: 正式文書とMail transportの運用値をRelease前に確定。

### S6-P3｜最小UXとScope 4接続

- できたこと: System Admin発行画面、本人新規 / 既存画面、Home CTA、新Org Personal UI / POST拒否を実装。
- Test / Evidence: Desktop 1440x1000、CSS viewport 390px、Feature Journey、horizontal overflowなし。
- 残課題: 外部Mailboxを使う全Journeyの実Browser受入はRelease前。
- Pending更新: Personal一般提供とHome全面再設計は後続Product判断。

### S6-P4｜統合・競合・Migration検証

- できたこと: Full regression、2実接続barrier、isolated clone up/rerun、empty DB rollback/re-up、Build、Browserを実施。
- Test / Evidence: 414 tests / 3396 assertions / 0 failures、Frontend build exit 0。
- 残課題: Production DB engineのConcurrency / Migration実測は未実施。
- Pending更新: Production適用前backup・rehearsalをRelease Gateへ維持。

### S6-P5｜Final Close

- できたこと: DC判定、保証 / 非保証、適用状態、Pending、主要ファイルを本Reportへ固定。
- Evidence: 実装Commit `26918df0ce7ccf3e9a94abf73791ed6296e9d7bf`。Report Commitは本ファイルを含む後続CommitとしてGit履歴と最終応答に記録する。
- 残課題: Production Deployなし。Scope 7未着手。

## 6. Test / Build / Browser Evidence

### Automated Test

| 区分 | 結果 |
| --- | --- |
| P0 fresh baseline | 401 tests / 3223 assertions / 0 failures / 138.97s |
| Scope 6 focused（最終） | 13 tests / 173 assertions / 0 failures / 6.46s |
| Full Suite（最終） | 414 tests / 3396 assertions / 0 failures / 144.17s |

Full SuiteはScope 1〜5のAI Proposal、Account、Organization / Group、Invitation、Membership lifecycle、Cross-Organization Project、Workspace、Financial、Project / Task等を含む。

### Static / Build

- Scope 6新規PHP 17ファイル: `php -l` error 0。
- Owner Onboarding route: 9 routesを確認。
- `php artisan view:cache`: success。
- Frontend: VS Code同梱Electronを`ELECTRON_RUN_AS_NODE=1`でNode v24.18.1として使用し、Vite 7.3.6 build exit 0。
- Build出力: `manifest.json` 331 bytes、CSS 39,190 bytes、JS 51,516 bytes。
- Node / npmの導入や環境変更は行っていない。

### Browser

- Desktop 1440x1000: `docs/evidence/scope-6/owner-onboarding-new-desktop.png`
- CSS viewport 390px相当: `docs/evidence/scope-6/owner-onboarding-existing-390.png`
- 新規Account入力、既存Account Login、Terms / Privacy、Owner確定UI、折返し、checkbox、horizontal overflowを確認。
- Email未確認、失敗 / 再開、Staff Invitation接続はFeature TestとHTTP応答で確認し、外部Mailbox込みの全実Browser操作は未実施。

### SQLite実接続Concurrency

- OS一時領域の隔離SQLite fileへ2つの実PHP processを接続。
- 両processがreadyになった後、同じbarrierを開放して同じ開始案件を同時確定。
- 結果: worker 1 success、worker 2 `database is locked`でfail-closed。
- 競合後retry: success、同じ`organization_id=1`。
- 最終件数: Organization 1、active Owner 1、shared active標準Workspace 1、Workspace owner 1、完了Audit 1。
- fixture DB / barrier / outputは検証後にOS一時領域から削除。
- SQLiteのwrite serializationをProduction DBや分散実行の一般保証として扱わない。

## 7. Migration / Data保持 / Rollback

### 追加Schema

- `organizations.personal_workspace_creation_enabled` boolean default true。
- `owner_onboardings`。
- `owner_onboarding_operations`。
- `owner_onboarding_audit_events`。
- `user_legal_consents`。

既存table / columnのRename・Drop・Data delete・推測backfillはない。既存Organizationはdefault trueで従来Personal作成を維持し、Scope 6新Orgだけwriterがfalseを明示する。

### 隔離Clone Evidence

- 通常local DBのcopyへScope 5→Scope 6を通常`migrate`で適用。
- 初回up成功、再実行は`Nothing to migrate`。
- Scope追加前から存在するcolumnだけを対象にした論理hashは前後とも `ea8df26e1476f49b8769047c5829d97c3d367962124b7f68c73f42bf81709ce6`で一致。
- 既存User / Organization / Membership / Workspace / ProjectのID・Relation・値を保持。

### Empty DB Rollback Evidence

- 隔離empty SQLiteへ全Migrationを適用。
- `migrate:rollback --step=1`でScope 6だけをdownし、Scope 6 MigrationがPendingへ戻ることを確認。
- Scope 6を再upして成功。

### Rollback方針

- `down()`はScope 6 tableとPersonal gate columnを戻せるが、実運用Dataが入った後のdownはOwner案件・同意Evidenceを削除するため自動実行しない。
- 本番適用前はbackupとisolated rehearsalを必須とする。
- 適用後の問題は原則forward fix / feature gateで切り戻し、Dataを伴うdownは個別承認とbackup確認後だけ行う。

## 8. 通常local DB / Production適用状態

### 通常local DB

2026-09-20 JSTの最終`migrate:status`:

- Scope 1〜4: Ran。
- `2026_09_20_000004_add_scope_five_membership_lifecycle`: Pending。
- `2026_09_20_000005_add_scope_six_owner_onboarding`: Pending。

通常local DBへ`migrate`、Data書込、rollbackを実施していない。そのため通常localでScope 5 / 6コードを利用する前に、別途backup確認とMigration適用作業が必要である。

### Production

- 接続、backup、Migration、Deployを実施していない。
- Productionの実Migration状態は本作業では確認していない。
- Git pushとProduction Deployは分離し、自動Deploy triggerを追加していない。

## 9. S6-DC-01〜30 最終判定

| DC | 判定 | 根拠 / Evidence |
| --- | --- | --- |
| S6-DC-01 | Done | P0 audit、基準HEAD、正本SHA、writer / route、local S5 Pending、fresh baseline |
| S6-DC-02 | Done | S6-DE-01〜04が正本5資料へ同期済みであることをP0で再照合 |
| S6-DC-03 | Done | system-admin middleware＋Service active SA再認可、regular / inactive negative、SA非所属Test |
| S6-DC-04 | Done | 既存Bootstrap回帰、新Owner `is_system_admin=false`、Public register非緩和 |
| S6-DC-05 | Done | 新規本人Account、Password hash、同意、Org確定前0件、User email unique / race回帰 |
| S6-DC-06 | Done | 既存User二社Journey、Password・他社Role / Permission不変Test |
| S6-DC-07 | Done | 未確認、Email変更、wrong account、現Email verifiedの最終Tx再確認 |
| S6-DC-08 | Done | 専用table / claim / route、Staff InvitationへのToken交換拒否、秘密非保存 |
| S6-DC-09 | Done | 期限inclusive、30分claim、世代、旧URL、再送、取消、issuer無効化、rate limit |
| S6-DC-10 | Done | 会社業務入力は会社名のみ。Group / Position / Finance / Billing / Avatar / Staffを必須化しない |
| S6-DC-11 | Done | organization / membership / workspace / result / audit全点fault injectionでOrg一式0件 |
| S6-DC-12 | Done | global active / verified本人のactive Owner 1件、S3 / S5 last Owner Full regression |
| S6-DC-13 | Done | S4 StandardWorkspaceServiceを同Tx合成し、pointer / shared active included / owner所属をTest |
| S6-DC-14 | Done | legacy member / company_role member / permissions空、CompanyAccess negative、他社値不変 |
| S6-DC-15 | Conditional | 2実接続barrierで1成功・1 SQLite lock fail-closed、retryは同じOrg、全件数1。Production DB実測なし |
| S6-DC-16 | Done | SA限定候補UI、同名別会社理由、同一案件再送、既存OrgはStaff Invitation案内、自動統合なし |
| S6-DC-17 | Done | 新規Account作成後の会社Tx障害でUser保持、案件issuedのまま再開可能 |
| S6-DC-18 | Done | Session context例外後もOrg 1件、同じ案件retryで同じOrgへHome着地 |
| S6-DC-19 | Done | 完了後Membership suspended replay拒否、Scope 5 lifecycle / epoch Full regression |
| S6-DC-20 | Done | Scope 5 Cross-Org Project回帰、他社Membership / Permission不変、所有Org所属追加なし |
| S6-DC-21 | Done | 新Org Personal UI非表示＋POST 403、既存Org default true＋既存Personal positive regression |
| S6-DC-22 | Done | Home任意CTA、S4新規 / 既存User Invitation Full regression、標準Workspace接続 |
| S6-DC-23 | Done | 新規Home着地、既存User二社、Company切替、OrgなしAccount、loop回帰 |
| S6-DC-24 | Done | Version / hash / User / case / purpose / JST、文書差替え再同意、未公開503 |
| S6-DC-25 | Done | encrypted after-commit Job、世代 / issuer再認可、pre-Org / Org Audit、enqueue失敗保持、秘密非記録 |
| S6-DC-26 | Done | Client会社化、追加Workspace、SA Membership管理の既存Full regression。S6へ委譲なし |
| S6-DC-27 | Done | additive Migration、clone up/rerun、empty down/re-up、既存column論理hash一致、local Pending |
| S6-DC-28 | Done | Scope 6 13/173、Full 414/3396、failure 0。P0値を転記せず再実行 |
| S6-DC-29 | Conditional | Vite build exit 0、Desktop / CSS390実Browser、Feature Journey合格。外部Mailbox込み全Browser操作は未実施 |
| S6-DC-30 | Done | Blocker 0、Final Report、実装 / Report Commit分離、Release Gate、Scope 1〜5 Closed、Scope 7未着手 |

## 10. Pending Decision / Release Gate

### 次Scopeへ持ち越す技術・Product事項

- Personal Workspaceの一般提供、販売・契約上の扱い、既存経路の最終整理。
- Client会社化とOwner Onboardingを含む販売入口全体の統合判断。
- Organization全体のPermission再設計、Project / Financial / AIへのRole自動grantは行わない。
- Account / Owner案件・同意Evidenceの長期保持・purge方針。

### Release前に必要な事項

- 正式Terms / Privacy本文、Version、内容SHA-256、公開URL、責任者を確定し、公開gateを有効化する。
- Production Mail transport / Queue / Worker / retry / failure監視と実Mailbox送受信を確認する。
- System Adminの発行・同名会社識別・既存OrganizationはStaff Invitationへ案内する運用手順を確定する。
- Production DB engineでConcurrency rehearsalを行う。
- 外部Mailbox込みで新規User、既存User、Email確認、失敗 / 再開、Staff接続の実Browser受入を行う。
- 通常local / Productionそれぞれでbackup、S5→S6 Migration、status、Data hash、切戻し判断を実施する。

### Scope 6固有で終了する事項

- 専用Token / claim / resend初期値、Service配置、Personal gateの物理field、pre-Org Audit方式は実装確定。
- S6-C01 / C02は未発生のまま終了。
- Scope 1〜5 Contractの変更・再Openはない。

## 11. 保証すること / 保証しないこと

### 保証すること

- 承認済み本人だけが、既存Accountまたは本人作成Accountから最小会社構成を開始できる。
- Organization一式は全成功または全rollbackで、同一案件のretryはOrganization 1件へ収束する。
- System Admin非所属、Owner初期Permission非grant、既存他社Relation不変、停止後replay拒否を維持する。
- 正式法務文書が未公開なら顧客入口を開かない。
- Scope 6 Migrationは既存Dataを削除・Renameしないadditive方式である。

### 保証しないこと

- 全世界の実在会社についての重複Organizationゼロ、自動法人同定、自動統合。
- Production DB / Queue / Mail /負荷環境での実動、同時HTTPが必ず双方即時成功すること。
- 法務文面の妥当性、契約成立、課金、Subscription、販売可否。
- Personal Workspace一般提供、初期Group / Position / Financial / Project / AI設定。
- 通常local / ProductionへのMigration適用、Production Deploy。

## 12. 主要ファイルと責務

| File / Directory | 責務 |
| --- | --- |
| `database/migrations/2026_09_20_000005_add_scope_six_owner_onboarding.php` | additive schema、Personal gate、案件 / operation / audit / consent |
| `config/owner_onboarding.php`, `.env.example` | Token / claim / resend / rate limit / legal release gate |
| `app/Models/OwnerOnboarding*.php` | 案件、operation、pre-Org audit |
| `app/Models/UserLegalConsent.php` | Scope 6限定の文書同意Evidence |
| `app/Services/Organization/OwnerOnboardingAdministration.php` | SA発行・再送・取消・冪等・duplicate判断・Mail投入失敗回復 |
| `app/Services/Organization/OwnerOnboardingJourney.php` | 新規/既存User結合、最終再認可、atomic Organization一式、retry |
| `app/Services/Organization/OwnerOnboardingClaim.php` | S4と分離した30分Session claim |
| `app/Services/Organization/OwnerOnboardingLegal.php` | 文書公開gate、Version/hash署名、JST同意記録 |
| `app/Services/Organization/OwnerOnboardingAudit.php` | Organization作成前からのsanitized Audit |
| `app/Services/Organization/OwnerOnboardingMailer.php` | 安全なURL生成、Mailer設定gate |
| `app/Jobs/SendOwnerOnboardingMail.php` | encrypted after-commit Mail、遅延時再認可、配送状態 |
| `app/Http/Controllers/OwnerOnboardingController.php` | 本人claim / show / register / prepare / complete |
| `app/Http/Controllers/SystemAdmin/OwnerOnboardingController.php` | SA一覧 / 発行 / 再送 / 取消 |
| `app/Http/Controllers/Auth/*`, `routes/web.php` | Login / Email writer後の復帰、認証境界、9 routes |
| `app/Http/Controllers/Workspace/WorkspaceController.php` | Personal新規作成のserver-side gate |
| `resources/views/owner-onboarding/` | 本人向け新規 / 既存Account最小Journey |
| `resources/views/system-admin/owner-onboardings/` | SA承認、候補、再送、取消UI |
| `resources/views/companies/home.blade.php` | 完了直後の任意Staff Invitation CTA、Personal表示互換 |
| `tests/Feature/OwnerOnboardingTest.php` | Scope 6の正負・atomicity・idempotency・security・recovery受入 |
| `tests/Support/owner_onboarding_concurrency_worker.php` | 一時SQLite限定の2実接続barrier Evidence worker |
| `docs/company-os-scope-6-p0-audit.md` | P0基準・inventory・baseline・C01/C02判定 |
| `docs/evidence/scope-6/` | Desktop / CSS390 Browser Evidence |

## 13. 最終結論

Scope 6は、正式文書・本番Mail・運用適用をRelease Gateとして閉じたまま、Ownerが本人操作でAccountからOrganization・最初のOwner・標準Workspaceまでを安全に開始できるコード基盤としてCloseする。

実装・Migration・TestはRepositoryへ完了しているが、通常local / Production DBには未適用であり、Production Deployも行っていない。次Scopeは自動開始しない。
