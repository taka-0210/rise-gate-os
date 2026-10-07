# Company OS｜G5 Postmark Production Mail Design v001

## 判定

Human + ChatGPT Decisionを次のとおりBindingする。

- Provider：Postmark
- Transport：Postmark API
- Primary Production Mailer：Postmark
- `ACCOUNT_MAIL_MAILER`：Primaryと同一のPostmark Mailer
- Second Choice：Amazon SES
- Postmarkの米国データ処理：ACCEPT
- Webhook Basic Auth + IP allowlist：ACCEPT
- Company OS側の送信／Webhook重複防止：IN

Amazon SESは、国内Region要求、大規模multi-tenantでのreputation分離、または送信量の大幅増加が現実になった時点で再評価する。

本DesignはProduction-freeである。Postmark Account、credential、DNS、NewTarget、Shared State、Application配置、Migration、public entry、Deployは変更しない。

## 最小Scope

対象はAccount lifecycle mailだけとする。

- Password reset
- Email verification / address change
- Organization invitation
- Owner onboarding
- Account lifecycle notification
- 上記のdelivery / bounce / complaint把握

Broadcast Mail、Marketing Mail、通知センター、Campaign分析、tenant別Provider自動構築は対象外である。

## Provider構成

推奨構成は次のとおり。

| 要素 | 正式構成 |
|---|---|
| Postmark Account | RISE GATE法人所有。MFA必須。運用管理者を複数名にする |
| Server | `Company OS Production`専用Server |
| Message Stream | Transactional専用 `account-lifecycle` |
| Application credential | Server API Tokenだけ。Account API Tokenは使用しない |
| Staging | Production Serverを共用せず、必要時に別Gateで別Serverを作る |

Postmark Server API TokenはServer単位の境界であり、Message Streamだけへの細粒度な権限ではない。そのため、Applicationごと・環境ごとにServerを分けることをCredential権限境界とする。

## Sending Domain / From

第一候補を以下とする。これはApplication Correctiveとは分離したHuman Identity Gateで確定する。

- Sending Domain：`mail.company-os.jp`
- From：`no-reply@mail.company-os.jp`
- From Name：`Company OS`
- Custom Return-Path：`pm-bounces.mail.company-os.jp`

専用subdomainにすることで、Company OSのtransactional reputationと、将来のCorporate／Marketing送信を分離しやすくする。

DNSはPostmarkが発行するexact値を正本とする。

- DKIM：Postmark発行recordを追加
- Return-Path：Postmark発行CNAMEを追加
- SPF：同一domainへ重複TXTを作らない。Custom Return-Pathによるalignmentを使う
- DMARC：まずreporting可能なpolicyから開始し、delivery Evidenceを得てから別Human Gateで強化

Repository、Evidence、terminalへDNS tokenやcredential値を保存しない。

## Laravel / Provider portability

Application CoreはPostmark SDKを直接呼ばない。

```text
Account lifecycle event
  -> provider-neutral Account Mail delivery service
  -> Laravel named mailer
  -> Symfony Postmark API transport

Postmark webhook
  -> Postmark adapter
  -> generic delivery state transition
```

Provider固有要素はTransportとWebhook Adapterへ閉じ込める。Coreが扱うstateは、`accepted`、`delivered`、`bounced`、`complained`、`suppressed`、`failed`、`delivery_unknown`等のgeneric stateとする。

将来Providerを変更するときは、Account domainを書き直さず、Transport、credential mapping、Webhook Adapterを交換できる境界を維持する。

## Frozen RC監査

Frozen RCは`924af91188cc60d33ff87c91b94ecc1d539566e6`のまま変更・再freezeしない。

監査結果：

- `config/mail.php`のPostmark mailer枠：存在
- `config/services.php`の`POSTMARK_API_KEY`枠：存在
- `config/account.php`のnamed mailer境界：存在
- `symfony/postmark-mailer`：未導入
- `symfony/http-client`：未導入
- `POSTMARK_MESSAGE_STREAM_ID` binding：コメント状態
- Account Mail専用のprovider-neutral delivery ledger：未実装
- Postmark Webhook endpoint / dedupe：未実装

LaravelのPostmark API transportには`postmark-mailer`とHTTP clientが必要である。したがって、Shared StateへPostmark値だけを追加してもProduction Mailは成立しない。

結論：Shared State Correctiveより先に、Production-free Application Correctiveが必要である。

## Minimal delivery ledger / deduplication

Delivery ledgerはAccount Mail専用とし、通知センターへScopeを広げない。

最小Contract：

- event、source identity、generation、recipient identityからcontext分離したHMAC dedupe keyを作る
- dedupe keyにはunique constraintを付ける
- 同じdedupe keyでpayload hashが異なる場合はfail closed
- Recipientは既存foreign keyまたはopaque HMACとし、新しいplaintext複製を作らない
- Mail body、Token、raw webhook bodyは保存しない
- Provider Message IDは受理後に保存する
- Webhook receiptは`provider + stable event identity`でuniqueにする
- 同一Webhookはstateを二重更新せず2xxを返す

Postmark APIにはCompany OSの業務dedupeを保証するIdempotency Keyを期待しない。Provider受付後に応答だけ失われた可能性がある場合は`delivery_unknown`とし、blind retryで重複送信しない。Provider Message IDまたはCompany OS delivery public IDで照合してからHuman判断またはreconciliationへ戻す。

このledgerにはadditive migrationが必要である。Application Correctiveにschemaを含めても、Production Migration実行は別Gateのまま維持する。

