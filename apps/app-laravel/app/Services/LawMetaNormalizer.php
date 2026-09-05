<?php

namespace App\Services;

class LawMetaNormalizer
{
    public static function legacyStatus(mixed $value): string
    {
        $status = trim((string) $value);

        if ($status === '') {
            return 'ร่าง';
        }

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

    /**
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    public static function parentDocumentIds(array $meta): array
    {
        $ids = [];
        if (is_array($meta['parent_document_ids'] ?? null)) {
            foreach ($meta['parent_document_ids'] as $entry) {
                $id = trim((string) $entry);
                if ($id !== '' && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        if ($ids === []) {
            $legacy = trim((string) ($meta['parent_document_id'] ?? ''));
            if ($legacy !== '') {
                $ids[] = $legacy;
            }
        }

        return $ids;
    }
}
