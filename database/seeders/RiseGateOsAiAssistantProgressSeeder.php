<?php

namespace Database\Seeders;

use App\Models\Improvement;
use App\Models\Task;
use App\Models\WorkspaceAiSetting;
use Illuminate\Database\Seeder;

class RiseGateOsAiAssistantProgressSeeder extends Seeder
{
    public function run(): void
    {
        $improvement = Improvement::query()
            ->where('title', 'AI提案を承認待ちで受け取る仕組みを作る')
            ->whereHas('project', fn ($query) => $query
                ->where('name', 'RISE GATE OS')
                ->whereHas('owningWorkspace', fn ($workspace) => $workspace->where('name', '社内WS')))
            ->firstOrFail();

        WorkspaceAiSetting::updateOrCreate(
            ['workspace_id' => $improvement->workspace_id],
            [
                'enabled' => true,
                'provider' => 'member_managed_ai',
                'allowed_data_categories' => WorkspaceAiSetting::DEFAULT_DATA_CATEGORIES,
                'terms_version' => WorkspaceAiSetting::TERMS_VERSION,
                'enabled_by' => $improvement->assigned_to,
                'enabled_at' => now(),
                'disabled_by' => null,
                'disabled_at' => null,
            ]
        );

        $improvement->update([
            'status' => Improvement::STATUS_IN_PROGRESS,
            'next_action' => '限定スコープのAI連携APIと、提案内容の検証処理を実装する。',
        ]);

        $this->complete($improvement, 'AI提案データの形式を設計する');
        $this->complete($improvement, '承認待ち提案の保存機能を実装する');
        $this->complete($improvement, '限定スコープのAI連携APIを実装する');
        $improvement->update([
            'status' => Improvement::STATUS_IMPLEMENTED,
            'implemented_by' => $improvement->assigned_to,
            'implemented_at' => now(),
            'completed_at' => now()->toDateString(),
            'result' => 'Workspace限定APIで承認待ち提案を受信し、本データと分離して保存できるようになった。',
            'next_action' => 'Codex専用連携から実際に提案を送る。',
        ]);

        $reviewImprovement = Improvement::query()
            ->where('project_id', $improvement->project_id)
            ->where('title', '提案内容を確認して承認・反映できる画面を作る')
            ->firstOrFail();

        $reviewImprovement->update([
            'status' => Improvement::STATUS_IMPLEMENTED,
            'implemented_by' => $reviewImprovement->assigned_to,
            'implemented_at' => now(),
            'completed_at' => now()->toDateString(),
            'result' => '追加・更新・検証エラーを表示し、承認・却下・階層データの一括反映ができるようになった。',
            'next_action' => '実際のAI提案を使ったE2E試験を行う。',
        ]);

        $this->complete($reviewImprovement, '承認待ち一覧と提案詳細画面を作る');
        $this->complete($reviewImprovement, '反映前の差分プレビューを作る');
        $this->complete($reviewImprovement, '承認・却下・一括反映を実装する');

        Improvement::query()
            ->where('project_id', $improvement->project_id)
            ->where('title', 'Codexから自然に操作できる専用連携を作る')
            ->update([
                'status' => Improvement::STATUS_IN_PROGRESS,
                'next_action' => 'Rise Gate OS専用MCPの操作と権限境界を設計する。',
            ]);

        Task::query()
            ->where('project_id', $improvement->project_id)
            ->where('title', 'Rise Gate OS専用MCPの操作を設計する')
            ->update(['status' => Task::STATUS_DONE, 'completed_at' => now()]);

        Task::query()
            ->where('project_id', $improvement->project_id)
            ->where('title', 'Projectの計画と進捗を読み取るMCPツールを作る')
            ->update(['status' => Task::STATUS_DONE, 'completed_at' => now()]);

        Task::query()
            ->where('project_id', $improvement->project_id)
            ->whereIn('title', ['メンバー別AI接続と権限制御を実装する', '根拠付きの進捗・完了更新提案を実装する'])
            ->update(['status' => Task::STATUS_DONE, 'completed_at' => now()]);

        Task::query()
            ->where('project_id', $improvement->project_id)
            ->where('title', 'Workspace別AI利用同意と有効化を実装する')
            ->update(['status' => Task::STATUS_DONE, 'completed_at' => now()]);

        Task::query()
            ->where('project_id', $improvement->project_id)
            ->where('title', '監査ログと二重登録防止を実装する')
            ->update(['status' => Task::STATUS_DONE, 'completed_at' => now()]);

        Task::query()
            ->where('project_id', $improvement->project_id)
            ->where('title', '社内WSで開発セッション全体をE2E試験する')
            ->update(['status' => Task::STATUS_IN_PROGRESS]);
    }

    private function complete(Improvement $improvement, string $title): void
    {
        $improvement->tasks()
            ->where('title', $title)
            ->update([
                'status' => Task::STATUS_DONE,
                'completed_at' => now(),
            ]);
    }
}
