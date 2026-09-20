# Company OS Ver.1 Scope 7 Implementation / Final Close Report

- Scope: 事業領域（Business Domain）
- Repository: `C:\xampp\htdocs\rise-gate-os`
- Branch: `master`
- P0基準HEAD / `origin/master`: `5ffdd69cb2e263cf09e4291d264d024ee2a39d5c`
- Scope 7実装Commit: `f7cd0258dc2018fc20a4064597149b2b0533c592`
- Report Commit: 本ファイルを含む実装Commit直後の独立Commit。正確なhashはGit履歴と最終引継ぎに記録する
- 実施日 / Timezone: 2026-09-21 / `Asia/Tokyo`
- Scope 1〜6: Closed Contractを維持
- Production deploy / Production migration: 未実施
- 通常local DB: Scope 5 / Scope 6 / Scope 7 Migrationとも未適用（Pending）
- Scope 8: 未着手

## 1. Final Close判定

Scope 7は、Organizationごとの事業領域を0..N件のFlatな正本として保持し、5観点、自社認識の強み、任意明細・属性、専用編集権限、保管・再開、改訂履歴、stale防止、idempotency、監査、認可済み内部参照を提供する基盤として実装した。

最終判定は **Done 31 / Conditional 1 / Not Done 0** とする。未解決Blockerは0件である。

唯一のConditionalはS7-DC-17である。SQLite上の2実接続barrierでは、Membership停止、編集grant解除、Owner降格とDomain更新の競合が、片方成功・片方`database is locked`のfail-closedとなり、部分更新や権限復元がないことを確認した。一方、Production DB engineでの実接続競合はProduction適用前のRelease Gateであり、今回実施していない。

Scope 1〜6のContractを再Openせず、Project / Workspace / Financial / AI等の既存PermissionをBusiness Domainから自動grantしていない。Business Domainを既存AI Contextへ自動注入せず、後続ResourceとのPivotも追加していない。Production DeployおよびScope 8実装は行っていない。

## 2. Scope 7でできるようになったこと

### Business Domain正本

- Organization内に0..N件の独立したFlatなBusiness Domainを作成できる。
- 名称だけでも保存でき、同名Domainも別IDで保持する。自動統合、業種Template強制、Workspace同期は行わない。
- WHAT / WHO / VALUE / WHERE / POSITIONと、自社認識の強みを任意入力として保持する。
- Domain配下に安定IDを持つ明細と、観点・label・valueを持つ柔軟属性を保持する。
- メーカー、ホテル、飲食、ライズアップの異なる表現を同一構造で格納できる。

### 閲覧・編集・保管

- 対象Organizationのactive Staffは、現在値と保管済み現在値を閲覧できる。
- active Ownerは明示grantなしで作成、編集、保管、再開、詳細履歴、編集担当管理ができる。
- active Admin / Memberは、Ownerが付与した有効なBusiness Domain編集grantがある場合だけ編集・詳細履歴を利用できる。
- grant付与・解除はactive Ownerだけが行える。Role、Position、Group、Workspace、Project、Financial、AI、legacy company permission、System Admin状態から自動派生しない。
- `active` / `archived`の状態遷移で本体・明細・履歴を保持する。物理削除、旧版Restore、自動purgeは実装していない。

### History / consistency

- 更新、明細変更、保管、再開ごとに全体Snapshot、Actor、JST時刻、理由、versionを改訂履歴として残す。
- 過去SnapshotはModel eventで更新・削除を拒否する。
- `expected_version`でlost updateを拒否し、HTTPでは一貫したConflictを返す。
- Actor×Organization×request IDとpayload hashによって、同一再送は同じ結果へ収束し、異なるpayloadでのrequest ID再利用を拒否する。
- 本体、明細、属性、改訂、操作結果、sanitized auditを同一Transactionで確定する。各書込点のfault injectionで全rollbackを確認した。

### Search / internal reference

- 名称、共通説明、明細名、属性label/valueをOrganization内で検索できる。
- active / archived / allを切り替え、上限付きpaginationで取得できる。
- actor、Organization、明示Domain ID、利用目的、任意versionを必須入力とする内部参照Serviceを追加した。
- 内部DTOはschema version、Domain ID / version、`organization_self_reported`の出所、構造化現在値を返す。
- 旧版参照は現在の詳細履歴権限を持つ利用者に限定する。

