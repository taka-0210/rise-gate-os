# Company OS Ver.1 Scope 5 P0 Audit

- Scope: Organization所属の停止・終了と一時停止からの復帰
- 実施日: 2026-09-20（Asia/Tokyo）
- Repository: `C:\xampp\htdocs\rise-gate-os`
- Branch: `master`
- P0基準HEAD / origin: `88b908ab6541f5bd9a65b2d62ffc96a4e1c1dc5b`
- P0開始時worktree: clean
- Runtime: PHP 8.2.12 / Laravel 12.63.0 / SQLite
- Test DB: `sqlite :memory:` / Session: array / Queue: sync
- Local DB: SQLite / Session: database / Queue: database
- Production接続・Migration・Deploy: 未実施

## 1. 正本・Evidence確認

| 資料 | SHA-256 |
|---|---|
| `CompanyOS_v133_scope4_done.pptx` | `B0A8CBD1BD4560034F79E02155035B104822D46B50B784AF458E9BC57742614B` |
| `CompanyOS_Ver1_要件仕様書_v030_Scope4_FinalClose.xlsx` | `36C61A5DC4CD328D406D08824EB82F380817E20EB8AB9277FCB5518AFC459678` |
| `CompanyOS_Ver1_RemainingScope_Roadmap_v004_Scope5確定版.md` | `84E949CF2B5A1A0C05689393015003210498A09EF2CDD692D9A1BEEC3577D148` |
| `CompanyOS_Ver1_RemainingScope_管理表_v004_Scope5確定版.xlsx` | `C54241582962B0D462AED19702CA75EFB71C226750E9F00854B83AF933FD13BD` |
| `CompanyOS_Scope5_実装準備書_v002_確定版.md` | `4E3C1F4BAE79C7CAF65820B1B8E0CE90DDB9601CE8D45914D418EEF37594E7ED` |
| `CompanyOS_Scope5_管理表_v002_確定版.xlsx` | `2864CED8BE51ED0C9B92925EDD7DDA0402E2594D8BB30369D2E6C887337D3837` |
| `CompanyOS_Scope5_Codex実装指示書_v002_確定版.md` | `B8A7C8984A0BD8848348287D42CB7CCA7909633F85BDE95A35C7826F8D03659A` |

- PPTは137ページ。p135〜137でScope 4実装`476f083...`、Close`88b908a...`、387 tests / 3008 assertions、Local Batch 45、Production未実施、次工程Scope 5を確認した。
- Master Excelは26 sheet。D-223〜232、Scope 4 Final Close、Scope 5未着手を確認した。
- Scope 5の5成果物でS5-DE-01〜03、PD01〜03解決済み、B01〜03解消済み、C01/C02条件付き未発生、Permission Matrix、状態遷移、S5-DC-01〜28、P0〜P5が同期している。
- Repository内のScope 1〜4 Final Close Reportと各実装Commitを確認した。

## 2. Repository / Baseline

- P0 HEAD / `origin/master`: `88b908ab6541f5bd9a65b2d62ffc96a4e1c1dc5b`。
- Scope 1実装`15751f2...`、Scope 2実装`7ee3c28...`、Scope 3実装`abf1493...`、Scope 4実装`476f083...`を包含する。
- P0 fresh full suite: **387 tests / 3008 assertions**、133.45秒、全件成功。
- 過去Reportの件数をS5実績として転記せず、`phpunit.xml`の`sqlite :memory:`、array session、sync queueで再実行した。
- Application timezoneと既存JST Testは成功した。

## 3. Local DB匿名Inventory

| 対象 | 件数 / 状態 |
|---|---|
| User | 3（active 3 / inactive 0） |
| Organization | 4 |
| Organization Membership | 4（active 4 / Owner 4） |
| active Owner | 各Organization 1名 |
| Group / Group Membership | 0 / 0 |
| Workspace / Workspace Membership | 5 / 5 |
| Project / Project Membership | 17 / 17 |
| AI Access Key | 2（revoked 0） |
| Organization Invitation | 0 |
| Organization Audit | 0 |

- Organization / Workspace / Project Membershipの重複0、User / Parent孤児0。
- AI Key 2件はいずれもUserとWorkspaceを持ち、Workspace→OrganizationとUserのOrganization Membershipを一意に解決できる。
- AI KeyのOrganization不明0、Membership欠落0、non-active Membership紐付け0。
- Email、Token、Key hash、Password、Avatar path / 原画像等は出力・記録していない。

