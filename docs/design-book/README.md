# Company OS Design Book

仮称: Company OS Design Book

## この資料の目的

このDesign Bookは、営業資料でも、代理店向け資料でも、Blueprintの短縮版でもありません。

> 高見さん自身がCompany OSを100%理解し、図を描きながら自分の言葉で説明できるようになるための学習資料です。

BlueprintはCompany OSの思想と構造を体系的に残すマスター資料です。

Design Bookは、その内容を一つずつ理解するための教材です。

```text
Blueprint
  Company OSの知識を体系的に残す
        ↓ 理解できる順番と図へ変換
Design Book
  高見さん自身の理解へ変える
        ↓ 自分の言葉で話せる
判断・設計・営業・代理店展開
```

## 対象範囲

初版では、Blueprint Chapter 1〜5を対象にします。

- Company OSはなぜ必要か
- 会社はどのように進化するか
- Company OSのConceptは何を意味するか
- なぜ出来事同士のつながりが必要か
- Company OS全体はどのような責任構造か
- なぜProjectは業務領域ではなく実行方式なのか
- 判断と現実をつなぐ責任とは何か

Chapter 6以降は、Blueprint側の整理が完了してから追加します。

---

## Design Bookの編集原則

### 1. 一枚につき、一つの疑問へ答える

各スライドは、高見さんの疑問をタイトルにします。

```text
Foundationとは何？
なぜProjectはBusiness Moduleではないの？
Executionは何をしているの？
```

タイトルを読んだだけで、そのページで理解する内容が分かるようにします。

### 2. 答えは一文にする

各スライドには、中心となる答えを一つだけ置きます。

本文を読まなければ理解できないスライドにはしません。

### 3. 文章より図を優先する

基本構成は次のとおりです。

```text
上部
  高見さんの疑問

中央
  矢印、円、箱、具体例による図

下部
  一文の答え
```

### 4. 同じ具体例を最後まで使う

Conceptごとに別の例を使わず、Blueprint Chapter 3と同じ改善ストーリーを通して使います。

```text
地域の設備会社

問い合わせフォームへのアクセス数は変わらない
しかし送信完了件数が30%減った
```

この一つの出来事が、Observation、Sense、Improvement、Decision、Execution、Result、Knowledgeへ育つ流れを繰り返し見せます。

### 5. 英語より先に、日本語で理解する

```text
会社で起きた事実や変化
        ↓
Observation
```

正式名称を先に覚えさせません。意味を理解した後で名前を添えます。

### 6. 分からないことを隠さない

正式名称が未確定の場合は、未確定と書きます。

`Execution Boundary`や`Operational Capabilities`を、決定済みの利用者向け名称として見せません。

### 7. PowerPointだけで完結させない

各スライドのノート欄へ、次の二つを入れます。

- Blueprintの参照ページ
- 高見さんが自分の言葉で答える確認質問

---

## 全体構成

初版は全32スライドです。

| Part | テーマ | スライド | 学習ゴール |
|---|---|---:|---|
| 0 | 読み方 | 1〜2 | この資料の目的と使い方を理解する |
| 1 | なぜCompany OSなのか | 3〜6 | Company OSが必要な理由を自分の言葉で話せる |
| 2 | 会社はどう進化するのか | 7〜9 | Company OSに完了がない理由を説明できる |
| 3 | 会社の進化を構成する言葉 | 10〜19 | 一つの改善ストーリーをConceptで説明できる |
| 4 | なぜ「つながり」が必要なのか | 20〜23 | Relationshipと説明責任の価値を説明できる |
| 5 | Company OSはどういう構造か | 24〜30 | Foundation、Core、実行、実務、Projectの責任を区別できる |
| 6 | 自分の言葉へ変える | 31〜32 | 図を見ずに3分でCompany OSを説明できる |

---

# Slide Outline

## Part 0: この資料の読み方

### Slide 1: Company OS Design Book

疑問:

> この資料は何のためにある？

一文の答え:

> Company OSを、高見さん自身の言葉で説明できるようになるための本です。

図解:

```text
知っている
    ↓
図で理解する
    ↓
自分の言葉で話せる
    ↓
自分で判断できる
```

参照:

- [`../blueprint/README.md`](../blueprint/README.md)

### Slide 2: どうやって使う？

疑問:

> 読むだけでいい？

一文の答え:

> 一枚見るたびに、図を隠して自分の言葉で説明します。

図解:

```text
見る → 閉じる → 話す → 分からない所へ印を付ける
```

確認質問:

> このスライドを見ずに、Design Bookの目的を説明できますか？

