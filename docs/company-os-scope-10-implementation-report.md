# Company OS｜Scope 10 通知・PWA Implementation Report

- 判定日: 2026-09-26 JST
- 正本: Master v144 / v040、Scope 10 v002成果物3点
- 実装base: 924af91188cc60d33ff87c91b94ecc1d539566e6
- 開発線: scope10-notification-pwa
- 状態: **Formal Closed**

## 1. P0 Audit

5点の正本を再照合し、S10-DE-01〜05、S10-PD-01〜05、S10-B01〜05が確定・解消済みであることを確認した。S10-C01〜03は検出していない。IR-1のmaster、RC、artifact、G05/G06/G12、Production、通常local DBには触れず、専用branch / worktree / TEMP SQLiteで分離した。

再利用したClosed Contractは、S1 atomic Proposal Apply、S2〜7 Account / Organization / Tenant、S8 Project / Action、S9 ActionExecution / Todayである。通知のために既存Permission、Tenant、History、Action / Execution責任境界は変更していない。

## 2. 実装差分

- 7つのadditive tableで、Organization Policy、例外日、個人Preference、通知正本、channel delivery、attempt receipt、暗号化Push購読を分離した。
- Action作成、実Review申請、差戻し、Today Digestを通知sourceへ接続した。自己依頼と0件Digestは生成しない。
- S1 / S8 AI Applyで作成するActionも既存Apply transaction内で通知化し、Apply失敗時は通知を含めrollbackする。
- 保存時の希望Timingと、Policy / Quiet Hours / 休日 / 個人Quietによる実配信可能時刻を分離した。UTC保存、JST入力・表示を維持した。
- Notification Center、未読badge、read / source seen / action doneの独立状態、opaque ULID Deep Link、再認可を実装した。
- channelはIn-App / Email / Web Pushを分離し、本人opt-in、verified email再確認、外部payload最小化、Push endpoint allowlist、購読鍵のencrypted castを実装した。
- deliveryはkill switch、bounded batch、lease、最大3回retry、attempt receipt、Push失敗時の明示Email fallbackを持つ。
- manifest、Service Worker、Push購読、Home Screen入口を追加した。Service Workerは業務画面・Business Data・sessionをcacheしない。
- minishlink/web-pushはadapter内に閉じ込め、Domain / Service ContractをProvider固有にしていない。

## 3. Done Review

### Done

S10-DC01〜15、DC21、DC22、DC24、DC25は実装・focused / Browser / regression EvidenceでDone候補とする。DC02〜09は、sourceとdeliveryの分離、対象者限定、自己通知抑止、実遷移通知、Digest重複/0件抑止、3 Timing、仕事を隠さないdeferred、状態分離を確認した。DC10〜15はOrganization / 個人Policy、JST、channel opt-in、source再認可、epoch / credential、SSRF / Secret境界を確認した。

### Conditional

- S10-DC16: SQLite unique / idempotency / lease実装は確認済み。Scope 10差分のMariaDB 10.11独立process競合は、既存隔離MariaDBが終了済みのため未実施。
- S10-DC17: fakeによる取消・retry・receipt構造は確認済み。許可された実Push / Email受信先での少数sampleとaccept後crash実測は未実施。
- S10-DC18: manifest / Service Worker / opaque Deep Linkは実Chromeで確認済み。実Android Chrome、iOS Home Screen、Desktop Edgeの実機Install / Push到達は未実施。
- S10-DC19: 実Chrome Desktop / 390px、recipient負例は合格。期限切れLogin、OS keyboard、実端末focusは未実施。
- S10-DC20: navigation / Business Data非cacheを実装・静的確認済み。実端末のoffline復帰、旧Service Worker更新、Cache Storage / IndexedDB目視は未実施。
- S10-DC23: 全95 Migrationを空の隔離SQLiteへ適用し、PRAGMA integrity_check=ok、Scope 10の7 tableを確認した。MariaDB 10.11 Migration実測は未実施。

