<?php

namespace Database\Seeders;

use App\Models\Improvement;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RiseGateOsAiAssistantSeeder extends Seeder
{
    public function run(): void
    {
        $workspace = Workspace::query()->where('name', '社内WS')->first();

        if (! $workspace) {
            throw new RuntimeException('Workspace「社内WS」が見つかりません。');
        }

        $project = Project::query()
            ->where('owning_workspace_id', $workspace->id)
            ->where('name', 'RISE GATE OS')
            ->first();

        if (! $project) {
            throw new RuntimeException('社内WSのプロジェクト「RISE GATE OS」が見つかりません。');
        }

        $userId = $project->owner_user_id;

        DB::transaction(function () use ($workspace, $project, $userId): void {
            $roadmap = Roadmap::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'title' => 'AIアシスタントと本番環境を安全につなぐ',
                ],
                [
                    'organization_id' => $project->organization_id,
                    'workspace_id' => $workspace->id,
                    'purpose' => '計画の登録と進捗反映の手間をなくし、会話と実際の開発状況からロードマップ・取り組み・タスクを安全に作成・更新できるようにする。',
                    'status' => Roadmap::STATUS_ACTIVE,
                    'sort_order' => 0,
                    'created_by' => $userId,
                ]
            );

            foreach ($this->improvements() as $index => $data) {
                $improvement = Improvement::firstOrNew([
                    'project_id' => $project->id,
                    'title' => $data['title'],
                ]);
                $improvement->fill([
                        'organization_id' => $project->organization_id,
                        'workspace_id' => $workspace->id,
                        'roadmap_id' => $roadmap->id,
                        'roadmap_sort_order' => ($index + 1) * 10,
                        'current_state' => $data['current_state'],
                        'desired_state' => $data['desired_state'],
                        'problem' => $data['problem'],
                        'hypothesis' => $data['hypothesis'],
                        'action' => $data['action'],
                        'next_action' => $data['next_action'],
                        'visibility' => Improvement::VISIBILITY_INTERNAL,
                        'proposed_by' => $userId,
                        'assigned_to' => $userId,
                ]);
                $improvement->status ??= Improvement::STATUS_PLANNED;
                $improvement->save();

                if ($data['title'] === 'Codexから自然に操作できる専用連携を作る') {
                    Task::query()
                        ->where('improvement_id', $improvement->id)
                        ->where('title', '社内WSでE2E試験運用する')
                        ->delete();
                }

                foreach ($data['tasks'] as $task) {
                    $taskModel = Task::firstOrNew([
                        'improvement_id' => $improvement->id,
                        'title' => $task['title'],
                    ]);
                    $taskModel->fill([
                        'organization_id' => $project->organization_id,
                        'workspace_id' => $workspace->id,
                        'project_id' => $project->id,
                        'description' => $task['description'],
                        'priority' => $task['priority'],
                        'assigned_to' => $userId,
                        'created_by' => $userId,
                    ]);
                    $taskModel->status ??= Task::STATUS_TODO;
                    $taskModel->save();
                }
            }
        });
    }

    private function improvements(): array
    {
        return [
            [
                'title' => 'AI提案を承認待ちで受け取る仕組みを作る',
                'current_state' => 'ローカル環境ではAIが直接登録できるが、本番環境では同じ操作を安全に行う受付口がない。',
                'desired_state' => 'AIが作成した変更案を本番へ送り、既存データを変更せず承認待ちとして保存できる。',
                'problem' => '本番DBへの直接操作は危険であり、管理画面からの大量手入力は時間がかかる。',
                'hypothesis' => '提案データと本データを分け、限定APIから提案だけを登録すれば、安全性と操作の滑らかさを両立できる。',
                'action' => 'AI変更依頼、変更明細、API認証、重複防止の最小構成を実装する。',
                'next_action' => '提案データの形式と許可する最初の操作を確定する。',
                'tasks' => [
                    $this->task('AI提案データの形式を設計する', 'ロードマップ、取り組み、タスクの追加案を一つの依頼として表現し、検証エラーも保持できる構造を決める。', Task::PRIORITY_URGENT),
                    $this->task('承認待ち提案の保存機能を実装する', '提案本体と変更明細を本データから分離して保存し、受信・検証・承認待ちの状態を管理する。', Task::PRIORITY_URGENT),
                    $this->task('限定スコープのAI連携APIを実装する', 'APIキーごとにWorkspaceと許可操作を限定し、最初は提案作成だけを許可する。', Task::PRIORITY_URGENT),
                ],
            ],
            [
                'title' => '提案内容を確認して承認・反映できる画面を作る',
                'current_state' => 'AIの提案内容を本番画面で確認し、安全に反映する操作がない。',
                'desired_state' => '追加・変更される内容を事前に確認し、承認または却下できる。',
                'problem' => '内容と影響範囲が見えないまま反映すると、誤登録や意図しない更新を防げない。',
                'hypothesis' => '件数、対象、変更前後、警告をプレビューし、人の承認を必須にすれば安心して利用できる。',
                'action' => '承認待ち一覧、差分プレビュー、承認・却下、トランザクション反映を実装する。',
                'next_action' => '追加提案のプレビュー画面から実装する。',
                'tasks' => [
                    $this->task('承認待ち一覧と提案詳細画面を作る', '依頼者、対象Workspace、対象Project、件数、状態、受信日時を確認できるようにする。', Task::PRIORITY_HIGH),
                    $this->task('反映前の差分プレビューを作る', '追加・更新・対象不明・重複候補を区別し、本データへの影響を表示する。', Task::PRIORITY_URGENT),
                    $this->task('承認・却下・一括反映を実装する', '承認時のみトランザクション内で反映し、失敗時は本データを変更せず再試行可能にする。', Task::PRIORITY_URGENT),
                ],
            ],
            [
                'title' => 'Codexから自然に操作できる専用連携を作る',
                'current_state' => '計画登録だけでなく、開発後に進捗や完了をRise Gate OSへ反映し続ける作業も人の負担になっている。',
                'desired_state' => 'Codexが現在の計画と進捗を読み、実際の変更・テスト結果・会話内容を根拠に、登録と進捗更新の両方を承認待ちで提案できる。',
                'problem' => 'API仕様を毎回人が意識したり、作業後に同じ内容をOSへ転記したりすると、開発への集中が途切れる。',
                'hypothesis' => 'Rise Gate OS専用MCPに読み取りと根拠付き更新提案を持たせれば、自然さ、安全性、情報の鮮度を両立できる。',
                'action' => 'MCPの読み取り、登録提案、進捗更新提案、監査ログ、重複防止を整え、開発セッションを通した試験運用を行う。',
                'next_action' => '計画参照と進捗更新を含む最小MCPツールの入出力と権限境界を設計する。',
                'tasks' => [
                    $this->task('Rise Gate OS専用MCPの操作を設計する', 'Workspace・Projectの検索、計画と進捗の読み取り、登録提案、進捗更新提案、提案状態の確認を安全な操作として定義する。', Task::PRIORITY_URGENT),
                    $this->task('メンバー別AI接続と権限制御を実装する', 'AI接続をWorkspaceとメンバーに紐づけ、本人が参加しているProjectだけを読み取り・提案できるようにする。', Task::PRIORITY_URGENT),
                    $this->task('Workspace別AI利用同意と有効化を実装する', 'Workspace管理者が送信情報を確認してAI機能を有効化・停止でき、同意者・日時・規約版を記録する。', Task::PRIORITY_URGENT),
                    $this->task('Projectの計画と進捗を読み取るMCPツールを作る', 'ロードマップ、取り組み、タスク、担当、状態、優先度を取得し、開発開始時に現在地を把握できるようにする。', Task::PRIORITY_URGENT),
                    $this->task('根拠付きの進捗・完了更新提案を実装する', '変更ファイル、コミット、テスト結果、会話上の決定などの根拠を添え、状態変更とコメントを承認待ちで提案できるようにする。', Task::PRIORITY_URGENT),
                    $this->task('監査ログと二重登録防止を実装する', 'AI、利用者、APIキー、依頼ID、承認者、反映結果を記録し、同一依頼の再送を安全に扱う。', Task::PRIORITY_HIGH),
                    $this->task('社内WSで開発セッション全体をE2E試験する', '作業開始時の計画読取、開発、進捗提案、画面確認、承認、本データ反映までを一連で検証する。', Task::PRIORITY_HIGH),
                ],
            ],
        ];
    }

    private function task(string $title, string $description, string $priority): array
    {
        return compact('title', 'description', 'priority');
    }
}
