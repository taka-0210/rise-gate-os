<?php

namespace App\Services\ActionExecution;

use App\Contracts\ActionDraftProvider;
use App\Models\AiAuditLog;
use App\Models\Task;
use App\Models\User;
use App\Services\AiProjectContextGuard;
use Illuminate\Validation\ValidationException;
use Throwable;

class ActionDraftAssistant
{
    public function __construct(private readonly AiProjectContextGuard $guard, private readonly ActionDraftProvider $provider) {}

    public function suggest(User $actor, Task $task, string $target, string $instruction): string
    {
        $allowed = $this->guard->allowedCategoriesForUser($actor, $task->project);
        if (! in_array('tasks', $allowed, true)) {
            throw ValidationException::withMessages(['ai_draft' => '文案生成に必要なData Categoryが許可されていません。']);
        }
        $payload = ['target' => $target, 'action_title' => $task->title, 'done_condition' => $task->done_condition, 'instruction' => $instruction];
        $started = hrtime(true);
        try {
            $draft = $this->provider->suggest($payload);
            $this->audit($actor, $task, true, $payload, $started);
            return $draft;
        } catch (Throwable $exception) {
            $this->audit($actor, $task, false, $payload, $started, $exception->getMessage());
            throw $exception;
        }
    }

    private function audit(User $actor, Task $task, bool $succeeded, array $payload, int $started, ?string $error = null): void
    {
        AiAuditLog::create(['workspace_id' => $task->workspace_id, 'user_id' => $actor->id, 'project_id' => $task->project_id,
            'event' => 'scope9.action_draft.suggested', 'tool_name' => 'action_draft', 'succeeded' => $succeeded,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'request_fingerprint' => hash('sha256', json_encode($payload)),
            'error_message' => $error, 'metadata' => ['payload_fields' => array_keys($payload), 'auto_saved' => false], 'occurred_at' => now()]);
    }
}
