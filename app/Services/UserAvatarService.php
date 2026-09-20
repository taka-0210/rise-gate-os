<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserAvatarService
{
    public function store(User $user, UploadedFile $file): void
    {
        if (! $file->isValid() || $file->getSize() > ((int) config('invitation.avatar.max_kilobytes') * 1024)) {
            throw ValidationException::withMessages(['avatar' => '画像は5MB以下のPNG / JPEG / WebPを選択してください。']);
        }

        $imageInfo = @getimagesize($file->getPathname());
        $allowed = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'];
        if (! is_array($imageInfo) || ! isset($allowed[$imageInfo[2]])
            || ($imageInfo[0] * $imageInfo[1]) > (int) config('invitation.avatar.max_pixels')) {
            throw ValidationException::withMessages(['avatar' => '画像形式または画素数を確認してください。']);
        }

        $bytes = file_get_contents($file->getPathname());
        $source = $bytes === false ? false : @imagecreatefromstring($bytes);
        if ($source === false) {
            throw ValidationException::withMessages(['avatar' => '画像を安全に読み込めませんでした。']);
        }

        $max = (int) config('invitation.avatar.max_dimension');
        $scale = min(1, $max / max($imageInfo[0], $imageInfo[1]));
        $width = max(1, (int) round($imageInfo[0] * $scale));
        $height = max(1, (int) round($imageInfo[1] * $scale));
        $output = imagecreatetruecolor($width, $height);
        imagealphablending($output, false);
        imagesavealpha($output, true);
        $transparent = imagecolorallocatealpha($output, 0, 0, 0, 127);
        imagefilledrectangle($output, 0, 0, $width, $height, $transparent);
        imagecopyresampled($output, $source, 0, 0, 0, 0, $width, $height, $imageInfo[0], $imageInfo[1]);

        $temporary = tmpfile();
        $metadata = $temporary ? stream_get_meta_data($temporary) : null;
        if (! $temporary || ! is_array($metadata) || ! imagewebp($output, $metadata['uri'], 85)) {
            imagedestroy($source);
            imagedestroy($output);
            if (is_resource($temporary)) {
                fclose($temporary);
            }
            throw ValidationException::withMessages(['avatar' => '画像を保存用形式へ変換できませんでした。']);
        }
        $encoded = file_get_contents($metadata['uri']);
        fclose($temporary);
        imagedestroy($source);
        imagedestroy($output);
        if ($encoded === false) {
            throw ValidationException::withMessages(['avatar' => '画像を保存できませんでした。']);
        }

        $path = 'avatars/'.$user->id.'/'.Str::ulid().'.webp';
        Storage::disk('local')->put($path, $encoded);
        $user->forceFill([
            'avatar_path' => $path,
            'avatar_mime' => 'image/webp',
            'avatar_width' => $width,
            'avatar_height' => $height,
            'avatar_updated_at' => now(),
        ])->save();
    }
}