## Webhook security

Postmark Webhookは署名検証ではなく、次の多層境界で保護する。

1. HTTPS only
2. Basic Auth
3. Postmark公式送信元IP allowlist
4. exact Server / exact Message Stream確認
5. schemaとevent typeのfail-closed validation
6. stable event identityによるdedupe

Xserverのtrusted proxy chainがEvidence化されるまでは、任意の`X-Forwarded-For`を信頼しない。IP判定は実接続元または正式に設定したtrusted proxy後のclient IPだけを使う。

対象eventはDelivery、Bounce、Spam Complaintに限定する。Transactional Account MailではSubscription Changeを扱わず、Open / Click trackingもScope外とする。

- 認証／IP失敗：401 / 403
- malformed payload：4xx
- duplicate valid event：idempotent 2xx
- DB等の一時失敗：5xxとしてProvider retryを許可

Raw payload、Message body、Basic Auth値はEvidenceへ保存しない。

## Queue / retry

既存Account Mail Jobの以下は維持する。

- encrypted job
- after-commit dispatch
- `tries = 3`

Correctiveで追加するもの：

- bounded exponential backoff
- retryable / permanent / ambiguousの分類
- Provider受理はdelivery完了ではないことをledgerへ反映
- ambiguous handoff後のblind retry禁止
- failed job監視

第一候補は既存`database` Queueである。ただしjobs table、worker常駐方式、failed jobs、scheduler/cronはApplication Release Gateで実環境確認する。Queue worker readinessはこのDesignでPASSにしない。

## Secret / Shared State

NewTargetの正式値は次の責務に分ける。

- `MAIL_MAILER=postmark`
- `ACCOUNT_MAIL_MAILER=postmark`
- `POSTMARK_API_KEY`：NewTarget用Human-supplied Secret。Legacyから継承しない
- `POSTMARK_MESSAGE_STREAM_ID`：Human承認したexact non-secret value
- `MAIL_FROM_ADDRESS`：Postmarkで検証済みのexact sender
- `MAIL_FROM_NAME=Company OS`
- Webhook Basic user/password：NewTarget用Secret
- Webhook allowlist：Human Gate時点のPostmark公式値をdate/hash付きでbinding
- `QUEUE_CONNECTION=database`：runtime readinessが成立してから有効化

TokenはServer API Tokenだけを使用し、Account API TokenをApplicationへ渡さない。Shared `.env`は`0600`、runtime owner readableを必須とする。Secret値はRepository、Evidence、terminalへ出さない。

既存Shared State initial / Corrective-1はSTOP済みで再実行しない。新しいCorrective generationで以下を行う。

- `rk11`のNewTarget requirednessを維持
- `rk11`のLegacy Source presence requiredを解除
- `rk11`をHuman Decisionで固定したtarget value `postmark`として扱う
- Postmark Token、From、Webhook Secretをtarget-suppliedとして検証する

新generationのShared State作成は、Application Corrective、Provider、Sending Domain、DNS、credentialの各GateがPASSするまで開始しない。

## Human operations sequence

HumanがPostmark側で行う作業は、一括ではなく次の別Gateに分ける。

1. Application Corrective Review / Candidate再binding
2. Sender Identity Decision
3. Postmark法人Account作成、MFA、管理者設定
4. Production Server / Transactional Stream作成
5. Sending Domain登録
6. DNS record適用・検証
7. Server API Token発行とSecret-safe intake
8. Webhook Basic Auth / IP allowlist設定
9. New Shared State Corrective
10. Queue runtime / DB readiness
11. non-user delivery smoke test

本時点では上記操作を1つも承認しない。

## 次Human Gate

次に必要なのは、Provider操作ではなくRepository内のProduction-free Correctiveである。

Approval phrase：

> **ONE G5 POSTMARK APPLICATION CORRECTIVE / PRODUCTION-FREEをAPPROVEします**

承認範囲：

- Composer package / lock
- Message Stream config
- provider-neutral Account Mail delivery ledger
- Postmark webhook adapter
- outbound / webhook dedupe
- bounded retry
- Secret-safe config contract
- focused test / Evidence

承認外：

- Production / Provider接続
- Postmark Account / Server / Stream作成
- credential発行
- DNS / SSL変更
- Shared State作成
- Application配置
- Migration実行
- public entry変更
- Deploy

Corrective後はHuman Product / Security Reviewへ戻し、Frozen RCの更新や再freezeを自動実行しない。

## Status

**POSTMARK DECISION BOUND / APPLICATION CORRECTIVE HUMAN GATE READY**

**G5 OPEN / DEPLOY NO-GO**

`PUBLIC_ENTRY_PREEXISTING_CONTENT_DISPOSITION=PENDING_G5_PUBLIC_ENTRY_GATE`を維持する。

## Primary references

- Laravel 12 Mail / Postmark transport: <https://laravel.com/docs/12.x/mail>
- Postmark Servers: <https://postmarkapp.com/developer/user-guide/managing-your-account/managing-servers>
- Postmark Message Streams: <https://postmarkapp.com/developer/api/message-streams-api>
- Postmark Webhooks / retry / security: <https://postmarkapp.com/developer/webhooks/webhooks-overview>
- Postmark DKIM: <https://postmarkapp.com/support/article/setting-up-dkim-for-your-domain>
- Postmark Custom Return-Path: <https://postmarkapp.com/support/article/910-how-do-i-add-a-custom-return-path>
- Symfony Mailer / Postmark headers and transport: <https://symfony.com/doc/current/mailer.html>
