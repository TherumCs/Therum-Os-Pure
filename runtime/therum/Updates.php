<?php
declare(strict_types=1);

namespace Therum;

/**
 * Therum OS Pure — auto-updater.
 *
 * Polls https://api.github.com/repos/{owner}/{repo}/releases/latest for the
 * configured repo (default TherumCs/Therum-Os-Pure), compares the tagged
 * version against bundle.json's version, and applies the new release via
 * atomic-rename + restore-on-failure.
 *
 * Mirror of the Unlocked channel in therum-updates.php, scaled down for
 * Pure: no WordPress filesystem layer, no transients — file-backed cache
 * via Storage, ZipArchive directly.
 *
 * Routes (registered in index.php):
 *   GET  /admin/updates    status panel
 *   POST /admin/updates/check    force-refresh release cache
 *   POST /admin/updates/apply    download + swap
 *
 * Release asset shape: the Pure ZIP from `./build.sh pure` ships index.php
 * + bundle.json + therum/ at root. Apply replaces the entire bundle root
 * — bundle.json is updated too, so the version reads correctly post-apply.
 */
final class Updates
{
    /** Cache TTL for the GitHub release lookup (file-backed). */
    private const CACHE_KEY  = 'updates_gh_cache';
    private const CACHE_TTL  = 6 * 60 * 60; // 6h
    private const NEGATIVE_TTL = 15 * 60;   // 15m for "no release" / errors

    public static function repo(): string
    {
        // Allow per-install override via env var or constant. Defaults to
        // the canonical Pure repo.
        if (defined('THERUM_GITHUB_REPO_PURE')) return (string) constant('THERUM_GITHUB_REPO_PURE');
        if ($env = getenv('THERUM_GITHUB_REPO_PURE')) return $env;
        return 'TherumCs/Therum-Os-Pure';
    }

    public static function current_version(): string
    {
        $bundle = t_app()->bundle_root . '/bundle.json';
        if (!is_file($bundle)) return '0.0.0';
        $data = json_decode((string) file_get_contents($bundle), true);
        return (string) ($data['version'] ?? '0.0.0');
    }

    /**
     * Latest release shape — or null if API failed / no releases.
     *
     * Returns: [
     *   'version' => '1.9.15',
     *   'tag'     => 'v1.9.15',
     *   'name'    => '…',
     *   'body'    => 'release notes markdown',
     *   'published_at' => '2026-…',
     *   'html_url'     => 'https://github.com/…/releases/tag/v1.9.15',
     *   'zip_url'      => 'https://github.com/.../Therum-OS-Pure-1.9.15.zip',
     *   'asset_name'   => 'Therum-OS-Pure-1.9.15.zip',
     *   'fetched_at'   => 1750000000,
     * ]
     */
    public static function latest_release(bool $force = false): ?array
    {
        $storage = t_app()->storage;
        if (!$force) {
            $cached = $storage->get(self::CACHE_KEY);
            if (is_array($cached)) {
                $age = time() - (int) ($cached['fetched_at'] ?? 0);
                $hit = $cached['hit'] ?? null;
                $ttl = $hit ? self::CACHE_TTL : self::NEGATIVE_TTL;
                if ($age < $ttl) {
                    return $hit ?: null;
                }
            }
        }

        $url = 'https://api.github.com/repos/' . self::repo() . '/releases/latest';
        $body = self::http_get_json($url);
        if (!is_array($body) || empty($body['tag_name'])) {
            $storage->set(self::CACHE_KEY, ['hit' => null, 'fetched_at' => time()]);
            return null;
        }

        $tag     = (string) $body['tag_name'];
        $version = ltrim($tag, 'vV');
        $zip_url = '';
        $asset_n = '';
        foreach ((array) ($body['assets'] ?? []) as $a) {
            $name = (string) ($a['name'] ?? '');
            // Prefer a Pure-shaped asset if multiple were uploaded.
            if (str_ends_with($name, '.zip') && stripos($name, 'Pure') !== false) {
                $zip_url = (string) ($a['browser_download_url'] ?? '');
                $asset_n = $name;
                break;
            }
        }
        // Fall back to the first .zip asset, then to the source zipball.
        if (!$zip_url) {
            foreach ((array) ($body['assets'] ?? []) as $a) {
                $name = (string) ($a['name'] ?? '');
                if (str_ends_with($name, '.zip')) {
                    $zip_url = (string) ($a['browser_download_url'] ?? '');
                    $asset_n = $name;
                    break;
                }
            }
        }
        if (!$zip_url) {
            $zip_url = (string) ($body['zipball_url'] ?? '');
            $asset_n = $tag . '.zip (source)';
        }

        $hit = [
            'version'      => $version,
            'tag'          => $tag,
            'name'         => (string) ($body['name'] ?? $tag),
            'body'         => (string) ($body['body'] ?? ''),
            'published_at' => (string) ($body['published_at'] ?? ''),
            'html_url'     => (string) ($body['html_url'] ?? ''),
            'zip_url'      => $zip_url,
            'asset_name'   => $asset_n,
        ];
        $storage->set(self::CACHE_KEY, ['hit' => $hit, 'fetched_at' => time()]);
        return $hit;
    }

