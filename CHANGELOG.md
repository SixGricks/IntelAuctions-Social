# Intel Auctions Social changelog

## 0.4.3 (2026-10-12) — Facebook Events are out
- DJ's decision: "don't worry about facebook events at all". The Facebook
  Event cards are gone — the list under the calendar with Mark created and
  Skip, the Facebook Events switch (Settings and the switches strip), and the
  section in the Monday email. The spec stays as written; the decision is
  recorded in `CLAUDE.md` and the guide.
- Tests: the event-card checks are replaced by checks that the calendar,
  Settings and the Monday email offer no Facebook Events.

## 0.4.2 (2026-10-12) — a person's decision holds, and the plan settles
From an independent review of 0.4.1; each fix has a test that fails on 0.4.1
(`tests/wp/05-decisions.php`, `tests/unit/engine.php`).
- **A draft that stopped for a person stays stopped.** Its reasons alone keep
  it from being pushed, and being dropped and brought back, revived by the
  sync, or restored brings it back as Needs a decision, never as Planned. An
  edit clears any old Keep as written.
- **Edits count.** Saving a post's text (or Keep as written) records that it
  was checked against the listing as it is now, so "the listing changed" no
  longer comes back for good. If the listing changes after a person edited a
  post — even an approved one — it stops for that person instead of being
  rewritten behind their back.
- **The dry run settles.** A post it logged counts as sent: inside the 48 hours
  it is fixed in place (no more moving every Lane B post back a day every
  morning, and no "would replace" a day in the log), and once its time passes
  it counts as published, marked dry run on the calendar, the listing panel and
  the Monday email — so its Facebook Event cards appear.
- **Pushes check again.** A Lane B post never sent goes only while its time is
  still 48 hours away (one released late waits for the next plan); a sent post
  that stops being ready — held, stale, its approval withdrawn, moved past the
  push range — gets a logged delete; a post the plan can't place loses its old
  time.
- An approved roundup or Results post that loses a listing (one of two
  same-day sales unpublished) loses its approval and is rewritten, like one
  whose facts changed; the listings are compared as sets.
- **Results never expire:** a Results post on the plan is no longer cancelled
  30 days after the sale.
- **Restore** over a skip the plugin made itself (the webhook already posted
  it; photos never arrived) sticks, with a week from today to go out.
- **Open House and Auction Week posts wait** while their Listing post waits for
  photos, so they never go first.
