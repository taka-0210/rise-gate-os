@if (! empty($actions))
    <div class='stack compact-stack organization-member__lifecycle'>
        <div class='meta'>MEMBERSHIP LIFECYCLE</div>
        <p class='meta'>一時停止は管理者が再開できます。所属終了はScope 5では再開できません。</p>
        <p class='meta'>停止・退職にしてもRole、Position、Group、Workspace、Project、過去履歴は削除されません。</p>
        <p class='meta'>この操作は対象Organizationだけに作用し、本人のAccount・Password・Email・他Organizationの利用には影響しません。停止・退職時は対象OrganizationのAI Keyと該当する招待を失効します。</p>
        @if (array_key_exists(\App\Services\Organization\OrganizationMembershipLifecycle::COMMAND_RESUME, $actions))
            <p class='notice'>再開すると、その時点で残っている有効なRelationとPermissionが再び利用可能になります。失効済みのAI Keyや招待Tokenは復活しないため、必要な場合は現在の権限で再発行・新規招待してください。</p>
        @endif
        <div class='organization-lifecycle-actions'>
            @foreach ($actions as $command => $requestId)
                @php
                    $label = match ($command) {
                        \App\Services\Organization\OrganizationMembershipLifecycle::COMMAND_SUSPEND => '一時停止',
                        \App\Services\Organization\OrganizationMembershipLifecycle::COMMAND_END => '退職・所属終了',
                        \App\Services\Organization\OrganizationMembershipLifecycle::COMMAND_RESUME => '所属を再開',
                    };
                    $isEnd = $command === \App\Services\Organization\OrganizationMembershipLifecycle::COMMAND_END;
                    $isResume = $command === \App\Services\Organization\OrganizationMembershipLifecycle::COMMAND_RESUME;
                @endphp
                <form class='stack compact-stack' method='POST' action='{{ route('organization-management.memberships.lifecycle', $membership) }}' @if ($isEnd) onsubmit='return confirm(&quot;所属終了後はScope 5の画面から再開できません。実行しますか？&quot;)' @endif>
                    @csrf
                    @method('PATCH')
                    <input type='hidden' name='command' value='{{ $command }}'>
                    <input type='hidden' name='expected_version' value='{{ $membership->lifecycle_version }}'>
                    <input type='hidden' name='request_id' value='{{ $requestId }}'>
                    <label>
                        {{ $label }}する理由
                        <textarea name='reason' required maxlength='500' rows='2' placeholder='本人と管理者が後から確認できる理由を入力'></textarea>
                    </label>
                    <button class='{{ $isResume ? '' : 'secondary' }}' type='submit'>{{ $label }}</button>
                </form>
            @endforeach
        </div>
    </div>
@endif