## 3. KEEP / REFACTOR / ADD

| 分類 | 対象 | 最終整理 |
| --- | --- | --- |
| KEEP | S1 AI Proposal / Apply / Undo / Context whitelist | Business Domain categoryや本文自動注入を追加せず維持 |
| KEEP | S2 Account / Session Security | active User、credential sessionの既存境界を利用 |
| KEEP | S3 Organization Role / Membership / Audit | active MembershipとOwner/Admin/Memberを再利用し、Roleから業務Permissionを派生させない |
| KEEP | S4 Invitation / Standard Workspace / Avatar | Business Domainを初期入力必須にせず既存Journeyを維持 |
| KEEP | S5 Membership lifecycle / Cross-Organization Project | 停止・復帰・epoch・他社共同Project Contractを維持 |
| KEEP | S6 Owner Onboarding | Domain 0件のままHome到達・Staff招待可能。完了TransactionへDomainを追加しない |
| KEEP | Project / Workspace / Financial / Client / Group / Position | 名称が類似する既存Dataを置換せず、Permissionも変更しない |
| REFACTOR | Company Home / navigation | 任意のBusiness Domain入口と件数表示だけを追加 |
| REFACTOR | Organization / OrganizationUser relation | Scope 7専用Relationをadditiveに追加 |
| ADD | Business Domain schema / model | 本体、明細、属性、編集grant、操作、改訂の6Entity |
| ADD | Dedicated access / writer / query / reference | Scope 7専用認可、atomic writer、検索、安定内部参照Contract |
| ADD | Business Domain UI | 一覧、検索、作成、編集、詳細、保管、再開、履歴、編集担当管理 |
| ADD | Scope 7 Evidence | focused / regression / browser / concurrency / migration保持TestとP0監査 |

## 4. Security / Permission / Transaction保証

- 全経路でglobal active Userと対象Organizationのactive Membershipを都度評価する。
- URL、古いSession、偽Organization ID、他社Domain ID、他Domain明細IDからTenant境界を迂回できない。
- active Ownerの直接管理能力はBusiness Domain専用であり、他ResourceへPermissionを付与しない。
- Admin / Memberの編集能力はactiveな明示grantのみに限定する。停止、left、Role変更、grant解除後は現在資格で拒否する。
- `CompanyAccess::allows()`のlegacy owner全許可はBusiness Domainに使用していない。
- grant解除は対象Staffが停止済みでもOwnerが実行でき、復帰時に自動復元されない。
- Domain writerのlock順はOrganization → actor Membership → grant（必要時）→ Domainに統一した。
- success auditは同一Transaction内で保存し、自由入力本文や過去SnapshotをOrganization Auditへ転記しない。
- XSS、mass assignment、oversized payload、foreign ID、IDOR、SQL injection相当入力をfocused Testで拒否または安全表示した。
- 日付・履歴表示と保存時刻は`Asia/Tokyo`で検証した。

## 5. Phase記録

### S7-P0｜基準・互換監査

- できたこと: Repository、Branch、HEAD、AGENTS、正本7点のhash、route / writer / schema / Permission、S1〜6包含、通常local DBを実コードから確認した。
- 変更内容: [`company-os-scope-7-p0-audit.md`](./company-os-scope-7-p0-audit.md)を追加。新機能コードはP0完了後に着手した。
- Test / Evidence: fresh baseline `414 tests / 3396 assertions / 0 failures / 154.76s`。
- 残課題: なし。
- Pending更新: 通常local S5 / S6 Migration Pendingを維持。S7-C01 / C02は未発生。
- 次Phase: P1へ進行。

### S7-P1｜Contract・Schema・Permission

- できたこと: 6Tableのadditive schema、6Model、専用Access、grant管理、安定ID、状態・version Contractを追加した。
- 変更内容: Organization relationとBusiness Domain専用認可を追加。既存Role / Permission列は変更していない。
- Test / Evidence: Tenant、active Membership、Owner直接能力、Admin / Member grant、非Owner legacy owner、他社・停止・left・inactive負例。
- 残課題: なし。
- Pending更新: Production DB engine競合はRelease Gateへ維持。
- 次Phase: P2へ進行。

