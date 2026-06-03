<?php
declare(strict_types=1);

namespace Therum;

/**
 * File-backed JSON storage. No database. All state lives under DATA_DIR as
 * individual JSON files, atomically written via tmpfile + rename.
 *
 * Two access shapes:
 *   - get/set/delete  — keyed scalar/blob state (one JSON file per key)
 *   - list_collection/save_to_collection/delete_from_collection — slug-keyed
 *     records under a subdirectory (one file per record). Used for pages.
 *
 * Why this and not SQLite: keeps Pure dependency-free at this stage. SQLite
 * adapter lands when the standalone runtime grows enough to need it.
 */
final class Storage
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        if (!is_dir($this->root)) {
            mkdir($this->root, 0755, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->key_path($key);
        if (!is_file($path)) return $default;
        $raw = file_get_contents($path);
        if ($raw === false) return $default;
        $decoded = json_decode($raw, true);
        return $decoded === null && $raw !== 'null' ? $default : $decoded;
    }

    public function set(string $key, mixed $value): void
    {
        $this->atomic_write($this->key_path($key), $value);
    }

    public function delete(string $key): void
    {
        $path = $this->key_path($key);
        if (is_file($path)) @unlink($path);
    }

    public function has(string $key): bool
    {
        return is_file($this->key_path($key));
    }

    // ── Collections (subdirectory of slug-keyed records) ──────────────────

    public function list_collection(string $collection): array
    {
        $dir = $this->root . '/' . $this->sanitize_key($collection);
        if (!is_dir($dir)) return [];
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (!str_ends_with($entry, '.json')) continue;
            $slug = substr($entry, 0, -5);
            $raw = file_get_contents($dir . '/' . $entry);
            if ($raw === false) continue;
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) continue;
            $decoded['slug'] = $slug;
            $out[$slug] = $decoded;
        }
        return $out;
    }

    public function get_from_collection(string $collection, string $slug): ?array
    {
        $path = $this->collection_path($collection, $slug);
        if (!is_file($path)) return null;
        $raw = file_get_contents($path);
        $decoded = json_decode($raw ?: '', true);
        if (!is_array($decoded)) return null;
        $decoded['slug'] = $slug;
        return $decoded;
    }

    public function save_to_collection(string $collection, string $slug, array $record): void
    {
        $path = $this->collection_path($collection, $slug);
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        unset($record['slug']); // slug lives in filename
        $this->atomic_write($path, $record);
    }

    public function delete_from_collection(string $collection, string $slug): void
    {
        $path = $this->collection_path($collection, $slug);
        if (is_file($path)) @unlink($path);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function key_path(string $key): string
    {
        return $this->root . '/' . $this->sanitize_key($key) . '.json';
    }

    private function collection_path(string $collection, string $slug): string
    {
        return $this->root . '/' . $this->sanitize_key($collection) . '/' . $this->sanitize_key($slug) . '.json';
    }

    private function sanitize_key(string $key): string
    {
        // Allow lowercase letters, digits, hyphens. Replace anything else.
        $clean = preg_replace('/[^a-z0-9\-]+/', '-', strtolower($key));
        return trim($clean ?? '', '-') ?: 'unnamed';
    }

    private function atomic_write(string $path, mixed $value): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Storage: json_encode failed');
        }
        if (file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException('Storage: could not write ' . $tmp);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Storage: could not rename ' . $tmp . ' → ' . $path);
        }
    }
}
