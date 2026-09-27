<section class="co-panel" aria-labelledby="co-input-title">
    <h2 id="co-input-title">Conversation Input / Attachment</h2>
    <p>Human Messageの保存、Attachment追加、AIへの相談は別操作です。保存やUploadだけでAIへ送信しません。</p>

    @if($conversation->status === 'active')
        <form class="co-form-grid" method="post" enctype="multipart/form-data" action="{{ route('ai-common.attachments.store', $conversation) }}">
            @csrf
            <input type="hidden" name="operation_id" value="{{ old('operation_id', Str::uuid()) }}">
            <label class="wide">新しいFile（PDF / DOCX / XLSX / JPEG / PNG、10 MiBまで）
                <input type="file" name="file" required accept=".pdf,.docx,.xlsx,.jpg,.jpeg,.png,.mp3,.m4a,.mp4,.webm,.wav">
            </label>
            <button type="submit">非公開quarantineへ追加</button>
        </form>
        <form class="co-form-grid" method="post" action="{{ route('ai-common.attachments.reference', $conversation) }}">
            @csrf
            <input type="hidden" name="operation_id" value="{{ Str::uuid() }}">
            <label class="wide">既存のProject社内メモFile Public ID
                <input name="origin_public_id" maxlength="64" required>
            </label>
            <button type="submit">copyせず参照接続</button>
        </form>
    @endif

    <div class="co-input-list">
        @forelse($conversation->attachments as $attachment)
            <article class="co-input-row">
                <div><strong>{{ $attachment->display_name }}</strong><br><small>{{ $attachment->variant }} / {{ $attachment->state }} / inspection {{ $attachment->inspection_status }}</small></div>
                <div class="co-actions">
                    @if($attachment->isReadyForUse())
                        <a class="button secondary" href="{{ route('ai-common.attachments.download', [$conversation, $attachment]) }}">認証Download</a>
                        @if(in_array($attachment->extension, ['jpg','jpeg','png'], true))
                            <a class="button secondary" href="{{ route('ai-common.attachments.image-preview', [$conversation, $attachment]) }}">安全Image Preview</a>
                        @elseif(in_array($attachment->extension, ['mp3','m4a','mp4','webm','wav'], true))
                            <audio controls preload="none" src="{{ route('ai-common.attachments.playback', [$conversation, $attachment]) }}"></audio>
                            <form method="post" action="{{ route('ai-common.attachments.transcribe', [$conversation, $attachment]) }}">@csrf
                                <input type="hidden" name="operation_id" value="{{ Str::uuid() }}">
                                <label><input type="checkbox" name="transcription_consent" value="1" required> Provider transcriptionに明示同意</label>
                                <button type="submit">Transcript Revision作成</button>
                            </form>
                        @elseif(in_array($attachment->extension, ['pdf','docx','xlsx'], true))
                            <form method="post" action="{{ route('ai-common.attachments.extract', [$conversation, $attachment]) }}">@csrf
                                <input type="hidden" name="operation_id" value="{{ Str::uuid() }}">
                                @if($attachment->extension === 'pdf')
                                    <input type="number" min="1" name="page_from" value="1"><input type="number" min="1" name="page_to" value="1">
                                @elseif($attachment->extension === 'docx')
                                    <input type="number" min="1" name="paragraph_from" value="1"><input type="number" min="1" name="paragraph_to" value="1">
                                @else
                                    <input type="number" min="1" name="sheet_index" value="1"><input type="number" min="1" name="row_from" value="1"><input type="number" min="1" name="row_to" value="10">
                                @endif
                                <button type="submit">Bounded extraction</button>
                            </form>
                        @endif
                        <form method="post" action="{{ route('ai-common.attachments.ai-reference', [$conversation, $attachment]) }}">@csrf
                            <input type="hidden" name="operation_id" value="{{ Str::uuid() }}">
                            <input type="hidden" name="enabled" value="{{ $attachment->allows_ai_reference ? 0 : 1 }}">
                            <button type="submit">AI参照 {{ $attachment->allows_ai_reference ? 'OFF' : 'ON' }}</button>
                        </form>
                        @foreach($attachment->derivatives->where('state', 'ready') as $derivative)
                            <a class="button secondary" href="{{ route('ai-common.attachments.preview', [$conversation, $attachment, $derivative]) }}">安全Preview</a>
                            <small>Source: attachment_extract / {{ $derivative->public_id }}</small>
                        @endforeach
                        @foreach($attachment->transcriptRevisions as $revision)
                            <small>Transcript Revision {{ $revision->revision_number }} / Source: attachment_transcript / {{ $revision->public_id }}</small>
                            @if($conversation->status === 'active')
                                <form method="post" action="{{ route('ai-common.attachments.transcripts.revise', [$conversation, $attachment, $revision]) }}">@csrf
                                    <input type="hidden" name="operation_id" value="{{ Str::uuid() }}">
                                    <textarea name="content" maxlength="4000" required>{{ $revision->content }}</textarea>
                                    <button type="submit">Human Revision作成</button>
                                </form>
                            @endif
                        @endforeach
                    @endif
                    @if($attachment->state !== 'revoked')
                        <form method="post" action="{{ route('ai-common.attachments.revoke', [$conversation, $attachment]) }}">
                            @csrf
                            <input type="hidden" name="operation_id" value="{{ Str::uuid() }}">
                            <input type="hidden" name="reason" value="User requested revoke">
                            <button class="secondary" type="submit">利用取消</button>
                        </form>
                    @endif
                </div>
            </article>
        @empty
            <p>Attachmentはありません。</p>
        @endforelse
    </div>

    @if($conversation->status === 'active')
        <form class="co-form-grid" method="post" action="{{ route('ai-common.human-messages.store', $conversation) }}">
            @csrf
            <input type="hidden" name="operation_id" value="{{ Str::uuid() }}">
            <label class="wide">Human Message
                <textarea name="content" maxlength="4000" rows="4" required></textarea>
            </label>
            @foreach($conversation->attachments->filter(fn ($attachment) => $attachment->isReadyForUse()) as $attachment)
                <label><input type="checkbox" name="attachment_ids[]" value="{{ $attachment->id }}"> {{ $attachment->display_name }}</label>
            @endforeach
            <button type="submit">Human Messageとして保存（AI送信なし）</button>
        </form>
    @endif