### S7-P2｜Writer・History・Search・Reference

- できたこと: atomic writer、Snapshot、operation、revision、stale、idempotency、保管・再開、検索、内部参照Serviceを追加した。
- 変更内容: 明細・属性の安定IDを維持し、入力から欠落した既存行はarchiveとして履歴保持する。Resource PivotやAI接続は追加していない。
- Test / Evidence: fault injection、二重送信、異payload、foreign ID、old version、immutable revision、DTO schema/source、入力上限・XSS。
- 残課題: なし。
- Pending更新: Resource cardinality / Pivotは担当Scope、AI実接続はS11へ維持。
- 次Phase: P3へ進行。

### S7-P3｜最小UI / UX

- できたこと: 一覧、検索、0件状態、作成、編集、詳細、保管、再開、履歴、編集担当管理を追加した。
- 変更内容: Company Homeと共通navigationへ任意入口を追加。S6 Onboardingの必須入力・完了条件は変更していない。
- Test / Evidence: Desktop 1280×900、Mobile 390×844の実Chrome Journey、permission negative、長文、明細属性、stale、保管、履歴、再開、grant解除、横overflowなし。
- 残課題: なし。
- Pending更新: 業種Template、独立商品Master、Domain限定公開は後続判断。
- 次Phase: P4へ進行。

### S7-P4｜回帰・競合・Migration・Build / Browser

- できたこと: focused、関連回帰、Full Test、Build、Browser、2実接続競合、isolated clone、empty DB rollback / re-upを検証した。
- 変更内容: 検証で判明したeditor viewのrequest ID生成をController責務へ修正。Transaction checkpointsを本体・改訂・操作・Audit後に揃えた。
- Test / Evidence: §6〜8参照。
- 残課題: Production DB engineの競合実測のみ。
- Pending更新: S7-DC-17をConditionalとしてRG-09へ引継ぎ。
- 次Phase: P5へ進行。

### S7-P5｜Done判定・Final Close

- できたこと: DC32件のEvidence、保証範囲、限界、Pending、Migration状態、主要ファイル、引継ぎを本Reportへ固定した。
- 変更内容: Final Close Reportのみ。追加機能・通常local Migration・Production操作なし。
- Test / Evidence: 実装Commit `f7cd0258dc2018fc20a4064597149b2b0533c592`、Report Commitは本ファイルを含む独立CommitとしてGit履歴と最終引継ぎへ記録する。
- 残課題: Conditional 1件とRelease Gate。
- Pending更新: §10参照。
- 次Phase: Scope 8へ進まず停止。

## 6. Test / Build / Static Evidence

### Automated Test

| 区分 | 結果 |
| --- | --- |
| P0 fresh baseline | 414 tests / 3396 assertions / 0 failures / 154.76s |
| Scope 7 focused（最終） | 14 tests / 129 assertions / 0 failures / 1.17s |
| S1 / S3 / S5 / S6関連回帰 | 62 tests / 668 assertions / 0 failures / 27.78s |
| Full Suite（最終HEAD） | 428 tests / 3525 assertions / 0 failures / 141.07s |

Full SuiteはAccount、Invitation、Organization / Group、Membership lifecycle、Owner Onboarding、Cross-Organization Project、AI Proposal / Context、Workspace、Financial、Project / Roadmap / Improvement / Task等を含む。

### Static / Build

- Scope 7 PHP syntax check: error 0。
- 対象Pint: success。
- Business Domain route: 12 routesを確認。
- Blade compile / view cache: success。
- `git diff --check`: error 0。
- Frontend: VS Code同梱Electronを`ELECTRON_RUN_AS_NODE=1`でNode v24.18.1として使用。環境へのNode導入・変更なし。
- Vite 7.3.6、58 modules、build exit 0、1.42s。
- 生成物: `manifest.json`、CSS 39.19 kB、JS 51.52 kB。`public/build`はgitignore対象でCommit差分なし。

## 7. Browser / Concurrency Evidence

### Browser

