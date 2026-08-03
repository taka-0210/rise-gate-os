# Chapter 5: 会社の土台・思考・実行を、一つにつなぐ

内部整理名: Company OS Product Architecture

## Chapterの目的

Chapter 4までで、会社は一つ一つの出来事ではなく、その意味あるつながりによって理解できることを見てきました。

ここから初めて、その思想を支えるプロダクト全体の姿を扱います。

Company OSは、たくさんの機能を一か所へ集めたシステムではありません。

会社が立つ土台、会社が考えて学ぶ中心、判断を現実へ移す仕組み、会社ごとに広がる実務を、一つの進化へつなぐ基盤です。

このChapterは、名前を決めるための章ではありません。

それぞれが何に責任を持ち、どのようにつながるかを理解する章です。

```text
責任
  ↓
構造
  ↓
命名
```

読み終えた人が、次のように感じられることを目標とします。

> なるほど。この構造だから、Company OSは会社の進化を支えられるのか。

このChapterは7ページで構成します。

---

## Page 5-1: Company OSは、責任をつなぐ基盤

### 中心メッセージ

> Company OSの全体構造は、機能の種類ではなく、それぞれが何に責任を持つかによって決まります。

### 本文

会社によって、必要な仕事と機能は異なります。

顧客対応を強くしたい会社もあれば、会議、店舗運営、環境整備、制作、開発を中心に改善したい会社もあります。

機能の数や組み合わせからCompany OSを設計すると、新しい機能が増えるたびに全体構造が変わってしまいます。

そこでCompany OSは、機能より先に責任を分けます。

```text
会社の正式な土台を支える責任

会社が観察し、考え、判断し、学ぶ責任

判断を現実の行動へ移し、結果を戻す責任

会社ごとの実務を深く支える責任
```

機能は変わります。会社に必要な実務も増えていきます。

しかし、この責任の違いは変わりません。

> 機能を先に並べるのではなく、会社の進化に必要な責任を先につなぐ。それがCompany OSのプロダクト構造です。

### 図解案

機能アイコンを並べるのではなく、四つの責任が一つのContinuous Evolutionを支える姿を示します。このページではまだ英語名称や製品名を前面に出しません。

### 参照元

- `company-os-core-model.md`: Core Statement、Product Design Test
- `chapter-05-responsibility-review.md`: 各領域の責任
- `chapter-05-final-structure-proposal.md`: 最終推奨構造

---

## Page 5-2: 会社には、判断を支える土台がある

### 中心メッセージ

> 会社が何者で、何を大切にし、いまどの状態にあるかが分かるから、変化の意味を判断できます。

### 本文

会社の改善は、何もない場所から始まるわけではありません。

同じ売上の変化でも、会社の理念、目指す未来、財務状況、組織によって意味は変わります。

Company OSでは、会社の存在と判断の前提になる正式情報を、会社の土台として扱います。

責任設計では、この領域を仮に`Foundation`と呼んでいます。

```text
経営理念       何のために会社が存在するか
経営指針       どのような会社を目指すか
経営数値       会社はいまどの状態にあるか
借入           未来の判断にどのような条件があるか
組織・メンバー 誰が会社をつくり、責任を担うか
```

この土台は、追加機能の一つではありません。

Company OS全体が共通して参照する、会社の正式な文脈と現在地です。

ただし、土台は変化の意味を決めません。

経営数値が変わった事実を持っていても、その変化をどう捉え、何を改善するかは、会社が考える中心の責任です。

経営理念や経営指針そのものは会社の土台にあります。

それらを参照し、「いま会社はどこへ向かうのか」という判断基準として表したものが`Direction`です。

> 会社の土台は、会社を固定するためではなく、変化の意味を見失わないためにあります。

### 図解案

建物の一番下に置く箱ではなく、Company OS全体へ会社の正式情報を届ける大地として描きます。経営理念・指針・数値・組織が、思考と実務の双方を支える姿にします。

### 参照元

- `company-os-core-model.md`: Direction
- `common-staff-platform-foundation.md`: 組織、スタッフ、所属、権限の基盤
- `chapter-05-final-structure-proposal.md`: Foundationの責任

