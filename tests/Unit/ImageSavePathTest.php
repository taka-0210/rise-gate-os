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
