# Intel Auctions Social changelog

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