---

## Page 5-3: Company Coreは、会社が考え、学ぶ中心

### 中心メッセージ

> Company Coreは、会社に起きた変化を、意味・改善・判断・学びへつなぐCompany OS独自の中心です。

### 本文

会社の正式情報がそろっていても、それだけでは会社は進化しません。

変化へ気付き、その意味を考え、実現したい未来へ育て、会社として判断し、結果から学ぶ必要があります。

この責任を担うのが、仮に`Company Core`と呼んでいる中心領域です。

```text
Direction       会社が向かう方向を照らす
Observation     会社の内外の変化を受け取る
Sense           その変化の意味を考える
Improvement     実現したい変化へ育てる
Decision        会社として何をするか決める
Result          実際に起きたことを受け取る
Knowledge       経験を次に使える会社の記憶にする
Relationship    それぞれがなぜつながったかを残す
```

Company DialogueとCompany AIも、この中心を支えます。

独立した便利機能として答えを返すのではなく、会社の正式な情報とRelationshipをたどり、人が意味と責任を与えるための問いと候補を提示します。

この中心は、特定の業務だけに属しません。

顧客対応で生まれたObservationも、Projectから戻ったResultも、会議から得たKnowledgeも、一つの会社の進化としてつなぎます。

> Company Coreがあるから、異なる実務で生まれた経験が、一つの会社の記憶になります。

### 図解案

会社の進化が循環する中心として描きます。Company Dialogueは人と中心をつなぎ、Company AIは各段階へ根拠と候補を届ける支援として表現します。

### 参照元

- `company-os-core-model.md`: Continuous Evolution、Core Concepts、Human and AI
- `company-os-query-model.md`: Company Intelligence and Accountability
- `chapter-05-final-structure-proposal.md`: Company Coreの責任

---

## Page 5-4: 判断と現実を、双方向につなぐ

### 中心メッセージ

> 会社の判断は行動へ渡され、現実で起きたことはResultとして必ず会社の思考へ戻ります。

### 本文

Improvementを見つけ、Decisionを残すだけでは、現実は変わりません。

一方、仕事を完了するだけでは、それによって会社がどう変わったのかを学べません。

Company OSには、会社の思考と現実の仕事を双方向につなぐ責任があります。

```text
外へ向かう

Decision
  ↓
適切な実行方式を選ぶ
  ↓
誰が・何を・いつまでに行うかを明らかにする
  ↓
現実の行動へ渡す


内へ戻る

実際に起きたこと
  ↓
Resultとして受け取る
  ↓
Knowledgeと次のObservationへつなぐ
```

責任設計では、このつなぎ目を仮に`Execution Boundary`と呼びました。

しかし、これはBlueprint上の正式名称ではありません。

`Boundary`、`Bridge`、`Gateway`、`Connector`など、責任と「つなぐ」というCompany OSの思想を同時に表せる言葉は、命名段階で改めて比較します。

いま確定するのは名前ではなく、次の責任です。

> Decisionを現実へ渡し、ResultをCompany Coreへ戻す。

### 最小限必要なこと

Company OSでは、高度なProject管理を使わなくても、次のつながりを標準で成立させます。

- Decisionと実行がつながっている。
- 誰が実行に責任を持つか分かる。
- 実行がどの状態にあるか分かる。
- 実行後のResultがCompany Coreへ戻る。

### 図解案

壁や境界線として描かず、Company Coreから現実へ向かう矢印と、現実からResultが戻る矢印が往復する橋として表現します。正式名称は図へ入れず、「判断を行動へ」「結果を学びへ」という役割を示します。

### 参照元

- `company-os-core-model.md`: Decision、Task、Project、Result
- `company-os-relationship-model.md`: Decision and Authority、Execution、Outcome and Learning
- `chapter-05-final-structure-proposal.md`: Execution Boundaryの責任

---

## Page 5-5: Projectは、実行方式の一つ

### 中心メッセージ

> ProjectはCompany OSの中心でも業務領域でもなく、複雑なImprovementを実現するために選ぶ実行方式です。