    public static function has_update(): bool
    {
        $latest = self::latest_release();
        if (!$latest) return false;
        return version_compare($latest['version'], self::current_version(), '>');
    }

    /**
     * Download the release zip and swap the runtime in place.
     *
     * Strategy: stage the new bundle in a sibling directory, atomically
     * rename the live `therum/` dir to `therum.bak.{ts}/`, move staged
     * `therum/` into place. On any failure, restore the backup.
     *
     * Returns the apply summary on success; throws on failure.
     */
    public static function apply(): array
    {
        $latest = self::latest_release(true);
        if (!$latest || empty($latest['zip_url'])) {
            throw new \RuntimeException('No release available to apply.');
        }

        $root = t_app()->bundle_root;
        $ts   = date('Ymd-His');
        $tmp_zip = sys_get_temp_dir() . '/therum-pure-update-' . bin2hex(random_bytes(4)) . '.zip';
        $stage   = sys_get_temp_dir() . '/therum-pure-stage-' . bin2hex(random_bytes(4));
        $backup  = $root . '/therum.bak.' . $ts;

        // 1. Download
        if (!self::download($latest['zip_url'], $tmp_zip)) {
            throw new \RuntimeException('Download failed from ' . $latest['zip_url']);
        }

        // 2. Extract to staging dir
        if (!class_exists('ZipArchive')) {
            @unlink($tmp_zip);
            throw new \RuntimeException('ZipArchive extension missing — install php-zip on this host.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmp_zip) !== true) {
            @unlink($tmp_zip);
            throw new \RuntimeException('Could not open downloaded zip.');
        }
        mkdir($stage, 0755, true);
        $zip->extractTo($stage);
        $zip->close();
        @unlink($tmp_zip);

        // GitHub's source zipball wraps in {repo}-{branch}/. Detect + unwrap.
        $stage_root = self::flatten_single_dir($stage);

        // The Pure release asset puts therum/ + index.php + bundle.json at
        // root. Validate by checking for therum/ — if missing, abort before
        // we touch the live install.
        if (!is_dir($stage_root . '/therum')) {
            self::rrmdir($stage);
            throw new \RuntimeException('Release zip does not have therum/ at the root — wrong asset shape.');
        }

        // 3. Atomic swap of therum/
        $live = $root . '/therum';
        $new  = $stage_root . '/therum';

        // Preserve the live data/ directory across the swap — that's the
        // user's site state, not part of the released code.
        $data_src = $live . '/data';
        $data_dst = $new . '/data';
        if (is_dir($data_src)) {
            if (is_dir($data_dst)) self::rrmdir($data_dst);
            if (!rename($data_src, $data_dst)) {
                self::rrmdir($stage);
                throw new \RuntimeException('Could not preserve data/ — aborting.');
            }
        }

        if (!rename($live, $backup)) {
            self::rrmdir($stage);
            throw new \RuntimeException('Could not back up live therum/ to ' . $backup);
        }
        if (!rename($new, $live)) {
            // Restore: put the backup back.
            @rename($backup, $live);
            self::rrmdir($stage);
            throw new \RuntimeException('Could not move new therum/ into place — restored from backup.');
        }

        // 4. Replace bundle.json + index.php at the root if the release shipped them.
        $touched = [];
        foreach (['bundle.json', 'index.php'] as $f) {
            $src = $stage_root . '/' . $f;
            if (is_file($src)) {
                $dst = $root . '/' . $f;
                if (is_file($dst)) copy($dst, $dst . '.bak.' . $ts);
                copy($src, $dst);
                $touched[] = $f;
            }
        }

        // 5. Clean up the staging tree
        self::rrmdir($stage);

        // Invalidate the release cache so the next status check refetches.
        t_app()->storage->delete(self::CACHE_KEY);

        return [
            'from'        => self::current_version(),  // reads new bundle.json
            'to'          => $latest['version'],
            'backup_path' => $backup,
            'touched'     => $touched,
            'applied_at'  => time(),
        ];
    }

    // ── Admin views ───────────────────────────────────────────────────────

    public static function status_view(?string $flash = null, ?string $error = null): string
    {
        $current = self::current_version();
        $latest  = self::latest_release();
        $repo    = self::repo();
        $h       = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);

        $banner = '';
        if ($error) $banner = '<div class="t-err">' . $h($error) . '</div>';
        elseif ($flash) $banner = '<div class="t-ok">' . $h($flash) . '</div>';

        $body = '';
        if ($latest === null) {
            $body = <<<HTML
<div class="t-card" style="display:block;padding:24px">
  <strong>Couldn't reach GitHub.</strong>
  <p class="t-muted">Network down, rate-limited, or the repo has no releases yet. Negative cached for 15 min.</p>
  <form method="post" action="/admin/updates/check" style="margin-top:14px"><button class="t-btn">Try again</button></form>
</div>
HTML;
        } else {
            $newer  = version_compare($latest['version'], $current, '>');
            $status = $newer ? 'UPDATE AVAILABLE' : 'UP TO DATE';
            $color  = $newer ? 'var(--ok)' : 'var(--text-3)';
            $notes  = $h($latest['body']) ?: '<em class="t-muted">No release notes.</em>';
            $apply_btn = $newer
                ? '<form method="post" action="/admin/updates/apply" onsubmit="return confirm(\'Replace the live therum/ directory? A backup will be taken automatically.\')"><button class="t-btn t-btn-primary">Apply ' . $h($latest['version']) . '</button></form>'
                : '';
            $body = <<<HTML
<div class="t-cards">
  <div class="t-card" style="display:block">
    <div class="t-card-label">Installed</div>
    <div class="t-card-num">{$h($current)}</div>
  </div>
  <div class="t-card" style="display:block">
    <div class="t-card-label">Latest on GitHub</div>
    <div class="t-card-num" style="color:{$color}">{$h($latest['version'])}</div>
    <div class="t-muted" style="font-size:11px;margin-top:6px;font-weight:600;letter-spacing:.06em">{$status}</div>
  </div>
</div>
<div style="margin-top:24px;display:flex;gap:10px;align-items:center">
  {$apply_btn}
  <form method="post" action="/admin/updates/check" class="t-inline-form"><button class="t-btn">Refresh</button></form>
  <a class="t-link-muted" href="{$h($latest['html_url'])}" target="_blank" rel="noopener">View on GitHub ↗</a>
</div>
<h3 style="margin-top:32px">Release notes</h3>
<pre style="background:var(--surface-2);padding:18px;border-radius:8px;white-space:pre-wrap;font:13px/1.55 -apple-system,system-ui,sans-serif">{$notes}</pre>
HTML;
        }

        $upload_panel = <<<HTML
<h3 style="margin-top:36px">Drop a ZIP</h3>
<p class="t-muted" style="margin:6px 0 14px;font-size:13px">Apply a Pure ZIP from disk — useful for offline installs, fork builds, or rolling back from a known-good local copy. Same backup-and-swap flow as the GitHub channel.</p>
<form method="post" action="/admin/updates/upload" enctype="multipart/form-data" onsubmit="return confirm('Apply this ZIP? The live therum/ directory will be backed up first.')" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
  <input type="file" name="zip" accept=".zip,application/zip" required style="flex:1;min-width:260px;padding:10px 12px;border:1px dashed var(--border-2);border-radius:8px;background:var(--surface-2);font:13px/1 inherit;color:var(--text);">
  <button type="submit" class="t-btn t-btn-primary">Apply ZIP</button>
</form>
<p class="t-muted" style="margin:12px 0 0;font-size:12px;line-height:1.6">Accepts a Pure release ZIP with <code>therum/</code> at root (single-dir GitHub wrap auto-detected). Max 50 MB. Live <code>therum/data/</code> is preserved across the swap. Backups land at <code>therum.bak.{timestamp}/</code> beside the live runtime — rename it back if anything goes wrong.</p>
HTML;

        return Admin::layout('Updates', <<<HTML
<div class="t-page-head">
  <h1>Updates</h1>
  <span class="t-muted" style="font-family:ui-monospace,monospace;font-size:12px">{$h($repo)}</span>
</div>
{$banner}
{$body}
{$upload_panel}
HTML);
    }

