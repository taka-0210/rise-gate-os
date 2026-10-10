@php
    $meetingOrganization = request()->attributes->get('currentCompany');
    $meetingShared = $conversation->conversation_kind === 'shared';
    $meetingPolicy = \App\Models\OrganizationAiPolicy::query()->where('organization_id', $meetingOrganization->id)->first();
    $meetingEnabled = $meetingPolicy?->allows('common_entry') && $meetingPolicy?->allows('company_management');
    $meetingDocuments = app(\App\Services\AiCommon\AiCommonManagementContext::class)->catalogue(auth()->user(), $meetingOrganization);
    $meetingSources = [];
    foreach ($conversation->sources()->whereIn('resource_type', \App\Services\AiCommon\AiCommonManagementContext::TYPES)->with('currentRevision')->get() as $selection) {
        if (! $selection->currentRevision) { continue; }
        try {
            $source = $meetingShared
                ? app(\App\Services\AiCommon\AiCommonSharedContext::class)->authorizeRevision(auth()->user(), $meetingOrganization, $selection->currentRevision)
                : app(\App\Services\AiCommon\AiCommonSourceManifest::class)->authorizeRevision(auth()->user(), $meetingOrganization, $selection->currentRevision);
            $meetingSources[] = ['selection' => $selection, 'data' => $source['data']];
        } catch (\Illuminate\Auth\Access\AuthorizationException|\Illuminate\Validation\ValidationException|\Illuminate\Database\Eloquent\ModelNotFoundException $error) {
            // Never project stale or unauthorized document text into the room.
        }
    }
@endphp
<section id="co-text-meeting" class="{{ $meetingShared ? 'shared-card' : 'co-panel' }}" style="overflow-wrap:anywhere">
<h2>会議で参照する経営指針</h2>
<p>部署ごとに会話を作成し、対象年度・部署の正式方針を選んでください。読めることとAIへ渡せることは別です。共有会話では参加者全員の権限が必要です。</p>
<p>共通入口：{{ $meetingPolicy?->allows('common_entry') ? 'ON' : 'OFF' }} ／ 会社の経営指針：{{ $meetingPolicy?->allows('company_management') ? 'ON' : 'OFF' }}。OFFの場合はOwnerにAI Policyの設定を依頼してください。</p>
@foreach($meetingDocuments as $document)
<form method="post" action="{{ route($meetingShared ? 'ai-common.shared.sources.store' : 'ai-common.sources.store', $conversation) }}">@csrf
<input type="hidden" name="resource_type" value="{{ $document['type'] }}"><input type="hidden" name="resource_public_id" value="{{ $document['id'] }}">
<input type="hidden" name="selection_reason" value="会議で正式方針との整合を確認">
<p>{{ $document['label'] }} ／ {{ $document['state_label'] }} ／ 文書AI許可：{{ $document['allows_ai_reference'] ? 'ON' : 'OFF' }}</p>
@if($conversation->status === 'active')<button type="submit" @disabled(!$meetingEnabled || !$document['allows_ai_reference'])>参照に選択／最新版を再選択</button>@endif
</form>
@endforeach
@foreach($meetingSources as $source)
<p>{{ $source['data']['title'] }} ／ {{ $source['data']['period']['organization_name'] ?? '' }} ／ {{ $source['data']['content']['group_name_at_approval'] ?? '' }} ／ Revision {{ $source['data']['revision_no'] }} <a href="{{ $source['data']['source_url'] }}">出典を確認</a></p>
@endforeach
<p>許可や正式版が変わると再選択が必要です。本文は上限超過時に切り捨てず停止します。</p>
@if($meetingShared && $conversation->status === 'active')
<form method="post" action="{{ route('ai-common.shared.co-requests.store', $conversation) }}">@csrf
<input type="hidden" name="operation_id" value="{{ Str::uuid() }}">
<label>今回COへ渡す正式方針</label>
@foreach($meetingSources as $source)<label style="display:block"><input style="width:auto" type="checkbox" name="source_ids[]" value="{{ $source['selection']->id }}" checked> {{ $source['data']['title'] }} ／ Revision {{ $source['data']['revision_no'] }}</label>@endforeach
<label>議論・決定済み事項・未決事項のメモ<textarea name="content" rows="5" maxlength="4000" required placeholder="長い会議では、決定済み事項と未決事項の最新メモも入力してください。"></textarea></label>
<p>正式方針はチェックしたものを毎回再確認して送信します。過去の発言すべてを記憶する保証はありません。最終判断とProject／Action登録は人間が行います。</p>
<button type="submit">COに論点整理を相談する（テキスト）</button>
</form>
@endif
</section>