### 本文

会社のDecisionを現実へ移す方法は、一つではありません。

```text
Task
  小さく明確な行動

Project
  複数の人、期間、工程、成果物を伴う実行

Experiment
  仮説を確かめる試行

Routine / Workflow
  繰り返し行う定常的な実行
```

Projectを使うのは、複数の人、期間、工程、成果物を必要とするImprovementを実現するときです。

```text
Improvement
  問い合わせ体験を改善する
        ↓
Decision
  Webサイトと対応方法を一緒に見直す
        ↓
Project
  制作、顧客対応、会議を横断して実行する
```

一方、顧客へ一本電話するだけならTaskで十分です。

新しい案内文の反応を確かめるならExperiment、毎朝の確認ならRoutineが自然です。

すべての行動をProjectへ入れる必要はありません。

Company OS標準で必要なのは、実行とResultがつながることです。

Roadmap、複雑なTask階層、ガント、工数、見積、実施計画書などの高度なProject Managementは、必要な会社が利用できる拡張能力として整理できます。

> Projectは終了します。しかし、そのResultは次のObservationへ戻り、会社の進化は続きます。

### 図解案

Decisionから複数の実行方式へ枝分かれする図にします。Projectだけを大きくせず、仕事の複雑さと目的に応じて方式を選ぶことを示します。

### 参照元

- `company-os-core-model.md`: Task、Project、Result
- `company-os-relationship-model.md`: Execution
- `chapter-05-final-structure-proposal.md`: Projectの最終推奨位置

---

## Page 5-6: 会社ごとの実務は、外へ広がっていく

### 中心メッセージ

> Company OSの中心は共通でも、現実の仕事は会社ごとに異なり、必要に応じて広げられます。

### 本文

会社には、それぞれ固有の仕事があります。

顧客との関係を育てる仕事、会議で合意をつくる仕事、働く環境を整える仕事、業種固有の仕事があります。

責任設計では、これらを仮に`Operational Capabilities`と呼びました。

ただし、これは利用者向けの正式名称ではありません。

`Business Modules`を含め、どの言葉がCompany OSの思想と利用者の理解に最も合うかは、命名段階で決めます。

いま大切なのは、それぞれがCompany OSへどうつながるかです。

```text
Signal / Event
  実務で起きた変化をObservation候補として渡す

Action
  Decisionに基づく実際の仕事を行う

Result
  行動によって起きたことをCompany Coreへ返す
```

例えば、顧客対応の領域で同じ要望が増えたとき、それはObservation候補になります。

会議で正式な判断が行われれば、Company CoreのDecisionへ接続します。

環境整備で変化が起きれば、そのResultが新しいObservationになります。

Company OSは、実務領域の中だけにImprovementやKnowledgeを閉じ込めません。

新しい実務領域が加わっても、会社のObservation、Decision、Result、Knowledgeとして共通の進化へ戻します。

HIT-HUBも現時点では正式機能ではなく、将来Company OSへ接続・統合を検討する実務領域です。

> 会社ごとに使う実務は違っても、そこで生まれた経験は一つの会社の進化へ戻ります。

### 製品としてのModuleとの違い

責任上の実務領域と、販売・契約・画面上のModuleは同じとは限りません。

責任構造はBlueprintの鳥瞰図で示し、具体的な提供Moduleは機能一覧や料金体系で別に整理します。

### 図解案

Company Coreの周囲に、会社ごとの実務が必要に応じて広がる構図にします。固定された機能一覧にはせず、Signal・Action・Resultの往復を主役にします。

### 参照元

- `chapter-05-responsibility-review.md`: Business Modulesの責任
- `chapter-05-final-structure-proposal.md`: Operational Capabilitiesの責任
- `roadmap.md`: 現在の実装と将来構想

---

## Page 5-7: Company OS全体鳥瞰図

### 中心メッセージ

> Company OSは、会社の土台の上で、思考と現実を往復させ、あらゆる実務から生まれた結果を次の進化へ戻します。

### 本文

