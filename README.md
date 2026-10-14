# Intel Auctions Social

A WordPress plugin (**v0.19.0**) for intelauctions.com, the sister of the
[Intel Auctions Toolkit](https://github.com/SixGricks/IntelAuction). It turns
each auction into a set of Facebook posts (and Google Business Profile posts, once switched on), plans them three a day through OneUp, and keeps
a person in the loop on the posts that carry hard facts.

- **What it is built to do:** [`docs/build-spec.md`](docs/build-spec.md) — DJ's spec, verbatim.
- **How it works and how to use it:** [`docs/guide.md`](docs/guide.md), also shown in
  WordPress at Auctions → Social Calendar → Guide.
- **What changed:** [`CHANGELOG.md`](CHANGELOG.md).
- **Working on it:** [`CLAUDE.md`](CLAUDE.md) (conventions) and [`tests/README.md`](tests/README.md).

**Needs** the Intel Auctions Toolkit 4.66.0 or newer (4.68.0 for Google posts to be sent) with its Anthropic API key set (every draft is a Claude call through it), PHP 7.4+ and WordPress 5.3+. Without
the toolkit it shows a notice and does nothing.

**Install:** upload `intel-auctions-social.zip` from the latest GitHub release (Plugins → Add
New → Upload) and activate. Updates then arrive through WordPress (`includes/updater.php`,
from this repo's latest non-prerelease). Put the OneUp API key in Settings → OneUp (or in
wp-config as `INTEL_ONEUP_API_KEY`), then **Look up accounts** and choose the page from the list.

**Safe by default:** the master switch, every post type and Google are off
at install, so nothing can reach Facebook until DJ turns a switch on. Every switch is on (it really
posts) or off (nothing goes, and what is on OneUp comes back off) — there is no dry run.

## Layout

| Path | What |
|---|---|
| `intel-auctions-social.php` | Header, constants, the dependency gate and the loader (`ias_boot()` on `plugins_loaded`) |
| `includes/dependency.php` | The toolkit check and its notice — loaded before anything else |
| `includes/settings.php` | Every setting and its default (`ias_settings`) |
| `includes/db.php` | The tables (posts, history, chat, voice), post rows and the usage history |
| `includes/log.php` | `ias_log()` into the toolkit's event log |
| `includes/post-types.php` | The post types and statuses from the spec |
| `includes/facts.php` | A listing's facts, read through the toolkit's helpers |
| `includes/candidates.php` | Which posts should exist and their windows (pure) |
| `includes/sync.php` | Keeps the posts in step with the listings; the drafts runner; the hourly job |
| `includes/content.php` | Prompts, templates, voices, calls to action, hashtags, links; writing a draft |
| `includes/voice.php` | The brand voice: the log of final posts, house rules, examples for the prompts, Suggest updates |
| `includes/chat.php` | Talk it over: the chat on each post, its guards, its admin-ajax answers and the live preview |
| `includes/photos.php` | Photo grading, picking and the three crops |
| `includes/graphics.php` | The Results graphic, the roundup collage, the branded card (GD) |
| `includes/checks.php`, `fairhousing.php` | What every draft is checked for (pure) |
| `includes/scheduler.php` | The scheduler: days and minutes for every post (pure) |
| `includes/plan.php` | The morning plan, the pushes (on or off — off takes back), cancelling at once, Cancel posts |
| `includes/oneup.php` | The Facebook renderer, the OneUp client, the switches that gate it, the directory Look up accounts keeps |
| `includes/channels.php` | Google, Instagram, X and LinkedIn: their rules, words and renderers (Instagram, X and LinkedIn preview only) |
| `includes/live.php` | Phase 5: sending to OneUp, taking back, the hourly read-back, Verify OneUp (and for Google), corrections |
| `includes/gbp.php` | Google Business Profile sent for real: its switches, record, sends, take-backs and read-back |
| `includes/instant.php` | Nobody needs OneUp: Move (and dragging on the calendar, by morning, afternoon or evening), Publish now, Try again, Edit it here again, and asking OneUp every minute while the calendar is open |
| `includes/summary.php` | The monthly summary (with site visits from Google Analytics through the toolkit) |
| `includes/approval.php` | Lane A approval emails, the signed one-time links and their page, reminders, the Monday email |
| `includes/admin*.php` | The Social Calendar (and each post's view), Brand voice, Settings, Log and Guide screens, and the listing panel |
| `assets/fonts/` | Inter, subset to Latin, with its licence (OFL) |
| `includes/updater.php` | GitHub updater (same mechanism as the toolkit's) |
| `uninstall.php` | Removes everything the plugin stored — the catalog of it |
| `tests/` | Pure tests (`php tests/run.php`) and in-WordPress tests (`tests/wp-run.php`) |
