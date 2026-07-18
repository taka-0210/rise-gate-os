<?php

namespace App\Console\Commands;

use App\Models\AiAccessKey;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAiAccessKey extends Command
{
    protected $signature = 'ai:key:create {workspace : WorkspaceのID・slug・名前} {user? : 接続するメンバーのメールアドレス。省略時はWorkspace所有者} {--name=Codex} {--days=90}';
    protected $description = '承認待ちAI提案を作成するための限定APIキーを発行します';

    public function handle(): int
    {
        $workspaceValue = (string) $this->argument('workspace');
        $workspace = Workspace::query()
            ->where('id', ctype_digit($workspaceValue) ? (int) $workspaceValue : 0)
            ->orWhere('slug', $workspaceValue)
            ->orWhere('name', $workspaceValue)
            ->first();

        if (! $workspace) {
            $this->error('Workspaceが見つかりません。');
            return self::FAILURE;
        }

        $email = $this->argument('user');
        $user = $email
            ? $workspace->users()->where('email', (string) $email)->first()
            : $workspace->owner;
        if (! $user) {
            $this->error('このWorkspaceに所属するメンバーが見つかりません。');
            return self::FAILURE;
        }

        $days = max(1, min(365, (int) $this->option('days')));
        $plainToken = 'rgos_'.Str::random(64);

        AiAccessKey::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => (string) $this->option('name'),
            'token_hash' => hash('sha256', $plainToken),
            'scopes' => [AiAccessKey::SCOPE_PROJECTS_READ, AiAccessKey::SCOPE_PROPOSALS_CREATE],
            'expires_at' => now()->addDays($days),
            'created_by' => $workspace->owner_user_id,
        ]);

        $this->warn('このAPIキーは再表示できません。安全な場所へ保存してください。');
        $this->line($plainToken);
        $this->info("Workspace: {$workspace->name} / Member: {$user->name} / 有効期間: {$days}日");

        return self::SUCCESS;
    }
}