- 実Chrome / Playwright core / isolated SQLite / Login済みSessionで実行。
- Desktop 1280×900: screenshot 55,152 bytes。
- Mobile 390×844: screenshot 186,212 bytes。
- 0件、一覧、複数件、長文、作成、明細属性、stale、保管、履歴、再開、編集担当解除、permission negativeを完走。
- 390pxでdocument幅とviewport幅を比較し、horizontal overflowなしを確認。
- Browser用一時DBと一時screenshotは検証後に削除し、RepositoryへEvidenceバイナリを残していない。

### SQLite 2実接続Concurrency

- 独立PHP processをbarrierで同時解放し、停止 / grant解除 / Owner降格とDomain更新を競合させた。
- 停止競合: suspend成功、Domain更新はDB lockでfail-closed。最終Domain v1 / revision 1、Membership suspended、grant保持。
- grant解除競合: Domain更新成功、revokeはDB lockでfail-closed。最終Domain v2 / revision 2、active Admin、grant active。
- Role変更競合: Domain更新成功、demoteはDB lockでfail-closed。最終Domain v2 / revision 2、Owner、grantなし。
- いずれも部分更新、二重version、暗黙grant生成・復元はない。
- 逐次回帰では、停止、grant解除、Owner降格後の次回アクセスが拒否されることを確認した。
- SQLite writer serializationをProduction DB engineの一般保証とはしない。Production相当engineの実測はRG-09へ引き継ぐ。

## 8. Migration / Data保持 / Rollback

### 追加Schema

- `business_domains`
- `business_domain_items`
- `business_domain_item_attributes`
- `business_domain_editor_grants`
- `business_domain_operations`
- `business_domain_revisions`

既存Table / columnのRename、Drop、既存Data delete、推測backfill、既存OrganizationへのDomain / grant自動生成はない。

### Isolated clone Evidence

- 通常local DBのcopyへScope 5 → Scope 6 → Scope 7を順に適用した。
- 初回up成功、再実行は`Nothing to migrate`。
- Migration前から存在する71Tableの列、値、ID、Relation論理hashを比較し、差分0件。
- Scope 5 / 6 / 7の新規Tableを確認した。
- 通常local DB本体にはMigrationを適用していない。

### Empty DB rollback Evidence

- isolated empty SQLiteで全Migrationを適用。
- `migrate:rollback --step=1`でScope 7だけをdownし、Scope 7 MigrationがPendingへ戻ることを確認。
- Scope 7を再度upして成功。
- Data入りDBのdownはDomain / grant / historyを削除するため自動実行していない。

### Rollback / 切戻し方針

- 適用前にbackupとisolated rehearsalを必須とする。
- 適用後障害はfeature gateまたはforward fixを優先する。
- Dataを伴う`down()`は不可逆な業務履歴削除になるため、個別承認、backup確認、復元手順なしに実行しない。
- Scope 7 codeを切り戻す場合も、追加Tableを残したまま旧codeへ戻す方式を優先する。

## 9. 通常local DB / Production適用状態

### 通常local DB

2026-09-21 JSTの最終`migrate:status`:

- Scope 1〜4: Ran。
- `2026_09_20_000004_add_scope_five_membership_lifecycle`: Pending。
- `2026_09_20_000005_add_scope_six_owner_onboarding`: Pending。
- `2026_09_21_000006_create_scope_seven_business_domains`: Pending。
- DB file size: 1,081,344 bytes。
- DB SHA-256: `DDC022271CEAD810E01CFD55C26A0FF78A4E20F4A3455A49149E8C785D42A8A1`。
- 通常local DBに`migrate`、rollback、Data書込みを実行していない。

したがって、RepositoryはScope 7実装済みだが、通常local環境でScope 5〜7機能を利用するには、別作業としてbackup確認後にPending Migrationを順番に適用する必要がある。

### Production

- Production backup、Migration、Deployは未実施。
- Production DBへ接続して`migrate:status`を取得していないため、直接の適用状態は未確認。
- Repository上ではScope 5 / 6 / 7 MigrationがProduction未適用前提のRelease対象として残る。
- PushとDeployは分離しており、push triggerによるDeployは追加していない。

