<?php

namespace App\Services\Release;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class R0BundleHash
{
    /** @param list<string> $excludedPrefixes */
    public static function tree(string $root, array $excludedPrefixes = []): string
    {
        $root = realpath($root) ?: $root;
        if (! is_dir($root)) {
            throw new RuntimeException('R0 bundle hash root is unavailable.');
        }

        $normalizedExclusions = array_map(
            static fn (string $prefix): string => trim(str_replace('\\', '/', $prefix), '/'),
            $excludedPrefixes,
        );
        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() && ! $file->isLink()) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            if (self::isExcluded($relative, $normalizedExclusions)) {
                continue;
            }

            if ($file->isLink()) {
                $target = readlink($file->getPathname());
                if (! is_string($target)) {
                    throw new RuntimeException('R0 bundle symlink hash failed.');
                }
                $hash = hash('sha256', 'symlink'.chr(0).str_replace('\\', '/', $target));
            } else {
                $hash = hash_file('sha256', $file->getPathname());
                if ($hash === false) {
                    throw new RuntimeException('R0 bundle file hash failed.');
                }
            }
            $entries[$relative] = $hash;
        }

        ksort($entries);

        return hash('sha256', implode('', array_map(
            static fn (string $path, string $hash): string => $path."\0".$hash."\n",
            array_keys($entries),
            array_values($entries),
        )));
    }

    /** @param list<string> $excludedPrefixes */
    private static function isExcluded(string $relative, array $excludedPrefixes): bool
    {
        foreach ($excludedPrefixes as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
