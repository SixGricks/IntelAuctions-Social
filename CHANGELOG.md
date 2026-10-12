# Intel Auctions Social changelog

## 0.1.0 (2026-10-12) — Phase 1: foundation
- **The plugin and its dependency on the toolkit.** It boots on `plugins_loaded`
  only when the Intel Auctions Toolkit is active at 4.66.0 or newer with every
  helper it calls; otherwise it shows one notice ("Intel Auctions Social is doing
  nothing", and why) and registers nothing else — no menu, no job, no hooks on
  listings. Includes load behind a Throwable catch, like the toolkit's.
- **Tables** `{prefix}ias_posts` (one row per post: listing(s), type, status,
  score, window, scheduled time, OneUp id, content, images used, approvals,
  history) and `{prefix}ias_history` (openers, calls to action, hashtag sets,
  templates and images used, for the no-repeat rules), versioned by
  `ias_db_version`.
- **Settings** (`ias_settings`): the safety switches — master off, dry run on,
  not paused, every post type and the Facebook Event cards off — the spec's
  weights and starting-bid multipliers, 3 posts a day 3 hours apart in the three
  posting windows with up to 25 minutes of shift, the 48-hour veto window, a
  30-day plan pushed 21 days ahead from 5:00, the approvers and the Monday email,
  the OneUp category and account ids (the key stays in wp-config as
  `INTEL_ONEUP_API_KEY`), the first-comment link and the tracking tags.
- **Screens** under Auctions → **Social Calendar** (kept in simple mode), with
  tabs: the read-only 30-day **Calendar** with the Pause button, each post's own
  view, the **Settings**, the **Log** (`ias.*` entries in the toolkit's event
  log, filterable, dry-run payloads expandable) and this **Guide**. A switches
  strip on every screen shows whether anything can reach Facebook. Each auction's
  edit screen gets a **Social posts (Facebook)** box.
- **Updater** from this repo's releases (`intel-auctions-social.zip` only), and an
  `uninstall.php` that removes both tables, the options, the job bookkeeping, the
  `_ias_*` meta, the generated pictures and this plugin's log entries.
- Tests: `php tests/run.php` (pure) and `php tests/wp-run.php <wordpress>` (inside
  WordPress, including a process with the toolkit switched off).
