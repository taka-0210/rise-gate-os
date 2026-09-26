# Company OS｜S10 Companion Delta Capture Unit / Text Quick Capture｜P0 Audit

実施日：2026-09-26 JST
対象：CD-TQC-P0、およびP0で再現したCD-TQC-C01の承認済み限定Corrective Delta
状態：P0完了／C01解消候補／CD-TQC-G01 CLOSED

## 1. 正本と実施境界

次の5点を正本として照合した。

1. `CompanyOS_v145_Capture_Unit_Text_Quick_Capture.pptx`（SHA-256 `d90e76aa6aa94c076eacd6e38dae8a893300ba27809bc443fe5542bdd81fec41`）
2. `CompanyOS_Ver1_要件仕様書_v041_Capture_Unit_Text_Quick_Capture.xlsx`（SHA-256 `57ebcf40c61a447198d0842e076b42dbe0458f866734d884da617c61ea713c81`）
3. `CompanyOS_S10CD_TQC_Implementation_Preparation_v002.md`（SHA-256 `09d3a26a3f94bdd0072af7ee98ee01d8a04a68af4fca7e51cfee803f5a3ba2ef`）
4. `CompanyOS_S10CD_TQC_Decisions_Pending_v002.md`（SHA-256 `28792a487b5f861fca3008a82a67a44d4302835b3a813b0a8fd183c1e83bfeab`）
5. `CompanyOS_S10CD_TQC_Codex_Instructions_v002.md`（SHA-256 `762025f4b4059810f893012ec4368801631ad0c55b6693b8153dd8e446c00999`）

DE01〜11確定、PD06〜11解決済み、B06〜11解消済み、Product判断Blocker 0件を維持した。今回の変更は既存S10 Closed Contractへ合わせるC01限定是正だけであり、Capture Unit、Quick Capture、Inbox、Capture source、Action昇格、Capture Migration、P1〜P5、Scope 11は開始していない。

検証はPHPUnitのSQLite `:memory:`、synthetic fixture、fake/in-app Channel、固定時計を使用した。通常local DB、Production、生Business Data、実Email、実Push、IR-1は使用していない。

## 2. P0 Current State

| 項目 | Evidence / 判定 |
|---|---|
| 開始Development Line | `scope10-notification-pwa@de70a4f9d72dd4a619294890a91fe2b240c24641`から独立worktree／branch `s10cd-tqc`を作成。開始時clean、同名origin branchなし |
| S10 Formal Close | `docs/company-os-scope-10-implementation-report.md` §11のFormal Closed、DC01〜25 Done、Conditional 0を維持 |
| IR-1分離 | IR-1/master worktreeは`924af91188cc60d33ff87c91b94ecc1d539566e6`でclean。IR-1 RC / Artifact / Track A / Track B / Gateに変更なし |
| S8 Writer / Permission / History | `ProjectExecutionWriter::createAction`、`ProjectExecutionAccess`、transaction、Project historyを接点確認。既存Writerを権限緩和なしで再利用可能。C02非該当 |
| S9 Today / Execution | CaptureをToday、Execution、実施率、Today Digestへ加える差分なし。Action / ActionExecution境界を維持 |
| S10 Notification | SourceWriter / Timing / Authorization / Center / Processor / Headerを再照合。A01〜A03を実動再現し限定是正 |
| Capture / Inbox差分 | `app`、`database`、`routes`、`resources`にCapture Unit / Quick Capture / Inbox相当の実装差分なし |
| Migration | Repositoryは95件。Capture Migration追加なし、通常local Migration未実施 |
| C03 | 独立line、IR-1分離、Tenant保全、既存schema不変更を維持できるため非該当 |

## 3. A01〜A03 実動再現

初回focused実行では4 tests中4 testsがFAILし、静的所見を現在HEADで実動再現した。fixture helper名とLaravel TestCase APIの衝突は検証fixture defectとして先に除去し、Application判定には含めていない。

| ID | 再現結果 | 再現した事実 |
|---|---|---|
| A01｜公開境界 | 再現 | `eligible_at_utc`より前に`content_visible_at_utc`がnowとなり、Center本文とunread badgeが公開された |
| A02｜Timing / Policy | 再現 | `specified`がHoliday / Quietを迂回、窓外`now`、現在窓内`next_window`、空窓fallback、Policy変更後retryの再評価不足を確認 |
| A03｜source現在権限 | 再現 | Task再割当後も旧recipientのCenter本文とbadgeが残り、readがsource現在権限を確認しなかった |

## 4. C01限定Corrective Delta

Capture専用基盤や別計算器は作らず、既存S10共通経路だけを修正した。

