<?php

namespace Database\Seeders;

use App\Models\Improvement;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\Task;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CommonStaffPlatformProjectSeeder extends Seeder
{
    public function run(): void
    {
        $project = Project::query()
            ->where('name', '共通スタッフ基盤システム')
            ->first();

        if (! $project) {
            throw new RuntimeException('プロジェクト「共通スタッフ基盤システム」が見つかりません。');
        }

        $userId = $project->owner_user_id;

        DB::transaction(function () use ($project, $userId): void {
            foreach ($this->plan() as $roadmapIndex => $roadmapData) {
                $roadmap = Roadmap::updateOrCreate(
                    ['project_id' => $project->id, 'title' => $roadmapData['title']],
                    [
                        'organization_id' => $project->organization_id,
                        'workspace_id' => $project->owning_workspace_id,
                        'purpose' => $roadmapData['purpose'],
                        'status' => $roadmapData['status'],
                        'sort_order' => ($roadmapIndex + 1) * 10,
                        'created_by' => $userId,
                    ]
                );

                foreach ($roadmapData['improvements'] as $improvementIndex => $improvementData) {
                    $improvement = Improvement::firstOrNew([
                        'project_id' => $project->id,
                        'title' => $improvementData['title'],
                    ]);
                    $improvement->fill([
                            'organization_id' => $project->organization_id,
                            'workspace_id' => $project->owning_workspace_id,
                            'roadmap_id' => $roadmap->id,
                            'roadmap_sort_order' => ($improvementIndex + 1) * 10,
                            'current_state' => $improvementData['current_state'],
                            'desired_state' => $improvementData['desired_state'],
                            'problem' => $improvementData['problem'],
                            'hypothesis' => $improvementData['hypothesis'],
                            'action' => $improvementData['action'],
                            'next_action' => $improvementData['next_action'],
                            'visibility' => Improvement::VISIBILITY_INTERNAL,
                            'proposed_by' => $userId,
                            'assigned_to' => $userId,
                    ]);
                    $improvement->status ??= $improvementData['status'];
                    $improvement->save();

                    foreach ($improvementData['tasks'] as $taskData) {
                        $task = Task::firstOrNew([
                            'improvement_id' => $improvement->id,
                            'title' => $taskData['title'],
                        ]);
                        $task->fill([
                            'organization_id' => $project->organization_id,
                            'workspace_id' => $project->owning_workspace_id,
                            'project_id' => $project->id,
                            'description' => $taskData['description'],
                            'priority' => $taskData['priority'],
                            'assigned_to' => $userId,
                            'created_by' => $userId,
                        ]);
                        $task->status ??= Task::STATUS_TODO;
                        $task->save();
                    }
                }
            }
        });
    }

    private function plan(): array
    {
        return [
            [
                'title' => 'Phase 1：共通スタッフ基盤の方針とデータ設計',
                'purpose' => 'ライズアップ全体で共有するスタッフ情報、所属、権限、認証の基準を決め、後続システムが迷わず連携できる状態にする。',
                'status' => Roadmap::STATUS_ACTIVE,
                'improvements' => [
                    $this->improvement(
                        'スタッフ情報と所属構造を定義する',
                        'システムごとに個別アカウントを作る可能性があり、共通のスタッフ識別子がまだない。',
                        '全スタッフを一意のstaff_idで管理し、店舗・役職・在籍状態を共通利用できる。',
                        '項目や所属ルールが曖昧なまま実装すると、環境整備システムや将来のシステムで作り直しが発生する。',
                        '最初に必要最小限のマスター項目と所属関係を確定すれば、MVPを小さく保てる。',
                        'スタッフ項目、店舗との関係、退職時の扱いを文書化する。',
                        '必要項目を確認し、データモデルを確定する。',
                        [
                            $this->task('スタッフ基本項目を決める', '氏名、社員番号、ログインID、所属店舗、役職、在籍状態など、MVPで必要な項目を確定する。', Task::PRIORITY_URGENT),
                            $this->task('店舗とスタッフの所属ルールを決める', '主所属のみか複数店舗所属を許可するか、異動履歴を持つかを決める。', Task::PRIORITY_HIGH),
                            $this->task('退職・休職・異動時のデータ保持ルールを決める', '過去の作業履歴を残したままログインだけを停止できるルールを定義する。', Task::PRIORITY_HIGH),
                        ]
                    ),
                    $this->improvement(
                        '認証方式とシステム別権限を設計する',
                        '共通ログインと各システム内の権限の境界が未定義。',
                        '一つの個人アカウントで、許可されたシステムだけを適切な権限で利用できる。',
                        '会社での役職と各業務システムでの権限を混ぜると、権限変更が複雑になる。',
                        '共通認証とシステム別ロールを分離すれば、システム追加にも対応しやすい。',
                        'ログイン、セッション、パスワード、利用許可、システム別ロールを設計する。',
                        '環境整備システムに必要なロールを確定する。',
                        [
                            $this->task('ログインIDとパスワード運用を決める', 'メールアドレス利用の可否、初期パスワード、変更・再発行の流れを決める。', Task::PRIORITY_URGENT),
                            $this->task('システム別利用権限のモデルを決める', '利用可否と管理者・一般などのロールをスタッフ単位で設定できる構造にする。', Task::PRIORITY_URGENT),
                            $this->task('セキュリティ要件を決める', 'パスワードハッシュ、セッション期限、ログイン試行制限、監査ログの最低要件を定義する。', Task::PRIORITY_HIGH),
                        ]
                    ),
                ],
            ],
            [
                'title' => 'Phase 2：hit_staff 共通ログインMVP',
                'purpose' => 'スタッフ登録・停止・ログイン・利用システム判定ができる、最小限の共通スタッフ基盤を稼働させる。',
                'status' => Roadmap::STATUS_DRAFT,
                'improvements' => [
                    $this->improvement(
                        'スタッフ管理機能を実装する',
                        'スタッフ情報を一元管理する画面と保存先がない。',
                        '管理者がスタッフの登録、編集、無効化、所属変更を行える。',
                        '個別システムにスタッフ情報を持たせると二重管理になる。',
                        'hit_staffを唯一のスタッフマスターにすることで更新箇所を一本化できる。',
                        'スタッフマスターと管理画面を実装する。',
                        'データベースと登録・編集画面を作成する。',
                        [
                            $this->task('スタッフ・所属・権限テーブルを作成する', 'staffs、所属関係、systems、システム別権限のテーブルと制約を実装する。', Task::PRIORITY_URGENT),
                            $this->task('スタッフ一覧・登録・編集画面を作成する', '検索、登録、編集、在籍状態の変更ができる管理画面を作る。', Task::PRIORITY_HIGH),
                            $this->task('スタッフ無効化処理を実装する', '過去データを削除せず、すべての新規ログインを停止できるようにする。', Task::PRIORITY_HIGH),
                        ]
                    ),
                    $this->improvement(
                        '共通ログインと認証連携を実装する',
                        '個人アカウントで各システムに安全にログインする仕組みがない。',
                        'スタッフが共通アカウントでログインし、許可されたシステムへ移動できる。',
                        '独自仕様の認証連携は、なりすましやセッション漏えいの原因になる。',
                        '署名付きの標準的な認証フローと短い有効期限を採用すれば安全に連携できる。',
                        '認証処理、ログイン履歴、システムへの認証引き渡しを実装する。',
                        '認証フローを実装し、単体テストを行う。',
                        [
                            $this->task('ログイン・ログアウト・パスワード変更を実装する', '安全なパスワード保存とセッション管理を含む基本認証を作る。', Task::PRIORITY_URGENT),
                            $this->task('ログイン試行制限と履歴を実装する', '連続失敗の制限と、成功・失敗・日時・対象スタッフの監査記録を残す。', Task::PRIORITY_HIGH),
                            $this->task('認証連携の受け渡し仕様を実装する', '環境整備システムがstaff_idと権限を安全に検証できる連携方式を作る。', Task::PRIORITY_URGENT),
                            $this->task('共通認証の自動テストを作成する', '正常ログイン、無効スタッフ、権限なし、不正な認証情報、期限切れを検証する。', Task::PRIORITY_HIGH),
                        ]
                    ),
                ],
            ],
            [
                'title' => 'Phase 3：環境整備管理システムとの連携',
                'purpose' => '環境整備の記録を個人アカウントに結び付け、実施者・確認者・完了者を追跡できる状態にする。',
                'status' => Roadmap::STATUS_DRAFT,
                'improvements' => [
                    $this->improvement(
                        '環境整備システムを共通認証へ接続する',
                        '環境整備システムは個人運用の予定だが、ログイン基盤との接続が未実装。',
                        'hit_staffで認証されたスタッフだけが、自分の権限で環境整備システムを利用できる。',
                        '連携境界が不明確だと環境整備側にもパスワードが複製される。',
                        '環境整備側はstaff_idとシステム権限だけを受け取り、認証情報を保持しない構成がよい。',
                        '認証コールバック、ローカルセッション、権限チェックを実装する。',
                        '開発環境でログインから権限判定まで接続する。',
                        [
                            $this->task('環境整備システムの認証入口を実装する', '未ログイン時にhit_staffへ誘導し、認証後に安全に戻す。', Task::PRIORITY_URGENT),
                            $this->task('staff_idと所属店舗を環境整備側へ連携する', '個人と店舗を識別し、担当範囲を決められるようにする。', Task::PRIORITY_URGENT),
                            $this->task('環境整備の管理者・一般権限を実装する', '閲覧、実施、確認、設定変更などの操作をロールで制御する。', Task::PRIORITY_HIGH),
                        ]
                    ),
                    $this->improvement(
                        '個人単位の作業・確認履歴を残す',
                        '実施者や確認者を追跡できないと、環境整備の運用改善や責任範囲の確認が難しい。',
                        '各記録に実施者、確認者、完了者、日時、店舗が残り、退職後も履歴を確認できる。',
                        '現在の氏名だけを参照すると、氏名変更や退職後に履歴表示が崩れる可能性がある。',
                        'staff_idに加えて記録時点の氏名や所属をスナップショット保存すると監査性を保てる。',
                        '環境整備の操作履歴と表示を実装する。',
                        '必要な履歴項目を確定し、記録処理を作る。',
                        [
                            $this->task('環境整備記録の担当者項目を設計する', '作成者、実施者、確認者、完了者と各日時を定義する。', Task::PRIORITY_HIGH),
                            $this->task('氏名・所属のスナップショット保存を実装する', 'staff_idを主キーとして保持しつつ、記録当時の表示情報も残す。', Task::PRIORITY_NORMAL),
                            $this->task('操作履歴の閲覧画面を作成する', '誰がいつ何を変更・完了したかを管理者が確認できるようにする。', Task::PRIORITY_HIGH),
                        ]
                    ),
                ],
            ],
            [
                'title' => 'Phase 4：試験運用・安定化・将来拡張',
                'purpose' => '限定店舗で安全に試験運用し、日常運用を固めたうえで、hit_portalや将来の業務システムへ展開できる基盤にする。',
                'status' => Roadmap::STATUS_DRAFT,
                'improvements' => [
                    $this->improvement(
                        '限定店舗で試験運用する',
                        '実際の店舗運用で認証、権限、異動、退職対応が機能するか未検証。',
                        '限定店舗で問題なく利用でき、問い合わせ対応と障害時の復旧手順が整っている。',
                        '全店舗一斉導入では、権限設定やアカウント配布の問題を切り分けにくい。',
                        '少人数の試験運用で課題を集めれば、安全に全社展開できる。',
                        '対象店舗を決め、アカウント発行、利用確認、改善を行う。',
                        '試験店舗と参加スタッフを選定する。',
                        [
                            $this->task('試験運用の対象店舗・スタッフを決める', '管理者と一般スタッフを含む小さな利用グループを選ぶ。', Task::PRIORITY_HIGH),
                            $this->task('初期アカウント発行と利用案内を行う', '初回ログイン、パスワード変更、問い合わせ先を案内する。', Task::PRIORITY_HIGH),
                            $this->task('試験結果を確認して改善項目を登録する', 'ログイン失敗、権限不足、操作迷い、運用負荷を記録して次の改善につなげる。', Task::PRIORITY_HIGH),
                        ]
                    ),
                    $this->improvement(
                        '運用・バックアップ・障害対応を整備する',
                        'アカウント管理や障害時の責任者・復旧方法が未定義。',
                        '入退社、異動、パスワード再発行、バックアップ、障害対応を継続運用できる。',
                        '認証基盤の停止は連携する全システムへ影響する。',
                        '日常運用と復旧手順を先に整えれば、システム追加後の影響を抑えられる。',
                        '運用手順、バックアップ、監視、緊急時対応を整える。',
                        '運用担当とアカウント申請フローを決める。',
                        [
                            $this->task('入社・異動・退職のアカウント運用手順を作る', '申請者、承認者、実施者、実施期限を含む手順を文書化する。', Task::PRIORITY_HIGH),
                            $this->task('バックアップと復元手順を確認する', 'スタッフ・権限データのバックアップ頻度と復元テスト方法を決める。', Task::PRIORITY_HIGH),
                            $this->task('障害・不正アクセス時の対応手順を作る', '認証停止、セッション無効化、ログ確認、連絡先、復旧判断を整理する。', Task::PRIORITY_HIGH),
                        ]
                    ),
                    $this->improvement(
                        '次の業務システムへ展開できる連携仕様を整える',
                        '環境整備以外のシステムが増えた際の接続手順がまだない。',
                        '新しい業務システムを追加するとき、同じ安全基準で短時間に共通ログインへ接続できる。',
                        '環境整備専用の実装になると、システム追加のたびに設計を繰り返すことになる。',
                        '接続仕様とチェックリストを共通化すれば、hit_portalを含む将来連携が容易になる。',
                        '認証連携仕様、権限登録手順、検証項目を文書化する。',
                        '環境整備連携で得た知見を接続ガイドにまとめる。',
                        [
                            $this->task('新規システム向け認証連携ガイドを作る', '必要な設定、認証フロー、エラー処理、ログアウト、セキュリティ確認をまとめる。', Task::PRIORITY_NORMAL),
                            $this->task('システム追加・権限登録の管理手順を作る', 'systemsへの追加とロール定義、管理者承認の流れを決める。', Task::PRIORITY_NORMAL),
                            $this->task('hit_portal個人アカウント化の判断条件を整理する', '投稿者・完了者の記録など、店舗アカウントから移行する必要が生じる条件を明文化する。', Task::PRIORITY_LOW),
                        ]
                    ),
                ],
            ],
        ];
    }

    private function improvement(
        string $title,
        string $currentState,
        string $desiredState,
        string $problem,
        string $hypothesis,
        string $action,
        string $nextAction,
        array $tasks
    ): array {
        return [
            'title' => $title,
            'current_state' => $currentState,
            'desired_state' => $desiredState,
            'problem' => $problem,
            'hypothesis' => $hypothesis,
            'action' => $action,
            'next_action' => $nextAction,
            'status' => Improvement::STATUS_PLANNED,
            'tasks' => $tasks,
        ];
    }

    private function task(string $title, string $description, string $priority): array
    {
        return compact('title', 'description', 'priority');
    }
}