    public static function handle_check(): string
    {
        self::latest_release(true);
        header('Location: /admin/updates');
        exit;
    }

    public static function handle_apply(): string
    {
        try {
            $result = self::apply();
            return self::status_view('Applied ' . $result['from'] . ' → ' . $result['to'] . ' · backup at ' . basename($result['backup_path']));
        } catch (\Throwable $e) {
            return self::status_view(null, 'Apply failed: ' . $e->getMessage());
        }
    }

    /**
     * POST /admin/updates/upload — drop a ZIP from disk.
     *
     * Validates the upload, moves it out of PHP's tmpdir into a stable
     * staging path, extracts, locates the Pure tree inside (handles patch
     * shape, full-bundle shape, and GitHub source-zipball wrap), then runs
     * the same atomic-rename swap as apply().
     */
    public static function handle_upload(): string
    {
        try {
            if (empty($_FILES['zip']) || !is_array($_FILES['zip'])) {
                throw new \RuntimeException('No file uploaded.');
            }
            $file = $_FILES['zip'];
            if (!empty($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Upload error code ' . (int) $file['error'] . '.');
            }
            if (($file['size'] ?? 0) < 100) {
                throw new \RuntimeException('File is too small to be a Pure bundle.');
            }
            if (($file['size'] ?? 0) > 50 * 1024 * 1024) {
                throw new \RuntimeException('File exceeds the 50 MB cap.');
            }
            $name = basename((string) ($file['name'] ?? ''));
            if (!preg_match('/\.zip$/i', $name)) {
                throw new \RuntimeException('Expected a .zip file.');
            }

            $dest = sys_get_temp_dir() . '/therum-pure-uploaded-' . bin2hex(random_bytes(4)) . '.zip';
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                throw new \RuntimeException('Could not move the uploaded file out of PHP\'s tmpdir.');
            }
            try {
                $result = self::apply_from_zip_file($dest, 'upload');
            } finally {
                @unlink($dest);
            }
            return self::status_view('Applied uploaded ZIP · was ' . $result['from'] . ' → now ' . $result['to'] . ' · backup at ' . basename($result['backup_path']));
        } catch (\Throwable $e) {
            return self::status_view(null, 'Upload failed: ' . $e->getMessage());
        }
    }