Conditional項目をfake PASSへ置き換えない。Code CloseとReleaseは分離し、外部配信・VAPID・worker・Origin・実機確認はRelease Evidenceとして別途必要である。

## 4. Verification Evidence

- PHP lint: 変更対象23 files、error 0。追加補正fileも個別lint合格。
- Blade compile: PASS。
- JavaScript syntax: PWA / Browser harness PASS。
- Scope 10 focused: 10 tests PASS。
- S1 / S8 / S9 / S10 focused regression: 59 tests / 296 assertions PASS。
- Release Hardening + Scope 8 / 9 / 10 critical regression: 40 tests / 196 assertions PASS。
- normal isolated SQLite full suite: **519 PASS / 0 FAIL / 16 SKIP / 4,189 assertions**。16 SKIPは承認済みMariaDB RG02専用profileでありPASSへ算入していない。
- Browser E2E: ProjectからAction作成、Assignee通知、Deep Link、Review申請、Reviewer通知、差戻し、Assignee通知、別recipient 404、Desktop、390px、manifest / Service Workerを、事前通知生成なしの実Chrome JourneyでPASS。
- Frontend production build: Vite 7.3.6、58 modules、manifest / CSS / JS生成PASS。
- isolated SQLite Migration: 95件、integrity ok、Scope 10 table 7件、DB SHA-256 3D3F08AF1A20AE1380115B4B010013315200F3B2767CFAD627EC47DE96412D6E。

## 5. Formal Close前の人確認

実Android Chrome、iOS Home Screen、Desktop Edgeで、Install、Push許可/拒否/解除、Login復帰、Deep Link、Logout、Account切替、offline復帰、Service Worker更新を確認する。Test専用VAPIDと本人が許可した受信先だけを使い、Business Data本文を外部payloadへ出さない。MariaDB 10.11は隔離環境でScope 10 Migrationとdelivery claim競合を確認する。

## 6. Companion Delta

Text Quick Captureは本体へ混入していない。正式な次工程は **S10 Formal Close → S10-CD-TQC｜Text Quick Captureの設計・実装・検証 → Scope 11** とする。S10-PD-12を維持し、S10-CD-G01によりText Quick Capture完了Evidenceが揃うまでScope 11へ進まない。Voice / STT / AI構造化はさらに別差分である。

## 7. Formal Close前 Close Verification（2026-09-26 JST）

既存Evidenceを再利用し、未取得だったDC16 / DC17 / DC18 / DC19 / DC20 / DC23だけをSAFE LOOPで再評価した。Production、通常local DB、IR-1、Masterには接続・変更していない。

### S10-DC16｜PASS

- 実施環境: 公式MariaDB 10.11.19 Windows package、隔離TEMP datadir、127.0.0.1:13319、InnoDB、utf8mb4_unicode_ci、REPEATABLE READ。package SHA-256は 398ea30e5036010bbebe01d2b1804280424dcc2626e36d8e95155c04d25a0490。
- 同一Eventを独立2 processで競合させ、commit待ちを含めてもNotification 1件 / Delivery intent 1件、duplicate key 0件へ収束した。
- 2 worker同時claimの初回実測で、同一Deliveryを両workerが処理しAttemptが2件になるDefectを検出した。active lease判定をPHP時刻比較からMariaDB上のUTC predicate + row lockへ変更し、claim未取得をfailureへ誤計上しないtri-stateへ最小修正した。
- 修正後はworker Aが processed 1 / delivered 1、worker Bが processed 0 / delivered 0 / failed 0。Delivery 1件 / Attempt 1件、duplicate Notification / Delivery intentとも0件。
- active lease中はprocessed 0、lease期限後はprocessed 1 / delivered 1で回収。最終fixture全体はNotification 3件 / Delivery 3件 / delivered 3件 / Attempt 3件、duplicate 0件。
- Corrective Delta: あり。Product / Tenant / Permission / Data Contract変更なし。

### S10-DC23｜PASS