- `NotificationTiming`を会社×本人の共通計算器として整理し、`now` / `next_window` / `specified`を同じPolicy、Holiday、Working Exception、Organization Quiet、個人Quietへ通した。`specified`は希望最早時刻とし、空窓・Policy未確認は任意時刻へfallbackせず保留する。
- `NotificationSourceWriter`はCenter公開時刻とDelivery開始時刻を同じ計算結果へ揃えた。既存non-null schemaは変更せず、計算不能時の5分後時刻は再評価点としてのみ使い、公開・配信は現在Policyでfail-closedする。
- `NotificationDeliveryProcessor`はattempt claim前に現在Policyを再評価する。窓外はattemptを消費せず次の可能時刻へ保留し、retryも同じ経路を通す。現在権限喪失はDeliveryとCompanyNotificationを取消する。
- `NotificationVisibility`を追加し、Center一覧、本文、read、open、header unread badgeへ同じ公開時刻・現在Policy・source authorizationを接続した。失権時は404で情報非開示とした。
- S10既存epoch再認可testへconfirmed Policy fixtureを補い、Policy未確認保留というClosed Contractをtest前提へ反映した。Permission、Tenant、Admissionを弱めていない。
- DB schema、Migration、外部Channel、Product Contractは変更していない。

## 5. C01-E01〜07

| Evidence | 判定 | 実測内容 |
|---|---|---|
| E01｜公開境界 | PASS | 通知可能時刻前はCenter本文・一覧・badge非公開、境界到達後は公開 |
| E02｜指定日時・通知窓 | PASS | Holiday上のspecifiedを次の許可窓へ移動。specifiedを希望最早時刻として扱い、現在許可窓内のnext_windowはnow、窓外nowは次窓 |
| E03｜Policy変更・retry | PASS | claim前に現在Policyを再評価。変更後の窓外ではprocessed 0、attempt 0、次窓でdelivery成功 |
| E04｜空窓・例外日 | PASS | 空窓とPolicy未確認は`null`で保留。Holidayをskipし、Working Exceptionも個人Quietで狭めた10:00 JSTから許可 |
| E05｜現在権限 | PASS | Task再割当後、旧recipientの一覧・本文・badgeを非公開、read/openは404、DeliveryとNotificationを取消 |
| E06｜既存正常経路 | PASS | Task 3通知、自己割当抑止、Today Digestのzero suppression / dedupe、通常delivery、read / seen / action_done分離を既存S10 testで維持 |
| E07｜陰性境界 | PASS | non-recipientのlist/read/open、suspended、left、global inactive、stale membership epoch、stale credential generationを拒否し取消 |

## 6. Verification結果

| 対象 | 結果 |
|---|---|
| C01 focused (`S10CdTqcC01CompatibilityTest`) | 10 PASS / 0 FAIL / 0 SKIP / 49 assertions |
| C01 + S10 + S8 + Product Organization impact regression | 51 PASS / 0 FAIL / 0 SKIP / 311 assertions |
| PHP syntax | 変更PHP 7 files、syntax error 0 |
| Diff hygiene | `git diff --check` error 0 |

S9 `ScopeNineActionExecutionTest`は10 PASS / 3 FAIL / 47 assertionsだった。3 FAILは同一test・同一結果を未変更の`scope10-notification-pwa@de70a4f9...`でも確認しており、今回のC01差分によるregressionではない。日付依存の既存fixtureが現在時計で予定回を作らないbaselineであり、PASSへ数えず、本P0でS9 Product / Applicationを変更していない。

## 7. CD-TQC-G01 6条件

| 条件 | P0終了時判定 |
|---|---|
| 1. DE06〜11正式確定 | 充足維持 |
| 2. Product判断Blocker 0 | 充足維持 |
| 3. C01解消Evidence | **充足候補**。E01〜07はPASS。ただしC01の正式解消は人＋ChatGPT Review待ち |
| 4. 人＋ChatGPTによる接続再開確認 | **未充足** |
| 5. C02 / C03非該当 | 充足候補。P0で非該当を再確認 |
| 6. 独立Development Line確認 | 充足。`s10cd-tqc`、IR-1非混入を確認 |

従って、**CD-TQC-G01はCLOSEDを維持**する。C01は解消候補だが自動で正式解消せず、P1へ進まない。S10-CD-G01もCLOSEDを維持し、Scope 11へ進まない。

## 8. 終了判定

- CD-TQC-P0：完了
- CD-TQC-C01：実動再現、限定是正、E01〜07 PASS。**解消候補**
- CD-TQC-C02 / C03：発生なし
- S10 Formal Close：維持
- IR-1 / Production / 通常local DB：非変更
- Capture実装 / Migration / P1〜P5：未着手
- 次の操作：人＋ChatGPTによるC01 Evidence Reviewと接続再開判断待ち
