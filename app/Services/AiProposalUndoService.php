<?php

namespace App\Services;

use App\Models\AiProposal;
use App\Models\AiProposalItem;
use App\Models\AiProposalUndo;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiProposalUndoService
{
    public function __construct(private readonly AiProposalAuthorization $authorization) {}

    public function undo(AiProposal $proposal, User $actor): AiProposalUndo
    {
        if ($proposal->status !== AiProposal::STATUS_APPLIED) {
            throw ValidationException::withMessages(['undo' => '適用済みの提案だけを元に戻せます。']);
        }
        $this->authorization->authorize($actor, $proposal->project, $proposal);
        if ($proposal->items()->where('operation', AiProposalItem::OPERATION_CREATE)->exists()) {
            throw ValidationException::withMessages(['undo' => '新規作成を含む提案はScope 1のUndo対象外です。']);
        }
        if ($proposal->undos()->where('status', AiProposalUndo::STATUS_APPLIED)->exists()) {
            return $proposal->undos()->where('status', AiProposalUndo::STATUS_APPLIED)->latest('id')->firstOrFail();
        }

        $record = $proposal->undos()->create(['actor_id' => $actor->id, 'status' => AiProposalUndo::STATUS_PROCESSING]);
        try {
            DB::transaction(function () use ($proposal, $actor, $record): void {
                $locked = AiProposal::query()->lockForUpdate()->with(['project', 'items'])->findOrFail($proposal->id);
                if ($locked->status !== AiProposal::STATUS_APPLIED) {
                    throw ValidationException::withMessages(['undo' => '適用済みの提案だけを元に戻せます。']);
                }
                $this->authorization->authorize($actor, $locked->project, $locked);
                $project = $locked->project()->lockForUpdate()->firstOrFail();
                if ($locked->undos()->where('status', AiProposalUndo::STATUS_APPLIED)->where('id', '!=', $record->id)->exists()) {
                    throw ValidationException::withMessages(['undo' => 'この変更はすでに元へ戻されています。']);
                }

                $targets = [];
                $restoreAttributes = [];
                foreach ($locked->items->sortBy([['entity_type', 'asc'], ['applied_entity_public_id', 'asc'], ['id', 'asc']]) as $item) {
                    $model = $this->target($project, $item);
                    $current = $model ? AiProposalContract::snapshot($model, $item->entity_type) : [];
                    $expectedCurrent = array_replace($item->before ?? [], $item->after ?? []);
                    if (! $model
                        || (int) $model->plan_version !== (int) $item->applied_version
                        || $current !== $expectedCurrent) {
                        throw ValidationException::withMessages(['undo' => '適用後に変更された対象があるため上書き復元しません。現在状態から新しい提案を作成してください。']);
                    }
                    $targets[$item->id] = $model;
                    $restoreAttributes[$item->id] = Arr::only($item->before ?? [], array_keys($item->after ?? []));
                }

                foreach ($locked->items->sortBy([['sort_order', 'asc'], ['id', 'asc']]) as $item) {
                    $targets[$item->id]->update($restoreAttributes[$item->id]);
                }
                $record->update([
                    'status' => AiProposalUndo::STATUS_APPLIED,
                    'result' => ['restored_items_count' => count($targets)],
                    'error_code' => null,
                    'error_message' => null,
                ]);
            }, 3);
        } catch (Throwable $error) {
            [$errorCode, $message] = match (true) {
                $error instanceof ValidationException => ['conflict', $error->validator->errors()->first('undo') ?: '現在の状態では元に戻せません。'],
                $error instanceof AuthorizationException => ['permission_denied', '現在の権限では元に戻せません。'],
                $error instanceof ModelNotFoundException => ['target_not_found', '復元対象を安全に確認できませんでした。'],
                $error instanceof QueryException => ['temporary_database_error', '一時的な障害で復元できませんでした。'],
                default => ['internal_error', '内部処理を完了できませんでした。'],
            };
            $record->update([
                'status' => AiProposalUndo::STATUS_FAILED,
                'error_code' => $errorCode,
                'error_message' => $message,
            ]);
            throw $error;
        }

        return $record->fresh();
    }

    private function target($project, AiProposalItem $item): ?Model
    {
        if ($item->entity_type === 'project') {
            return $project;
        }

        $query = match ($item->entity_type) {
            'roadmap' => $project->roadmaps(),
            'improvement' => $project->improvements(),
            'task' => $project->tasks(),
            default => null,
        };

        return $query?->lockForUpdate()->where('public_id', $item->applied_entity_public_id)->first();
    }
}
