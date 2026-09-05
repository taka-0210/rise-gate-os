<?php

namespace Tests\Unit;

use App\Services\ImageSavePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ImageSavePathTest extends TestCase
{
    public function test_japanese_nested_path_is_preserved(): void
    {
        $this->assertSame('クライアントA/デザイン案/第1案.png', ImageSavePath::normalize('クライアントA\\デザイン案\\第1案.png'));
    }

    #[DataProvider('unsafePaths')]
    public function test_invalid_or_protected_path_is_rejected(string $path): void
    {
        $this->expectException(RuntimeException::class);
        ImageSavePath::normalize($path);
    }

    public function test_suggested_filename_is_safe_and_preserves_japanese(): void
    {
        $this->assertSame('お団子中身見えるパターン.png', ImageSavePath::suggestedName('お団子中身見えるパターン.png'));
        foreach (['../団子/中身.png', 'CON.png', '<団子>?.png', str_repeat('団', 300)] as $name) {
            $suggestion = ImageSavePath::suggestedName($name);
            $this->assertSame('画像/'.$suggestion, ImageSavePath::normalize('画像/'.$suggestion));
            $this->assertLessThanOrEqual(68, mb_strlen($suggestion));
        }
    }

    public function test_legacy_generated_image_uses_description_and_saved_path_has_priority(): void
    {
        $message = new \App\Models\AiChatMessage([
            'image_name' => 'generated-1234-abcd.png', 'content' => 'お団子の中身が見える案',
        ]);
        $this->assertSame('画像/お団子の中身が見える案.png', $message->suggestedImagePath());
        $message->image_save = ['path' => '採用案/団子.png', 'status' => 'saved'];
        $this->assertSame('採用案/団子.png', $message->suggestedImagePath());
    }

    public static function unsafePaths(): array
    {
        return array_map(fn (string $path): array => [$path], [
            '../outside.png', '/outside.png', 'C:\\outside.png', 'images/../outside.png',
            '.git/image.png', 'images/.env/image.png', 'vendor/image.png', 'storage/app/image.png',
            'images//a.png', 'images/a.svg', 'images/CON.png', 'images/a?.png', 'images /a.png',
            'images./a.png', str_repeat('a', 240).'.png',
        ]);
    }
}
