# Ace Revisions changelog

Plain-English record of what changed in each release. Dates are when the version was pushed.

## 0.4.1 - 10 September 2026
- Per-type revision switches are now "switch revisions off" (off by default), so a script or a partial save can never turn revisions off by accident. Anyone who used 0.4.0's tick-to-keep setting: nothing to do, revisions are on unless you tick off.

## 0.4.0 - 10 September 2026
- Overview now opens with what is being tracked, and the nightly clean-up (switch it on under Storage) reports its last run there.
- One-click presets under Meta keys: Ace Crawl Enhancer, WooCommerce prices and stock, featured image, redirect meta.
- Meta saved after the revision is written (classic meta boxes, REST) is now caught, so the revision always holds the final values.
- WP-CLI: `post <id>` lists a post's revisions with the meta that changed; `batch <id> --undo` rolls back everything a batch touched; `cleanup` runs the stale-revision clean-up.

## 0.3.0 - 10 September 2026
- Term history now uses the standard WordPress revisions: one revision per save of the whole term, with the usual compare slider and Restore. The custom history table is gone.
- New Overview tab: how many revisions the database holds and how much space they take, per content type, plus a clean-up for revisions of content nobody has touched for a chosen number of months.
- New Native limits tab: switch revisions on or off per post type, set how many to keep, and set the autosave interval, all in one place.
- Housekeeping term meta keys (timestamps, migration markers) can be ignored so they do not create noise.
- Empty values no longer count as changes.
- A settings save now takes effect immediately in the same request.

## 0.2.0 - 10 September 2026
- Settings page rebuilt on the shared Ace layout with a guide panel on every tab, WordPress help tabs and a full Guide tab.
- Help tab on tracked term edit screens.
- Fixed first term meta writes being logged twice and batch ids set mid-request being ignored.

## 0.1.0 - 10 September 2026
- First cut: post meta on native revisions, term change log, History with Restore on the term edit screen, WP-CLI commands.