---

## Part 1: なぜCompany OSなのか

### Slide 3: Company OSとは何？

一文の答え:

> 会社に起きる変化を、改善・実行・学びへつなぎ続けるOperating Systemです。

図解:

```text
変化 → 気付く → 考える → 動く → 学ぶ → 次の変化
```

参照:

- [`../blueprint/chapter-01-why-company-os.md`](../blueprint/chapter-01-why-company-os.md): Page 1-3

### Slide 4: なぜ必要なの？

疑問:

> 既存の業務システムだけでは何が足りない？

一文の答え:

> 方針、仕事、判断、結果、知識が別々に残り、会社がなぜ変わったのか分からなくなるからです。

図解:

```text
理念      Excel      会議      Project      AI
 ●          ●          ●          ●          ●

             つながっていない
```

参照:

- [`../blueprint/chapter-01-why-company-os.md`](../blueprint/chapter-01-why-company-os.md): Page 1-1、1-4

### Slide 5: 誰のためのOS？

疑問:

> DXやAIのためのシステム？

一文の答え:

> 主役は中小企業と、そこで働く人です。DXとAIは進化を支える手段です。

図解:

```text
           中小企業と人
                ★
          ↙           ↘
        DX             AI
             手段
```

参照:

- [`../blueprint/chapter-01-why-company-os.md`](../blueprint/chapter-01-why-company-os.md): Page 1-2

### Slide 6: Company OSは何ではない？

一文の答え:

> Project管理、問題管理、AIチャットのどれか一つではなく、それらを会社の進化へつなぐ基盤です。

図解:

```text
問題管理   Project管理   AIチャット
    \          |          /
        会社の進化へつなぐ
             Company OS
```

参照:

- [`../blueprint/chapter-01-why-company-os.md`](../blueprint/chapter-01-why-company-os.md): Page 1-5

確認質問:

> Company OSとProject管理システムの違いを、一文で説明できますか？

---

## Part 2: 会社はどう進化するのか

### Slide 7: Company OSにはなぜ完了がない？

一文の答え:

> TaskやProjectは終わっても、会社はその結果からまた変化し続けるからです。

図解:

```text
Task       完了する
Project    終了する
Company    進化し続ける
```

参照:

- [`../blueprint/chapter-02-continuous-evolution.md`](../blueprint/chapter-02-continuous-evolution.md): Page 2-2

### Slide 8: 会社の進化はどんな流れ？

一文の答え:

> 観察し、意味を考え、改善し、判断し、動き、学び、また観察します。

図解:

```text
観察 → 意味 → 改善 → 判断 → 実行 → 結果 → 学び
 ↑                                              ↓
 └──────────────────────────────────────────────┘
```

参照:

- [`../blueprint/chapter-02-continuous-evolution.md`](../blueprint/chapter-02-continuous-evolution.md): Page 2-3

### Slide 9: Resultはなぜゴールではない？

一文の答え:

> Resultによって初めて現実の変化が分かり、それが次のObservationになるからです。

図解:

```text
実行した
   ↓
実際に何が起きた？  = Result
   ↓
新しく気付いたこと  = 次のObservation
```

参照:

- [`../blueprint/chapter-02-continuous-evolution.md`](../blueprint/chapter-02-continuous-evolution.md): Page 2-4

確認質問:

> Projectが終わった後、Company OSでは何を確認しますか？

---

## Part 3: 会社の進化を構成する言葉

### Slide 10: これから使う共通の物語

一文の答え:

> 一つの出来事が会社の学びへ育つまでを、同じ会社の例で追います。

図解:

```text
地域の設備会社

フォームへのアクセス数は同じ
送信完了件数は30%減った
```

このスライド以降、例を変更しません。

### Slide 11: Directionとは何？

一文の答え:

> 会社がどこへ向かい、何を大切にするかという判断の基準です。

図解:

```text
初めてのお客様も
迷わず安心して相談できる会社になる
                  ↓
     変化の重要性を照らす光
```

参照:

- [`../blueprint/chapter-03-company-os-dictionary.md`](../blueprint/chapter-03-company-os-dictionary.md): Direction

### Slide 12: Observationとは何？

疑問:

> なぜProblemではない？

一文の答え:

> まだ問題と決めず、会社の内外で起きた事実や違和感をそのまま受け取るからです。

図解:

```text
事実
アクセス数は同じ
送信完了件数は30%減った

問題？ 原因？ 改善案？
まだ決めない
```

参照:

- [`../blueprint/chapter-03-company-os-dictionary.md`](../blueprint/chapter-03-company-os-dictionary.md): Observation

