# Ace Revisions

Revision history for post meta and taxonomy terms. Tracks who changed what, when and from where, with restore.

Part of the Ace plugin family (Ace Crawl Enhancer, Ace Redis Cache, Ace Community Events). Generic by design:
anything site-specific stays behind settings or hooks so the same plugin can be submoduled into every site.

**Requires:** WordPress 6.4+, PHP 8.1+. **Licence:** GPLv2 or later.

## Install

Add as a submodule in the site's plugins directory and activate:

```bash
git submodule add git@github.com:AceMedia/Ace-Revisions.git assets/plugins/ace-revisions
```

Build output is committed, so no build step is needed on deploy.

## Develop

```bash
npm install
npm run build
```

## Hooks

- `ace_revisions_settings_fields` - add or adjust settings fields (schema array).
- `ace_revisions_settings_tabs` - add or adjust settings tabs.
- `ace_revisions_setting` - filter a single resolved setting value.
- `ace_revisions_settings_saved` - action after settings are saved.

Plugin-specific hooks are documented in the source next to each `apply_filters` / `do_action`.

## Where things are

- **Settings → Revisions**: Post meta, Terms, Storage, Guide. Every tab has a "How this works" panel and the Help pull-down carries the manual.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a plain-English record of every release.