- 同じ隔離MariaDB 10.11.19へRepository全95 Migrationを適用し、Scope 10の7 additive tableを確認した。
- 7 tableはすべてInnoDB / utf8mb4_unicode_ci。12 FK、dedupe_key / public_id / notification+channel / operation_id / organization+date / organization / organization+user / endpoint_hashのunique、due / center / unread / recipient indexを実測した。
- Migration再実行は Nothing to migrate。既存Migrationの推測変換、通常local Migration、Production Migrationは行っていない。
- 隔離fixtureのUser 2 / Organization 1 / Workspace 1 / Project 1 / Action 3を維持したままScope 10処理を実行した。
- Corrective Delta: なし。

### S10-DC17｜CONDITIONAL

- fakeによる既存Evidenceは維持。Test専用VAPID、Test Origin、Test Account、Test Push Subscription、本人承認済みTest Emailが現環境に揃っていないため、外部送信はfail-closedで未実施。
- 実Push Provider受付、実端末受信、Email実受信、invalid subscription / unsubscribe / retry / accept後crashの外部境界は、本人が受信先と操作を確認した後に実施する。
- Corrective Delta: なし。

### S10-DC18｜CONDITIONAL

- 既存Chrome E2Eのmanifest / Service Worker / Deep Link Evidenceは再利用。
- Android Chrome、iOS Home Screen、Desktop EdgeのInstall / Push permission / 実受信 / tap / Login / Deep Linkは物理端末操作待ち。
- Corrective Delta: なし。

### S10-DC19｜CONDITIONAL

- isolated localhost + 実Chromeで、期限切れLoginからNotification Deep Linkを開くとLogin後にCompany Homeへ失われるDefectを再現した。
- Login時にurl.intendedを無条件破棄していた処理を、Organization context選択後にintendedへ復帰するよう最小修正した。Invitation / Owner Onboarding優先時とOrganization未確定時はintendedを破棄する既存安全境界を維持した。
- 修正後の実Chromeで Push相当Deep Link → Login → 元Notification open → 正しいProjectへの復帰、Logout後のNotification Center拒否を確認した。
- 実スマートフォンkeyboard / focus、Push Permission拒否・解除・再判定、実端末Account切替は本人操作待ち。
- Corrective Delta: あり。Product / Tenant / Permission / Security Contract変更なし。

### S10-DC20｜CONDITIONAL

- isolated localhost + 実ChromeでService Worker controlling、offline時のBusiness navigation非提供、network復帰、registration.update時の編集中form保持、Logoutを確認した。
- Cache Storageは company-os-shell-v1 の favicon.png / manifest.webmanifestだけ。IndexedDBは0件で、Business Data / session / CSRF / offline write queueが残らないことを実測した。
- 実端末上の旧Service Workerから新Service Workerへのversion切替は本人操作待ち。Corrective Deltaなし。

### Corrective Verification

- Scope 10 / Product Organization / Invitation / Owner Onboarding focused regression: 54 tests / 542 assertions PASS。
- Scope 10単体: 11 tests / 33 assertions PASS。active lease再claim負例を追加した。
- focused Chrome Close Journey: expired Login復帰、SW control、Cache Storage / IndexedDB非保持、form保持、offline非queue、network復帰、Logout保護がPASS。
- 現在の再判定: DC16 Done、DC23 Done、DC17〜20 Conditional。Conditionalは6件から4件へ減少し、Not Doneは0件。
- Formal Close判定: 現時点ではConditional。本人実配信・実機Evidenceの反映後に再判定する。
- Master Update: 現時点で不要。S10-CD-TQC / S10-CD-G01は維持し、Text Quick CaptureとScope 11へ進んでいない。

## 8. Master / Environment

現時点でProduct / Architecture Contractの変更はなく、Master v144 / v040更新は不要候補。通常local Migration、Production接続・Migration、Deploy、IR-1変更、HOW、Scope 11は未実施。

## 9. 本人実配信・実機Close Verification追補（2026-09-26 JST）

前節までの履歴を維持し、Scope 10専用合成Dataを同一Private Network内の一時HTTPS Originで本人検証した。Production、通常local DB、IR-1のCredential・User・Data・設定は使用していない。