### Slide 13: Senseとは何？

一文の答え:

> Observationが会社にとって何を意味するかという、現時点の解釈や仮説です。

図解:

```text
Observation
送信完了が30%減った
        ↓ どういう意味？
Sense A  入力項目が多すぎるかもしれない
Sense B  季節変動かもしれない
```

参照:

- [`../blueprint/chapter-03-company-os-dictionary.md`](../blueprint/chapter-03-company-os-dictionary.md): Sense

### Slide 14: Improvementとは何？

疑問:

> 改善案やTaskとは違う？

一文の答え:

> 手段ではなく、現在からどのような状態へ変わりたいかを表します。

図解:

```text
× フォームの項目を減らす
  手段

○ 初めてのお客様が
  迷わず問い合わせを完了できる状態にする
  実現したい変化
```

参照:

- [`../blueprint/chapter-03-company-os-dictionary.md`](../blueprint/chapter-03-company-os-dictionary.md): Improvement

### Slide 15: Decisionとは何？

一文の答え:

> 誰が、何を根拠に、会社として何をするか・しないかを決めた記録です。

図解:

```text
選択肢
  A 項目を減らす
  B 何もしない
  C 追加観察する
        ↓ 人が責任を持つ
Decision
  必須項目を10個から5個へ減らし、1か月確認する
```

参照:

- [`../blueprint/chapter-03-company-os-dictionary.md`](../blueprint/chapter-03-company-os-dictionary.md): Decision

### Slide 16: Executionとは何？

一文の答え:

> Decisionを現実の行動へ変えることです。

図解:

```text
Decision
  必須項目を減らす
        ↓
Execution
  担当を決める
  フォームを変更する
  1か月測定する
```

ここでは、まだProjectを前提にしません。

参照:

- [`../blueprint/chapter-05-company-os-product-architecture.md`](../blueprint/chapter-05-company-os-product-architecture.md): Page 5-4

### Slide 17: Resultとは何？

一文の答え:

> 実行によって、実際に何が起きたかという事実です。

図解:

```text
期待
  送信完了率が上がる

実際
  送信完了率は改善した
  送信後の確認時間は増えた
```

成功だけでなく、失敗、変化なし、想定外もResultです。

参照:

- [`../blueprint/chapter-03-company-os-dictionary.md`](../blueprint/chapter-03-company-os-dictionary.md): Result

### Slide 18: Knowledgeとは何？

疑問:

> Documentとの違いは？

一文の答え:

> 結果から得た、次の判断に再利用できる意味がKnowledgeです。

図解:

```text
Document
  フォーム改善報告書.pdf

Knowledge
  項目を減らしすぎると、
  送信後の確認時間が増える
```

参照:

- [`../blueprint/chapter-03-company-os-dictionary.md`](../blueprint/chapter-03-company-os-dictionary.md): Knowledge

### Slide 19: 全部つなぐと何が見える？

一文の答え:

> 一つの出来事が、会社の判断と学びへ育った物語が見えます。

図解:

```text
Direction
   ↓
Observation → Sense → Improvement → Decision
                                      ↓
                                  Execution
                                      ↓
Result → Knowledge → 次のObservation
```

確認質問:

> フォーム改善の物語を、ObservationからKnowledgeまで自分の言葉で説明できますか？

参照:

- [`../blueprint/chapter-03-company-os-dictionary.md`](../blueprint/chapter-03-company-os-dictionary.md): Chapter全体

---

## Part 4: なぜ「つながり」が必要なのか

### Slide 20: 一件のデータだけでは何が分からない？

一文の答え:

> 何が起きたかは分かっても、なぜ重要で、何を決め、どうなったかは分かりません。

図解:

```text
送信完了件数が30%減った

なぜ重要？
どう考えた？
何を決めた？
どうなった？
```

参照:

- [`../blueprint/chapter-04-meaningful-relationships.md`](../blueprint/chapter-04-meaningful-relationships.md): Page 4-1

### Slide 21: Relationshipとは何？

一文の答え:

> 二つの情報が近くにあることではなく、「なぜつながったか」という会社の意味です。

図解:

```text
Observation
送信完了が減った
        ↓ 入力項目が原因かもしれない
Sense
項目追加が離脱を増やした可能性
```

矢印の横にある理由がRelationshipです。

参照:

- [`../blueprint/chapter-04-meaningful-relationships.md`](../blueprint/chapter-04-meaningful-relationships.md): Page 4-2

### Slide 22: 星・星座・星雲とは何？