## 10. S7-DC-01〜32 最終判定

| DC | 判定 | 根拠 / Evidence |
| --- | --- | --- |
| S7-DC-01 | Done | P0 audit、基準HEAD、AGENTS、正本7点hash、現物差分、fresh 414/3396、local S5/S6 Pending |
| S7-DC-02 | Done | S7-DE-01〜04がDecision / PD / Blocker / Permission / State / DC / G15 / 両管理表へ同期済み。正本hash再確認 |
| S7-DC-03 | Done | Domain 0件のS6 Home、Staff Invitation、Owner Onboarding回帰。Business Domainは任意入口 |
| S7-DC-04 | Done | 一覧、詳細、検索、履歴、内部参照、書込のglobal active＋対象Org active Test。A/B社、invited / suspended / left / inactive / fake ID負例 |
| S7-DC-05 | Done | Owner grant 0直接管理、Admin / Member grant前後、legacy owner negative、既存Resource Permission不変Test |
| S7-DC-06 | Done | active Ownerだけがgrant / revoke。停止中grant解除、Role再判定、自動grant / 復元なし、atomic operation / audit Test |
| S7-DC-07 | Done | 同社active Staff現在値、Owner旧版、grant editor旧版、未付与Staff旧版・他社・停止負例と漏えい防止Test |
| S7-DC-08 | Done | 0..N Flat Domain、名称のみ、同名別件、no auto merge、no Workspace sync Test |
| S7-DC-09 | Done | 5観点とself-recognized strengthsの任意保存・再表示、空欄維持、AI補完なし |
| S7-DC-10 | Done | 明細 / 属性の安定ID、構造化列、foreign Domain item拒否、JSON一塊不採用 |
| S7-DC-11 | Done | メーカー / ホテル / 飲食 / ライズアップの4 fixtureで同一構造と属性対応を確認 |
| S7-DC-12 | Done | active↔archived、理由、ID / 明細 / 履歴保持、既定一覧除外、物理削除なし |
| S7-DC-13 | Done | 更新 / 明細 / 保管 / 再開の全体Snapshot、Actor、JST、理由、current一致、immutable過去版Test |
| S7-DC-14 | Done | 本体 / 改訂 / 操作 / audit後のfault injectionで全rollback、部分Domain・版欠落なし |
| S7-DC-15 | Done | 2編集と明細編集のexpected version Conflict、lost update防止、HTTP 409 |
| S7-DC-16 | Done | 作成 / 編集再送、応答紛失相当、同payload収束、異payload拒否、Domain / 版 / audit重複なし |
| S7-DC-17 | Conditional | SQLite 2実接続barrierはfail-closedで部分更新なし。停止 / 解除 / 降格後拒否も確認。Production DB engine実測はRG-09 |
| S7-DC-18 | Done | Domain / 明細ID再採番なし。S5停止でData / Relation / History保持、grant復元なし、Ownerは復帰後の現在Roleで直接判定 |
| S7-DC-19 | Done | actor＋org＋明示Domain ID / version / purpose、他社・停止・不存在版拒否、versioned DTO |
| S7-DC-20 | Done | S7でResource Pivot / UI / Permission変更なし。S8 / S12〜15の0..N候補・両側認可をPendingへ記録。Project回帰成功 |
| S7-DC-21 | Done | S5 Cross-Org Project positiveを維持し、相手OrganizationのDomain一覧・詳細・内部参照はnegative |
| S7-DC-22 | Done | AI Chat / MCP / Proposal収集payloadにDomain名称・本文・件数なし。偽category拒否、Apply対象追加なし |
| S7-DC-23 | Done | 自社認識の強みとmarket positionを自己申告として保存し、SWOT / Confidenceへ自動書込なし。DTO source明示 |
| S7-DC-24 | Done | Business Profile / bank / documents / Client / Group / Project / Financialの回帰、71既存Table論理hash差分0 |
| S7-DC-25 | Done | escape、長さ / 件数 / pagination上限、mass assignment、XSS、foreign ID、oversized payload、IDOR負例 |
| S7-DC-26 | Done | sanitized Organization Auditと本文Snapshotを分離。操作名 / ID / version / Actor / resultのみAuditへ保存 |
| S7-DC-27 | Done | Full 428/3525。S1〜6 Closed、Account / Invitation / Offboarding / Onboarding / Permission / Undo回帰成功 |
| S7-DC-28 | Done | additiveのみ、Domain / grant backfill 0。clone up / rerun、71Table保持、empty down / re-up、Data入りdown未実行 |
| S7-DC-29 | Done | focused 14/129、関連62/668、Full 428/3525、Vite build exit 0。現行HEADでfresh取得 |
| S7-DC-30 | Done | 実Chrome Desktop / 390pxで主要Journey・negative・長文・競合・保管・履歴・grant、overflowなし |
| S7-DC-31 | Done | S1〜6 Closed → S7 → S8〜18 → RH → RC、旧S7〜17の繰下げ、286 FR、原本Pending 85件を資料全文で再照合 |
| S7-DC-32 | Done | 全DC判定、Blocker 0、限界 / RG / Commit / DB状態を本Reportへ記録。Scope 8未着手、Deployなし |

