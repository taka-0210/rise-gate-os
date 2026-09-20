<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountAudit;
use App\Services\UserAvatarAccess;
use App\Services\UserAvatarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserAvatarController extends Controller
{
    public function update(Request $request, UserAvatarService $avatars, AccountAudit $audit): RedirectResponse
    {
        $validated = $request->validate(['avatar' => ['required', 'file', 'max:5120']]);
        $avatars->store($request->user(), $validated['avatar']);
        $audit->record('account.avatar.updated', 'success', $request->user(), $request->user());

        return back()->with('status', 'Avatarを更新しました。');
    }

    public function show(Request $request, User $user, UserAvatarAccess $access): StreamedResponse
    {
        abort_unless($user->avatar_path && $access->allows($request->user(), $user), 404);
        abort_unless(Storage::disk('local')->exists($user->avatar_path), 404);

        return Storage::disk('local')->response($user->avatar_path, null, [
            'Content-Type' => $user->avatar_mime ?: 'image/webp',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