一文の答え:

> 一つの出来事が星、つながった改善物語が星座、会社が育てた記憶全体が星雲です。

図解:

```text
星       一つのConcept
  ↓ つながる
星座     一つの改善ストーリー
  ↓ 積み重なる
星雲     会社の記憶と進化
```

参照:

- [`../blueprint/chapter-04-meaningful-relationships.md`](../blueprint/chapter-04-meaningful-relationships.md): Page 4-3

### Slide 23: AIはなぜ答えを説明できる？

一文の答え:

> 結論だけを作らず、どの事実とつながりを根拠にしたかをたどるからです。

図解:

```text
質問
粗利率が下がった原因は？
        ↓
Observation → Sense → Decision → Result → Knowledge
        ↓
回答 + 根拠 + 反証 + 未確認
```

情報が足りなければ、「現時点では判断できない」と答えます。

参照:

- [`../blueprint/chapter-04-meaningful-relationships.md`](../blueprint/chapter-04-meaningful-relationships.md): Page 4-4、4-5

確認質問:

> 普通の検索と、Company OSがRelationshipをたどることの違いは何ですか？

---

## Part 5: Company OSはどういう構造か

### Slide 24: なぜ機能一覧から設計しない？

一文の答え:

> 機能は増減しても、会社の進化に必要な責任は変わらないからです。

図解:

```text
機能から考える
  機能が増えるたび構造が変わる

責任から考える
  機能が増えても構造は変わらない
```

参照:

- [`../blueprint/chapter-05-company-os-product-architecture.md`](../blueprint/chapter-05-company-os-product-architecture.md): Page 5-1

### Slide 25: Foundationとは何？

一文の答え:

> 会社が何者で、何を大切にし、いまどの状態にあるかを支える正式な土台です。

図解:

```text
経営理念  経営指針  経営数値  借入  組織・人
──────────────────────────────────────
          Company OS全体を支える大地
```

補足:

Foundationは変化の意味を決めません。判断の前提を支えます。

参照:

- [`../blueprint/chapter-05-company-os-product-architecture.md`](../blueprint/chapter-05-company-os-product-architecture.md): Page 5-2

### Slide 26: Company Coreとは何？

一文の答え:

> 会社が観察し、意味を考え、改善し、判断し、学ぶ中心です。

図解:

```text
        Company Core

Observation → Sense → Improvement
     ↑                    ↓
Knowledge ← Result ← Decision
```

補足:

顧客管理や会議運営などの業務固有機能は抱え込みません。

参照:

- [`../blueprint/chapter-05-company-os-product-architecture.md`](../blueprint/chapter-05-company-os-product-architecture.md): Page 5-3

### Slide 27: Executionは何をしている？

一文の答え:

> Decisionを現実へ渡し、現実で起きたResultをCompany Coreへ戻します。

図解:

```text
Company Core
  Decision
      ↓ 判断を現実へ
   Execution
      ↑ Resultを戻す
現実の仕事
```

補足:

`Execution Boundary`は責任設計上の仮称です。正式名称はまだ決めません。

参照:

- [`../blueprint/chapter-05-company-os-product-architecture.md`](../blueprint/chapter-05-company-os-product-architecture.md): Page 5-4

### Slide 28: なぜProjectはBusiness Moduleではない？

一文の答え:

> Projectは「何の仕事か」ではなく、複雑なImprovementを「どう実行するか」という方式だからです。

図解:

```text
業務領域 = 何について仕事をする？
  顧客 / 会議 / 環境 / 制作

実行方式 = どう動かす？
  Task / Project / Experiment / Routine
```

参照:

- [`../blueprint/chapter-05-company-os-product-architecture.md`](../blueprint/chapter-05-company-os-product-architecture.md): Page 5-5

### Slide 29: 会社ごとの実務はどこにある？

一文の答え:

> Company Coreの外側へ必要に応じて広がり、Signal・Action・ResultでCompany OSとつながります。

図解:

```text
顧客   会議   環境   業種固有   外部システム
  \      |      |       |        /
      Signal / Action / Result
                 ↕
            Company Core
```

補足:

`Operational Capabilities`や`Business Modules`は、まだ正式名称ではありません。

参照:

- [`../blueprint/chapter-05-company-os-product-architecture.md`](../blueprint/chapter-05-company-os-product-architecture.md): Page 5-6

### Slide 30: Company OS全体はどうつながる？

一文の答え:

> 会社の土台の上で、思考と現実が往復し、すべての実務のResultが次の進化へ戻ります。

図解:

