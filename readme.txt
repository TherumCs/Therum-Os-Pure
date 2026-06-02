Pure
- **Therum raw.** Just Therum's own code — no WordPress, no forks, no add-ons. ~20 KB.
- Bundle contents: `index.php` (front controller), `bundle.json`, and `therum/` (the standalone runtime — Storage, Auth, Router, Install, Admin, Pages, Builder, Renderer + CSS/JS assets).
- **Boots standalone.** PHP 8.2+ via any web server. First request triggers the install wizard (site title + admin user). After that: a Therum-native admin at `/admin` with dashboard, page list, basic page builder (title + HTML body), and site settings. Frontend serves `/page/{slug}` and the home page at `/`.
- Storage is file-backed JSON under `therum/data/` — no DB, no Composer, no third-party dependencies.
- Source: [pure/runtime/](Therum%20OS/pure/runtime/) is the bundle template; edit there and `./build.sh pure` re-emits.
