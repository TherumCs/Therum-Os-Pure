<?php
declare(strict_types=1);

namespace Therum;

/**
 * Page model. Each page is a JSON file under data/pages/{slug}.json:
 *
 *   { "title": "...", "body": "...html...", "created_at": ..., "updated_at": ... }
 *
 * Slugs are URL-safe and unique. The collection name lives in Storage as
 * `pages` (i.e. files under data/pages/). Pure's frontend mounts them at
 * `/page/{slug}`. A single `home` slug, when present, also takes over `/`.
 */
final class Pages
{
    public const COLLECTION = 'pages';

    public static function list(): array
    {
        $pages = t_app()->storage->list_collection(self::COLLECTION);
        // Sort by updated_at desc.
        uasort($pages, fn($a, $b) => ($b['updated_at'] ?? 0) <=> ($a['updated_at'] ?? 0));
        return $pages;
    }

    public static function get(string $slug): ?array
    {
        return t_app()->storage->get_from_collection(self::COLLECTION, $slug);
    }

    public static function save(string $slug, string $title, string $body, ?array $existing = null): array
    {
        $now = time();
        $record = [
            'title'      => $title,
            'body'       => $body,
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
        ];
        t_app()->storage->save_to_collection(self::COLLECTION, $slug, $record);
        $record['slug'] = $slug;
        return $record;
    }

    public static function delete(string $slug): void
    {
        t_app()->storage->delete_from_collection(self::COLLECTION, $slug);
    }

    public static function slugify(string $input): string
    {
        $s = strtolower(trim($input));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-') ?: 'page-' . bin2hex(random_bytes(3));
    }
}
