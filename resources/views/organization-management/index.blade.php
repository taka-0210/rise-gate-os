@extends('layouts.app', ['title' => 'Organization設定 - Company OS'])

@section('content')
    <div class="stack organization-management">
        <section class="page-header">
            <div>
                <div class="meta">Company OS / ORGANIZATION</div>
                <h1>Organization設定</h1>
                <p>{{ $organization->name }} の所属、職務、Groupを管理します。</p>
            </div>
        </section>

        @if (session('success')) <div class="success">{{ session('success') }}</div> @endif
        @if ($errors->any())
            <div class="error">
                @foreach ($errors->all() as $error) <div>{{ $error }}</div> @endforeach
            </div>
        @endif

        <section class="panel stack">
            <div>
                <div class="meta">MEMBERSHIP</div>
                <h2>所属メンバー</h2>
                <p>Organization RoleはOrganization管理だけに使用します。Positionは表示情報で、Permissionを付与しません。</p>
            </div>

            <div class="organization-member-list">
                @foreach ($memberships as $membership)
                    <article class="card organization-member">
                        <div class="organization-member__identity">
                            <div>
                                <x-user-avatar :user='$membership->user' :size='40' />
                                <h3>{{ $membership->user->name }}</h3>
                                <p>{{ $membership->user->email }}</p>
                            </div>
                            <span class="badge">{{ \App\Models\OrganizationUser::membershipStatuses()[$membership->membership_status] ?? $membership->membership_status }}</span>
                        </div>

                        <div class="organization-member__forms">
                            <div class="stack compact-stack">
                                <div class="meta">ORGANIZATION ROLE</div>
                                @if ($canChangeRoles && $membership->membership_status === \App\Models\OrganizationUser::STATUS_ACTIVE && $membership->user->is_active)
                                    <form class="inline-form" method="POST" action="{{ route('organization-management.memberships.role', $membership) }}">
                                        @csrf
                                        @method('PUT')
                                        <select name="organization_role" aria-label="{{ $membership->user->name }} のOrganization Role">
                                            @foreach (\App\Models\OrganizationUser::organizationRoles() as $value => $label)
                                                <option value="{{ $value }}" @selected($membership->organization_role === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit">Roleを保存</button>
                                    </form>
                                @else
                                    <strong>{{ \App\Models\OrganizationUser::organizationRoles()[$membership->organization_role] ?? '未解決' }}</strong>
                                @endif
                            </div>

                            <div class="stack compact-stack">
                                <div class="meta">POSITION</div>
                                @if ($membership->membership_status === \App\Models\OrganizationUser::STATUS_ACTIVE && $membership->user->is_active)
                                    <form class="inline-form" method="POST" action="{{ route('organization-management.memberships.position', $membership) }}">
                                        @csrf
                                        @method('PUT')
                                        <input name="position" value="{{ $membership->position }}" maxlength="100" placeholder="例：営業部長" aria-label="{{ $membership->user->name }} のPosition">
                                        <button type="submit">Positionを保存</button>
                                    </form>
                                @else
                                    <span>{{ $membership->position ?: '未設定' }}</span>
                                @endif
                            </div>
                        </div>

                        <div>
                            <div class="meta">GROUP</div>
                            <p>{{ $membership->groups->whereNull('archived_at')->pluck('name')->join(' / ') ?: 'Groupなし' }}</p>
                        </div>

                        @include('organization-management.partials.membership-lifecycle', [
                            'actions' => $lifecycleActions[$membership->id] ?? [],
                        ])
                    </article>
                @endforeach
            </div>
        </section>

        <section class="panel stack">
            <div>
                <div class="meta">GROUP</div>
                <h2>Group</h2>
                <p>Groupは平坦で複数所属できます。Workspaceとは別概念で、Group所属だけではResource Permissionは増えません。</p>
            </div>

            <form class="inline-form organization-group-create" method="POST" action="{{ route('organization-management.groups.store') }}">
                @csrf
                <input name="name" required maxlength="100" placeholder="Group名" aria-label="新しいGroup名">
                <button type="submit">Groupを作成</button>
            </form>

            <div class="organization-group-grid">
                @forelse ($groups as $group)
                    <article class="card stack organization-group-card">
                        <div class="organization-member__identity">
                            <div>
                                <div class="meta">{{ $group->archived_at ? 'ARCHIVED GROUP' : 'ACTIVE GROUP' }}</div>
                                <h3>{{ $group->name }}</h3>
                            </div>
                            <span class="badge">{{ $group->memberships->count() }}名</span>
                        </div>

                        @if (! $group->archived_at)
                            <form class="inline-form" method="POST" action="{{ route('organization-management.groups.update', $group) }}">
                                @csrf
                                @method('PUT')
                                <input name="name" value="{{ $group->name }}" required maxlength="100" aria-label="Group名">
                                <button type="submit">改名</button>
                            </form>

                            <form class="inline-form" method="POST" action="{{ route('organization-management.groups.members.store', $group) }}">
                                @csrf
                                <select name="organization_user_id" required aria-label="追加するメンバー">
                                    <option value="">メンバーを選択</option>
                                    @foreach ($memberships->where('membership_status', \App\Models\OrganizationUser::STATUS_ACTIVE) as $membership)
                                        @if ($membership->user->is_active)
                                            <option value="{{ $membership->id }}">{{ $membership->user->name }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                <button type="submit">所属を追加</button>
                            </form>

                            <div class="stack compact-stack">
                                @forelse ($group->memberships as $groupMembership)
                                    <div class="organization-group-member">
                                        <span>{{ $groupMembership->organizationMembership->user->name }}</span>
                                        <form method="POST" action="{{ route('organization-management.groups.members.destroy', [$group, $groupMembership->organizationMembership]) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="secondary" type="submit">解除</button>
                                        </form>
                                    </div>
                                @empty
                                    <p class="meta">所属メンバーはいません。</p>
                                @endforelse
                            </div>

                            <form method="POST" action="{{ route('organization-management.groups.archive', $group) }}">
                                @csrf
                                @method('DELETE')
                                <button class="secondary" type="submit" @disabled($group->memberships->isNotEmpty())>空のGroupを保管</button>
                            </form>
                        @else
                            <p class="meta">{{ $group->archived_at->timezone('Asia/Tokyo')->format('Y/m/d H:i') }} JST に保管。履歴として保持します。</p>
                        @endif
                    </article>
                @empty
                    <p class="meta">Groupはまだありません。Groupなしでも利用できます。</p>
                @endforelse
            </div>
        </section>
    </div>

    @include('organization-management.partials.staff-invitations')

    <style>
        .organization-member-list, .organization-group-grid { display:grid; gap:14px; }
        .organization-member { display:grid; gap:16px; }
        .organization-member__identity, .organization-group-member { display:flex; align-items:center; justify-content:space-between; gap:12px; }
        .organization-member__forms { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
        .inline-form { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .inline-form input, .inline-form select { flex:1 1 180px; min-width:0; }
        .compact-stack { gap:8px; }
        .organization-group-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .organization-member__lifecycle { border-top:1px solid var(--line); padding-top:14px; }
        .organization-lifecycle-actions { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
        @media (max-width:700px) {
            .organization-member__forms, .organization-group-grid, .organization-lifecycle-actions { grid-template-columns:1fr; }
            .inline-form { align-items:stretch; flex-direction:column; }
            .inline-form button, .inline-form input, .inline-form select { width:100%; flex:0 0 auto; }
            .organization-group-member { align-items:flex-start; }
        }
    </style>
@endsection