    /**
     * Apply a ZIP that's already on disk locally. Shared between the
     * GitHub-channel apply() and the upload-channel handle_upload(). The
     * label is purely for the audit record.
     */
    private static function apply_from_zip_file(string $zip_path, string $label): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('ZipArchive extension missing — install php-zip on this host.');
        }
        if (!is_readable($zip_path)) {
            throw new \RuntimeException('Zip not readable: ' . $zip_path);
        }

        $root  = t_app()->bundle_root;
        $ts    = date('Ymd-His');
        $stage = sys_get_temp_dir() . '/therum-pure-stage-' . bin2hex(random_bytes(4));
        $backup = $root . '/therum.bak.' . $ts;

        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            throw new \RuntimeException('Could not open zip.');
        }
        mkdir($stage, 0755, true);
        $zip->extractTo($stage);
        $zip->close();

        // Locate the Pure tree inside the staged extract. Accepts patch
        // shape (therum/ at root), full-bundle shape, or a single-dir wrap.
        $stage_root = self::find_pure_source($stage);
        if (!$stage_root) {
            self::rrmdir($stage);
            throw new \RuntimeException('Uploaded zip does not look like a Pure bundle — therum/ not found within 4 levels.');
        }

        $live = $root . '/therum';
        $new  = $stage_root . '/therum';

        // Preserve the live data/ directory across the swap.
        $data_src = $live . '/data';
        $data_dst = $new . '/data';
        if (is_dir($data_src)) {
            if (is_dir($data_dst)) self::rrmdir($data_dst);
            if (!rename($data_src, $data_dst)) {
                self::rrmdir($stage);
                throw new \RuntimeException('Could not preserve data/ — aborting.');
            }
        }

        if (!rename($live, $backup)) {
            self::rrmdir($stage);
            throw new \RuntimeException('Could not back up live therum/ to ' . $backup);
        }
        if (!rename($new, $live)) {
            @rename($backup, $live);
            self::rrmdir($stage);
            throw new \RuntimeException('Could not move new therum/ into place — restored from backup.');
        }

        // Replace bundle.json + index.php if present in the upload.
        $touched = [];
        $previous_version = self::current_version(); // before bundle.json swap
        foreach (['bundle.json', 'index.php'] as $f) {
            $src = $stage_root . '/' . $f;
            if (is_file($src)) {
                $dst = $root . '/' . $f;
                if (is_file($dst)) copy($dst, $dst . '.bak.' . $ts);
                copy($src, $dst);
                $touched[] = $f;
            }
        }
        $new_version = self::current_version(); // reads new bundle.json

        self::rrmdir($stage);
        t_app()->storage->delete(self::CACHE_KEY);

        return [
            'from'        => $previous_version,
            'to'          => $new_version,
            'backup_path' => $backup,
            'touched'     => $touched,
            'label'       => $label,
            'applied_at'  => time(),
        ];
    }

    /**
     * Walk a freshly-extracted directory looking for the Pure tree root —
     * the directory that contains therum/ (and ideally index.php). Returns
     * that directory path, or null. Searches up to 4 levels.
     */
    private static function find_pure_source(string $dir, int $depth = 0): ?string
    {
        if ($depth > 4) return null;
        if (is_dir($dir . '/therum') && is_file($dir . '/therum/bootstrap.php')) {
            return $dir;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $sub = $dir . '/' . $entry;
            if (!is_dir($sub)) continue;
            $found = self::find_pure_source($sub, $depth + 1);
            if ($found) return $found;
        }
        return null;
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** Plain HTTP GET → decoded JSON. Returns null on any failure. */
    private static function http_get_json(string $url): ?array
    {
        $opts = [
            'http' => [
                'method'  => 'GET',
                'header'  => "Accept: application/vnd.github+json\r\nUser-Agent: Therum-OS-Pure-Updater\r\n",
                'timeout' => 12,
            ],
        ];
        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function download(string $url, string $dest): bool
    {
        $opts = [
            'http' => [
                'method'         => 'GET',
                'header'         => "User-Agent: Therum-OS-Pure-Updater\r\nAccept: application/octet-stream\r\n",
                'timeout'        => 60,
                'follow_location' => 1,
                'max_redirects'  => 5,
            ],
        ];
        $stream = @fopen($url, 'rb', false, stream_context_create($opts));
        if (!$stream) return false;
        $out = @fopen($dest, 'wb');
        if (!$out) { fclose($stream); return false; }
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) break;
            fwrite($out, $chunk);
        }
        fclose($stream);
        fclose($out);
        return is_file($dest) && filesize($dest) > 0;
    }

    /** If a dir contains exactly one entry which is also a dir, return its path; else return $dir. */
    private static function flatten_single_dir(string $dir): string
    {
        $items = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        if (count($items) === 1 && is_dir($dir . '/' . $items[0])) {
            return $dir . '/' . $items[0];
        }
        return $dir;
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) { @unlink($dir); return; }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            self::rrmdir($dir . '/' . $entry);
        }
        @rmdir($dir);
    }
}