### P0保全hash

| 対象 | SHA-256 |
|---|---|
| User非秘密field | `bd7ef162433115bbeb40747df9141a7ad8baba331287aa0dd4665957221d8ae3` |
| Organization | `a5a71f6caefdf0d005fad02c0551c07d65d58121ea4159251f010edeed24d45b` |
| Organization Membership | `a1a73aea3fa4e6a0781938fe599ec24b82420e9d4348f3fdb5e8314d66e0fde5` |
| Workspace Membership | `b795892d03edc9ab6d31d8e644d29c5a3cf2b869fcecc32ec5f657b51b385dce` |
| Project Membership | `e85b68ae088c3ef1772ceefbcdae24b623a180ed436f1490eb33183dd91ff70e` |
| AI Access Key非秘密field | `469af8201fc0cfc7fd7cf291735eff84ee4dc05fe4178086fa9536ba7d6e2c5f` |

## 4. 現行Gate / Session / Route

- `EnsureCurrentCompany`はRequestごとにactive Organization Membershipだけを読み、停止Orgのcurrent company / workspace sessionを破棄する。
- `EnsureCurrentWorkspace`と`User::canAccessWorkspace`はWorkspace active、Workspace Membership、Organization active Membershipを再評価する。
- Company選択、Login後選択、Company / Workspace route、Financial、Project、画像 / attachment downloadは上記Web middlewareまたは各Resource認可へ接続される。
- API / MCPは`AuthenticateAiAccessKey`でKey、User、Workspace、active Organization Membership、Workspace Membershipを再評価する。
- `AiProjectContextGuard`とScope 1 authorizationはOrganization / Workspace / Project Membershipを再評価する。
- S2の`credential_generation`はglobal Account credential用であり、Organization停止には使用しない。
- 現行Sessionはcurrent Organization ID / Workspace IDを保持するがOrganization Membership epochを持たず、suspend→resumeのABAで古いcontextを区別できない。
- Queue JobはAccount MailとInvitation Mailの2種類。Invitation Jobは実行時にpending / generation / sponsor global active / sponsor active Membership / Roleを再評価する。
- 長時間の業務Jobは存在しない。Download / Export相当はHTTP Request時のcurrent Resource認可境界にある。
- Standalone Project Appは独立Account / Sessionで、CO Identity統合前のS17責務。Scope 5で新しい停止保証を追加しない。

## 5. Writer Inventory

### Organization Membership / Role / Status

- Bootstrap `RegisteredUserController`: Owner active Membershipを新規作成。Scope 6まで維持。
- Client会社化 `PromoteClientToCompanyAccount`: Owner active Membershipを新規作成。既存業務writerとして維持。
- Invitation `OrganizationInvitationMembershipWriter`: invited準備、受諾時active化。left / suspendedは既にAcceptanceで拒否する。
- Organization Role / Position / Group `OrganizationAdministration`: Organization lock、最新row、active target、last Owner、Auditを実装済み。
- System Admin Workspace追加 `MemberController::storeWorkspace`: existing Membershipは更新せず、欠落時だけactive Memberを作成する。suspended / leftをactiveへ戻さないが、追加自体の明示拒否が必要。
- System Admin Workspace解除 `destroyWorkspace`: 最後のWorkspace解除時にOrganization Membershipをdetachする現行挙動があり、Scope 5の履歴保持に合わせてOrg Membershipを保持する必要がある。
- System Admin global active更新 `MemberController::update`: global System Admin invariantはあるが、global inactiveでOrganization最後のactive Ownerを0人にできる不足がある。

### AI Key

- Web `AiConnectionController`: current active Workspace / Organization context内で本人Keyを発行・revokeする。
- Console `CreateAiAccessKey`: Workspace memberを解決するがactive Organization Membershipを明示確認していない。不足を閉じる。
- API / MCP middlewareはactive Membershipを確認済み。
- Scope 5では対象User×対象OrganizationをWorkspace relationで限定し、未revoke Keyだけを同一Transactionでrevokeする。

### Invitation

