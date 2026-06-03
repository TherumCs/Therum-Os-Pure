<?php
declare(strict_types=1);

namespace Therum;

/**
 * Builder — page list + edit/new form + handlers. v1 is intentionally
 * minimal: a title field plus an HTML textarea per page, with a Visual /
 * Code tab toggle handled in admin.js. Blocks land in a later phase.
 *
 * Routes (registered in index.php):
 *   GET  /admin/pages                 list
 *   GET  /admin/pages/new             new form
 *   POST /admin/pages/new             create
 *   GET  /admin/pages/{slug}/edit     edit form
 *   POST /admin/pages/{slug}/edit     update
 *   POST /admin/pages/{slug}/delete   destroy
 */
final class Builder
{
    /** Page list. */
    public static function list_view(?string $flash = null): string
    {
        $pages = Pages::list();
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
        $flash_html = $flash ? '<div class="t-ok">' . $h($flash) . '</div>' : '';
        $rows = '';
        foreach ($pages as $slug => $p) {
            $when = date('M j, Y', (int) ($p['updated_at'] ?? 0));
            $rows .= '<tr>'
                  . '<td><a href="/admin/pages/' . $h($slug) . '/edit"><strong>' . $h($p['title'] ?? $slug) . '</strong></a><br><span class="t-muted">/' . ($slug === 'home' ? '' : 'page/' . $h($slug)) . '</span></td>'
                  . '<td class="t-muted">' . $h($when) . '</td>'
                  . '<td class="t-row-actions">'
                  . '<a href="/admin/pages/' . $h($slug) . '/edit" class="t-link">Edit</a> · '
                  . '<a href="' . ($slug === 'home' ? '/' : '/page/' . $h($slug)) . '" target="_blank" class="t-link">View</a> · '
                  . '<form method="post" action="/admin/pages/' . $h($slug) . '/delete" class="t-inline-form" onsubmit="return confirm(\'Delete this page? This cannot be undone.\')"><button class="t-link t-link-danger">Delete</button></form>'
                  . '</td></tr>';
        }
        if (!$rows) {
            $rows = '<tr><td colspan="3" class="t-empty">No pages yet. <a href="/admin/pages/new">Create your first →</a></td></tr>';
        }
        return Admin::layout('Pages', <<<HTML
<div class="t-page-head">
  <h1>Pages</h1>
  <a class="t-btn t-btn-primary" href="/admin/pages/new">＋ New page</a>
</div>
{$flash_html}
<table class="t-table">
  <thead><tr><th>Title</th><th>Updated</th><th></th></tr></thead>
  <tbody>{$rows}</tbody>
</table>
HTML);
    }

    /** New or edit form. $existing is the page record (or null for new). */
    public static function edit_view(?array $existing = null, ?array $errors = null, ?array $values = null): string
    {
        $is_new  = $existing === null;
        $h = fn(string $s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $values  = $values ?? [
            'title' => $existing['title'] ?? '',
            'slug'  => $existing['slug']  ?? '',
            'body'  => $existing['body']  ?? '',
        ];
        $err_html = '';
        if ($errors) {
            $err_html = '<div class="t-err">' . implode('<br>', array_map($h, $errors)) . '</div>';
        }
        $action = $is_new ? '/admin/pages/new' : '/admin/pages/' . $h($values['slug']) . '/edit';
        $title_label = $is_new ? 'New page' : 'Edit page';
        $delete_btn = $is_new ? '' :
            '<form method="post" action="/admin/pages/' . $h($values['slug']) . '/delete" class="t-inline-form" onsubmit="return confirm(\'Delete this page?\')">'
            . '<button class="t-btn t-btn-danger">Delete</button></form>';
        $slug_help = $is_new
            ? '<small>Leave blank to auto-generate from the title. Use <code>home</code> for the front page.</small>'
            : '<small>Slug can\'t be changed once a page exists. (Reach me later, I\'ll add rename.)</small>';
        $slug_input = $is_new
            ? '<input name="slug" value="' . $h($values['slug']) . '" placeholder="about, contact, home…" />'
            : '<input name="slug" value="' . $h($values['slug']) . '" readonly />';
        $title_v = $h($values['title']);
        $body_v  = $h($values['body']);
        return Admin::layout($title_label, <<<HTML
<div class="t-page-head">
  <h1>{$title_label}</h1>
  {$delete_btn}
</div>
{$err_html}
<form method="post" action="{$action}" class="t-form t-form-wide">
  <label>Title <input name="title" required value="{$title_v}" placeholder="Page title" autofocus /></label>
  <label>Slug {$slug_input}{$slug_help}</label>
  <label>Body
    <div class="t-editor">
      <div class="t-editor-tabs">
        <button type="button" class="t-tab is-active" data-tab="code">Code</button>
        <button type="button" class="t-tab" data-tab="preview">Preview</button>
      </div>
      <textarea name="body" rows="18" class="t-editor-code" placeholder="<h1>Hello, world.</h1>&#10;<p>Write HTML directly. A block-mode editor lands in a later release.</p>">{$body_v}</textarea>
      <div class="t-editor-preview" hidden></div>
    </div>
  </label>
  <div class="t-form-actions">
    <button type="submit" class="t-btn t-btn-primary">Save</button>
    <a href="/admin/pages" class="t-link-muted">Cancel</a>
  </div>
</form>
<script src="/therum/assets/admin.js"></script>
HTML);
    }

    public static function handle_new(): string
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug  = trim((string) ($_POST['slug']  ?? ''));
        $body  = (string) ($_POST['body'] ?? '');

        $errors = [];
        if ($title === '') $errors[] = 'Title is required.';

        $slug = $slug !== '' ? Pages::slugify($slug) : Pages::slugify($title);

        if (Pages::get($slug) !== null) {
            $errors[] = 'A page with the slug "' . $slug . '" already exists.';
        }

        if ($errors) {
            return self::edit_view(null, $errors, ['title' => $title, 'slug' => $slug, 'body' => $body]);
        }

        Pages::save($slug, $title, $body);
        header('Location: /admin/pages/' . $slug . '/edit');
        exit;
    }

    public static function handle_update(string $slug): string
    {
        $existing = Pages::get($slug);
        if (!$existing) {
            http_response_code(404);
            return Admin::layout('Not found', '<h1>Page not found</h1><p><a href="/admin/pages">← Back to pages</a></p>');
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        $body  = (string) ($_POST['body'] ?? '');

        $errors = [];
        if ($title === '') $errors[] = 'Title is required.';

        if ($errors) {
            return self::edit_view($existing, $errors, ['title' => $title, 'slug' => $slug, 'body' => $body]);
        }

        Pages::save($slug, $title, $body, $existing);
        // Re-render the editor with a flash via redirect (PRG).
        header('Location: /admin/pages/' . rawurlencode($slug) . '/edit?saved=1');
        exit;
    }

    public static function handle_delete(string $slug): string
    {
        Pages::delete($slug);
        header('Location: /admin/pages?deleted=' . rawurlencode($slug));
        exit;
    }
}
