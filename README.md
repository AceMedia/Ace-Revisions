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

## Changelog

### 0.1.0
- Initial scaffold: settings page, options store, build tooling.

## How it works

- **Post meta** piggybacks native revisions. Tracked meta is copied onto every revision, a revision is forced when
  only tracked meta changed, and restoring a revision restores the meta. The comparison screen shows a diff row per
  changed key plus the source (admin, rest, cli, cron, batch).
- **Terms** log to `{prefix}ace_revisions_terms` (term_id, taxonomy, field, old, new, user_id, source, batch_id,
  created_at). Core fields are diffed on `edited_term`; term meta is captured on the metadata filters so every
  plugin's term panel is covered. A History table with Restore sits at the bottom of the term edit screen.
- **Cap per object** counts change sets (one screen save or one batch = one set), not rows.
- **Batch id**: `Ace_Revisions::set_batch( 'id' )` from code, or `ACE_REVISIONS_BATCH=<id> wp ...` from the shell.

## WP-CLI

```bash
wp ace-revisions term <term_id> [--taxonomy=<tax>] [--format=json]
wp ace-revisions restore <row_id>
wp ace-revisions prune [--taxonomy=<tax>]
```

## Plugin hooks

- `ace_revisions_post_types`, `ace_revisions_meta_keys`, `ace_revisions_taxonomies`, `ace_revisions_term_meta_keys` - filter what is tracked.
- `ace_revisions_source` - override the detected change source.
- `ace_revisions_term_log_row` - filter a row before insert; `ace_revisions_term_logged` after.
- `ace_revisions_meta_copied`, `ace_revisions_meta_restored`, `ace_revisions_term_restored` - actions.
