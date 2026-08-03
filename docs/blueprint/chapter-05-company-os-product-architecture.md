# Chapter 5: 会社の土台・思考・実行を、一つにつなぐ

内部整理名: Company OS Product Architecture

> [!IMPORTANT]
> このChapterは責任設計レビュー中です。Project ManagementをBusiness Modulesと同列に置く初版構造は確定していません。先に[`chapter-05-responsibility-review.md`](chapter-05-responsibility-review.md)で責任と実行方式の境界を整理し、その判断後に本文と全体鳥瞰図を改訂します。

## Chapterの目的

Chapter 4までで、会社は出来事の数ではなく、出来事同士の意味あるつながりによって理解できることを見てきました。

ここから初めて、その思想を支えるプロダクト全体の姿を扱います。

Company OSは、たくさんの機能を一か所へ集めたシステムではありません。

会社が持つ土台、会社が考えて学ぶ中核、会社が実際に動くための機能を、一つの進化へつなぐ基盤です。

このChapterでは、Company OSを次の3層として捉えます。

```text
Foundation
  会社の土台

Company Core
  会社の思考と進化

Business Modules
  会社の実務と実行
```

読み終えた人が、次のように感じられることを目標とします。

> Company OSは一つの業務システムではなく、会社の土台・思考・実行を一つにつなぐプラットフォームである。

このChapterは6ページで構成します。

---

## Page 5-1: Company OSは、一つのシステムではない

### 中心メッセージ

> Company OSは、決められた機能をすべての会社へ当てはめるシステムではなく、その会社に必要な進化を支える基盤です。

### 本文

会社によって、仕事も課題も成長の順番も異なります。

Projectを中心に動く会社もあれば、顧客対応、会議、店舗運営、環境整備が日々の実務の中心となる会社もあります。

必要な機能が違うからといって、Company OSそのものが別のシステムになるわけではありません。

どの会社にも、変わりにくい土台があります。

その土台をもとに、変化へ気付き、意味を考え、判断し、学ぶ営みがあります。

そして、判断したことを現実の仕事へ移すために、会社ごとの実務があります。

```text
会社の土台
    ↓ 支える
会社の思考
    ↓ 導く
会社の実行
    ↓ 結果と学びが戻る
会社の進化
```

Company OSは、この全体が途切れないための基盤です。

機能を増やすことが目的ではありません。必要な実務が増えても、会社の考え方と学びから切り離されないことが大切です。

> Company OSの広がりは、機能の数ではなく、会社の進化がどこまでつながっているかで決まります。

### 図解案

異なる業種の会社を三つ並べ、それぞれが異なる実務機能を利用しながら、同じCompany OSの土台と中核につながっている姿を示します。

### 参照元

- `company-os-core-model.md`: Core Statement、Product Design Test
- `company-os-value.md`: COMPANY OSの完成形と現在地

---

## Page 5-2: Foundationは、会社が立つ土台

### 中心メッセージ

> 会社が何を大切にし、どのような状態にあるかが分かるから、変化の意味を判断できます。

### 本文

会社の改善は、何もない場所から始まるわけではありません。

同じ売上の変化でも、会社の理念、目指す未来、財務状況、組織によって意味は変わります。

そこでCompany OSは、会社の判断を支える変わりにくい情報を`Foundation`として扱います。

```text
Foundation

経営理念       何のために会社が存在するか
経営指針       どのような会社を目指すか
経営数値       会社はいまどの状態にあるか
借入           未来の判断にどのような条件があるか
組織・メンバー 誰が会社をつくり、責任を担うか
```

Foundationは、会社ごとに利用するかどうかを選ぶ追加モジュールではありません。

すべてを最初から詳細に入力しなければ使えない、という意味でもありません。会社の成長に合わせて整えながら、Company Coreを支える共通の土台です。

経営理念や経営指針そのものはFoundationにあります。

それらを参照し、「いま会社はどこへ向かうのか」という判断基準として表したものが、Company Coreの`Direction`です。

```text
経営理念・経営指針・経営数値
              ↓ 照らす
          Direction
              ↓
何を重要な変化として扱うかを判断する
```

> Foundationは会社を固定するためではなく、変化の意味を見失わないための土台です。

### 図解案

建物の基礎としてFoundationを描き、その上のCompany Coreへ理念・指針・数値・組織が判断材料を渡す構成にします。上下関係ではなく、思考を安定して支える関係を表します。

### 参照元

- `company-os-core-model.md`: Direction
- `common-staff-platform-foundation.md`: 組織、スタッフ、所属、権限の基盤

---

## Page 5-3: Company Coreは、会社が考え、学ぶ場所

### 中心メッセージ

