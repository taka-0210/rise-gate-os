# Company OS Ver.1 Scope 4 P0 Audit

- Scope: Staff Invitation・初回利用
- 実施日: 2026-09-20（Asia/Tokyo）
- Repository: `C:\xampp\htdocs\rise-gate-os`
- Branch: `master`
- P0基準HEAD / origin: `ea9f1b5760f3b8f1c05206aba910ae0c8ebb7172`
- P0開始時worktree: clean
- Runtime: PHP 8.2.12 / Laravel 12.63.0 / SQLite
- Production接続・Migration・Mail送信: 未実施

## 1. 正本・Evidence確認

| 資料 | SHA-256 |
|---|---|
| `CompanyOS_v132_scope3_done.pptx` | `2E455423ADC5E6C0A7843A4B29B15BE2B16FE74671C421708CBCADDB1447E541` |
| `CompanyOS_Ver1_要件仕様書_v029_Scope3_FinalClose.xlsx` | `1EBD846634FAFF79133130E72641CE78F6BAA0C6295BBBF73D2B233F5877B61F` |
| `CompanyOS_Ver1_RemainingScope_Roadmap_v002_確定版.md` | `5D8E3D3ED12C7106FEA7895AF4AD4E9075C74C6751A0980CCA4C76BF7BC5FD44` |
| `CompanyOS_Ver1_RemainingScope_管理表_v002_確定版.xlsx` | `F098F05C6AEDDB8158884CDD0FC5A1FEE4646099AC5FD04D34A5DD8FCF582DAB` |
| `CompanyOS_Scope4_実装準備書_v002_確定版.md` | `2098951BFF637366686E6064141C494E4AA2714E5BC8FDF0E34A1784CA24AE90` |
| `CompanyOS_Scope4_管理表_v002_確定版.xlsx` | `8659CD1629D895CCA54E4E9CBE9A4F85E88A9E7F55A2B45F2E6572D2634240E6` |
| `CompanyOS_Scope4_Codex実装指示書_v002_確定版.md` | `93909C357B932A2AF458673C1C708A79C74545EB7C97997E385E4E318698BF15` |

Repository内のScope 1〜3 Final Close Reportも確認した。Scope 1実装`15751f2...`、Scope 2実装`7ee3c28...`、Scope 3実装`abf1493...`およびScope 3 Final Report`ea9f1b5...`は現在HEADに包含される。

S4-DE-01 / S4-DE-02は高見氏の正式判断、記録日2026-09-20（Asia/Tokyo）、一次Evidenceは本会話として確定済みであり、再判断していない。

## 2. Fresh baseline

- Command: `php artisan test`
- Result: **372 tests / 2792 assertions**、124.61秒、全件成功。
- 過去の372 / 2792を結果として転用せず、P0で再実行した。
- Node / npmはPATH上に存在しない。P0ではApplication変更前のためbuildを実行していない。P4で隔離portable Nodeを使用してbuild Evidenceを取得する。
- Application timezoneは既存Testを含め`Asia/Tokyo`で成功した。

## 3. DB匿名baseline

| 対象 | 件数 |
|---|---:|
| User / active User | 3 / 3 |
| Organization | 4 |
| Organization Membership | 4 |
| Workspace / Workspace Membership | 5 / 5 |
| Project / Project Membership | 17 / 17 |
| Group / Group Membership / Organization Audit | 0 / 0 / 0 |

- Organization Membershipは全4件が`active`、新Role / legacy Roleとも`owner`。
- Workspaceはshared 5件、active 5件、Membershipはowner 5件。
- Organization / Workspace / Project / Group Membershipの重複0、User / Parent孤児0。
- 明示的な標準Workspace column / table / flagは存在しない。
- User Avatar column / tableは存在しない。

### P0保全hash

| 対象 | SHA-256 |
|---|---|
| User非秘密field | `368befd2a6a8852fb85a580cbae864d4226803306c365223394fed81f599c452` |
| Organization | `95959572419f45270e93d1bac1766bc73b239bfb6b7bf35efb466bcd508f2dea` |
| Organization Membership | `8a1ce7b79194c99d172e54efd7bf65890221e6501cfcf8c231f49800919b009e` |
| Workspace | `528ad7c6cfa8db6d9c642a43f2f4d99c5e3f614802a9323d235e4cc2db476f8d` |
| Workspace Membership | `d944770397ef116343895b57d6547435d41af4696ec04c26a74754ad7c5aeb09` |
| Project Membership | `da41757a02dd82c015220d522279ee8c019c5e7294c8c81298a793303661ce9e` |

Password hash、remember token、Mail credential、Invitation token等の秘密値は出力・記録していない。

## 4. Writer / Route / Token / Queue / File監査

### Membership writer

