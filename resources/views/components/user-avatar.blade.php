@props(['user', 'size' => 40])
<span class='user-avatar' style='width:{{ (int) $size }}px;height:{{ (int) $size }}px' title='{{ $user->name }}'>
    @if ($user->avatar_path)
        <img src='{{ route('users.avatar', $user) }}' alt='{{ $user->name }}' width='{{ (int) $size }}' height='{{ (int) $size }}'>
    @else
        <span aria-hidden='true'>{{ mb_strtoupper(mb_substr(trim($user->name), 0, 1)) }}</span>
    @endif
</span>
