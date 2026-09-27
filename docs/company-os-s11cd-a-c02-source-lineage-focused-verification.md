# Company OS｜S11 Companion Delta A
# CD-AB-C02 Source lineage｜Focused Compatibility Verification

**実施日：2026-09-27 JST**

## 1. 判定

Scope 11 Formal Closed HEADを変更せず、A-P0 Report §6.2のF01〜F04を合成fixture・fake Provider・SQLite `:memory:`で実動再現した。

| Scenario | 判定 | 要点 |
| --- | --- | --- |
| F01｜推移的Source依存 | **FAIL** | R2生成時にProvider historyへR1本文が投入されたが、R2へR1のSource依存が継承されなかった。Source A失権後もR2がvisibleで、次turn historyへ残った |
| F02｜Source revision / reselect | **FAIL** | 同じSourceをreselectすると新revisionを作らず既存rowを上書きした。旧R1のpivotが新handle/versionへ付け替わり、旧R1がv2根拠でvisibleになった |
| F03｜retry直前のPolicy変更 | **FAIL** | Organization Policy OFF、Resource Policy OFFの両caseで第2attempt前の再認可がなく、旧payloadを再送してsuccess ledgerを作った |
| F04｜Provider処理中のMembership / Source失権 | **PASS** | Membership失権時はConversation read自体を拒否。Source失権時は応答本文・Citation・provider historyをReaderが遮断し、epoch/version更新後の再加入・再有効化でも旧応答は再露出しなかった |

**CD-AB-C02は未解消。限定Corrective Deltaが必要。A-G01はCLOSEDを維持する。** Scope 11本体はFormal Closedを維持し、再Openしない。

## 2. 実施環境・固定点

- Repository：`taka-0210/rise-gate-os`
- Branch：`scope11-ai-common-entry`
- Formal Closed HEAD：`5d3ef34c15a92db55e4f8aef907b1f04ec136b2d`
- Tree：`6b2cc0865d5877f5a1207e13922c714db979ff18`
- Runtime：PHP `8.2.12`、PHPUnit `11.5.56`
- DB：`phpunit.xml`のisolated SQLite `:memory:`
- Provider：`AiCommonProvider`へTest doubleをDI。外部network・実Provider・Secretなし
- Data：合成Organization / User / Workspace / Project / Conversation / Sourceのみ
- Production、通常local DB、IR-1、Scope 11 application code、Migration：非変更
- 一時harness SHA-256：`0536A38191B1651F4ED72D167528017C28E890F586CE805F995F207D5C7F7E22`
- 一時harnessはEvidence取得後に削除し、FAILを恒久suiteの期待値へ固定しない

実行command：

```text
C:\xampp\php\php.exe artisan test tests\Feature\S11CdAC02SourceLineageFocusedVerificationTest.php --testdox
```

最終一括結果：**2 PASS / 4 FAIL / 10 assertions**。F03はOrganization PolicyとResource Policyの2 subcaseに分けたため、Test case数は6。Scenario判定はF01 FAIL、F02 FAIL、F03 FAIL、F04 PASSの4件。

## 3. Scenario Evidence

### F01｜推移的Source依存：FAIL

再現条件：

1. Source Aを選択してU1を送信し、R1を生成する。
2. Source未選択でU2を送信する。Providerへ渡るhistoryにR1が含まれることを確認する。
3. R2生成後、Project Membershipを`left`へ変更してSource Aの現在権限を失わせる。

Contract assertion：R1/R2の両方を非表示とし、次turn historyから両方を除外し、R2がAの推移的依存を保持すること。

実測：

- Provider call：2回
- 第2callにR1本文：あり
- 失権後assistant visibility：`[false, true]`（期待`[false, false]`）
- 失権後provider history内assistant：`1`（期待`0`）
- R1/R2のSource Relation件数：`[1, 0]`（期待`[1, 1]`）
- 現Schema/APIにはSource由来Proposalへ推移的lineageを固定するRelationがなく、派生Proposalの遮断を表現できない

結果：R2がAに依存するR1を再利用したにもかかわらず、R2自身はSourceなしとして扱われる。Source失権後もR2本文が公開・再投入され得るためFAIL。

### F02｜Source revision / reselect：FAIL

再現条件：