- Bootstrap: `RegisteredUserController`が初回User / Organization / Workspace ownerを作成する。S6責務として保持する。
- System Admin: `MemberController::store`がUserと恒久Passwordを作成し、Organization / Workspaceへ直接所属させる。Scope 4代替Journey成立後に通常入口を退役する対象。
- System Admin: `storeWorkspace`は既存UserをOrganization / Workspaceへ直接追加するため、招待迂回防止として新規追加入口を退役する対象。
- Client会社化: `PromoteClientToCompanyAccount`は別業務writerであり、Scope 4では変更しない。
- Scope 3: `OrganizationAdministration`はRole / Position / Group変更をOrganization lock＋Transaction＋Auditで実行する。Invitationは同じlock順・Auditを利用する。

### Scope 2接続

- Loginはactive User、Rate Limit、Session ID再生成、credential generationを実装済み。
- orgless / invited Userも`/account`、Password、Email Verificationを利用できる。Company middleware外にある。
- Email Verificationはhash token、signed relative URL、現在User / Email / credential generation、期限を検証する。
- `SendAccountActionMail`は`ShouldBeEncrypted`＋`ShouldQueueAfterCommit`。Invitationは同じMail transport / Mailable契約を利用し、Invitation専用Jobで最新世代を再確認する。
- Account Recovery tokenをInvitationへ流用しない。

### Avatar能力

- private `local` diskは`storage/app/private`。
- PHP runtimeはGD、fileinfo、exifを利用可能。
- 既存画像UploadはMIME / size検証とprivate responseの実例を持つが、Avatar用の16MP上限・512px再エンコード・EXIF除去・Identity閲覧認可は未実装。
- 汎用Document / File Foundationは新設せず、User Profileの最小Avatar差分と共通表示componentを追加する。

## 5. 実効Permission到達表

新Staffの安全な最低値は次のとおり固定する。

- legacy Organization `role=member`
- `company_role=member`
- 個別`permissions=[]`
- `organization_role`はInvitation予定値を受諾時にのみ設定
- `membership_status=invited`から最終受諾時だけ`active`
- 標準Workspace `role=member`
- Project Membership、AI Access Keyは作成しない

| Resource / 操作 | 到達結果 | 根拠・境界 |
|---|---|---|
| Organization管理 | intended RoleがOwner / Adminなら受諾後に可。Memberは不可 | `OrganizationAccess`は新Role＋activeを評価。Invitation前は非active |
| Financial閲覧・管理 | 不可 | `CompanyAccess`はlegacy owner/adminまたは個別permission。legacy member＋空permissionはdeny |
| 既存Project閲覧・編集 | 不可 | Project Policy / AI Contextはactive Project Membershipを別途要求 |
| Scope 1 Apply | 不可 | active Project Membership＋permissionを要求。Org Role / Group / Workspaceだけではgrantしない |
| AI Project Context | 不可 | Workspace所属だけでなくProject Membership、AI設定、AI Key等を要求 |
| Workspace閲覧 | 可 | S4-DE-02の標準Workspace memberとして明示的に初期所属 |
| 新規Client / Project作成 | 可 | 既存Workspace member Contract。新規Project作成者はそのProject ownerになる。既存Projectは見えない |
| Workspace設定 / AI設定 / Business Profile変更 | 不可 | Workspace owner / adminのみ |
| Group所属 | 受諾時だけ予定Groupへ所属 | GroupからResource Permissionは派生しない |

Workspace memberに新規Client / Project作成能力がある点は既存Contractであり、S4-DE-02が明示するmember所属と整合する。既存Project・Financial・AI Context・機密情報への自動到達はないため、S4-B03は発生しない。

## 6. 標準Workspace互換監査

- 現行Model / Migration / DBに明示的な標準Workspace設定はない。
- Workspace名、最古ID、shared type、件数からの推測選定はしない。
- 既存4 Organization / 5 Workspaceは全て未設定のまま維持する。
- `organizations.standard_workspace_id`相当のnullable参照をadditiveに追加し、backfillしない。
- 未設定Organizationではactive Ownerの明示操作だけが、空のshared / active Workspaceを作り、そのOwnerをWorkspace ownerとして所属させる。
- 設定後の切替、既存Data移動、既存Staff一括所属、Personal新設は実装しない。

## 7. Migration差分案

- Organizationへnullableな標準Workspace参照。
- Userへnullableなprivate Avatar参照情報。
- Organization Invitation本体。
- Invitation予定Group pivot。
- Invitation idempotency operation記録。
- 既存Dataのbackfill / Rename / 削除なし。
- Invitation status、Mail delivery status、Membership statusを別軸にする。
- Token平文、Password、URLをDB / Auditへ保存しない。

## 8. P0出口判定

- S4-DE-01互換: 合格。Scope 3 Owner限定Role管理を維持できる。
- S4-DE-02互換: 合格。既存Workspaceを推測せず、明示初期化と新規受諾者だけのmember所属で実装できる。
- S4-B03: 未発生。安全なlegacy member / empty permissionsが存在する。
- S4-B04: 未発生。additive Migrationで実装可能。
- 確定仕様変更、重大な新Permission判断、既存Data削除、不可逆Migrationは不要。

したがってS4-P0を完了し、追加確認を挟まずS4-P1へ進む。
