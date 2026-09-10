# Ace Revisions (`ace_revisions`)

Revision history for post meta and taxonomy terms. Tracks who changed what, when and from where, with restore. Canonical repo: `git@github.com:AceMedia/Ace-Revisions.git`, checked out at
`/var/www/html/plugins/ace-revisions`. For AceMedia-wide conventions (British English, commit rules, deploy
patterns) use the **speedforce** skill; this file only holds plugin-specific facts.

**Canonical plan = the GitHub issues on this repo.** Pick work up from there and keep them current.

## Shared plugin rules

- Shared across sites as a submodule. **Keep it generic**: no domains, site slugs, or another site's CPT/meta
  keys. Site-specific behaviour lives in that site's must-use plugin and reaches this plugin through filters.
- Native WP APIs only, no new runtime dependencies. Capability checks, nonces, sanitise on input, escape late.
  Multisite safe (per-site options, `ace_revisions_options`).
- Negligible frontend cost: at most one cached lookup per request; invalidate on save. Cache through the object
  cache / Ace-Redis-Cache with a version stamp option, never bare transients (see speedforce conventions).
- Plays nicely with Ace Crawl Enhancer meta (`_ace_seo_*`) and the Ace Redis Cache drop-ins.
- **Bump `ACE_REVISIONS_VERSION` and the `Version:` header on every release** so asset URLs bust caches.

## Layout

- `ace-revisions.php` - header, constants, loader.
- `includes/class-ace-revisions-settings.php` - option schema + sanitiser. Add a setting by adding one entry to `fields()`.
- `includes/class-ace-revisions.php` - plugin core (hooks wired in `__construct`).
- `includes/admin/` - settings page under **Settings** (`class-ace-revisions-admin.php` + `views/settings.php`): tabs →
  fieldset sections → fields from the settings schema; custom tabs render via `ace_revisions_settings_tab_content`;
  `class-ace-revisions-guide.php` holds the manual (Guide tab + WP help tabs + per-tab guide panels).
- `src/` - admin JS (wp-scripts). `styles/scss/admin.scss` - compiles to `assets/css/admin.css`.
- `build/` and `assets/css/` are committed: consuming sites do not run a build.

## Build & verify

```bash
npm install
npm run build        # wp-scripts + sass
npm run lint:php     # php8.4 -l over every file
```

Verify on a `.pi` site with `node /var/www/html/pi-verify.cjs https://<site>.pi/wp-admin/...` or in the
WordPress Playground MCP.

## Gotchas

- `update_term_meta()` on a key that does not exist yet falls through to `add_metadata()`, so the
  `update_term_metadata` filter AND `added_term_meta` both fire. The update handler bails when
  `metadata_exists()` is false, otherwise every first write logs twice.
- `deleted_term_meta` passes an **array** of meta ids as its first argument. Do not type-hint it `int`.
- `dbDelta()` needs plain `CREATE TABLE` (no `IF NOT EXISTS`) or it stops diffing the table forever.
- On the local Pi, `wp` post saves fatal inside Ace-Redis-Cache (no phpredis in CLI). Test with
  `wp --skip-plugins=Ace-Redis-Cache eval-file ...`.
