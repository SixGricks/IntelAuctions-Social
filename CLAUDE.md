# Intel Auctions Social — repo guide for Claude

WordPress plugin for intelauctions.com (Intel Auctions LLC): it turns each auction
into a set of Facebook posts, plans them three a day through OneUp, and keeps a
person in the loop on the posts that carry hard facts. Entry point
`intel-auctions-social.php`. **What it is for is `docs/build-spec.md` (DJ's spec,
verbatim) — read it before changing behaviour.** DJ's later decisions override
it, and the spec stays verbatim, so they are recorded here:

- **Facebook Events are out** (Oct 12, 2026: "don't worry about facebook events
  at all"). Removed in 0.4.3 — no event cards, no switch, no Monday-email
  section. Don't build them back from the spec's "Facebook Events" section.
- **The keys belong in Settings** (Oct 13, 2026), not only in wp-config: the
  OneUp key has a field under Settings → OneUp (option `ias_oneup_api_key`,
  never echoed back or logged; the `INTEL_ONEUP_API_KEY` constant still wins),
  and the toolkit sets the Claude key on Settings & Data → Services & Budgets
  (4.67.0). The spec's "API key stored in wp-config" is superseded.
- **More networks are provisioned** (Oct 13, 2026: "provision for more social
  networks now. At least Instagram, X, and LinkedIn"). `includes/channels.php`
  holds their rules and renderers; they are planned, previewed and dry-run
  only. Making one live needs its own Verify and its own sent record
  (`flags.oneup` is Facebook's) — never reuse Facebook's. X's words come from
  the facts (`ias_x_line()`), never from Claude's prose. Facebook's renderer
  (`ias_render_facebook()`) must not change output for the others' sake: its
  hash is what was sent.
- **A person chooses pictures by hand** (Oct 13, 2026) and that holds
  (`flags.media_by`), like their edited words.
- **Talk it over and the brand voice** (Oct 13, 2026: "a little AI chatbot I
  can discuss the post with and have it edit real time, then keep a log of
  final posts to learn the brand voice over time"). The chat (`chat.php`)
  only ever changes the words on the person's screen — **Save text is the one
  way a post's words change**, through the same edit path and checks. Every
  turn is one `ia_ai_generate()` call (`social.chat`), behind
  `ias_ai_blocker()`, a per-post lock, the repeat guard and the per-post cap
  (`chat_turns`). The brand voice (`voice.php`) is **learned only from what a
  person wrote, approved or starred**; house rules are a person's to save —
  Claude's suggestions (`social.voice`) change nothing until then, and a rule
  never overrules facts, bidding or fair housing (`ias_prompt_rules()` comes
  first in every prompt, drafts and chat alike).

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
`ias_required_toolkit_functions()` (or, for a helper the plugin can do without,
guard every call with `function_exists()` and add it to the optional list in
`tests/unit/contract.php` — that test fails on any toolkit call that is neither).

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
  facts changed or a person asks; a photo is graded once). A batch of calls
  checks `ias_ai_blocker()` first: with no key or the month's cap spent, nothing
  is tried and the switches strip says so once, rather than every post and
  listing recording the same failure. AI output is always a
  draft: prompts forbid invented facts (`[VERIFY]` markers hold the post), and the
  fair-housing screen holds any flagged draft for a person.
- **Nothing reaches Facebook until DJ turns a switch on.** The master switch and
  every post type ship **off**, dry run ships **on**, and Pause stops every new
  post. A change that could make a post public needs all of them checked on its
  path: every write to OneUp goes through `ias_push_blockers()` (a new post) or
  `ias_oneup_blockers(true)` (a take-back), every read through
  `ias_oneup_blockers(false)`; the master switch off means not one call, from
  any screen. A write also needs **Verify OneUp passed for the page set now**
  (`ias_oneup_verified()`). The one write before that is Verify's own test
  draft (master on, dry run may be on), which must never be left on OneUp.
- **Never send twice, never delete by guess.** One push run at a time
  (`ias_push_lock()`), and the read-back runs first in it. An attempt goes into
  `flags.sending` before it is sent and stays there when the answer is lost
  (`ias_oneup_uncertain()`): it is looked for on OneUp's list and adopted
  (`ias_oneup_adopt_attempt()`), never re-sent blindly. A post is replaced or
  taken back only by its `post_id` — never a bare `id`, never a match by
  text — and OneUp's "not found" counts only when its list agrees
  (`ias_oneup_confirm_absent()`). "Deleted by a person" needs a real, complete
  list and two read-backs 30 minutes apart. Errors pass through
  `ias_oneup_redact()` so the key never leaves the request.
- **One record of what was "sent", in both modes** (`ias_post_sent()`): live, the
  OneUp id, hash, text, time and facts in `flags.oneup` (and the id in
  `oneup_post_id`); dry run, `flags.dry_hash`. Both are treated alike: fixed
  inside the veto window, taken back when the post stops being ready, and
  `published` once its time passes (`flags.dry_run` for the dry run's). Going
  live clears every `dry_hash` (`ias_push_mode_changed()`). A post the read-back
  finds moved or edited by a person in OneUp is `locked`: never replaced, taken
  back only if skipped or cancelled. Status `pushed` is a display status
  (`ias_post_shown_status()`), never stored.
- **OneUp's API is only partly documented where this was built** (its site was
  unreachable): the endpoint names and methods live in `ias_oneup_endpoints()`,
  answers are read loosely (`ias_oneup_rows()`, `ias_oneup_find_id()`,
  `ias_oneup_error_in()`), and **Verify OneUp** checks the real thing with a
  draft. The list rows' shape (post_id, content, date_time in the account's time
  zone, created_at in UTC, social_network_username) is from the real account.
- **A person's decision holds.** Anything that puts a post back on the plan uses
  `ias_back_on_plan_status()` (a post with unanswered `holds` comes back held,
  never planned); a person-edited post is never rewritten by the system — when
  its facts change it is held with `ias_stale_hold()`.
- **A `[VERIFY]` marker never goes anywhere** (`ias_has_verify()`): not by
  Keep as written, not by any other release of a hold — push readiness says no.
- **The post view** (`ias_calendar_render_post()`): the bar
  (`ias_post_view_bar`) speaks for what the post waits for and carries its
  buttons; Edit and Pictures (`ias_post_view_main`, with Talk it over between
  them on `ias_post_view_after_edit`), the preview per network
  (`ias_post_view_preview`, redrawn unsaved through `ias_preview_html()`),
  the details (`ias_post_view_side`). One primary
  button at rest. Show people by name (`ias_actor_name()`), never a login.
  Screens print `ias_header_end()` after the header so notices land under it.
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
