# Ace Revisions

Revision history for post meta and taxonomy terms. Tracks who changed what, when and from where, with restore.

Part of the Ace plugin family by Shane Rounce / AceMedia. Anything site-specific stays behind settings or hooks, so the same plugin works on any site.

## Features

- Revisions for post meta: choose the post types and meta keys to track, then compare and restore.
- Revisions for taxonomy terms (and chosen term meta keys), using the standard WordPress revisions interface: one revision per save, with the compare slider and Restore.
- Native limits tab: switch revisions off per post type, set how many to keep, and set the autosave interval.
- One-click meta key presets: Ace Crawl Enhancer, WooCommerce prices and stock, featured image and redirect meta.
- Overview tab showing how many revisions the database holds and the space they use, per content type.
- Optional nightly clean-up of revisions for content that has not changed for a chosen number of months.
- Only stores a revision when a tracked value actually changed (configurable).
- WP-CLI commands for inspecting a post's revisions, undoing a batch and running the clean-up.
- Built-in Guide tab and WordPress help tabs.

## Requirements

- WordPress 6.4 or later
- PHP 8.1 or later

## Installation

### From a release (recommended)

1. Download the zip from the [latest GitHub Release](https://github.com/AceMedia/Ace-Revisions/releases/latest).
2. In WordPress go to **Plugins > Add New > Upload Plugin**, choose the zip and click **Install Now**.
3. Click **Activate**.

### From source

```bash
git clone https://github.com/AceMedia/Ace-Revisions.git ace-revisions
cd ace-revisions
npm install
npm run build
npm run plugin-zip
```

`npm run plugin-zip` produces an installable zip, or copy the folder into `wp-content/plugins/` and activate it. The compiled assets are committed, so the build step is only needed if you change the source.

## Usage

Open **Settings > Revisions**. The tabs are:

- **Overview**: what is being tracked and what the revisions cost in storage.
- **Post meta**: pick post types and meta keys (or a preset) to track.
- **Terms**: pick taxonomies and term meta keys to track, and term meta keys to ignore.
- **Native limits**: per post type revision switches, limits and autosave interval.
- **Storage**: retention and the nightly clean-up.
- **Guide**: the full manual.

Each tab has a "How this works" panel. Restore a term from its revision screen using the usual compare slider.

### WP-CLI

The plugin registers WP-CLI commands including `post <id>` (list a post's revisions and the meta that changed), `batch <id> --undo` (roll back everything a batch touched) and `cleanup` (run the stale-revision clean-up). Run `wp help` for the registered command name and options.

## Configuration

All options live under **Settings > Revisions**. Developers can adjust behaviour with filters such as `ace_revisions_post_types`, `ace_revisions_meta_keys`, `ace_revisions_taxonomies`, `ace_revisions_term_meta_keys`, `ace_revisions_key_presets`, `ace_revisions_setting`, `ace_revisions_settings_fields` and `ace_revisions_settings_tabs`, and actions such as `ace_revisions_meta_restored`, `ace_revisions_term_restored`, `ace_revisions_cleanup_ran` and `ace_revisions_settings_saved`. The source documents each hook next to its `apply_filters` or `do_action` call.

## Development

```bash
npm install
npm start          # watch JavaScript
npm run build      # build JavaScript and compile the Sass
npm run watch:css  # watch Sass
npm run lint:js
npm run lint:php
npm run plugin-zip
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Licence

GPLv2 or later. See [LICENSE](LICENSE) and <https://www.gnu.org/licenses/gpl-2.0.html>.

Author: [Shane Rounce / AceMedia](https://acemedia.ninja). Repository: <https://github.com/AceMedia/Ace-Revisions>