### S10-DC17｜CONDITIONAL

- Test専用VAPID / Origin / Account / iPhone Push Subscriptionだけを使用。外部payloadは汎用title / body / opaque linkに限定し、Business Dataを含めていない。
- Apple Push Provider受付、iPhone実受信、tap、認証済み遷移、未Login時のLogin復帰、再認可後のNotification Center表示を本人確認した。Provider受付とreadは別状態で、duplicate送信はなかった。
- 予約TLDのVAPID subjectに対する403を成功扱いしないことを確認し、本人承認済みの有効な連絡先形式でのみ実受信した。fakeによるinvalid subscription、unsubscribe、bounded retry、fallback opt-in、accept後crash / unknown、receipt Evidenceは維持する。
- 隔離SMTPはexternal mailer / host / username / password / secretが未設定だった。通常local / Production Credentialは流用せず、本人Test Emailへの実送信はfail-closedで未実施。この1点によりConditionalを維持する。
- Corrective Delta: Provider rejection状態記録（`55b295b`）。Product / Privacy / Channel Contract変更なし。

### S10-DC18｜CONDITIONAL

- iPhone SafariからHome Screenへ追加し、standalone表示、Login、Push permission、実Push受信、tap、Login復帰、Deep Linkを本人確認した。
- Desktop EdgeでPWAをInstallして独立app windowで起動。Push permission拒否時は安全な拒否表示となった。
- iPhone / EdgeはPASS。利用可能端末として提示されていないAndroid Chrome実機Evidenceは未取得のためConditionalを維持する。
- Corrective Delta: iOS 390px overflow（`9eafbc1`）と、既存PWA windowを対象URLへnavigateしてfocusするnotification click（`4167ad9`）。Contract変更なし。

### S10-DC19｜PASS

- iPhoneでsoft keyboard / focus / overflow、Push許可・OS拒否・解除・再判定、Logout / Loginを確認した。
- 別の合成Accountでは元Accountの通知を表示せず、元Accountへ戻してもTenant境界を維持した。
- 認証済みPush tapと、Push tap → Login → intended notification open → Notification Centerの未Login復帰を確認した。
- 匿名化traceで修正前のstart_url再利用と修正後のopaque open → 認可 → Notification Centerを実測した。Cookie、payload、Business Data、実IDは記録していない。generic Test NotificationにはBusiness sourceがないためNotification Centerが正しい遷移先である。

### S10-DC20｜PASS

- iPhoneのnetwork切断中はBusiness Dataを表示・更新・queueせず、復帰後は同じTest Accountで正常再開した。
- `company-os-shell-v2` への更新は編集中画面を強制reloadせず、更新後のPush tapも正常だった。
- EdgeのCache Storageはv2の `/favicon.png` と `/manifest.webmanifest` だけ。旧cache、Business Data、HTML、session、CSRF、offline queueはなく、IndexedDBも「なし」を本人確認した。

### Verification / cleanup / 再判定

- Focused regression: **27 tests / 175 assertions / 0 failures**。JavaScript syntax PASS。DB / Migration変更なし。
- iPhone Test CA、Windows CurrentUser Root CA、Firewall規則8085/8443、listener 8085/8443/8774、TEMP内CA秘密鍵・VAPID/password secret・隔離SQLite・traceを削除し、すべて残件0を確認した。
- Scope 1〜9 Closed Contract、IR-1、Master v144 / v040は変更なし。Master Update不要。
- S10-DC01〜16、DC19〜25: Done。DC17: Conditional（実Email）。DC18: Conditional（Android）。**Conditional 2 / Not Done 0**。
- Formal Close可否: **現時点では不可**。残Evidence取得後に人＋ChatGPT Final Reviewで再判定し、Codex単独ではFormal Closeしない。

## 10. Formal Close Decision（2026-09-26 JST）

