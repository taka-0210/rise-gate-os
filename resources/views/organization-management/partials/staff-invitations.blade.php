<section class='panel stack'>
    <div>
        <div class='meta'>STANDARD WORKSPACE</div>
        <h2>通常Staffの開始先</h2>
        @if ($standardWorkspace)
            <p><strong>{{ $standardWorkspace->name }}</strong> を明示的な標準Workspaceとして使用します。</p>
        @else
            <p>標準Workspaceは未設定です。既存Workspaceを推測せず、Ownerの明示操作で空の共有Workspaceを1件作成します。</p>
            @if ($actorMembership->organization_role === \App\Models\OrganizationUser::ORGANIZATION_ROLE_OWNER)
                <form method='POST' action='{{ route('organization-management.standard-workspace.store') }}'>
                    @csrf
                    <button type='submit'>空の標準Workspaceを初期設定</button>
                </form>
            @endif
        @endif
    </div>
</section>

<section class='panel stack'>
    <div><div class='meta'>INVITATION HISTORY</div><h2>招待状況</h2></div>
    <div class='stack'>
        @forelse ($invitations as $invitation)
            @php($canManageInvitation = $actorMembership->organization_role === \App\Models\OrganizationUser::ORGANIZATION_ROLE_OWNER || $invitation->intended_organization_role === \App\Models\OrganizationUser::ORGANIZATION_ROLE_MEMBER)
            <article class='card stack'>
                <div class='organization-member__identity'>
                    <div><strong>{{ $invitation->normalized_email }}</strong><div class='meta'>{{ strtoupper($invitation->status) }} / {{ strtoupper($invitation->delivery_status) }}</div></div>
                    <span class='badge'>{{ \App\Models\OrganizationUser::organizationRoles()[$invitation->intended_organization_role] }}</span>
                </div>
                <p>Group: {{ $invitation->groups->pluck('name')->join(' / ') ?: 'Groupなし' }}</p>
                <p class='meta'>期限 {{ $invitation->expires_at->timezone('Asia/Tokyo')->format('Y/m/d H:i') }} JST / Generation {{ $invitation->token_generation }}</p>
                @if ($invitation->status === \App\Models\OrganizationInvitation::STATUS_PENDING && $canManageInvitation)
                    <div class='actions'>
                        <form method='POST' action='{{ route('organization-management.invitations.resend', $invitation) }}'>
                            @csrf<input type='hidden' name='request_id' value='{{ \Illuminate\Support\Str::uuid() }}'><button class='secondary' type='submit'>再送</button>
                        </form>
                        <form method='POST' action='{{ route('organization-management.invitations.revoke', $invitation) }}'>
                            @csrf @method('DELETE')<input type='hidden' name='request_id' value='{{ \Illuminate\Support\Str::uuid() }}'><button class='secondary' type='submit'>取消</button>
                        </form>
                    </div>
                @endif
            </article>
        @empty
            <p class='meta'>招待履歴はありません。</p>
        @endforelse
    </div>
</section>

<section class='panel stack'>
    <div><div class='meta'>STAFF INVITATION</div><h2>Staffを招待</h2></div>
    <form class='stack' method='POST' action='{{ route('organization-management.invitations.store') }}'>
        @csrf
        <input type='hidden' name='request_id' value='{{ \Illuminate\Support\Str::uuid() }}'>
        <div class='field'><label for='invitation-email'>Email</label><input id='invitation-email' name='email' type='email' required maxlength='255' value='{{ old('email') }}'></div>
        @if ($actorMembership->organization_role === \App\Models\OrganizationUser::ORGANIZATION_ROLE_OWNER)
            <div class='field'>
                <label for='invitation-role'>Organization Role</label>
                <select id='invitation-role' name='organization_role'>
                    @foreach (\App\Models\OrganizationUser::organizationRoles() as $value => $label)
                        <option value='{{ $value }}' @selected(old('organization_role', 'member') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @else
            <p class='meta'>Adminの招待はMember固定です。Roleの入力・付与操作は公開されません。</p>
        @endif
        <fieldset class='card stack'>
            <legend><strong>Group（任意・複数可）</strong></legend>
            @forelse ($groups->whereNull('archived_at') as $group)
                <label><input style='width:auto' type='checkbox' name='group_ids[]' value='{{ $group->id }}' @checked(in_array($group->id, old('group_ids', [])))> {{ $group->name }}</label>
            @empty
                <p class='meta'>選択できるGroupはありません。Groupなしで招待できます。</p>
            @endforelse
        </fieldset>
        <button type='submit'>招待Mailを送る</button>
    </form>
</section>
