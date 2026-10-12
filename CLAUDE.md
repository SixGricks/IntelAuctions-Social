# Intel Auctions Social — repo guide for Claude

WordPress plugin for intelauctions.com (Intel Auctions LLC): it turns each auction
into a set of Facebook posts, plans them three a day through OneUp, and keeps a
person in the loop on the posts that carry hard facts. Entry point
`intel-auctions-social.php`. **What it is for is `docs/build-spec.md` (DJ's spec,
verbatim) — read it before changing behaviour.**

It is a **sister plugin of the Intel Auctions Toolkit** (`SixGricks/IntelAuction`)
and, unlike the Event Screen, **depends on it**: every listing fact, the Claude
engine, the event log, the hourly dispatcher and the admin look come from the
toolkit's helpers. `includes/dependency.php` checks `IA_TOOLKIT_VERSION` against
`IAS_MIN_TOOLKIT` and the helpers by name; with the toolkit missing, too old or
half-loaded the plugin registers **nothing but a notice** — no menu, no cron job,
no hooks on listings. Keep it that way: nothing outside `dependency.php` and
`updater.php` may run before `ias_boot()` has passed the check. When a feature
needs a new toolkit helper, add it to the toolkit (a release there, documented in
its plugin guide §8.13), raise `IAS_MIN_TOOLKIT` and add the name to
`ias_required_toolkit_functions()`.

## ⚠️ Documentation must stay current

**`docs/guide.md` is the canonical user documentation**, rendered at Auctions →
Social Calendar → Guide (by the toolkit's Markdown renderer — the same subset the
toolkit documents in its CLAUDE.md: `#`–`####` headings, paragraphs, single-level
lists, pipe tables with no `|` inside a cell, fenced code, blockquotes, `---`,
comments, inline code/bold/italic/links). Any change that adds, changes or removes
a post type, rule, screen, setting, email, cron behaviour, table or option **must
update `docs/guide.md` in the same commit**, including its "Documented version:
X.Y.Z" line, and add an entry to `CHANGELOG.md`.

## Conventions

- **Versioning:** every release bumps the `Version:` header and `IAS_VERSION` in
  `intel-auctions-social.php`; commit messages follow `vX.Y.Z: summary`. The
  updater (`includes/updater.php`) ships releases from GitHub
  (`SixGricks/IntelAuctions-Social`, latest non-prerelease tag) and installs only
  an asset named `intel-auctions-social.zip` (else the zipball).
- **Names:** functions, options, tables, meta and events are **`ias_`** (tables
  `{prefix}ias_*`, meta `_ias_*`, events `ias.*`), never `ia_` — the toolkit owns
  `ia_` and already has `ia_social_*` functions and options of its own (its
  webhook). Every function is wrapped in `if (!function_exists(...))`.
- **Admin screens** require `manage_options`, use nonces, and follow
  POST → redirect → GET with the toolkit's `ia_flash_set()`; page slugs start
  `ia-social-` so the toolkit's admin CSS loads on them.
- **Data:** schema changes go in `includes/db.php` with an `IAS_DB_VERSION` bump.
  Anything new the plugin stores (option, meta key, transient, file, job id) goes
  on the list in `uninstall.php`, which doubles as the catalog. Job ids for the
  toolkit dispatcher start `ias_` so uninstall can find their bookkeeping.
- **Cron:** no raw WP-Cron events — one job on the toolkit's hourly dispatcher
  (`ia_cron_jobs`), which decides inside itself what is due (the 5:00 plan, the
  morning reminders, the Monday email).
- **Money and AI safety:** every Claude call goes through the toolkit's
  `ia_ai_generate()` (metered against its monthly cap) with a `social.*` feature
  name, and is guarded against paying twice (a draft is regenerated only when its
  facts changed or a person asks; a photo is graded once). AI output is always a
  draft: prompts forbid invented facts (`[VERIFY]` markers hold the post), and the
  fair-housing screen holds any flagged draft for a person.
- **Nothing reaches Facebook until DJ turns a switch on.** The master switch,
  every post type and the Facebook Event cards ship **off**, dry run ships **on**,
  and Pause stops every push. A change that could make a post public needs all of
  them checked on its path.
- **The dry run keeps its own record of what it "sent"** (`dry_hash` in a post's
  flags) and treats it like OneUp: fixed inside the veto window, a logged delete
  when it stops being ready, and `published` with `flags.dry_run` once its time
  passes. When Phase 5 makes pushing live, clear every `dry_hash` first, so
  nothing counts as sent that was not.
- **A person's decision holds.** Anything that puts a post back on the plan uses
  `ias_back_on_plan_status()` (a post with unanswered `holds` comes back held,
  never planned); a person-edited post is never rewritten by the system — when
  its facts change it is held with `ias_stale_hold()`.
- **Never post on Sunday, never send an approval email or reminder on Sunday.**
  Times are the site's time zone (`ia_wp_tz()`), always.
- **Field names** resolve through the toolkit's helpers (`ia_acf_field_*`); the
  few this plugin adds (`ias_acf_field_brochure()`) are filterable the same way.

## Testing

- `php tests/run.php` — the pure tests (no WordPress, `tests/unit/`). Must print
  ALL PASSED. Keep anything that is logic rather than WordPress plumbing testable
  here — the candidate builder, the scheduler and the checks are pure on
  purpose. **`tests/unit/november.php` is the scheduler's acceptance test** (35
  auctions, every morning for ten weeks): any change to planning must keep it
  passing.
- `php tests/wp-run.php /path/to/wordpress` — every `tests/wp/*.php` inside a
  real throwaway WordPress with the toolkit active (see `tests/README.md`; never a
  live site). Must print ALL PASSED. Add a check for every new behaviour.
- `php -l` every PHP file.
