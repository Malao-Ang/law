<?php

namespace App\Services;

class LawMetaNormalizer
{
    public static function legacyStatus(mixed $value): string
    {
        $status = trim((string) $value);

        return $status === 'ยกเลิก' ? 'ยกเลิกการใช้งาน' : $status;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function effectiveVisibility(array $meta): string
    {
        $accessScope = ($meta['access_scope'] ?? 'public') === 'private' ? 'private' : 'public';

        return $accessScope === 'private' ? 'restricted' : 'public';
    }
}