- **Approval links for out-of-date facts** are refused ("the listing changed
  since this email"): a waiting post whose facts change has its links voided,
  gets no reminder, and cannot be approved until it is rewritten.
- Refused approval links that belong to no approver are no longer logged (an
  anonymous loop could have filled the shared log).
- Without GD, a priced Results post goes out with each property's plain photo
  and the roundup with the brochure covers (it went out with no picture).
- The toolkit contract is wired: `ia_social_facebook_managed` answers yes only
  when posts can really reach OneUp (live pushing built — Phase 5 — master on,
  dry run off, not paused, Listing posts on), so the toolkit's webhook keeps
  posting Facebook until then and stops the moment this plugin takes over.
- A stored setting outside its field's range is shown clamped, so it can never
  make the Settings form unsaveable.

## 0.4.1 (2026-10-12) — review fixes from the end-to-end run
- An approved roundup or Results post whose facts change before it goes out
  (a corrected sale price) loses its approval: it is rewritten and sent for a
  fresh yes, and its old links stop working.
- A draft whose listing changed is never pushed until it has been rewritten,
  and it is rewritten on the next run (at most once an hour), not after 6
  hours.
- The roundup's name on the calendar and in emails reads "3 new sales".
- The OneUp request box on a post wraps long addresses.
- Tests: an uninstall test (both tables, options, meta, pictures and log
  entries gone; listings and the toolkit's own data untouched); the fixtures'
  marker no longer uses the plugin's `_ias_` prefix, so an uninstall test
  cannot strand other tests' data.

## 0.4.0 (2026-10-12) — Phase 4: approval
- **Lane A by email.** A roundup or Results post with a clean draft becomes
  Waiting for approval and each approver gets the full text, the picture, the
  first comment, **Approve** and **Edit in WordPress**. Approve is a signed
  one-time link per approver (random, stored only as a hash, 4 days) opening a
  page whose button approves — just following the link changes nothing. The
  first yes wins; used, expired, altered and pre-edit links fail safely and say
  why. Reminders every morning with fresh links until someone answers; never
  expires; no approval email or reminder on Sunday. Approving plans the post at
  once (an hour ahead, no 48-hour wait) and queues it. Changing a waiting
  post's words voids its links and asks again. On the calendar: Approve, Send
  for approval now, Send a reminder now, Save and approve, and the approval
  history.
- **The Monday email** at 7:00 to the approvers: the week ahead, waiting for
  approval, needs a decision, new since last week, dropped with reasons, the
  Facebook Events to create, and the OneUp calendar link. Send it now from
  Settings. The review day can no longer be Sunday.
- **Facebook Event cards.** Facebook does not let apps create Page events and
  OneUp's events are GBP-only, so each open house and auction day is prepared
  for a one-minute manual create once the Listing post has gone out: name,
  start and end, address, description and cover photo, with Mark created and
  Skip; in the Monday email while the Facebook Events switch is on.
- Every stored timestamp now comes from the plugin's own clock (`ias_now()`),
  so stored times and the rules comparing them never disagree.
- **Acceptance:** `tests/wp/04-approval.php` — a Results post round-trips
  (email, approve, queued) and an expired link fails safely.

## 0.3.0 (2026-10-12) — Phase 3: the scheduler
- **The morning plan.** From 5:00 the hourly job re-plans the next 30 days
  with a scheduler that places guaranteed posts first, bumps lower ones when it
  must (a guaranteed post is never moved aside or dropped; it runs late, or as
  a last resort bends the county rule — both reported), drops a droppable post
  only when its whole window is full (with the reason), and pulls posts into
  days with fewer than two. Never Sunday, at most 3 a day, 3 hours apart, in
  the three posting windows with a stable shift of up to 25 minutes, never on
  the hour; nothing new inside the 48-hour veto window for Lane B; one post per
  property per day, no two posts about the same county on one day, a
  property's later posts after its Listing post, divisions mixed when it is a
  tie. A post keeps its day and minute while they still work. **Plan now** on
  the calendar.
- **Acceptance:** `tests/unit/november.php` — 35 auctions across November,
  planned every morning from Oct 1 to Dec 10 with posts pushed, published and
  approved between mornings: no guaranteed post dropped, every rule kept every
  morning, every property's Listing and Results posts out, every roundup on its
  Friday or Saturday.
- **Same-day sales merge only at the same place** (same town): the Sunbury
  twins share a post; a Lancaster farm selling the same day keeps its own. Slot
  keys gain the place (`auction_week:2026-11-18:sunbury`).
- **Dry-run pushes.** Every ready post in the next 21 days becomes the exact
  OneUp request (`scheduleimagepost` / `schedulevideopost` / `scheduletextpost`
  with the category and account ids, the time, the text and the tagged
  `first_comment`; a moved post is delete-then-schedule; a dropped or cancelled
  one is a delete), logged once per change with what stops it being sent. The
  API key never appears. Live sending is not built (Phase 5).
- **Cancelling at once.** Unpublishing, password-protecting, trashing or
  deleting a listing cancels its posts before the request ends; republishing
  brings them back. **Cancel posts / Resume posts** on the listing panel.
- **Calendar:** last plan line, Plan now, Dropped in the last 30 days, Running
  late or squeezed, and each post's What would go to OneUp. **Settings:** Check
  connection (a read-only call listing OneUp's categories and accounts).

## 0.2.0 (2026-10-12) — Phase 2: content
- **Every current listing gets its posts.** An hourly job (`ias_social` on the
  toolkit's dispatcher) reads every listing's facts through the toolkit and plans
  the posts the spec calls for, on their ideal days: the Listing post from
  promotion (or from when photos arrive), Open House 7→5→3 days before each,
  Auction Week 10→7→3 days before the sale (same-day sales share one), Results
  the day after a priced sale (a Saturday sale's on Monday; liquidations get a
  thank-you with no price; same-day sales share one), and the Friday New This
  Week roundup of the week's announcements. The late-listing rule, Sunday shifts,
  priorities (weight × starting-bid multiplier) and no second Listing post for a
  listing the toolkit's webhook already posted. Unpublished or deleted listings
  lose their posts; republished ones get them back; nothing a person skipped,
  cancelled or edited is undone.
- **Claude writes every post** through the toolkit's metered engine from the
  facts alone, with five rotating templates, three division voices, a rotating
  call to action and plugin-chosen hashtags; no opening line, call to action or
  hashtag set repeats within 30 days. The link goes in the first comment, tagged
  by post type and post.
- **Photos**: graded once each by Claude (quality, kind, alt text), chosen so a
  property never leads with a photo it has used, the Listing post leaving enough
  for the later posts; three crops of every chosen photo. A property video file
  becomes the Open House post.
- **Pictures it draws**: the Results graphic (price in white over the featured
  photo, two or three same-day sales side by side) and the New This Week collage
  of brochure covers with branded cards for the missing ones, in the brand font
  or the bundled Inter (OFL).
- **Checks**: `[VERIFY]` markers, bidding stated exactly once and correctly,
  dollar figures and phone numbers only from the listing, the fair-housing
  screen, no emoji walls — a hit stops the post (**Needs a decision**); links
  and stacked exclamation points are fixed.
- **Calendar**: posts on their ideal days; each post's preview with Save text,
  Rewrite, Skip / Restore and Keep as written; Needs a decision, Waiting for
  photos and **Write drafts now** under the grid. The listing panel gains sale
  kind, parcels and brochure cover; Settings gain the brand font, extra
  fair-housing phrases, the grading size and drafts per run.
- Requires the toolkit's 4.66.0 helpers (`ia_ai_generate()` with image blocks).

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
