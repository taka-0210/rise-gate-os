# Company OS｜Scope 10 通知・PWA Implementation Report

- 判定日: 2026-09-26 JST
- 正本: Master v144 / v040、Scope 10 v002成果物3点
- 実装base: 924af91188cc60d33ff87c91b94ecc1d539566e6
- 開発線: scope10-notification-pwa
- 状態: **Code Complete候補 / Formal Close Review待ち**

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

## 7. Master / Environment

現時点でProduct / Architecture Contractの変更はなく、Master v144 / v040更新は不要候補。通常local Migration、Production接続・Migration、Deploy、IR-1変更、HOW、Scope 11は未実施。