> Company Coreは、会社に起きた変化を、判断・実行・学びへつなぐCompany OS独自の中核です。

### 本文

Foundationがあるだけでは、会社は進化しません。

数字や方針を保存していても、変化へ気付き、意味を考え、判断し、学びへつながらなければ、会社の知識は育ちません。

この循環を担うのが`Company Core`です。

```text
Direction       会社が向かう方向を照らす
Observation     会社の内外の変化を受け取る
Sense           その変化の意味を考える
Improvement     実現したい変化へ育てる
Decision        会社として何をするか決める
Result          実行によって実際に起きたことを受け取る
Knowledge       経験を次に使える会社の記憶にする
Company Dialogue
                人とCompany OSが問いを交わす
Company AI      根拠をたどり、候補と問いを提示する
```

Company Coreは、特定の業務だけに属しません。

Projectで起きたResultも、顧客対応で生まれたObservationも、会議で得たKnowledgeも、同じ会社の進化としてつなぎます。

Company DialogueとCompany AIは、独立した便利機能ではありません。

会社の正式な情報とRelationshipをもとに問いを生み、人が意味と責任を与えるための支援です。AIは候補を示しますが、会社のDirectionとDecisionを決めるのは人です。

> Company Coreがあるから、異なる実務が一つの会社の記憶になります。

### 図解案

Company Coreを会社の思考が循環する輪として描きます。中央にはCompany Dialogueを置き、Company AIは輪の外から答える存在ではなく、各段階を横断して根拠と候補を届ける支援として表現します。

### 参照元

- `company-os-core-model.md`: Continuous Evolution、Core Concepts、Human and AI
- `company-os-query-model.md`: Company Intelligence and Accountability

---

## Page 5-4: Business Modulesは、会社が現実を動かす場所

### 中心メッセージ

> Business Modulesは、会社ごとに必要な実務を担い、その結果をCompany Coreへ戻す実行領域です。

### 本文

会社が考え、判断したことは、現実の仕事として実行されて初めてResultを生みます。

その実務を担うのが`Business Modules`です。

```text
Business Modules

Project Management   複数の人・期間・工程を伴う改善を実行する
CRM                  顧客との関係と対応を育てる
Meeting              対話、合意、判断を実務へつなぐ
Environment          働く環境の状態と改善を支える
HIT-HUB              将来統合を検討する業務領域
Other Modules        会社や業種に必要な実務を追加する
```

Business Modulesは、すべての会社がすべて使う前提ではありません。

会社、拠点、役割に応じて必要な領域を選び、後から追加できます。ただし、単独の便利機能として増やすのではなく、Company CoreのDecisionを実行へ移し、そこで生まれたObservationとResultをKnowledgeへつなげることを共通条件とします。

HIT-HUBも現時点では正式機能ではなく、将来Company OSへ統合を検討するBusiness Moduleです。

> モジュールは入れ替わっても、会社の実行が思考と学びへ戻る構造は変わりません。

### 参照元

- `company-os-core-model.md`: Project、Task、Result
- `architecture.md`: Project、Improvement、実行構造
- `roadmap.md`: 現在の実装と将来構想

---

## Page 5-5: 実行を支える役割に、どんな名前を与えるか

### 中心メッセージ

> 名称は機能の由来ではなく、Company OS全体の中で果たす役割を表す必要があります。

### 本文

現在の`RISE GATE OS`は、主にProject Managementを支える実装として育ってきました。

しかし将来、Projectだけでなく、さまざまなBusiness Modulesの実行を共通して支える可能性があります。

そのとき、現在の名前と役割が一致するかを見直す必要があります。

```text
Project Management Engine
  分かりやすいが、Project以外へ広がりにくい

Execution Engine
  実行領域を広く表せるが、単独では少し一般的

Evolution Engine
  Company OSの思想には近いが、
  考え、学び、進化するCompany Coreと役割が重なる

Company Execution Engine
  Company OSの中で、会社の実行を支える役割が明確
```

現時点の第一候補は`Company Execution Engine`です。

これは、利用者が新たに覚えるサービス名を増やすための名称ではありません。

```text
正式サービス名
  Company OS

利用者が選ぶ実務領域
  Project Management / CRM / Meeting / ...

実行領域を共通して支える役割名の第一候補
  Company Execution Engine

開発・運営会社
  RISE GATE
```

鳥瞰図ではRISE GATE OSを独立した上位ブランドとして強調せず、Company OSの中にある実行領域として整理します。

ただし、これは正式名称の決定ではありません。Business Modulesがどこまで共通の実行基盤を必要とするかを確かめながら、ブランド名、内部名称、利用者へ見せる名称を分けて判断します。

> 名前を残すことより、Company OSの構造を迷わず理解できることを優先します。

### 参照元

