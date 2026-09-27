<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonAttachment;
use App\Models\AiCommonConversation;
use App\Models\Organization;
use App\Models\ProjectInternalNoteAttachment;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AiCommonAttachmentAccess
{
    public function __construct(
        private readonly AiCommonAccess $common,
        private readonly ProjectExecutionAccess $projects,
    ) {}

    public function authorizeConversation(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        bool $requireActive = false,
    ): void {
        $this->common->authorizeConversation($actor, $organization, $conversation, $requireActive);
    }

    public function authorizeAttachment(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonAttachment $attachment,
        bool $requireReady = false,
    ): void {
        $this->authorizeConversation($actor, $organization, $conversation);
        if ($attachment->organization_id !== $organization->id
            || $attachment->ai_common_conversation_id !== $conversation->id
            || $attachment->revoked_at_utc !== null
            || $attachment->state === AiCommonAttachment::STATE_REVOKED) {
            throw new AuthorizationException;
        }
        if ($requireReady && ! $attachment->isReadyForUse()) {
            throw ValidationException::withMessages(['attachments' => '利用可能になっていないAttachmentは投稿できません。']);
        }
        if ($attachment->variant === AiCommonAttachment::VARIANT_EXISTING) {
            $this->authorizeExistingOrigin($actor, $organization, $attachment->origin_public_id);
            if (! hash_equals((string) $attachment->origin_sha256, (string) $this->existingOrigin(
                $attachment->origin_public_id
            )->sha256)) {
                throw ValidationException::withMessages(['attachments' => '参照元Fileの同一性を確認できません。']);
            }
        } elseif ($attachment->variant === AiCommonAttachment::VARIANT_UPLOAD) {
            $disk = Storage::disk('ai_common_attachments');
            if (! $attachment->storage_key || ! $disk->exists($attachment->storage_key)) {
                throw ValidationException::withMessages(['attachments' => '保存済みFileを利用できません。']);
            }
            $actualHash = hash('sha256', $disk->get($attachment->storage_key));
            if (! $attachment->sha256 || ! hash_equals($attachment->sha256, $actualHash)) {
                throw ValidationException::withMessages(['attachments' => '保存済みFileの同一性を確認できません。']);
            }
        }
    }

    public function authorizeExistingOrigin(
        User $actor,
        Organization $organization,
        ?string $originPublicId,
    ): ProjectInternalNoteAttachment {
        $this->common->authorizeOrganization($actor, $organization);
        $origin = $this->existingOrigin($originPublicId);
        $origin->loadMissing('note.project');
        $project = $origin->note?->project;
        if (! $project || $project->organization_id !== $organization->id
            || $origin->project_id !== $project->id
            || $origin->project_internal_note_id !== $origin->note->id) {
            throw new AuthorizationException;
        }
        $membership = $this->projects->activeExplicitMember($actor, $project);
        if (! $membership || $membership->project_role === ProjectMember::ROLE_CLIENT) {
            throw new AuthorizationException;
        }
        $disk = Storage::disk('local');
        if (! $disk->exists($origin->stored_path)) {
            throw ValidationException::withMessages(['origin' => '参照元Fileを利用できません。']);
        }
        $actualHash = hash_file('sha256', $disk->path($origin->stored_path));
        if (! is_string($actualHash) || ! hash_equals((string) $origin->sha256, $actualHash)) {
            throw ValidationException::withMessages(['origin' => '参照元Fileの同一性を確認できません。']);
        }

        return $origin;
    }

    public function binary(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonAttachment $attachment,
    ): string {
        $this->authorizeAttachment($actor, $organization, $conversation, $attachment, true);
        $binary = $attachment->variant === AiCommonAttachment::VARIANT_EXISTING
            ? Storage::disk('local')->get($this->existingOrigin($attachment->origin_public_id)->stored_path)
            : Storage::disk('ai_common_attachments')->get($attachment->storage_key);
        if (! hash_equals((string) $attachment->sha256, hash('sha256', $binary))) {
            throw ValidationException::withMessages(['attachments' => 'Attachment integrity verification failed.']);
        }

        return $binary;
    }

    private function existingOrigin(?string $publicId): ProjectInternalNoteAttachment
    {
        if (! $publicId) {
            throw new AuthorizationException;
        }

        return ProjectInternalNoteAttachment::query()->where('public_id', $publicId)->firstOrFail();
    }
}
