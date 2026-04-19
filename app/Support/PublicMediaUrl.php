<?php

namespace App\Support;

class PublicMediaUrl
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $storagePath = self::extractStoragePath($value);
        if ($storagePath === null) {
            return $value;
        }

        return url('storage/' . $storagePath);
    }

    public static function normalizeMany(?array $values): array
    {
        return array_values(array_filter(array_map(
            static fn ($value) => is_string($value) ? self::normalize($value) : null,
            $values ?? []
        )));
    }

    public static function fromStoragePath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim(str_replace('\\', '/', $path), '/');

        return $path === '' ? null : '/storage/' . $path;
    }

    public static function toStoredValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $storagePath = self::extractStoragePath($value);
        if ($storagePath === null) {
            return $value;
        }

        return self::fromStoragePath($storagePath);
    }

    private static function extractStoragePath(string $value): ?string
    {
        $path = parse_url($value, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $value;
        $path = str_replace('\\', '/', $path);

        $marker = '/storage/';
        $position = stripos($path, $marker);

        if ($position === false) {
            return null;
        }

        $storagePath = trim(substr($path, $position + strlen($marker)), '/');

        return $storagePath === '' ? null : $storagePath;
    }
}