```text
         会社ごとに広がる実務
                  ↕
         判断と現実をつなぐ
                  ↕
            Company Core
                  ↕
──────────────────────────
             会社の土台
```

参照:

- [`../blueprint/chapter-05-company-os-product-architecture.md`](../blueprint/chapter-05-company-os-product-architecture.md): Page 5-7

確認質問:

> Foundation、Company Core、Execution、会社ごとの実務の責任を、それぞれ一文で説明できますか？

---

## Part 6: 自分の言葉へ変える

### Slide 31: 3分でCompany OSを説明する

目的:

> これまでの内容を、図を一枚描きながら自分の言葉で説明します。

使用する図:

```text
変化
 ↓
Company Core ⇄ 現実の仕事
 ↑              ↓
会社の土台 ← Result
```

説明に必ず含めること:

1. Company OSは何のためにあるか。
2. Company OSにはなぜ完了がないか。
3. ObservationからKnowledgeまで、何が起きるか。
4. なぜRelationshipが必要か。
5. Projectはどこに位置するか。

### Slide 32: まだ分からないことは何？

一文の答え:

> 分からないことは設計の失敗ではなく、次に理解を深めるためのObservationです。

図解:

```text
分からない
    ↓
質問を書く
    ↓
Blueprintへ戻る
    ↓
自分の言葉で答える
    ↓
Design Bookを更新する
```

記入欄:

- Foundationについて、まだ曖昧なこと
- Company Coreについて、まだ曖昧なこと
- Executionについて、まだ曖昧なこと
- Projectについて、まだ曖昧なこと
- 全体構造について、まだ曖昧なこと

---

## PowerPoint制作ルール

チャッピーがPPTを作成するときは、次のルールを守ります。

### 必須

- 16:9で作成する。
- 1スライドにつき、疑問は一つにする。
- タイトルは原則として質問形にする。
- 一文の答えは40文字程度を目安にする。
- 箱、円、矢印、短いラベルを中心にする。
- 本文は最大5行を目安にする。
- 詳細説明はスライドへ詰め込まず、ノート欄へ入れる。
- 各スライドのノート欄へBlueprint参照元を記載する。
- 未確定名称には必ず「仮称」「名称未確定」と付ける。
- 同じConceptは全スライドで同じ色と形にする。

### 避ける

- 長い段落を貼り付ける。
- Blueprint本文をそのまま縮小して載せる。
- 1枚で複数のConceptを詳しく説明する。
- 英語用語だけで説明する。
- 装飾のためだけの写真やアイコンを増やす。
- 未来構想を実装済み機能のように見せる。
- 未確定の名称をロゴや正式レイヤー名として固定する。

### 最小限の視覚ルール

デザイン性より、同じ意味が同じ見た目で現れることを優先します。

```text
会社の土台       地面または横長の帯
Company Core     中央の円
Observation      小さな星または点
Relationship     理由が添えられた線
Execution        外向きと内向きの二本矢印
会社ごとの実務   Coreの周囲に広がる領域
未確定事項       点線
```

---

## チャッピーへ渡す依頼文

```text
Company OS Design BookのPowerPointを作成してください。

この資料は営業資料ではなく、高見本人がCompany OSを100%理解するための学習資料です。

添付するDesign Book構成に従い、まずPart 0とPart 1だけを作成してください。一気に全32スライドを作らないでください。

ルール:

- 16:9
- 1スライド = 1つの疑問、1つの答え
- 文章より図
- 難しい言葉より具体例
- 地域の設備会社のフォーム改善例を全体で統一
- 未確定名称は未確定と明記
- Blueprintの意味を変えない
- 各スライドのノート欄に参照元と理解確認の質問を入れる

Design Bookは見せる資料ではなく、理解する資料です。
きれいさより、高見が図を見て自分の言葉で説明できることを優先してください。
```

---

## 完成の判定

PowerPointが完成したかどうかは、見た目では判断しません。

高見さんが資料を閉じても、次の問いへ答えられることを完成条件とします。

1. Company OSは何のために存在するか。
2. Company OSにはなぜ完了がないか。
3. Observation、Sense、Improvementの違いは何か。
4. Resultはなぜ次のObservationへ戻るのか。
5. Relationshipは何を残すのか。
6. Foundationは何に責任を持つのか。
7. Company Coreは何に責任を持つのか。
8. Executionは何をつなぐのか。
9. なぜProjectはBusiness Moduleではないのか。
10. Company OS全体を一枚の図で説明できるか。

答えに詰まった箇所は、Design Book自身を改善するための新しいObservationとして扱います。
