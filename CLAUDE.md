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
  holds their rules and renderers; they are **previewed only** (their switch
  shows a preview tab, and is not a posting switch — 0.12.0). Making one live
  needs its own Verify and its own sent record
  (`flags.oneup` is Facebook's) — never reuse Facebook's. X's words come from
  the facts (`ias_x_line()`), never from Claude's prose. Facebook's renderer
  (`ias_render_facebook()`) must not change output for the others' sake: its
  hash is what was sent.
- **A person chooses pictures by hand** (Oct 13, 2026) and that holds
  (`flags.media_by`), like their edited words.
- **Talk it over and the brand voice** (Oct 13, 2026: "a little AI chatbot I
  can discuss the post with and have it edit real time, then keep a log of
  final posts to learn the brand voice over time"). The chat (`chat.php`)
  only ever changes the post on the person's screen — **Save changes is the one
  way a post changes**, through the same edit path and checks. Every
  turn is one `ia_ai_generate()` call (`social.chat`), behind
  `ias_ai_blocker()`, a per-post lock, the repeat guard and the per-post cap
  (`chat_turns`). The brand voice (`voice.php`) is **learned only from what a
  person wrote, approved or starred**; house rules are a person's to save —
  Claude's suggestions (`social.voice`) change nothing until then, and a rule
  never overrules facts, bidding or fair housing (`ias_prompt_rules()` comes
  first in every prompt, drafts and chat alike). **The rules are the long-term
  memory and the log is a window** (DJ: "write short rules and guides based on
  the changes made over time"): `ias_voice_prune()` keeps 120 days of posts
  (starred kept, 400 at most) and 45 of chat, `ias_voice_auto_learn()` reads
  the log at most weekly; nothing sent to Claude may grow with the log.
- **The chat edits the whole post; saving never approves** (Oct 13, 2026: "give
  the AI agent a way to edit first comments and anything else about the
  post" and "this needs a way to save changes without approving"). Talk it
  over changes the text, the first comment, the pictures (only the post's and
  the property's own, by key) and their alt text (`flags.alt`, kept through
  rewrites) — never the time, the type or the facts. Every **Save changes**
  submits the one Edit form (`ias_media`, `ias_alt` ride along) and never
  approves; an approve-first post is sent for its yes once per save
  (`ias_media_save(..., false)`), and pictures or alt text alone never turn
  the words into a person's edit. A first comment is checked on save
  (`ias_comment_holds()`: fair housing, `[VERIFY]`, the listing link). The
  first comment's lead-ins are a setting (`comment_leads`); Results says
  "View the full listing", never "how it sold".
- **Google Business Profile is sent from here, and ids are chosen, not typed**
  (Oct 13, 2026: "why … am i entering the data rather then just having the
  system call, respond, and give me a choice" and "I want to be able to
  schedule GBM posts from here"). Look up accounts keeps OneUp's directory
  (`ias_oneup_directory`) and Settings offers lists from it. Google
  (`includes/gbp.php`) is the second live network: its own switch, its own
  Verify (`ias_gbp_verify`), its own record (`flags.gbp`, `gbp_sending`,
  `gbp_off`, `gbp_locked`) — never Facebook's — and every Facebook rule on
  those books. It is sent only with toolkit 4.68.0+, and answers
  `ia_social_gbp_managed` so the toolkit's own Google post stands down; the
  two must never both post Google. Google's words come from Facebook's
  (`ias_channel_text()`: no hashtags, no phone numbers), never a Claude call.
- **Nobody needs to touch OneUp, and it is instant both ways** (Oct 13, 2026).
  This overrides the spec's "edit, move or delete them there": everything is
  done on the Social Calendar. A person's change is sent in the same request
  (`ias_push_now()` after the action, the actions in `ias_push_now_actions()`)
  — under the push lock, after a light read-back that must never conclude a
  delete. OneUp has no webhooks: an open calendar asks every minute
  (`ias_oneup_pulse()`, shared, throttled) and the hourly run reads back the
  rest. Move pins a time (`flags.pinned`, honoured by `ias_engine_posts()`,
  and the person's yes past the veto window); Try again and Edit it here
  again replace "fix it in OneUp". Never write a note, button or email that
  sends someone into OneUp.
- **On or off — no dry run, and off means off** (Oct 14, 2026: "I want to
  turn dry run off and remove it. Either things are active or inactive.").
  Every switch either really posts or sends nothing; there is no rehearsal
  mode, and Google is one switch (`networks.gbp.on`). Off means off: when the
  master switch, Pause, a post type or Google is off (`ias_push_switched_off()`,
  `ias_gbp_switched_off()`), a copy already on OneUp and not yet out is taken
  back (`was_on_oneup` / `gbp_was`, so it goes again when on). A missing key or
  id, or Verify not passed, stops new posts but takes nothing back. A switch
  saved in Settings, Pause and Resume act at once (`ias_push_switched()`, a
  20-second budget, soonest first; the rest on the hourly run). The update
  retired the dry run once (`ias_dry_run_retire()`): a site in it had every
  type and Google switched off — an update must never start posting. Never
  build a dry run, a "log what would be sent" mode or a second "send for real"
  switch back.
- **Publish now** (Oct 14, 2026: "I also want a "Publish now" button if a post
  is past it's scheduled date"). A post whose time passed without going out
  (`ias_publish_now_state()`) is sent a few minutes ahead from its page, its
  time pinned like a Move; on Lane A pressing it is the approval. Never while
  its switches are off, never on a Sunday, never with holds, a `[VERIFY]` or a
  stale draft.
- **Drag on the calendar, by time of day** (Oct 14, 2026: "I want to be able
  to drag posts around on the calendar and choose the morning, afternoon, or
  evening time slot"). A drop, and the post's Move, choose a posting window
  (`ias_windows()`), never a free-typed minute by default: the post takes its
  own shifted minute there (`ias_slot_minute()`), pinned like a Move, clear of
  the posts that stay put (`ias_move_fixed()`); the plan then runs at once and
  OneUp follows for every post it moved. The exact time stays on the post's
  Move for a person who wants it. Phones use the post's Move (no drag).
- **A switch on every post, and a preview on hover** (Oct 14, 2026: "I also
  want to be able to turn on skipped items and turn off others" and "Hover
  over an item on the calendar should give a preview of the post"). The
  calendar's switch is Skip / Restore (`ias_calendar_onoff()`), worded as
  turning a post off and back on; Restore re-plans at once. The preview is
  data on the chip (`ias_calendar_preview()`), rendered with `.text()` only.
- **Variety, and bidding said positively** (Oct 13, 2026: "We need more
  variety in the Results posts… sometimes paragraphs and sometimes bullets…
  1 out of every 5 posts… emoji heavy… Positive statements are better").
  Every post has a shape (`ias_shapes()`: paragraphs, bullets, short,
  emoji-led — weighted so emoji-led is about one in five) and Results has its
  own openings (`ias_templates_results()`) and six calls to action. Bidding is
  said as what the sale *is*, never what it isn't; the check
  (`ias_negated_at()`) does not count a way of bidding the sentence denies, so
  "no onsite auction" on a phone post is not held. **Only a phone-bid sale must
  say how bidding works** (Oct 14, 2026: "We only need to say when it's a phone
  bid auction"): an in-person post need not say "in-person", but may not say
  phone bidding. With no open house ahead, about half the Listing and Auction
  Week posts say "Call for a private showing" (`ias_showing_brief()`; Oct 14:
  "we don't always need to say that"). The emoji cap is three, or
  eight on an emoji-led post (`ias_emoji_max()`).

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
- **Nothing reaches Facebook until DJ turns a switch on.** The master switch,
  every post type and Google ship **off**, and Pause turns everything off. A
  change that could make a post public needs all of them checked on its path:
  every new post to OneUp goes through `ias_push_blockers()` (Google:
  `ias_gbp_blockers()` plus its switched-off list), every take-back through
  `ias_oneup_takeback_blockers()` (the key only — off means off, so no switch
  stops a take-back), every read through `ias_oneup_blockers(false)`; the
  master switch off means no read and nothing new, only taking back what is
  ours, and then not one call. A new post also needs **Verify OneUp passed for
  the page set now** (`ias_oneup_verified()`). The one write before that is
  Verify's own test draft (master on, every type may be off), which must never
  be left on OneUp.
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
- **One record of what was sent** (`ias_post_sent()`): the OneUp id, hash,
  text, time and facts in `flags.oneup` (and the id in `oneup_post_id`); fixed
  inside the veto window, taken back when the post stops being ready or its
  switch goes off, and `published` once its time passes. (`flags.dry_run` on
  an old post means its time came in the dry run: shown as Not sent.) A post the read-back
  finds moved or edited by a person in OneUp is `locked`: never replaced, taken
  back only if skipped or cancelled. Status `pushed` is a display status
  (`ias_post_shown_status()`), never stored.
- **OneUp's API is documented at docs.oneupapp.io** (unreachable when this was
  built; checked against it Oct 14, 2026, 0.16.0 — read it before changing a
  call). The endpoint names and methods live in `ias_oneup_endpoints()`
  (scheduling and delete are POST); answers are read loosely
  (`ias_oneup_rows()`, `ias_oneup_find_id()`, `ias_oneup_error_in()`) because the
  live account has answered differently from the docs (`posts`/`next_start`
  vs `data`), and **Verify OneUp** checks the real thing with a draft. What the
  docs settle: a new post's answer carries **no post id** (it is found on the
  list by words and time; "0 new Posts Scheduled." is a refusal); lists come
  **50 a page** from `start` — always read every page (`ias_oneup_list()`),
  never take one page for the whole list; the **published** list's `post_id`
  is the network's own id, so a published row is matched by id or by words,
  page and time (`ias_published_pick()`) — a status only, never a delete; a
  failed row's reason is `fail_reason`. Row times: date_time in the account's
  time zone, created_at in UTC. Analytics (`analyze.oneupapp.io`) and comments
  (`feed.oneupapp.io`) need OneUp's Intermediate plan or above — DJ has it
  (Oct 14, 2026). **How each post did** (0.17.0, `stats.php`) is a daily read
  of the analytics into `flags.stats`, matched by Facebook's id
  (`flags.oneup.fb_id`) or words and time; a read only, never a write.
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