- issue / resend / revoke / acceptはOrganization row lockを先頭に取り、最新Invitation / Membership / Sponsor権限を確認する。
- pending InvitationはOrganization＋Emailで一意、Token hash / generation / claim / expiryを持つ。
- 停止時には同一OrganizationのSponsor一致pendingと、安全にUserへ紐づくpendingだけをSystem effectとしてrevokeする。
- lifecycle revokeは人向けS4 Permission APIを拡張せず、Lifecycle Service内部に限定する。

## 6. P1 schema差分案

`membership_status`既存enum相当は再Migrationしない。次の最小additive差分が必要。

- `organization_users.access_epoch`: unsigned bigint default 1。停止 / 終了 / 復帰ごとに増分し、古いSession / Job contextのABAを拒否する。
- `organization_users.lifecycle_version`: unsigned bigint default 1。expected versionとstale commandを検証する。
- `organization_users.status_changed_at`: nullable timestamp。
- `organization_users.status_changed_by_user_id`: nullable User FK / nullOnDelete。
- `organization_users.status_change_reason`: nullable string。最新理由の管理表示用で、全履歴はAuditへ残す。
- `organization_membership_lifecycle_operations`: Organization / Membership / actor / command / request ID / payload hash / expected・result version / result status・epoch / 影響件数を保持するidempotency Evidence。

既存rowのepoch / versionは権限を変えない決定的default 1とし、架空の理由・退職日・actorをbackfillしない。既存Role、Position、Group / Workspace / Project Relation、legacy role / company_role / permissions、User / Historyは削除・Rename・一括更新しない。

## 7. C01 / C02判定

### S5-C01

**未発生。** 現行AI KeyはUser / Workspaceを持ち、WorkspaceのOrganizationへ一意に対応する。共有Provider secretは`ai_access_keys`とは別で、個人停止により変更しない。Web / API / MCPの既存入口はactive Membership gateへ接続可能であり、最小差分で停止を閉じられる。

### S5-C02

**未発生。** Scope 1〜4 Contractを変更せず、additive schema、既存active gate、Organization lock、Audit、Invitation generationを再利用できる。Data削除・破壊的Rename・推測補正・不可逆Migrationは不要。System Admin writerの補強はS4 DC25の既存Workspace管理を維持したまま、Org Membershipの履歴保持とlast Owner保護を追加するもの。

## 8. P0出口判定

- S5-DE-01〜03互換: 合格。
- 二社境界: active Membership / Workspace / AI KeyをOrganization単位で評価可能。
- last Owner: 既存Organization lockを再利用し、Lifecycle / Role / global inactive writerへ同じ順序を適用可能。
- Relation / History保持: 物理削除なしで実装可能。
- Migration: 最小additive / reversible schemaで実装可能。
- Production / 実local DB: Scope 5 Migrationを適用しない。

重大互換問題はないためS5-P0を完了し、S5-P1へ自律進行する。

## 9. S5-P1追加Decision Evidence

### S5-DE-04｜Cross-Organization Project Access

- 判断者・確定日: 高見氏、2026-09-20（Asia/Tokyo）。
- Project所有Organizationのactive Membershipは一律必須にしない。
- 認可主体は、対象UserのactiveなProject Membershipに保存された`workspace_id`、そのWorkspace Membership、Workspaceのactive状態、そのWorkspaceが属するOrganizationのactive Membership、および既存`permission_level`とする。
- Project / Roadmap / Improvement / Taskは、上記を満たす既存Cross-Organization共同参加を許可する。
- Project Member自身のWorkspaceが属するOrganization Membershipが`suspended`または`left`になった場合は、Project Membershipを保持したまま、古いSession / Context / URLを含むアクセスを拒否する。
- Project所有OrganizationへのMembership追加、Organization RoleからProject Permissionへの自動grant、Project Permission Level変更は行わない。
- 一次Evidence: 本会話の「Scope 5追加Decision｜Cross-Organization Project Access」で始まるユーザー指示。
- 実装Evidence: `ProjectPolicy` / `RoadmapPolicy` / `ImprovementPolicy` / `TaskPolicy`と`OrganizationMembershipLifecycleTest::test_cross_organization_project_access_follows_the_project_members_own_workspace_membership`。

この追加Decisionにより、重点回帰で検出した「Project所有Organizationへの所属を誤って要求する」2件の失敗を、既存共同参加を維持する形で解消する。S5-C01 / C02は引き続き未発生であり、Scope 1〜4のPermission LevelとRelationを変更しない。
