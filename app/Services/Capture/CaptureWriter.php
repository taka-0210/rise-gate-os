<?php

namespace App\Services\Capture;

use App\Models\Capture;
use App\Models\CaptureActionRelation;
use App\Models\CaptureEvent;
use App\Models\CompanyNotification;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Notification\NotificationSourceWriter;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CaptureWriter
{
    public function __construct(private readonly CaptureAccess $access, private readonly NotificationSourceWriter $notifications, private readonly ProjectExecutionAccess $projects, private readonly ProjectExecutionWriter $projectWriter) {}

    public function create(User $actor, Organization $org, array $a): Capture
    {
        $op = (string) ($a['operation_id'] ?? '');
        $recipient = ($a['type'] ?? null) === Capture::TYPE_SELF ? $actor : User::query()->findOrFail((int) ($a['recipient_user_id'] ?? 0));
        $canonical = ['organization_id' => $org->id, 'actor_id' => $actor->id, 'recipient_id' => $recipient->id, 'type' => $a['type'] ?? null, 'body' => trim((string) ($a['body'] ?? '')), 'timing' => $a['notification_timing'] ?? 'now', 'notify_at' => $a['notify_at'] ?? null];
        $hash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $existing = Capture::query()->where('create_operation_id', $op)->first();
        if ($existing) {
            if (! hash_equals($existing->create_payload_hash, $hash)) {
                throw ValidationException::withMessages(['operation_id' => '同じ操作IDを異なる内容には使用できません。']);
            }

            return $existing;
        }
        if (! $this->access->canParticipate($actor, $org) || ! $this->access->canParticipate($recipient, $org)) {
            throw new AuthorizationException;
        }if (! in_array($canonical['type'], [Capture::TYPE_SELF, Capture::TYPE_REQUEST, Capture::TYPE_TELL_LATER], true)) {
            throw ValidationException::withMessages(['type' => '種別を選択してください。']);
        }if ($canonical['type'] !== Capture::TYPE_SELF && $recipient->id === $actor->id) {
            throw ValidationException::withMessages(['recipient_user_id' => '自分向けは「自分用」を選択してください。']);
        }if ($canonical['body'] === '' || mb_strlen($canonical['body']) > 4000) {
            throw ValidationException::withMessages(['body' => '預ける内容を入力してください。']);
        }if (! in_array($canonical['timing'], ['now', 'specified', 'next_window'], true)) {
            throw ValidationException::withMessages(['notification_timing' => '通知時刻を選択してください。']);
        }if ($canonical['timing'] === 'specified' && blank($canonical['notify_at'])) {
            throw ValidationException::withMessages(['notify_at' => '希望最早時刻を入力してください。']);
        }

        try {
            return DB::transaction(function () use ($actor, $org, $recipient, $canonical, $op, $hash) {
                $cm = $this->membership($org->id, $actor->id);
                $rm = $this->membership($org->id, $recipient->id);
                $at = $canonical['notify_at'] ? CarbonImmutable::parse($canonical['notify_at'], 'Asia/Tokyo')->utc() : null;
                $c = Capture::query()->create(['organization_id' => $org->id, 'creator_user_id' => $actor->id, 'recipient_user_id' => $recipient->id, 'type' => $canonical['type'], 'body' => $canonical['body'], 'notification_timing' => $canonical['timing'], 'notify_at_utc' => $at, 'status' => Capture::STATUS_OPEN, 'version' => 1, 'create_operation_id' => $op, 'create_payload_hash' => $hash, 'creator_access_epoch' => $cm->access_epoch, 'recipient_access_epoch' => $rm->access_epoch, 'creator_credential_generation' => $actor->credential_generation, 'recipient_credential_generation' => $recipient->credential_generation]);
                $this->event($c, $actor, 'created', $op, 1, ['type' => $c->type, 'timing' => $c->notification_timing]);
                $this->notifications->captureCreated($actor, $c);

                return $c;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $committed = Capture::query()->where('create_operation_id', $op)->first();
            if ($committed && hash_equals($committed->create_payload_hash, $hash)) {
                return $committed;
            }
            throw $exception;
        }
    }

    public function acknowledge(User $actor, Capture $c, string $op, int $v): Capture
    {
        return $this->transition($actor, $c, $op, $v, 'acknowledged', function (Capture $locked) use ($actor) {
            if ($actor->id !== $locked->recipient_user_id) {
                throw new AuthorizationException;
            }$x = ['acknowledged_at_utc' => now('UTC'), 'acknowledged_by_user_id' => $actor->id];
            if ($locked->type === Capture::TYPE_TELL_LATER) {
                $x += ['status' => Capture::STATUS_CLOSED, 'closed_at_utc' => now('UTC'), 'closed_by_user_id' => $actor->id];
            }

            return $x;
        });
    }

    public function close(User $actor, Capture $c, string $op, int $v): Capture
    {
        return $this->transition($actor, $c, $op, $v, 'closed', fn () => ['status' => Capture::STATUS_CLOSED, 'closed_at_utc' => now('UTC'), 'closed_by_user_id' => $actor->id]);
    }

    public function cancel(User $actor, Capture $c, string $op, int $v): Capture
    {
        if ($actor->id !== $c->creator_user_id) {
            throw new AuthorizationException;
        }

        return $this->transition($actor, $c, $op, $v, 'cancelled', fn () => ['status' => Capture::STATUS_CLOSED, 'cancelled_at_utc' => now('UTC'), 'cancelled_by_user_id' => $actor->id, 'closed_at_utc' => now('UTC'), 'closed_by_user_id' => $actor->id]);
    }

    public function promote(User $actor, Capture $capture, Project $project, array $a, int $captureVersion, int $projectVersion): Task
    {
        return DB::transaction(function () use ($actor, $capture, $project, $a, $captureVersion, $projectVersion) {
            $c = Capture::query()->with('recipient')->lockForUpdate()->findOrFail($capture->id);
            if (! $this->access->canRead($actor, $c) || $actor->id !== $c->creator_user_id) {
                throw new AuthorizationException;
            }
            $relation = CaptureActionRelation::query()->where('capture_id', $c->id)->first();
            if ($relation) {
                return $relation->task()->firstOrFail();
            }if ($c->status !== Capture::STATUS_OPEN) {
                throw new AuthorizationException;
            }if ($c->version !== $captureVersion) {
                throw ValidationException::withMessages(['version' => 'Captureが更新されています。']);
            }if ($project->organization_id !== $c->organization_id || ! $this->projects->canCreateAction($actor, $project) || ! $this->projects->canRead($c->recipient, $project) || ! $this->projects->isExecutionMember($c->recipient, $project)) {
                throw new AuthorizationException;
            }if (! filter_var($a['confirm_project_visibility'] ?? false, FILTER_VALIDATE_BOOL)) {
                throw ValidationException::withMessages(['confirm_project_visibility' => 'Projectの公開範囲への移動を確認してください。']);
            }$task = $this->projectWriter->createAction($actor, $project, ['title' => $a['title'] ?? '', 'description' => $c->body, 'done_condition' => $a['done_condition'] ?? '', 'assigned_to' => $c->recipient_user_id, 'reviewer_user_id' => $a['reviewer_user_id'] ?? null, 'due_date' => $a['due_date'] ?? null], $projectVersion);
            CaptureActionRelation::query()->create(['capture_id' => $c->id, 'project_id' => $project->id, 'task_id' => $task->id, 'promoted_by_user_id' => $actor->id, 'operation_id' => $a['operation_id']]);
            $c->update(['status' => Capture::STATUS_CONVERTED, 'converted_at_utc' => now('UTC'), 'converted_by_user_id' => $actor->id, 'version' => $c->version + 1]);
            $this->event($c, $actor, 'converted', $a['operation_id'], $c->version, ['project_id' => $project->id, 'task_id' => $task->id]);
            $this->cancelNotifications($c);

            return $task;
        }, 3);
    }

    private function transition(User $actor, Capture $capture, string $op, int $v, string $event, callable $changes): Capture
    {
        return DB::transaction(function () use ($actor, $capture, $op, $v, $event, $changes) {
            $prior = CaptureEvent::query()->where('operation_id', $op)->first();
            if ($prior) {
                if ($prior->capture_id !== $capture->id || $prior->event_type !== $event) {
                    throw ValidationException::withMessages(['operation_id' => '操作IDが別操作で使用されています。']);
                }

                return $capture->fresh();
            }$c = Capture::query()->lockForUpdate()->findOrFail($capture->id);
            if (! $this->access->canRead($actor, $c) || $c->status !== Capture::STATUS_OPEN) {
                throw new AuthorizationException;
            }if ($c->version !== $v) {
                throw ValidationException::withMessages(['version' => 'Captureが更新されています。']);
            }$c->update($changes($c) + ['version' => $c->version + 1]);
            $this->event($c, $actor, $event, $op, $c->version);
            if ($c->status !== Capture::STATUS_OPEN) {
                $this->cancelNotifications($c);
            }

            return $c;
        }, 3);
    }

    private function membership(int $org, int $user): OrganizationUser
    {
        return OrganizationUser::query()->lockForUpdate()->where('organization_id', $org)->where('user_id', $user)->where('membership_status', OrganizationUser::STATUS_ACTIVE)->firstOrFail();
    }

    private function event(Capture $c, User $actor, string $type, string $op, int $v, array $metadata = []): void
    {
        CaptureEvent::query()->create(['capture_id' => $c->id, 'actor_user_id' => $actor->id, 'event_type' => $type, 'operation_id' => $op, 'capture_version' => $v, 'metadata' => $metadata ?: null, 'occurred_at_utc' => now('UTC')]);
    }

    private function cancelNotifications(Capture $c): void
    {
        CompanyNotification::query()->where('source_type', 'Capture')->where('source_id', $c->id)->whereNull('cancelled_at_utc')->update(['cancelled_at_utc' => now('UTC')]);
    }
}