## 11. Pending Decision / Release Gate

### 次Scopeへ持ち越す事項

- S8 / S12〜15: Project / Action / Improvement / Document / Knowledge / Meeting / Decision / 経営設計との物理Pivot、cardinality、Relation管理主体、両側認可。
- S11: Business DomainをAI Contextへ明示接続する場合のpurpose、visibility、consent、送信範囲、category、監査。自動注入は禁止を維持する。
- RH / RG-02: Production相当cloneでのbackup / restore / Migration rehearsalと責任者・手順。
- RH / RG-03: 全Application横断のTenant / Permission / AI / Export / Job経路監査。
- RH / RG-04: Revision / Auditのretention、purge、閲覧運用。
- RH / RG-06: RC時点の全体受入、fresh Test、Build、Mobile。
- RH / RG-09: Production DB engineの2実接続Concurrency Evidence。
- 通常local: backup確認後のScope 5 → 6 → 7 Migration適用は別の明示作業。
- Production: backup、Migration、monitoring、rollback、Deploy判定は今回外。

### Scope 7固有として確定・終了する事項

- Physical name、6Table、ULID public ID、Flat 0..N構造。
- name 255、各長文10,000、明細100、明細属性30、pagination 50の初期上限。
- 同名許可、no auto merge、業種Templateなし。
- archivedの変更はreopen後に行う。物理削除・自動Restoreなし。
- Owner直接管理、Admin / Member明示grant、他権限からの非派生。
- Snapshot schema v1、source `organization_self_reported`、内部参照Contract。
- S7-C01 / C02は未発生のまま終了。

## 12. 保証すること / 保証しないこと

### 保証すること

- Repository実装、automated Test、実Chrome受入、Vite build、SQLite競合、isolated Migration rehearsalで確認したScope 7 Contract。
- Organization / User / Membership / Workspace / Project等の既存Data・ID・Relationを変更しないadditive Migration。
- active Membership、専用編集grant、Tenant、stale、idempotency、atomicity、JST履歴、AI非自動注入の境界。
- S1〜6の既存回帰が現行HEADで全件成功していること。

### 保証しないこと

- Production DB / Production traffic / Production concurrencyでの動作。
- 通常localまたはProductionへScope 5〜7 Migrationが適用済みであること。
- Production backup / restore、monitoring、capacity、retention運用。
- Business DomainとAI、Project、Action、Meeting、Knowledge等の実接続。
- 物理削除、旧版Restore、承認workflow、field-level ACL、Domain限定外部公開。
- Data入りDBでの`down()`安全性。`down()`はScope 7 Data / Historyを削除する。

## 13. 主要ファイルと責務