1. Project A v1をSource選択し、R1へpivotを付ける。
2. 正規`ProjectExecutionWriter::updateProject()`でAをv2へ更新する。
3. 同じAをreselectし、旧R1のSource Relationと表示判定を読む。

Contract assertion：v2は新しいimmutable Source revisionとなり、v1 row/handle/versionと旧R1の根拠は不変で、旧R1は現在性不一致により安全表示となること。

実測：

- Source row：`1`（期待`2`）
- 新revision row：なし
- v1 handle不変：false
- v1 version不変：false
- 旧R1がv1 handleを維持：false
- 旧R1 visibility：true（期待false）

結果：`updateOrCreate`が同じConversation × resource type × public IDのrowを上書きし、旧R1の根拠がv2へ付け替わるためFAIL。

### F03｜retry直前のPolicy変更：FAIL

再現条件：第1attemptのfake Provider内でPolicyをOFF＋version更新し、`provider_unavailable`を返してretryを発生させる。Organization Policy caseとResource Policy caseを独立実行する。

Contract assertion：第2attempt前に現在PolicyとSource revisionを再認可し、旧payloadを送信せず停止すること。ledgerは実際に行った第1attemptのfailedだけを残すこと。

実測（両case同一）：

- blocked：false（期待true）
- Provider call：`2`（期待`1`）
- ledger attempt 1：`failed / provider_unavailable`
- ledger attempt 2：`success`

結果：`AiCommonGateway`は最初に構築されたmessages/sourcesを各attemptで再利用し、retry直前の認可callbackやpayload再構築がないためFAIL。

### F04｜Provider処理中のMembership / Source失権：PASS

再現条件：Source付きrequestのfake Provider処理中に、(a) Organization Membershipを`left`＋access epoch更新、(b) Resource PolicyをOFF＋version更新する。応答返却後にReader、Citation、provider historyを確認し、その後Membership再加入／Policy再有効化を別versionで行って再確認する。

Contract assertion：失権後の応答本文・Citation・次turn historyを公開せず、再認可後にも旧応答を再露出しないこと。

実測：

- Membership loss：Provider 1回、Conversation readはAuthorization拒否。access epochを更新して再加入後もassistantは非表示、provider history内assistantは0
- Source loss：Provider 1回、assistant visible=false、公開Citation 0、provider history内assistant 0。Policy versionを更新して再有効化後もassistantは非表示、provider history内assistantは0
- 応答row自体はDBへ保存され、`visibility_status=visible`のまま。公開遮断は`AiCommonConversationReader`の現在Source認可で成立する

結果：指定された公開・Citation・次turn境界はPASS。なおcommit直前の明示再認可がない点は残るため、F01〜F03是正時にresponse publish境界を明文化・回帰固定する。

## 4. Corrective Delta要否

**必要。今回は修正していない。** 人＋ChatGPT Reviewへ渡す限定候補は以下。

1. mutable selectionとimmutable Source revisionを分離し、reselectは新revisionを作る。
2. Providerへ再投入した過去回答の直接・推移的Source依存をmessage sidecarへ保存する。依存を解決できないturnはfail closedとする。
3. Source由来Proposalにlineage Relationを追加し、表示・Approval・Applyで現在認可する。既存Proposal Contract、hash、Unit Writerは変更・迂回しない。
4. 各Provider attemptの直前にMembership / credential / Organization・Workspace・Resource Policy / Source revisionを再認可し、payloadを再構築する。
5. Provider responseの保存・公開直前にも同じcurrent fingerprintを再確認し、遅延結果を公開可能状態にしない。F04のReader fail-closedを維持する。
6. 旧Conversationへ推測lineageをbackfillせず、既存Dataの扱いは別途限定Compatibility方針を明示する。

現Evidenceから新しいProduct Decision、Closed Contract変更、Permission/Privacy緩和、第二Proposal Engine、Unit Writer迂回は不要と見込む。ただしCorrective実施は本指示の範囲外であり、別承認待ち。

## 5. 停止状態

- Scope 11：**Formal Closed維持／再Openなし**
- CD-AB-C02：**Open／Corrective Delta required**
- A-G01：**CLOSED維持**
- Delta A P1〜P5：**未開始**
- application code / Migration / Master / IR-1 / Production / Deploy：**非変更**
- 次工程：人＋ChatGPT Review待ち