</section>

<section class="co-panel" aria-labelledby="co-voice-title">
    <h2 id="co-voice-title">Voice Input</h2>
    <p>Temporary Audio → 文字起こし → 本人確認・編集 → Human Message。Audio Attachmentとは別です。</p>
    @if($conversation->status === 'active')
        <form class="co-form-grid" method="post" enctype="multipart/form-data" action="{{ route('ai-common.voice.store', $conversation) }}" data-voice-recorder>
            @csrf
            <input type="hidden" name="operation_id" value="{{ Str::uuid() }}">
            <label class="wide">Voice File（10 MiB・3分まで）
                <input data-voice-file type="file" name="voice" required accept="audio/mpeg,audio/mp4,audio/webm,audio/wav,.mp3,.m4a,.mp4,.webm,.wav">
            </label>
            <div class="co-actions wide">
                <button data-voice-start type="button" class="secondary">録音開始</button>
                <button data-voice-stop type="button" class="secondary" disabled>録音停止</button>
                <button data-voice-cancel type="button" class="secondary" disabled>録音取消</button>
            </div>
            <p class="wide" data-voice-status aria-live="polite">録音またはFile選択後も、自動送信・自動投稿しません。</p>
            <button type="submit">Temporary Voiceとして保存</button>
        </form>
    @endif

    @foreach($conversation->temporaryAudios as $audio)
        <article class="co-input-row">
            <div><strong>Temporary Voice</strong><br><small>{{ $audio->state }} / {{ number_format(($audio->duration_ms ?? 0) / 1000, 1) }}秒 / {{ $audio->expires_at_utc->timezone('Asia/Tokyo')->format('Y/m/d H:i') }} JSTまで</small></div>
            @if($conversation->status === 'active' && in_array($audio->state, ['recorded', 'failed'], true))
                <form method="post" action="{{ route('ai-common.voice.transcribe', [$conversation, $audio]) }}">
                    @csrf
                    <input type="hidden" name="operation_id" value="{{ Str::uuid() }}">
                    <label><input type="checkbox" name="transcription_consent" value="1" required> この音声を文字起こしProviderへ送信することに同意</label>
                    <button type="submit">明示的に文字起こし</button>
                </form>
            @endif
            @if($conversation->status === 'active' && $audio->state === 'draft')
                <form class="co-form-grid" method="post" action="{{ route('ai-common.voice.post', [$conversation, $audio]) }}">
                    @csrf
                    <input type="hidden" name="operation_id" value="{{ Str::uuid() }}">
                    <label class="wide">Transcriptを確認・編集
                        <textarea name="content" maxlength="4000" rows="4" required>{{ $audio->draft_text }}</textarea>
                    </label>
                    <button type="submit">Human Messageとして明示投稿</button>
                </form>
            @endif
            @if(!in_array($audio->state, ['posted', 'cancelled', 'expired'], true))
                <form method="post" action="{{ route('ai-common.voice.cancel', [$conversation, $audio]) }}">@csrf<button type="submit" class="secondary">取消・cleanup</button></form>
            @endif
        </article>
    @endforeach
</section>

<script defer src="{{ asset('js/ai-common-input.js') }}"></script>