人＋ChatGPT Final Reviewにより、前節で未取得として維持した実SMTP到達とAndroid Chrome実機Compatibilityを、Scope Developmentの完成条件ではなくScope 10 Release Ready前のRelease Verification責任として正式に線引きした。この判断は未確認EvidenceをPASSへ変更するものでも、fake Evidenceを実機Evidenceへ置き換えるものでもない。

### DC17｜Scope Development Done / Release Verification Pending

- Email Channel、本人Opt-in、verified Email再確認、外部payload最小化、Delivery / Attempt / retry、明示fallback、Channel境界、およびfakeによるfailure / retry / unknownはScope Development Evidenceを充足する。
- Web PushはApple Push Provider受付からiPhone実受信、tap、Login復帰、再認可まで実機確認済みである。
- 実SMTP ProviderによるEmail実受信sampleは未確認のまま保持し、**Release Verificationへ正式移管**する。Scope 10専用Staging / Release Verification環境でRelease Ready前に必ず確認し、Productionを初回確認環境にしない。

### DC18｜Scope Development Done / Release Verification Pending

- iPhoneはHome Screen追加、standalone起動、Login、Push permission、実Push受信、tap、Login復帰、Deep Linkを本人実機で確認した。
- Desktop EdgeはPWA Install、standalone app window、Push permission拒否時の安全動作を確認した。
- Android固有のProduct Contractは定義していない。Android Chrome実機Install / Push / Deep Linkは未確認のまま、**Cross-platform CompatibilityのRelease Verificationへ正式移管**する。Scope 10専用Staging / Release Verification環境でRelease Ready前に必ず確認する。

### Scope Development最終判定

- S10-DC01〜25: **Scope Development Done**。
- Scope Development Conditional: **0件**。Not Done: **0件**。
- Release Verification Open Evidence:
  1. 実SMTP Providerを使用したEmail実受信
  2. Android Chrome実機でのPWA Install / Push / Deep Link
- Formal Close可否: **Scope 10 Formal Close可能**。ただし本Report更新ではFormal Close自体を実施せず、人＋ChatGPTの明示的なFormal Close承認を待つ。

### Close根拠・引継ぎ

- SQLite full suiteは519 PASS / 0 FAIL / 16 SKIP / 4,189 assertions。MariaDB 10.11 Migration / Concurrency、iPhone PWA / Push、Desktop Edge PWA、DC19 / DC20、offline非cache・非queue、限定Cache Storage、IndexedDB 0、Tenant / Account境界、cleanup完了のEvidenceを維持する。
- Corrective Delta履歴は、2 worker同時claim、期限切れLogin後Deep Link、390px overflow、iOS PWA notification clickであり、いずれも確定Contract内の修正である。
- Product Contract変更はなく、Master v144 / v040のUpdateは不要。Production、通常local DB、IR-1、Migration、Deployは変更していない。
- `S10-CD-TQC` / `S10-CD-G01`を維持する。Formal Close後の次工程はScope 10 Companion Delta｜Text Quick Captureであり、Scope 11へ直接進まない。

## 11. Scope 10 Formal Close確定（2026-09-26 JST）

最終Evidenceの人＋ChatGPT Final Reviewおよび明示承認に基づき、Scope 10「通知・PWA」を **Formal Closed** とする。

- Scope Development S10-DC01〜25: **Done**
- Scope Development Conditional: **0件**
- Product判断Blocker: **0件**
- S10-C01〜03: **発生なし**
- Master Update: **不要**。Master v144 / v040を継続正本とする。
- Product / Architecture / Tenant / Permission / Security / Migration Contract変更: なし。
- Production、通常local DB、IR-1、Migration、Deploy: 変更・実施なし。

Release Verification Open Evidenceは次の2件を未確認のまま明示維持し、Scope Developmentを再Openせず、Scope 10 Release Ready前に専用Staging / Release Verification環境で必ず取得する。

1. 実SMTP ProviderによるEmail実受信
2. Android Chrome実機でのPWA Install / Push / Deep Link

`S10-CD-TQC` / `S10-CD-G01`を維持する。次工程は **S10 Companion Delta｜Text Quick Capture** であり、本Closeでは実装せず、Scope 11へも進まない。