Company OSの全体構造は、製品を縦に積み上げた階層ではありません。

会社の正式な土台が全体を支え、その上でCompany Coreが考え、学びます。

Company Coreと現実の間では、Decisionが行動へ渡され、Resultが戻ります。

その外側には、会社ごとに必要な実務が広がります。

### Company OS全体鳥瞰図

```text
┌─────────────────────────────────────────────────┐
│                    COMPANY OS                   │
│                                                 │
│         会社ごとに広がる実務                      │
│   顧客・会議・環境・業種固有・外部システム          │
│                                                 │
│      ┌───────────────────────────────────┐      │
│      │       判断と現実をつなぐ仕組み       │      │
│      │                                   │      │
│      │ Decision → Action → Result        │      │
│      │ Task / Project / Experiment       │      │
│      │ Routine / Workflow                │      │
│      │                                   │      │
│      │   ┌───────────────────────────┐   │      │
│      │   │       COMPANY CORE        │   │      │
│      │   │                           │   │      │
│      │   │ 観察 → 意味 → 改善 → 判断  │   │      │
│      │   │   ↑             ↓         │   │      │
│      │   │ 学び ← 結果 ← 実行         │   │      │
│      │   └───────────────────────────┘   │      │
│      └───────────────────────────────────┘      │
│                                                 │
│  ─────────────── 会社の土台 ─────────────────  │
│  経営理念・経営指針・経営数値・借入・組織・人      │
└─────────────────────────────────────────────────┘
```

この図で最も大切なのは、箱の数ではありません。

```text
会社の土台が、判断を支える
        ↓
会社の思考が、何をするか決める
        ↓
判断が、現実の行動へ渡される
        ↓
実務から、Resultが戻る
        ↓
会社が学び、また観察する
```

この往復が続くから、Company OSには完了がありません。

実務領域や提供Moduleが増えても、この責任構造は変わりません。

> **Company OSは、機能を集めたシステムではなく、会社の土台・思考・実行・学びを、一つの進化へつなぐOperating Systemです。**

### ビジュアル制作方針

この鳥瞰図を、今後のホームページ、営業資料、代理店説明会、展示会、プレゼン資料の基準図とします。

- 縦の製品階層や機能配置図にしない。
- 会社の土台を、全体を支える大地として描く。
- Company Coreを会社が考え、学ぶ中心として描く。
- 判断と現実の間を、壁ではなく双方向のつながりとして描く。
- 会社ごとの実務は、固定機能ではなく外へ広がる領域として描く。
- Projectを中心に置かず、複数ある実行方式の一つとして描く。
- AIを全体の上に立つ主役として描かない。
- 未確定の正式名称を基準図へ固定しない。
- HIT-HUBなどの将来構想は、基準図本体ではなく具体例として補足する。

### 次章への接続

Company OSの責任構造が見えると、次に必要になるのは、この基盤を誰が、どの会社、拠点、役割で使うのかという整理です。

Chapter 6では、会社、人、組織、拠点、導入パートナーの関係を扱います。

### 参照元

- `company-os-core-model.md`: Continuous Evolution、Product Design Test
- `chapter-05-responsibility-review.md`: 構造案の比較
- `chapter-05-final-structure-proposal.md`: 最終推奨構造

---

## Chapter 5の編集メモ

- このChapterでは、責任と構造を確定し、正式名称は確定しない。
- 読者向け本文では「会社の土台」「会社が考え、学ぶ中心」「判断と現実をつなぐ仕組み」「会社ごとに広がる実務」を優先する。
- Foundation、Company Core、Execution Boundary、Operational Capabilitiesは責任設計上の仮称として扱う。
- `Boundary`、`Bridge`、`Gateway`、`Connector`などの名称比較は、ブランド設計で行う。
- 責任上の構造と、販売・契約・画面上のModule構成を混在させない。
- Projectを業務領域やCompany OSの中心にせず、一つの実行方式として扱う。
- 高度なProject Managementを利用しなくても、Decision、実行、Resultの最小循環が成立することを守る。
- HIT-HUBは将来構想であり、実装済みの正式機能として表現しない。