- `README.md`: Company OSとRISE GATE OSの現在のブランド構造
- `company-os-core-model.md`: Projectと実行の位置付け
- `company-os-value.md`: 現在のRISE GATE OSと将来像

---

## Page 5-6: Company OS全体鳥瞰図

### 中心メッセージ

> Company OSは、会社の土台・思考・実行を分断せず、一つのContinuous Evolutionとしてつなぎます。

### 本文

Company OSの3層は、別々の製品を積み上げたものではありません。

Foundationが判断の土台をつくり、Company Coreが変化へ意味を与え、Business Modulesが現実の仕事を動かします。

実行によって生まれたResultはCompany Coreへ戻り、Knowledgeと新しいObservationを生みます。その変化によって、DirectionやFoundationを見直すこともあります。

### Company OS全体鳥瞰図

```text
┌──────────────────────────────────────────────┐
│                  COMPANY OS                  │
│     会社の土台・思考・実行を一つにつなぐ基盤      │
│                                              │
│  ┌────────────────────────────────────────┐  │
│  │ BUSINESS MODULES                       │  │
│  │ 会社が現実を動かす                     │  │
│  │                                        │  │
│  │ Project Management  CRM  Meeting      │  │
│  │ Environment  HIT-HUB（将来構想） Other │  │
│  └───────────────────┬────────────────────┘  │
│                      │ Decision・実行         │
│                      ↕ Result・Observation    │
│  ┌────────────────────────────────────────┐  │
│  │ COMPANY CORE                           │  │
│  │ 会社が考え、判断し、学び、進化する       │  │
│  │                                        │  │
│  │ Direction  Observation  Sense         │  │
│  │ Improvement  Decision  Result         │  │
│  │ Knowledge  Company Dialogue  Company AI│  │
│  └───────────────────┬────────────────────┘  │
│                      │ 判断基準・現在地        │
│                      ↕                       │
│  ┌────────────────────────────────────────┐  │
│  │ FOUNDATION                             │  │
│  │ 会社が立つ土台                         │  │
│  │                                        │  │
│  │ 経営理念  経営指針  経営数値  借入       │  │
│  │ 組織・メンバー                         │  │
│  └────────────────────────────────────────┘  │
│                                              │
│  Result → Observation → 次の進化へ            │
└──────────────────────────────────────────────┘
```

この図の主役は、箱の数ではありません。

FoundationからBusiness Modulesまでを往復し続ける、会社の進化です。

新しいBusiness Moduleが加わっても、FoundationとCompany CoreはCompany OSの共通基盤として残ります。だから、会社ごとに必要な実務が違っても、全体構造は崩れません。

> **Company OSは、会社の土台・会社の思考・会社の実行を、一つの進化へつなぐOperating Systemです。**

### ビジュアル制作方針

この鳥瞰図を、今後のホームページ、営業資料、代理店説明会、展示会、プレゼン資料の基準図とします。

- 単なる機能配置図にしない。
- 3層の違いと、相互に循環する流れを同時に示す。
- Foundationを「下位」、Business Modulesを「上位」と評価する図にしない。
- Company CoreをCompany OS独自の価値として視覚的な中心に置く。
- AIを全体の上に立つ主役として描かない。
- HIT-HUBには「将来構想」と明記する。
- RISE GATE OSの名称は、ブランド判断が確定するまで鳥瞰図の主役にしない。

### 次章への接続

Company OSの全体構造が見えると、次に必要になるのは、この基盤を誰が、どの会社、拠点、役割で使うのかという整理です。

Chapter 6では、会社、人、組織、拠点、導入パートナーの関係を扱います。

### 参照元

- `company-os-core-model.md`: Continuous Evolution、Product Design Test
- `company-os-value.md`: 会社の文脈を一つにつなぐ価値
- `common-staff-platform-foundation.md`: 組織・スタッフ基盤

---

## Chapter 5の編集メモ

- プロダクトが初めて登場する章だが、機能一覧から始めない。
- Foundation、Company Core、Business Modulesの役割と変化の速さを混在させない。
- 経営理念・経営指針はFoundation、そこから導かれる現在の判断基準はCompany CoreのDirectionとして区別する。
- Company AIを単独の商品やチャットとして扱わず、Company Coreを横断する支援として扱う。
- Business Modulesは追加可能だが、ResultがCompany Coreへ戻らない孤立機能はCompany OSのModuleと呼ばない。
- RISE GATE OS、Company Execution Engineの名称は確定事項として扱わない。
- HIT-HUB、CRM、Meeting、Environmentは将来構想を含む例であり、実装済み機能一覧として表現しない。
- 鳥瞰図は情報量を増やすためではなく、3層とContinuous Evolutionを一目で理解するために使う。
