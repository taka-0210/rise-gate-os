<?php

namespace App\Services\AiCommon;

use App\Contracts\AiCommonAttachmentInspector;
use App\Models\AiCommonAttachment;
use App\Models\AiCommonConversation;
use App\Models\Organization;
use App\Models\ProjectInternalNoteAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiCommonAttachmentIngestor
{
    public function __construct(
        private readonly AiCommonAttachmentWriter $writer,
        private readonly AiCommonAttachmentAccess $access,
        private readonly AiCommonAttachmentInspector $inspector,
    ) {}

    public function upload(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        UploadedFile $file,
        string $operationId,
    ): AiCommonAttachment {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'Uploadを完了できませんでした。']);
        }
        $binary = file_get_contents($file->getRealPath());
        if (! is_string($binary) || $binary === '') {
            throw ValidationException::withMessages(['file' => '空のFileは受け付けられません。']);
        }
        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));
        $attachment = $this->writer->reserveUpload($actor, $organization, $conversation, [
            'operation_id' => $operationId,
            'display_name' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => strlen($binary),
        ]);
        if ($attachment->state === AiCommonAttachment::STATE_READY) {
            if (! hash_equals((string) $attachment->sha256, hash('sha256', $binary))) {
                throw ValidationException::withMessages(['file' => '同じ操作IDへ異なるFileを送信できません。']);
            }

            return $attachment;
        }
        if (! in_array($attachment->state, [AiCommonAttachment::STATE_RECEIVING, AiCommonAttachment::STATE_QUARANTINE], true)) {
            throw ValidationException::withMessages(['file' => 'このUpload操作は再開できません。']);
        }

        $hash = hash('sha256', $binary);
        if ($attachment->sha256 !== null && ! hash_equals($attachment->sha256, $hash)) {
            throw ValidationException::withMessages(['file' => '同じ操作IDへ異なるFileを送信できません。']);
        }
        Storage::disk('ai_common_attachments')->put($attachment->storage_key, $binary);
        $attachment = DB::transaction(function () use ($actor, $organization, $conversation, $attachment, $hash): AiCommonAttachment {
            $locked = AiCommonAttachment::query()->lockForUpdate()->findOrFail($attachment->id);
            $this->access->authorizeConversation($actor, $organization, $conversation->fresh(), true);
            if ($locked->sha256 !== null && ! hash_equals($locked->sha256, $hash)) {
                throw ValidationException::withMessages(['file' => '保存済みFileの同一性を確認できません。']);
            }
            $locked->update([
                'state' => AiCommonAttachment::STATE_QUARANTINE,
                'sha256' => $hash,
                'inspection_status' => 'pending',
            ]);

            return $locked->fresh();
        }, 3);

        return $this->inspect($actor, $organization, $conversation, $attachment, $binary);
    }

    public function referenceExisting(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        ProjectInternalNoteAttachment $origin,
        string $operationId,
    ): AiCommonAttachment {
        $attachment = $this->writer->referenceExisting(
            $actor,
            $organization,
            $conversation,
            $origin,
            ['operation_id' => $operationId],
        );
        if ($attachment->state === AiCommonAttachment::STATE_READY) {
            return $attachment;
        }
        $authorized = $this->access->authorizeExistingOrigin($actor, $organization, $origin->public_id);
        $binary = Storage::disk('local')->get($authorized->stored_path);

        return $this->inspect($actor, $organization, $conversation, $attachment, $binary);
    }

    private function inspect(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonAttachment $attachment,
        string $binary,
    ): AiCommonAttachment {
        try {
            $result = $this->inspector->inspect($binary, [
                'extension' => (string) $attachment->extension,
                'mime_type' => (string) $attachment->mime_type,
                'size_bytes' => (int) $attachment->size_bytes,
                'variant' => (string) $attachment->variant,
            ]);
        } catch (AiCommonInspectionUnavailable $error) {
            $attachment->update([
                'inspection_status' => 'unavailable',
                'inspection_safe_code' => $error->safeCode,
            ]);

            return $attachment->fresh();
        } catch (Throwable) {
            $attachment->update([
                'state' => AiCommonAttachment::STATE_REJECTED,
                'inspection_status' => 'rejected',
                'inspection_safe_code' => 'inspection_rejected',
                'inspected_at_utc' => now('UTC'),
            ]);

            return $attachment->fresh();
        }

        return DB::transaction(function () use ($actor, $organization, $conversation, $attachment, $result): AiCommonAttachment {
            $locked = AiCommonAttachment::query()->lockForUpdate()->findOrFail($attachment->id);
            $this->access->authorizeConversation($actor, $organization, $conversation->fresh(), true);
            if ($locked->state !== AiCommonAttachment::STATE_QUARANTINE) {
                throw ValidationException::withMessages(['file' => 'Attachment状態が検査中に変更されました。']);
            }
            $locked->update([
                'state' => AiCommonAttachment::STATE_READY,
                'version' => $locked->version + 1,
                'inspection_status' => 'passed',
                'inspection_driver' => $result['driver'],
                'inspection_version' => $result['version'],
                'inspection_safe_code' => null,
                'media_codec' => $result['codec'] ?? null,
                'duration_ms' => $result['duration_ms'] ?? null,
                'inspected_at_utc' => now('UTC'),
                'ready_at_utc' => now('UTC'),
            ]);

            return $locked->fresh();
        }, 3);
    }
}