| File / Directory | 責務 |
| --- | --- |
| `database/migrations/2026_09_21_000006_create_scope_seven_business_domains.php` | 6Tableのadditive schema、FK、unique、index |
| `app/Models/BusinessDomain.php` | Domain本体、ULID route key、Organization / item / revision relation |
| `app/Models/BusinessDomainItem.php` | Domain内明細とarchive状態 |
| `app/Models/BusinessDomainItemAttribute.php` | 明細の観点・任意label / value属性 |
| `app/Models/BusinessDomainEditorGrant.php` | Admin / Memberの明示編集grant |
| `app/Models/BusinessDomainOperation.php` | request ID / payload hash / resultによるidempotency |
| `app/Models/BusinessDomainRevision.php` | immutableな全体Snapshot、Actor、理由、version |
| `app/Services/BusinessDomain/BusinessDomainAccess.php` | active Membership、Owner直接能力、明示grantの専用認可 |
| `app/Services/BusinessDomain/BusinessDomainGrantManager.php` | Owner限定grant / revoke、lock、operation / audit |
| `app/Services/BusinessDomain/BusinessDomainWriter.php` | atomic create / update / archive / reopen、stale、idempotency、fault checkpoints |
| `app/Services/BusinessDomain/BusinessDomainSnapshot.php` | schema v1の構造化current / revision Snapshot |
| `app/Services/BusinessDomain/BusinessDomainQuery.php` | Organization内search / filter / pagination |
| `app/Services/BusinessDomain/BusinessDomainReferenceService.php` | actor / org / explicit Domain / purpose / versionを要求する内部参照 |
| `app/Http/Controllers/BusinessDomainController.php` | 12 Web routeのvalidation、認可、UI応答 |
| `resources/views/business-domains/` | 一覧、作成、編集、詳細、履歴、編集担当UI |
| `app/Http/Controllers/CompanyHomeController.php` | 任意のBusiness Domain件数をHomeへ供給 |
| `resources/views/companies/home.blade.php` | 任意入口Card |
| `resources/views/layouts/app.blade.php` | 共通navigation入口 |
| `app/Models/Organization.php`, `OrganizationUser.php` | Scope 7 relationのadditive追加 |
| `routes/web.php` | Business Domain route群 |
| `tests/Feature/BusinessDomainTest.php` | Permission、Tenant、writer、history、security、AI非注入、回帰Test |
| `tests/Browser/business-domain-acceptance.mjs` | 実Chrome Desktop / Mobileの主要Journey |
| `tests/Support/business_domain_concurrency_worker.php` | 2実接続barrier競合Evidence worker |
| `tests/Support/sqlite_logical_fingerprint.php` | isolated cloneの既存Data / relation論理hash |
| `docs/company-os-scope-7-p0-audit.md` | P0監査、baseline、正本hash、C01 / C02判定 |

## 14. 次Scopeへの技術的注意事項

- ResourceとDomainを結ぶだけでDomain閲覧権限を付与しない。Resource側とBusiness Domain側の両方を認可する。
- Cross-Organization Project参加者へ、相手OrganizationのDomainを暗黙公開しない。
- Business DomainをAIへ渡す場合は`BusinessDomainReferenceService`を入口候補とし、S11でpurpose / consent / visibility / auditを明示確定する。
- `AiProjectContextGuard::SCOPE_ONE_CATEGORIES`やScope 1 payloadを先回りして拡張しない。
- 更新系はOrganization先行lockと`expected_version`、request ID / payload hashを維持する。
- Revision Snapshotを現在値の代替にせず、現在値と履歴の責務を分ける。過去版を書き換えない。
- 通常local DBはScope 5 / 6 / 7がPendingである。次の実利用確認前に、別作業としてbackupと適用順を確認する。
- Production適用前にRG-02 / RG-03 / RG-09を実施し、SQLite EvidenceをProduction DB一般保証へ読み替えない。

## 15. 最終結論

Scope 7は、会社自身が「何を、誰に、どのような価値として提供するか」を、日常利用可能な最小UIと厳密なPermission / History / Transaction境界の両方を備えたCompany Context正本として保持できる状態になった。

現行HEADで `428 tests / 3525 assertions`、Frontend Build、実Chrome Desktop / 390px、isolated Migration、既存Data保持、SQLite 2実接続fail-closedを確認した。Production DB engine競合だけをConditionalとしてRelease Gateへ明示的に残す。

実装・Migration・TestはRepositoryへ完了しているが、通常local / Production DBにはScope 5〜7が未適用であり、Production Deployも行っていない。Scope 1〜6はClosedのまま維持し、Scope 8には進まず、Scope 7をここでCloseする。
