# Intel Auctions Social changelog

## 0.25.0 (2026-10-15) — your own posts, the post's own day, more templates
DJ, Oct 15, 2026: "I want to be able to create a post from in the social
calendar manually and choose what platforms it goes to… make sure the social
algorithm considers that post… the post planning calendar needs to be date
aware. If the auction is in 2 days it can say so in the post. If the open house
already passed it should not mention them… We also need to increase the variety
in how the posts are organized."

**New post** (`includes/manual.php`, type `manual`, label "Your post"):
- **+ New post** beside Pause opens a form: the words (your own, or **Draft it
  for me** — Claude writes from the listing's facts as of the post's day and/or
  a note, into the box to check; the same input within the hour is never paid
  for twice; `social.manual`), an optional first comment (with a listing and
  none typed, its tracked link), the listing's photos to tick, the networks,
  the day and the morning/afternoon/evening or an exact time, an optional
  listing and which of its posts it **stands for**.
- Networks (DJ's answer: all five, queued): Facebook and Google go out;
  Instagram, X and LinkedIn are kept on the post (`flags.networks`) until each
  is live with its own Verify and switch — nothing is sent there, and a post
  ticked only for those is closed with that note when its time passes.
- **The plan counts it** (DJ: "count it, replace the same kind"): pinned like a
  Move and always fixed in the scheduler (`ias_engine_posts()`), so it takes a
  place in the day's three and its spacing; a post not for Facebook takes none.
  Standing for the listing's Listing, Open House, Auction Week or Results post
  turns that planned post off (skipped by a person, `flags.replaced_by`); a
  shared Auction Week post stays. **Delete this post** cancels it (taken back
  off OneUp) and brings that post back.
- It is a person's: never rewritten (no Rewrite; `ias_generate_post()` refuses),
  never sent for approval, never cancelled by the sync, never drafted by the
  run. No post-type switch of its own (`ias_post_type_on()`): the ticks are
  its switch — the master switch, Pause and Verify still stop it; Google's
  switch too. **Where it goes** on its page changes the ticks (an unticked
  network it was on is taken back at once).
- The checks run (`ias_manual_check()`): `[VERIFY]`, online bidding, fair
  housing, and standing for a listing's post, that type's bidding rule and the
  Results seller rule; figures and phone numbers against the listing when there
  is one (`figures` in `ias_check_draft()`'s context; with no listing there is
  nothing to check them against). X's preview of a person's own post is their
  words fitted to 280, not a line from the facts.

**The post's own day** (`ias_post_written_at()`, `ias_days_phrase()`):
- The prompt (drafts and Talk it over) says the day the post goes out; the
  facts are as of then (`ias_facts_for_prompt($f, $at)`): open houses over by
  that day left out, the sale (`auction_is`) and each open house within six
  days counted from it. Auction Week may now say "in 3 days" beside the date
  (it used to be told never to). `flags.written_for` records the day.
- **When the day moves** (`ias_timing_check()`, after every plan and every
  move): words counting days from the old day, or naming an open house over by
  the new one (`ias_timing_problems()`), mark Claude's draft to be rewritten for
  the new day (`flags.stale_when`, its own bar) — or stop a person's words,
  kept or approved post for them (`ias_timing_hold()`). Plan now counts them;
  a move's notice says so. Drafts from before 0.25.0 are judged only on open
  houses they name.

**More templates** (`ias_templates_more()`, `ias_template_fits()`,
`ias_templates_fitting()`): 25 ways to organise a post adapted from DJ's
ChatGPT list of 50, by post type and division, each used only when the post has
what it needs (size, rentals, hunting ground, equipment, a price, the sale or
open house today/tomorrow/within the week…). None asks for facts the plugin
lacks (catalogue, inspection, pickup, registration, units, tract results) or
signs with a division's name. The roundup rotates too (it was always "list").
Division-specific ways come first when level; "SOLD" may be in capitals on a
Results post.

**From the Oct 15 security review and Codex (0.24.1, folded in here):**
- The push lock is restamped by every OneUp call while a run holds it, so a
  slow read-back can no longer let a second run in to send a post twice.
- A refused approval link is logged once a day, not once a press.
- The Approve page wants **I have read this post** ticked before the button
  counts (a mail filter that presses buttons does not tick boxes).
- A video goes only to OneUp's own storage: oneupapp.io, or the bucket OneUp
  itself handed out (`ias_youtube_bucket()`, learned into `ias_youtube_storage`
  and relearned by Verify for YouTube; `ias_youtube_storage_buckets` filter).
- The prompt says the listing's description is data, never an instruction.
- The Dashboard box shows this month's visits only from the summary's cache —
  it never calls Google while /wp-admin renders.
- A listing that says "No phone bids accepted" is no longer taken for Intel
  Phone Bidding (`ias_negated_at()`).

Tests: `tests/unit/dates.php`, `tests/unit/templates.php`,
`tests/wp/08e-post-day.php`, `tests/wp/08f-manual-posts.php`.

## 0.24.0 (2026-10-15) — Social on the WordPress Dashboard
DJ, Oct 15, 2026: "I would like a few wordpress dashboards on /wp-admin showing
things like the top of 'Recently announced' and some important social media
metrics." The toolkit's 4.71.0 puts three auction boxes on /wp-admin; this adds
**Social — this week** beside them (`includes/admin-dashboard.php`).
- Whether posting is on (or paused, or off), and this week's posts by where
  they stand: out, scheduled ahead, waiting for approval, needing a decision,
  failed — each a link to the calendar.
- The last 30 days on Facebook, added up from the numbers already read from
  OneUp's analytics (0.17.0): views, reactions, comments, shares, clicks and
  the engagement rate, with how many of the posts have numbers yet; and the
  visits those posts brought to the site when Google Analytics can be read.
- The next three posts going out, with their time and status.
- Read-only: it calls nothing — not OneUp, not Claude, not Google. Hidden with
  Screen Options like any box; the `ias_dashboard_show` filter (false) removes
  it. With a toolkit before 4.71.0 it carries its own few styles.
- Tests: `tests/unit/dashboard.php` (the week and the numbers, pure) and
  `tests/wp/08c-dashboard.php` (the box as rendered).

## 0.23.0 (2026-10-15) — posts stop flipping to "the listing changed"
DJ, Oct 15, 2026: "I keep having posts that i personally rewrite and approve or
just save and then a few minutes later they go from green to blue with the note:
The listing changed since this draft was written… Generally speaking it seems
like results are going out and nothing else is."
- **The cause.** A draft is checked against a fingerprint of the facts it was
  written from, and that fingerprint was a hash of the prompt's facts — whose
  description came from `get_the_excerpt()`, the page as every plugin's content
  filters print it. What Elementor and the rest print differs between a
  person's own request (a save, an approval, Plan now) and the hourly run, so
  the next run found "the listing changed" on posts nobody had touched: a post
  just approved or saved went blue, was taken off OneUp and rewritten, and few
  posts but Results (approved and gone within hours) ever reached their day.
- **The fingerprint is the facts themselves** (`ias_facts_fields()`,
  `ias_facts_sig()`): plain values, field by field, never as the prompt words
  them, with the description as the listing stores it (`ias_listing_text()`:
  its excerpt, else its text without markup or shortcodes — the prompt uses it
  too). Photos and videos never count; a Results post is not written from the
  open houses, lead, starting bid or sellers; the others not from the price.
- **The update re-stamps every post once** (`ias_facts_sig_v`), as it stands —
  never all called changed at once.
- **What changed is named**: "The listing's open houses changed since this
  draft was written" (`flags.stale_what`), on the post and in its hold.
- **A person's yes holds.** A post a person approved or kept as written stops
  for them when a fact really changes ("…changed after this post was
  approved") — never rewritten under them. Only a listing leaving the post
  sends an approved post back to be rewritten.
- **A person's emoji are theirs.** The emoji cap (three; eight on an
  emoji-led post) applies to Claude's drafts only, never to a person's own
  words, typed or asked of Talk it over.
- **An unstated auction type is in-person** — the usual kind — unless the
  listing's own name or words speak of phone bidding (then Intel Phone
  Bidding). Only a listing that contradicts itself still stops the post.
- **Results posts never name the sellers** (DJ: "Sellers tend not to want
  associated with how much they made on properties. Let's mention them on
  listings but not on results posts."): the sellers are left out of a Results
  prompt, the thanks opening and the call to action thank them never by name,
  and a Results post that names a seller (or a surname of theirs, unless it is
  also the place's name) stops for a person.
- **Download CSV on the Log** (DJ: "I also want to be able to export the log
  page"): every entry the event log keeps, narrowed by Show, with each entry's
  details and payload; cells that would run as a formula are made plain.

## 0.22.3 (2026-10-14) — review fixes for 0.22.2
From the review of SixGricks/IntelAuctions-Social#11:
- **Facebook and Google rows are read as they are.** 0.22.2 split every row
  OneUp listed at its first "~~~", so a Facebook or Google post carrying "~~~"
  in its own words read back shortened — taken for a person's edit in OneUp
  and locked. The split now happens only where YouTube's rows are read
  (`ias_youtube_row_is()`, `ias_youtube_row_ours()`, Verify for YouTube, the
  test-draft clean-up), and never to a row OneUp gave a title of its own.
- **A send-on cut short still counts.** The try is counted before the file
  is sent, so a run killed mid-send (a time limit, a deploy) uses one of the
  three, and three let the upload go — never an hourly retry for ever.
- **One clock for an abandoned upload.** The two hours run from the last
  piece in site time (`piece_at`, noted at most every five minutes), never a
  file's own timestamp, which is UTC and shifted the window by the site's
  offset (6–7 hours on intelauctions.com, none at all east of UTC+2).
- **An upload's own key** on every piece, "done" and "fail": a second upload
  started for the same listing never takes the first one's pieces, and a
  repeated "done" (its answer lost) is answered as the first.
- **The send-on lock** is an option row claimed atomically
  (`ias_youtube_xfer_lock()`, as the push lock), held up to two hours —
  longer than curl's own limit. The hourly send-on runs last in the run,
  with no PHP time limit.
- **The row's script**: a lost "done" is sent again (three times) and an
  upload the website never heard finish is reported as unfinished, never as
  success; an error that ends an upload deletes its file at once.
- Pieces are never bigger than PHP takes (no 1 MB floor); the Settings link
  uses `ias_admin_url()`; Verify's test-draft words are written once
  (`ias_oneup_test_draft_text()`); the yes/no checks no longer touch the
  disk; Verify never tells anyone to delete a test post in OneUp — an
  undeletable one is kept by id and deleted first by the next Verify.
- Tests for the sweep, the lock, a send cut short, two uploads at once, a
  repeated "done", the piece size and a Facebook post with "~~~"; the guide
  and uninstall name the new keys, the lock and the transients.

## 0.22.2 (2026-10-14) — YouTube on the live account
DJ's first **Verify for YouTube** on intelauctions.com failed two steps, and
both were the plugin's to fix:
- **OneUp lists a post with a title as "title~~~description".** Its draft list
  (and its other lists) give a YouTube post one `content` joining the two, so
  Verify could not find its own test draft, and a video sent for real — whose
  answer carries no id — would never have been found again: no link onto the
  listing, no take-back. `ias_oneup_row_split()` splits them as OneUp's lists
  are read; the video match checks the title too. The test fake lists YouTube
  rows the same way.
- **Old test drafts clean themselves up.** Verify finds its own test drafts on
  OneUp's draft list by their exact words and deletes them by id — so the one
  that first run left behind goes on the next run. The note that told a person
  to delete it in OneUp is gone.
- **Uploads through the website when OneUp's storage takes none from a
  browser.** The live account's storage is a DigitalOcean bucket with no CORS
  rule at all: every browser preflight gets a 403, so the 0.22.0 design (the
  browser PUTs straight there) can never work for it. Verify for YouTube now
  settles the route (`ias_youtube_route`): straight from the browser where the
  storage allows it, otherwise through the website — the browser sends 8 MB
  pieces (smaller where PHP takes less; a lost piece is resent, an out-of-step
  one answered with where to carry on), the website keeps them in
  `uploads/intel-social/video-tmp/` (checking first it has room), and once the
  file is whole it answers the browser, asks OneUp for an address (only then:
  one lasts 20 minutes), streams the file there with curl, checks it arrived
  and deletes its copy (`ias_youtube_transfer()`). A send that fails is tried
  again by the hourly run, three tries, then the row says why. An upload left
  halfway is let go two hours after its last piece; stray copies go after a
  day. Verify tests the route it chose with its 42-byte test file, so a
  preflight with no answer no longer fails it — the website route needs none.
- **The row is never blank.** Before YouTube is set up, each Property Videos
  row says what is missing and links to Social Settings → YouTube, rather than
  showing nothing.

## 0.22.1 (2026-10-14) — YouTube review fixes
From the review of 0.22.0 (SixGricks/IntelAuctions-Social#10):
- **A withdrawn listing's video is taken back.** A listing that stops being
  public (unpublished, withdrawn) counts as switched off for its own video:
  a copy on OneUp's schedule is taken back by its id, and goes again once the
  listing is published (`ias_youtube_listing_public()`).
- **Off means off past the scheduled minute.** The take-back no longer skips a
  video whose time has passed — OneUp can run late. If OneUp answers that it is
  no longer scheduled, it may already be out: the record keeps `sent`, notes
  `tb_missed` (nothing more is asked while things stay off) and the read-back
  settles it — never `ready`, which would send it a second time.
- **Lost a day on.** A `sent` video on none of OneUp's complete lists a day
  after its time (deleted in OneUp) becomes `lost`: the row asks a person to
  check the channel, then **Send it again**.
- **Verify for YouTube** no longer falls back to a text draft when the video
  draft is refused — that could pass while every real upload fails. Its
  storage check now asks the browser's own preflight question (origin, PUT and
  `Content-Type`) and counts no answer as a fail (`ias_youtube_cors_ok()`,
  which reads the headers as WordPress hands them over — a case-insensitive
  dictionary object, never cast to an array, which would empty it).
- **The link is counted only once the listing keeps it.** A write the toolkit
  reports refused (4.70.1 reads the field back) is tried again on later checks;
  after three refusals the history says to paste the link by hand.
- **YouTube alone keeps the calendar's minute check going** (`ias_pulse_pill()`),
  with Facebook and Google off.
- **A listing deleted for good takes its record with it.** The list of
  records only counts listings that exist, a write to a missing listing does
  nothing, and deleting a post clears the list kept for the request — so a
  late write can never leave a record on a post that is gone.

## 0.22.0 (2026-10-14) — Property videos to YouTube, one upload
DJ: "I'm on vimeo starter. The goal is to cut down on human workload. I want
one time upload, title, description and not to think about it again. I would
love to just give you the file and then have you upload to Youtube with
description as part of a single property workflow."
- **Upload video file** on each row of the toolkit's Property Videos (its
  `ia_missing_video_row` action, toolkit 4.69.0). The browser sends the file
  straight to OneUp's storage: GET `uploadmedia` hands the site a presigned
  address (20 minutes) and the file's public `file_path`, and the browser PUTs
  the file there with a progress bar (OneUp's bucket allows it from any page).
  Nothing passes through the website, so Elementor Cloud's 256 MB upload limit
  and its disk play no part. The site then checks the file is there (a HEAD)
  before it counts. .mp4/.mov/.m4v/.webm, up to 4 GB.
- **To YouTube through OneUp** (`schedulevideopost` to the channel, `title`
  and `content`, the chosen playlist as `youtube`), five minutes ahead. The
  title and description are built from the listing's facts
  (`ias_youtube_words()`), never by Claude: address and sale date in the
  title; the description has the size, the sale day and time, Intel Phone
  Bidding when it is that, the open houses still ahead, the listing link
  (`utm_source=youtube`), the listing's own description (left out when it has a
  fair-housing phrase), who to call, a Subscribe link, the canonical NAP and
  three tags — never the starting bid. So nobody has to approve it.
- **The link onto the listing**: once published, the YouTube link goes on the
  listing through the toolkit's `ia_video_save()` (Skip video cleared, the
  Next Step card closed) — never over a link a person put there. The video id
  comes from the published row, else OneUp's YouTube analytics
  (`analyze.oneupapp.io/api/youtube/posts`), else the channel's public feed,
  matched by title.
- **Facebook gets the real video**: the uploaded file is the property's video
  file (facts `video_file_url`), so an Open House post carries it natively; the
  Listing post's first comment carries the YouTube link (facts `video_link`).
- **Its own switch, Verify and record**: `networks.youtube` (on, the channel
  chosen from Look up accounts, the playlist chosen from what Verify read),
  **Verify for YouTube** (`ias_youtube_verify`: a video draft with no file, a
  42-byte test file PUT to the upload address and read back, OneUp's storage
  asked whether this site's pages may upload there, the playlists), and `_ias_youtube` on the listing — never a
  post's flags. Off at install. Never on a Sunday; the listing must be
  published. Off means off (YouTube, the master switch, Pause): a video on
  OneUp's schedule is taken back by its id and goes again when on. A lost
  answer is looked for and never sent twice; not found half an hour on, a
  person is asked to check the channel and **Send it again** (no other file can
  be uploaded over a lost one); a refusal is tried again 15 minutes later, and
  refused three times or failed on OneUp: **Try again**.
- Read back by the hourly run, an open calendar's pulse and opening Property
  Videos (at most every two minutes). Facebook's read-back never takes a
  channel video for a person's post, even when the channel shares the page's
  name.
- The toolkit's row says **On its way** (`ia_video_pending`) and no Next Step
  card asks for a video meanwhile; the auction's Social box says where the
  video stands; the log has `video` entries.
- Tests: `tests/unit/youtube.php`, `tests/wp/07n-youtube.php`; the fake OneUp
  learned uploads, YouTube rows, playlists, YouTube analytics, the storage
  HEAD and the channel feed.

## 0.21.0 (2026-10-14) — One colour per status
DJ: "I want to see the 'Key' cleaned up. Pushed and Published should not
match. Dropped, skipped, and Human should not match. red for dropped. Yellow
for needs a decision." — and "yes" to the proposed colours.
- Each status has its own colour (`ias_statuses()`: line, fill, text): Planned
  blue, Waiting for approval purple, Approved teal, Pushed light green,
  Published solid Intel navy, Needs a decision yellow, Failed orange, Dropped
  red, Skipped grey, Cancelled charcoal, Human a pink outline, Not sent pale
  grey and dashed. Dropped, Skipped, Cancelled and Not sent are struck
  through on the calendar.
- The calendar's posts (`ias_status_chip_css()`) and every status badge — the
  key, the post's page, the listing panel — use the same colours; a badge
  carries them inline (`ias_status_badge_style()`), so it looks the same on
  any screen. Every status's words are at least 4.5:1 on its fill.
- The guide's status table says each status's colour.
- Tests: `tests/unit/statuses.php`.

## 0.20.0 (2026-10-14) — Moves are instant; the plan waits until you are done
DJ: "When moving calendar items they move VERY slowly. I think the AI does a
full planning agent again. Can it just move them and wait to run the agent
until later. This will allow a human to make a bunch of changes without AI
changing things at the same time."
No AI ran on a move; the time went on the plan (0.02 s) and on OneUp: a drag
with 36 posts on OneUp made 11 calls before the page answered — 4.5 s at
400 ms a call, more on a slow day and with Google on.
- **A move (Move, a drag) changes only the post moved.** No plan run: the
  plan waits (`ias_plan_hold`, `ias_plan_hold()`), and the calendar says
  **The plan is waiting while you work** with how many posts moved. The
  hourly run tidies around the moves once nothing has moved for 15 minutes
  (`IAS_PLAN_QUIET`, `ias_plan_hold_ready()`), or **Plan now** does at once;
  any plan run ends the wait. A move that fills a day says the plan moves
  one of the others when it tidies up.
- **The page answers before OneUp is called** (0 calls, 0.02 s): the moved
  post is queued (`ias_push_later()`, `ias_push_queue`) and sent at the end of
  the request once the browser has its page (`fastcgi_finish_request()` /
  `litespeed_finish_request()` where the host has them), waiting up to 30 s
  for another run; what is still queued goes with the open calendar's next
  check (`ias_oneup_pulse()`) or the hourly run (`ias_push_queue_run()`).
  Move and move_slot left `ias_push_now_actions()`.
- Restore, Let the plan choose, Try again and Plan now still plan at once.
- Tests: `tests/wp/07m-quick-moves.php` (24 checks); `07f-drag`, `07d-instant`
  and `07l-replan-follow` follow the new behaviour (the tidy-up is checked
  when the plan runs).

## 0.19.1 (2026-10-14) — Everything a re-plan changes follows at once
From the Codex review of SixGricks/IntelAuctions-Social#8 (two findings, one
cause): a click that re-plans sent OneUp only the post clicked, or the
posts the plan moved — not a post it dropped back onto the plan when room
opened, and, for Restore, nothing else at all — so those waited for the
hourly run.
- `ias_plan_run_follow()`: runs the plan and sends OneUp, in the same
  request, every other post it moved, dropped or brought back (a snapshot of
  the live and dropped posts before and after). Restore, Move, a drag, Let
  the plan choose and Try again use it; Plan now and an approval already
  push everything after planning.
- Tests: `tests/wp/07l-replan-follow.php` (11 checks; Restore, the drag and
  Let the plan choose fail without it).

## 0.19.0 (2026-10-14) — Try again reuses OneUp's failed post
DJ: "Yes" — Try again reuses the copy OneUp could not publish rather than
sending a second one.
- **Try again** keeps the failed copy's OneUp id (`flags.reuse_failed`), and
  the push that follows asks OneUp to reschedule it for the post's time
  (POST `reschedulefailedpost`: post_id, scheduled_date_time — OneUp's
  docs). The post keeps its OneUp id; the copy leaves OneUp's failed list.
- Only an unchanged post: what was sent keeps a fingerprint without its time
  (`flags.oneup.body`, `ias_request_body_hash()`), and the reschedule is
  asked only when the post's still matches. Changed since, refused by OneUp
  (not on its failed list any more), or sent before 0.19.0 (no fingerprint):
  a fresh copy goes in the same click, as before.
- Never twice: the reschedule is an attempt like any send — a lost answer is
  looked for on the scheduled list by its words and time and kept, and
  nothing else is sent meanwhile. A failed row OneUp has not cleared yet
  beside the rescheduled copy never fails the post again (the scheduled list
  wins in the read-back).
- The Failed card says what Try again does.
- Tests: `tests/wp/07k-retry.php` (20 checks; 13 fail on 0.18.0), unit checks
  in `tests/unit/oneup.php`; the fake OneUp's `reschedulefailedpost`.

## 0.18.0 (2026-10-14) — Correction comments posted by the plugin
DJ: "Yes" — the correction comment for a post whose facts changed after it
went out is posted by the plugin (OneUp's Intermediate plan has comments).
- **Post it as a comment** (`ias_correction_post()`): POST
  `feed.oneupapp.io/api/reply-to-post` with a JSON body — platform facebook,
  Facebook's id for the post (`ias_correction_fb_id()`: the analytics' id, or
  the published list's "FB_…"), the words, the page's id. On the calendar's
  corrections card (beside **I posted it**, was Comment posted, and **Not
  needed**) and on the post's page, which then says who posted it.
- **Post corrections automatically** (`corrections_auto`, off at install):
  the hourly run posts each new correction itself, up to five a run.
- Needs the master switch on, nothing paused, the key and Facebook's id;
  never on a Sunday, never with `[VERIFY]` (`ias_correction_blockers()`).
- **Never twice**: the try is noted before it is sent; a lost answer is
  looked for among the page's comments (GET `fetch-top-comments`,
  `ias_correction_find()`) and marked posted when found; otherwise only a
  person sends it again (**Post it again**). Three refusals stop the
  automatic tries for it; a plan without comments (403) is said and not tried
  again for a week (`ias_comments_no_plan`).
- The Monday email points to **Post it as a comment**.
- Tests: `tests/wp/07j-corrections.php` (32 checks), `tests/unit/corrections.php`;
  the fake OneUp's comments (`reply-to-post`, `fetch-top-comments`).

## 0.17.0 (2026-10-14) — How each post did on Facebook
DJ is on OneUp's Intermediate plan, which has the analytics. The spec: "Every
post records when it went out and how it did."
- **Once a day** (the hourly run, 20 hours apart; `ias_stats_due()`), one GET
  to OneUp's analytics (`analyze.oneupapp.io/api/facebook/posts`, the page's
  plain id, the last 31 days to yesterday, the site's time zone):
  reactions, comments, shares, clicks and views for each of the page's posts
  (`ias_stats_run()`). Each is matched to ours by Facebook's id (once known,
  or the published list's network id — `ias_fb_ids_match()`) or by its words
  at its time (`ias_published_pick()`), and kept on the post: `flags.stats`
  with the engagement rate (`ias_stats_rate()`: the four over the views),
  when read and the address on Facebook, and Facebook's id as
  `flags.oneup.fb_id` ("<page>_<post>", `ias_fb_post_id()`) — what 0.18.0's
  correction comments need. Unchanged numbers are not rewritten.
- **Shown** on the post's page (How it did on Facebook, See it on Facebook),
  in its hover preview, and in the monthly summary (Reactions, Comments,
  Shares, Clicks, Views and Engagement by post type and division — so in the
  Monday email's summary too).
- **A read only**: the master switch, the key and the page, like every read;
  **How posts did** in Settings (`stats_on`, on) turns it off; nothing asked
  when nothing of ours went out in the last month. A plan without analytics
  (403) is said in Settings and asked again a week later; numbers already
  read stay. Settings shows the last read with **Read now**
  (`ias_stats_last`).
- The OneUp client takes full addresses (OneUp's analytics and comments
  hosts) and JSON bodies; only the scheduling calls send the account as a
  list.
- Tests: `tests/wp/07i-stats.php` (33 checks), `tests/unit/stats.php`, and
  the fake OneUp's analytics (`ias_t_fb_stat()`).

## 0.16.0 (2026-10-14) — OneUp as its documentation says
DJ shared OneUp's API documentation (docs.oneupapp.io), unreachable when the
OneUp side was built. Every call the plugin makes was checked against it.
What did not match, and could have gone wrong once more than a few posts
were on OneUp, is fixed; where the live account has answered differently
from the documentation (`posts` and `next_start` in its lists), both are read.
- **Lists past the first 50** (`ias_oneup_list()`, `ias_oneup_next_start()`):
  the documented lists come up to 50 a page from `start`, with no
  `next_start`. The plugin read one page and called it the whole list, so a
  post of ours past the first 50 was never found: OneUp gives a new post no
  id, so it could not be taken back when switched off, nor replaced — and
  with ids, it could be taken for "deleted in OneUp by a person" while still
  going out. Now every page is read (a full page means ask again from where
  it ended; a short page is the end; a `next_start` is followed), and a page
  that only repeats rows ends the reading as not complete, so nothing is
  concluded from it.
- **Gone out, confirmed** (`ias_published_pick()`): the published list's
  `post_id` is documented as the network's own id ("FB_…"). A post that went
  out was never confirmed ("OneUp has not confirmed this went out" a day
  later) and was taken for a person's own post, counting toward the day's
  three. A published row that is not ours by id is now matched by its words,
  on our page, at its time (15 minutes before to a day after); the network's
  id is kept (`flags.oneup.published_id`, `flags.gbp.published_id`). Google's
  read-back the same. Only a status is read this way — nothing is deleted or
  replaced by a match of words.
- **POST** for scheduletextpost / scheduleimagepost / schedulevideopost (and
  `editpost`, unused), as documented; a 405 is still retried the other way.
- **"0 new Posts Scheduled."** with no error flag is a refusal
  (`ias_oneup_error_in()`): nothing was created, and nothing is looked for.
- **OneUp's failure reason**: the failed list's `fail_reason`
  (`ias_oneup_fail_reason()`), for Facebook and Google.
- `ias_norm_text()` moved to `oneup.php`.
- Tests: a docs-shaped fake OneUp (`docs`, `ignore_start`, `zero_new`; the
  fake now reads a POST body as a server does), `tests/wp/07h-oneup-docs.php`
  (Verify and POST; 60 posts on the list; nothing taken for deleted; take-back
  from the second page; "0 new"; `fail_reason`; published by words, and a
  person's own still theirs — 15 of its 24 checks fail on 0.15.0), Google's
  published match in `tests/wp/07c-google.php`, and unit tests in
  `tests/unit/oneup.php`.

## 0.15.0 (2026-10-14) — Only phone bid has to be said; "Call for a private showing" sometimes
DJ: "don't require 'in person auction' as part of the description. We only
need to say when it's a phone bid auction." — and "If no open house date is
given then it's 'Call for private showing' but we don't always need to say
that."
- **Only a phone-bid sale must say how bidding works.** The check no longer
  holds an in-person sale's post for not saying "in-person auction"; it still
  holds a phone-bid post that does not say Intel Phone Bidding (or says
  in-person too), and now an in-person sale's post that mentions phone
  bidding ("but this is an in-person auction"). Of several sales on one day,
  only the phone-bid ones must be named. A listing that does not say which
  it is still stops the post.
- **The prompts say the same:** the rules ask for Intel Phone Bidding once
  when it is one and say an in-person auction need not be mentioned; the
  Listing, Open House and Auction Week briefs ask only whether it is Intel
  Phone Bidding; the facts say "the post must say so" / "need not say".
- **No open house set** (`ias_showing_brief()`, `ias_says_showing()`, `ias_open_house_ahead()`): a
  Listing or Auction Week post about one property (real estate) with no open
  house still ahead says "Call for a private showing" in about half the
  posts — each post decides by its own key, the same every rewrite — and the
  rest leave showings out; never an invented open house. The Listing brief
  mentions open houses only when one is still ahead (a past one counts as none).
- Tests: `tests/unit/showings.php`, the bidding rule in
  `tests/unit/checks.php`, and `tests/wp/02i-variety.php`.

## 0.14.0 (2026-10-14) — A switch on every post; hover for a preview
DJ: "I also want to be able to turn on skipped items and turn off others." —
and "Hover over an item on the calendar should give a preview of the post."
- **An on/off switch on each post on the calendar** (`ias_calendar_onoff()`):
  green on a post on its way — press it to turn the post off (Skip, off
  OneUp at once); grey on a skipped, cancelled or dropped post — press it to
  turn it back on (Restore). None on a post that went out, a person's own, or
  one whose time came before posting was on. It comes back to the calendar
  (`ias_from=calendar`, which the drag's form now sends too).
- **Turning a post back on re-plans at once** (Restore, from the calendar or
  its page): the plan gives it a time — its old one may be gone or inside the
  48 hours — and OneUp follows in the same request; the notice says when it
  goes, or that the plan had no room for it.
- **Words:** Skip is **Turn this post off…** / **Turn it off**, Restore is
  **Turn it back on**, and the notices say turned off / turned back on.
- **Hover a post for its preview** (`ias_calendar_preview()`): its type and
  listing, where it stands and when, the lead picture and how many more, the
  words, the start of the first comment — or that it has no draft yet.
  Carried on the chip, so no request; built as text, never HTML; not on a
  device without hover; Escape, moving away or scrolling hides it. It
  replaces the chip's old tooltip.
- Tests: `tests/wp/07g-onoff-preview.php`.

## 0.13.0 (2026-10-14) — Drag posts on the calendar; morning, afternoon or evening
DJ: "I want to be able to drag posts around on the calendar and choose the
morning, afternoon, or evening time slot."
- **Drag a post** (desktop) onto any day from today to 30 days out, or onto
  its own, and choose **Morning**, **Afternoon** or **Evening** — the posting
  windows from Settings, named by when they start (`ias_slot_names()`).
  Cancel or Escape puts it back. Sundays take no drop; a post that went out,
  or one changed inside OneUp, is not draggable (`ias_movable()`).
- **The choice is a move** (`ias_move_to_window()`, action `move_slot`): the
  post's own minute in that window — the shift the plan gives it, never on
  the hour (`ias_slot_minute()`) — pinned like a Move, so it holds and goes
  to OneUp at once (inside the 48 hours too). It keeps clear of the posts
  that stay put (`ias_move_fixed()`: published, human, changed in OneUp,
  moved there, on OneUp inside the 48 hours); a window they hold, or one
  already gone today, is refused, naming why. The plan then runs at once:
  the posts in the way get new times, their copies on OneUp follow
  (`ias_push_now()`), and the notice names what moved — or was dropped.
  A drag comes back to the calendar.
- **The post's Move** offers the same three times of day, or an exact time.
- Tests: `tests/unit/windows.php` (names, minutes, gaps, today) and
  `tests/wp/07f-drag.php` (the calendar's drag markup, a drop, the same day,
  a Sunday, a passed window, a held window, making room and OneUp
  following, the Move form, what cannot be dragged, a drop that leaves no
  room for another post).

## 0.12.0 (2026-10-14) — On or off: the dry run is gone; Publish now
DJ: "I want to turn dry run off and remove it. Either things are active or
inactive." — and "I also want a "Publish now" button if a post is past it's
scheduled date."
- **No dry run.** The `dry_run` setting, its records (`flags.dry_hash`,
  `flags.dry_ch`, `ias_push_mode`, `ias_gbp_mode`), the `dryrun` log entries,
  "What would go to OneUp" and "Published (dry run)" are gone. Every switch is
  on — it really posts (once Verify OneUp has passed) — or off.
- **Off means off.** The master switch, Pause, a post type or Google switched
  off: nothing new goes, and a post on OneUp not yet out is taken back
  (`ias_push_switched_off()`, `ias_gbp_switched_off()`; `was_on_oneup` /
  `gbp_was` so it goes again when on, even inside the 48 hours). Before, Pause
  and a type switched off stopped only new posts, and the dry run left posts
  on OneUp "to delete in OneUp". Taking back needs only the key
  (`ias_oneup_takeback_blockers()`); with the master switch off and nothing of
  ours left on OneUp, not one call. A missing key or id, or Verify not passed,
  stops new posts but takes nothing back.
- **Switches act at once.** Saving Settings with a switch changed, Pause and
  Resume bring OneUp in line in the same request (`ias_push_switched()`:
  soonest first, a 20-second budget, the rest on the hourly run), and the page
  says how many came off or went.
- **Google is one switch** — **Google posts** (`networks.gbp.on`); Send to
  Google is gone. Instagram, X and LinkedIn are **previews only**: their
  switch shows a preview tab, logs nothing and is not among the switches.
- **The switches strip** says On, Off, Paused or "Not posting yet: …" and Google
  "on, not sending yet — …"; Pause is **Pause all posting** / **Resume
  posting**.
- **The update retires the dry run once** (`ias_dry_run_retire()`, on the
  first admin page or hourly run): on a site that was in it, every post type
  and Google are switched off so the update never starts posting by itself;
  a note under the switches names them until Settings is saved
  (`ias_dry_run_retired`). A site already live keeps its switches; Google
  stays on only where it was really sent.
- **Publish now.** A post whose time passed without it going out — posting
  was off, it waited for a yes, or its time came in the old dry run (now shown
  as **Not sent**) — gets **Publish now** in its page's bar
  (`ias_publish_now_state()`): it goes to OneUp 3 minutes ahead in the same
  click, its time pinned like a Move; on a roundup or Results post it is the
  approval. Never while posting is off for it (the bar says why), never on a
  Sunday, never with holds, a `[VERIFY]` marker or a stale draft. The calendar
  lists posts that passed their time in the last 7 days.
- Reasons name the type's short name ("Listing posts are off").
- Tests: `tests/wp/07e-switches.php` (the retirement, off means off, Publish
  now); the scheduler, decisions, OneUp, safety, networks and Google tests
  now send to the test OneUp instead of reading a dry-run log.

## 0.11.0 (2026-10-13) — Results posts that don't read alike; positive bidding words
DJ: "We need more variety in the Results posts. They read almost identical…
sometimes paragraphs and sometimes bullets… 1 out of every 5 posts being
emoji heavy… Saying Phone bid implies no onsite. Positive statements are
better."
- **Shapes.** Every post is now written in a shape that rotates by weight per
  post type (`ias_shapes()`, `ias_pick_shape()`, kept 45 days in the history
  as `shape`): paragraphs, bullets ("• " lines of facts), short (Auction Week
  and Results) and emoji-led (about one in five; up to 8 emoji, one a line).
  Never the shape the property's last post used. The prompt carries a
  `SHAPE:` line; the post keeps `content.shape`; its page says "Written as:
  <opening>, <shape>"; the history entry names both.
- **Results openings of their own** (`ias_templates_results()`): result first
  (only with a price), thanks first, place first, sale day first, to the
  neighbors — in place of the listing openings.
- **More calls to action:** thinking of selling?, follow the page, save the
  date, share it, and a thank-you. Results rotates through six (join the list,
  a question, selling, follow, share, thanks).
- **Positive bidding words.** The rules ask for what the sale is ("bid by
  phone through Intel Phone Bidding", "an in-person auction on site"), never
  what it isn't ("no onsite auction", "not online").
- **The bidding check reads negation** (`ias_negated_at()`): a way of bidding
  the sentence denies ("no onsite auction", "not an in-person sale", "no
  online bidding", "phone bidding is not available") is no mention of it, so
  a phone-bid post saying "no onsite auction" is no longer held as saying
  both. "On site" is matched with or without the space or hyphen.
- **Talk it over keeps the shape** (`ias_chat_shape_line()`): its prompt
  names the post's shape and keeps it unless the person asks for another.
- **The emoji cap follows the shape** (`ias_emoji_max()`): three, or eight on
  an emoji-led post (`emoji_max` in the check's context, kept on recheck).
- Tests: `tests/unit/variety.php` (the mix over 100 picks: emoji 15–25%, no
  repeat per property, paragraphs and bullets both used), negation cases in
  `tests/unit/checks.php`, and `tests/wp/02i-variety.php` (ten Results posts
  written in turn; the phone-bid "no onsite auction" post not held).

## 0.10.2 (2026-10-13) — OneUp's documented delete; no test draft left behind
With the account sent as a list, the live site's Verify OneUp and Verify for
Google saved their drafts, read them back and matched the time zone — then
failed to delete them: "OneUp answered HTTP 404: Page Not Found". The delete
went to `deletepost`, a guessed name OneUp does not have. It now goes to
OneUp's documented `deletescheduledpost` (POST, `post_id`).
- OneUp's 404 web page (no such address) is never taken for "the post is
  gone" (`ias_oneup_no_route()`); its own "not found" answer still is, when its
  list agrees.
- A test draft Verify cannot delete is not left for a person to delete in
  OneUp: its id is kept (`ias_oneup_verify_leftovers`) and the next Verify
  deletes it first ("Earlier test drafts"). The two drafts the 0.10.1 runs
  left (#53747135, #53747143) are read from those runs' own steps and deleted
  the same way, by their ids.
- The test fake answers the old name with OneUp's 404 page.

## 0.10.1 (2026-10-13) — the account goes to OneUp as a list
The live account refused Verify OneUp and Verify for Google at "Save a
draft": "OneUp said: Social Network ID must be an array." Every scheduling
call now sends the account as a list of one (`["1336677789520495"]`, as
OneUp's documentation writes it); should OneUp refuse that with the same
words, the call is made once more as `social_network_id[0]=…`, and the form
that works is kept (`ias_oneup_id_form`) and tried first from then on. A
refusal like that creates nothing, so the second try can never make a
second post; refused both ways, the call says so after two tries. What is
recorded and compared as sent is unchanged, so no post looks changed by it.
The test fake now refuses a plain id the way OneUp does.

## 0.10.0 (2026-10-13) — Nobody needs OneUp: instant both ways
DJ: "can we make sure that Jacob and the intel team never needs to touch
OneUp? I would love a clear 2 way communication between oneup and the plugin.
Instant update both directions."
- **To OneUp at once.** Save changes, Rewrite, Skip, Restore, Keep as
  written, Move, Try again, Edit it here again and Google's buttons send that
  post to OneUp in the same click (`ias_push_now()`), for Facebook and Google,
  under the hourly run's rules and lock, after reading OneUp's scheduled list
  (a light read that never concludes a delete). The page says what happened
  on OneUp. The push loops are one function per post now (`ias_push_one()`,
  `ias_gbp_one()`), shared by the hourly run.
- **From OneUp while anyone looks.** OneUp has no webhooks, so an open
  calendar or post asks it every minute (`ias_oneup_pulse()`, one full
  read-back for everyone looking, `ias_pulse`), with **Check now**; news
  shows at once on the calendar and as **Show the changes** on a post. The
  hourly run still reads back when nobody looks.
- **Never into OneUp.** **Move…** on a post (a day and a time; it holds —
  `flags.pinned` — and goes even inside the veto window: the person said
  yes; never Sunday), **Let the plan choose the time**, **Try again** on a
  failed post (a fresh copy; the failed one's id kept apart), **Edit it here
  again** on a post someone changed in OneUp (their version becomes the
  post's, nothing re-sent), **Send this version to Google instead** for a
  Google copy changed there. No screen, note or email tells anyone to do
  something in OneUp: the Monday email's OneUp button and Settings' OneUp
  calendar link are gone, and the veto window reads "skip, move or change it
  here".
- Tests: a single test file run on its own no longer leaves the next full
  run's install-defaults check failing.

## 0.9.0 (2026-10-13) — Google Business Profile; accounts chosen from lists; the calendar
DJ, on Social Settings after Check connection: "why if you can call and return
this am i entering the data rather then just having the system call, respond,
and give me a choice what to add. Also I want to be able to schedule GBM posts
from here." And: "the calendar page needs some UI work."
- **Look up accounts.** One button in Settings → OneUp asks OneUp for its
  categories and their accounts and keeps the answer (`ias_oneup_directory`:
  ids, names and kinds — never the key). The category, the Facebook page, the
  Google location and each other network's account are then chosen from
  lists — no id is typed. Each list offers only its own kind of account, from
  the category chosen; a category's only account of a kind comes ready chosen
  (saved only by Save settings). It fills the lists in place, keeping unsaved
  changes; without script it is a form. A saved account OneUp no longer lists
  stays, marked; one from another category is pointed out on save. The
  account's name in OneUp (which the read-back matches by) comes from the
  list. Check connection is gone — Look up accounts replaces it.
- **Google Business Profile**, through OneUp, with its own switch (**Send to
  Google**), its own **Verify for Google** (`ias_gbp_verify`), its own record of
  what was sent (`flags.gbp`, never Facebook's `flags.oneup`) and Facebook's
  rules on its own books: never twice (an attempt is looked for before it is
  sent again), replaced or taken back only by its OneUp id, a person's edit,
  move or delete in OneUp kept, a cancelled listing's copy off at once.
  Google's words are Facebook's without hashtags or phone numbers (Google
  refuses those posts), within 1,500 characters, with one picture (a video
  post's listing photo), a Learn more button to the listing and no first
  comment, a minute after the Facebook post. A refusal, or a failure to
  publish, stops Google for that post only — never the Facebook post. A
  post's Google tab says where it stands, with **Keep this one off Google** /
  **Send it to Google again**. Which post types go to Google is a setting.
  Until it is live, Google is a dry run like Instagram's. Live needs toolkit
  4.68.0, whose `ia_social_gbp_managed` this answers (while Listing posts go
  to Google): the toolkit then drafts no Google post, queues no "Review and
  post: GBP post", drops Google from its webhook and promo cards.
- **Verify** takes a network (`ias_oneup_verify($by, 'gbp')`); Facebook's is
  unchanged.
- **The calendar**: whole weeks (this week's earlier days greyed with what
  went out, the last week's days past the plan faint); a narrow Sunday
  column; a time never breaks across lines; a post with no time yet reads
  *no time yet* with a dashed edge; the key lists only the statuses on the
  calendar; the switches are one line (Facebook, then the other networks)
  with the chips folded under it. On a phone the calendar is a list — Today,
  then the days that carry posts, each run of empty days one line — instead
  of a two-column grid (the one-column rule was being overridden by the
  tablet one).
- Settings: the Verify buttons get room; a name badge no longer repeats the
  list beside it.

## 0.8.0 (2026-10-13) — Talk it over edits the whole post; Save changes never approves
DJ, on the first real chats: "give the AI agent a way to edit first comments
and anything else about the post. Also this needs a way to save changes
without approving."
- **The whole post.** Talk it over now changes the first comment, which
  pictures go with the post and in what order (from the post's and the
  property's own photos, which Claude is shown by key with their kind, grade,
  alt text and whether another post uses them), and their alt text — as well
  as the text. Unknown or repeated pictures are dropped; more than 10 never
  reach the screen. Undo and Use this version cover all of it, and the
  preview follows the pictures and the comment too. What Claude cannot
  change (the time, the type, the facts) it says, and where to change it.
- **Save changes, everywhere, never approving.** One button name in the Edit
  card, a "Not saved yet" strip in the chat and the Pictures card, all saving
  the words, the first comment, the pictures and their alt text in one go;
  **Save and approve** stays for an approve-first post. A post waiting for a
  yes gets one new approval email for everything saved at once (it was one
  per change). Pictures or alt text changed on their own no longer make the
  words a person's edit. Discard changes throws the unsaved away.
- **Alt text by hand** (✎ on each picture), kept on the post (`flags.alt`)
  through rewrites and re-picks; alt text alone needs no fresh yes.
- **The first comment is checked** on save: fair housing, `[VERIFY]`, and the
  listing link (a comment that lost it stops for a decision).
- **First comment lead-ins in Settings** (`comment_leads`). Results now says
  "View the full listing:" — DJ: never "how it sold". Posts already written
  keep their comment until edited or rewritten.
- **After a second UI/UX review** (every finding fixed or noted):
  - Claude's answer shows what it changed where DJ is looking — a word diff of
    the text or comment, thumbnails of new pictures — with **Show me**, and a
    note per answer kept true as the post changes (On the post above — not
    saved yet / Saved / Not on the post now). Was: the change happened off
    screen, and the notes went stale after Undo or a reload.
  - Undo puts back only what that answer changed and asks before it would
    throw away typing done since (it used to restore the whole screen).
  - The bar's Approve waits while unsaved changes are on the screen (it would
    have approved the saved words); Save and approve shows only then.
  - Save changes is greyed out until something changes, and a save with
    nothing changed saves nothing (it used to make the words a person's edit)
    — except on a post that stopped for a decision, which can be saved as it
    is to be checked again (Codex, PR #3).
  - Blank alt text goes back to the plugin's, even over a person's saved
    alt text (it put the saved one back; Codex, PR #3).
  - Alt text is edited in the tile (✎ is a 24px button, 32px on touch), not a
    browser prompt; on a phone ‹ › always show and a finger can drag
    (jquery-touch-punch).
  - A post's page folds the switches under one line, so the decision bar is
    on a phone's first screen; the Brand voice log stacks into cards on a
    phone; the Guide's tables scroll inside themselves.
  - The Approval card says "DJ or Jacob", not "Lane A" and email addresses;
    Rewrite says whose words it throws away; "Use these rules" says how many
    rules it adds and takes out (and is "Replace my rules with these" when it
    takes any out), and a greyed-out Suggest updates says why; no star on a
    post that can never be an example; the message after a save that stops a
    post points to the bar instead of repeating it.

## 0.7.0 (2026-10-13) — Talk it over, and the brand voice (every switch still off)
DJ, after reading more previews: "a little AI chatbot I can discuss the post
with and have it edit real time, then keep a log of final posts to learn the
brand voice over time."
- **Talk it over.** A chat under each post's text: say what you don't like and
  Claude rewrites the words in the editor as you talk, with the preview
  following unsaved. Undo, Use this version, quick asks (Shorter, Plainer,
  Warmer, Lead with the auction date, Fix grammar only), and a warning when a
  version would stop for a decision if saved. Nothing is saved until Save
  text, which checks it like any edit. Claude works under the drafts' rules
  (now shared, `ias_prompt_rules()`) and the house rules; it may use a fact
  the person gives it — saying the listing lacks it — never one of its own.
  One metered `social.chat` call per message, behind the no-Claude check, a
  per-post lock, a repeat guard (a double click is answered once) and at most
  30 answers a post (Settings → Talk it over). Kept in `{prefix}ias_chat`;
  what was asked follows the post into a Rewrite.
- **Brand voice** (new tab). House rules, up to 30, in every draft and chat
  after facts, bidding and fair housing (a fair-housing phrase is refused as a
  rule); **Add to the house voice** from the chat. The log of final posts
  (`{prefix}ias_voice`): every post a person saved or approved and every post
  that went out, Claude's draft beside the final words with a word-by-word
  diff, and what was asked in the chat. The newest posts a person wrote,
  approved or starred go into each new draft as examples (Settings: 3; never
  the same listing's, a held one or a marked one); a post that went out
  unread teaches nothing unless starred. **Suggest updates from the log**:
  one `social.voice` call; Claude's rules wait for a person to save them.
- **The rules are what lasts; the log is a window** (DJ: it "should write
  short rules and guides based on the changes made over time"). Claude reads
  the log on its own once 8 new posts teach, at most weekly (Settings → Learn
  on its own), and the suggestion waits on Brand voice, the calendar and the
  weekly email. Once a day the log forgets posts after 120 days (starred ones
  stay; 400 at most) and chat messages after 45; a person's chat message
  keeps a fingerprint of the words, not a second copy.
- Each draft now keeps Claude's words as written (`content.claude_text`).
- Guide: §3 named the old place for the Anthropic key; it is Services &
  Budgets from toolkit 4.67.0.
- DB 0.2.0 (two new tables); uninstall removes them, the four `ias_voice_*`
  options and the chat locks.

## 0.6.0 (2026-10-13) — More networks, pictures by hand, a cleaner post view (every switch still off)
From DJ's first look at a real draft and the UI review that followed:
- **Pictures by hand.** Each post's page has a picture editor: add any
  picture from the Media Library, drag (or ‹ ›) to reorder, × to take one out,
  up to 10; the first leads. A person's choice holds through rewrites and
  listing changes (`flags.media_by`); "Let the plugin choose again" hands it
  back (and the property's record of pictures used follows it, so its other
  posts avoid the right ones); an approved roundup or Results post needs a
  fresh yes.
- **Never the same view twice.** Narvon Country Home's post opened with two
  near-identical front exteriors. Every photo now gets a difference-hash
  fingerprint (`_ias_dhash`); two shots of one view never share a post, an
  Open House skips the Listing post's views, and the Listing post follows its
  lead with a different kind. A partly graded gallery is chosen from its
  graded photos only (no more SEO filenames as alt text).
- **No "12:00 am".** A sale with a date and no time (the date field reads as
  midnight) gives Claude no time, so it no longer holds the draft on
  `[VERIFY: auction time, facts say 12:00 am]`.
- **A `[VERIFY]` marker never goes out.** Keep as written cleared the hold and
  the marked text could reach Facebook; now it is refused (and not offered),
  and a post whose text or first comment carries a marker is never ready to
  push (`ias_has_verify()`).
- **More networks, provisioned.** Instagram, X and LinkedIn
  (`includes/channels.php`): rules, a switch and OneUp account id each in
  Settings, a renderer (Instagram: the words with "link in our bio" and square
  crops; X: one line from the facts within 280; LinkedIn: the words and the
  link), a preview tab each, and their requests in the dry-run log with
  take-backs. Nothing is sent to them, even with Facebook live — each needs
  its own Verify first. Facebook's requests are unchanged.
- **The OneUp key in Settings** (option `ias_oneup_api_key`, not autoloaded,
  never shown back or logged; a wp-config constant still wins). Verify OneUp
  now records the key it passed with: another key must verify again.
- **The post view, cleaned up** (UI review): a bar that says what the post
  waits for with the buttons beside it; Edit and Pictures | a Facebook-style
  preview | the details, in three, two or one columns; plain words in About
  this post; people's names in the history and the Log; the OneUp request
  folded; Approve the one primary button on a waiting post. The calendar puts
  Needs a decision, Waiting for approval and Corrections above the grid when
  there are any; Settings has a Save button near the top; the Log reads in
  words; nothing is wider than a phone.
- Claude's key is now set in the toolkit's Settings & Data → Services &
  Budgets (toolkit 4.67.0); Settings links there when the toolkit can.
- Tests: `02c-photos-and-time`, `02d-pictures`, `02e-post-view`,
  `07b-networks`, more in `05-decisions` and `08-oneup-safety`; the test
  site's month of fake Claude usage no longer spends the real cap.

## 0.5.2 (2026-10-13) — Without Claude, say so (every switch still off)
From the first audit of the live site, where the toolkit has no Anthropic key:
- **Nothing is tried without Claude.** When the toolkit has no Anthropic key,
  or the month's token cap is spent (`ias_ai_blocker()`), the hourly drafts
  and photo grading stop before calling anything. No post is stamped with the
  error or put on the six-hour wait, and no "failed" log line is written for
  each listing. Planning carries on.
- **Every Social screen says so**, under the switches ("Drafts are stopped"
  and why), and **Settings → Claude** shows ready or stopped, with the month's
  tokens against the toolkit's cap.
- Guide: §3 needs the toolkit's Anthropic key, and covers installing from a
  `main` branch zip and what the updater installs. §7 treats the toolkit's
  social webhook as optional (no URL means nothing to hand over), and turns
  Listing posts on as the only type when going live.
- `tests/wp/02b-claude-stopped.php`.

## 0.5.1 (2026-10-12) — Phase 5 review: OneUp safety (every switch still off)
Every finding of the Phase 5 review, each with a test against the fake OneUp
(`tests/wp/08-oneup-safety.php`, and the tests fail on 0.5.0):
- **Nothing is written to OneUp until Verify OneUp has passed for the page set
  now** (`ias_oneup_verified()`: every step ok, OneUp's time zone the site's,
  the same category and account). Until then the plugin stays in the dry run
  and Facebook stays with the toolkit's webhook; set another page and it must
  pass again. Verify needs the master switch on (dry run can stay on), sets
  its draft a month out, and fails — taking the post off at once — if OneUp
  schedules the draft as a real post. A test draft whose answer was lost is
  looked for (scheduled and drafts) and deleted.
- **Never sent twice.** An attempt is recorded before it is sent; when OneUp's
  answer is lost (no answer, a 5xx) it is not taken for a refusal — the next
  run (or the read-back) finds the post on OneUp's list by its text and time
  and adopts it, and only a post that is not there is sent again. The
  read-back no longer takes such a post for a person's Human post.
- **One push run at a time** (`ias_push_lock`, atomic; a crashed run's lock is
  taken over after 10 minutes). The read-back runs first in every push, so a
  person's change in OneUp is never overwritten.
- **"Deleted in OneUp by a person" needs proof**: only from a whole list that
  really is a list (an error or "too many requests" body is a failed read),
  only when missing on two read-backs at least 30 minutes apart, and never
  when none of the plugin's posts are on the list (logged instead).
- **Only an id takes a post off.** A bare `id` in an answer is no longer taken
  for a post id; a post without one is found on the list by text and time, and
  until then is neither replaced nor taken back (a take-back that cannot find
  it says so, to delete by hand). OneUp's "not found" to a delete is believed
  only when its list agrees; a "not found" while replacing, once confirmed, is
  a person's delete and stands.
- **Pause with a changed post**: a post on OneUp that changes while it is
  paused (or its type is off) is taken back rather than left to go out with
  the old words, and goes again when allowed.
- **A person's edit in OneUp holds**: a locked post whose listing changes stops
  as Needs a decision (fix it in OneUp) and is never redrafted by Claude; a
  post on OneUp keeps its listings when another sale joins its day (the
  newcomer gets its own Auction Week or Results post).
- **The key never travels on**: every OneUp error is redacted (as typed or
  URL-encoded) before it reaches the log, a note or an email.
- **The master switch off means no call at all**, from Settings too: Check
  connection and Verify OneUp are refused (and their buttons disabled, saying
  why).
- Read-back: a renamed page is followed; a post that went out although it was
  cancelled or skipped here says so; a post OneUp has not confirmed a day after
  its time gets a note, once. Published lists are read 4 pages deep.
- Back in the dry run, the calendar lists posts still on OneUp that the plan no
  longer wants as they are, to delete there.

## 0.5.0 (2026-10-12) — Phase 5: OneUp live (built, every switch still off)
- **Live pushing.** With the master switch on, dry run off and the key and ids
  set, each ready post of a type that is switched on is scheduled on OneUp for
  the Facebook page; a changed or moved post is taken off and scheduled again;
  a post that stops being ready is taken back; a cancelled listing's posts come
  off at once (before the page that cancelled them has finished). Pause and a
  type's switch stop new posts, never a take-back. A refused post is retried
  each hour and stops as Failed after the third refusal; a replace refused
  inside the 48 hours is retried, not left off OneUp. The live record
  (`flags.oneup`: id, fingerprint, text, time, facts) works exactly like the dry
  run's, and going live clears the dry run's record. On the calendar a post on
  OneUp shows as Pushed, with its OneUp id.
- **Read-back** every hour while the master switch is on, for the Facebook page
  only (the category's Google Business Profile and YouTube posts are ignored):
  a post a person moved or edited in OneUp keeps their version and is locked;
  one a person deleted there stays off the plan; a person's own posts on the
  page become Human posts the plan works around (the spec's acceptance test: a
  human post in OneUp moves the schedule around it); published posts are
  confirmed; posts OneUp could not publish stop as Failed with its reason.
  OneUp's scheduled times are read in the account's time, published ones as
  UTC — as the real account reports them.
- **Verify OneUp** (Settings): a safe round trip — a draft that never publishes
  is saved, read back, its time compared with the site's, and deleted; the
  scheduled, published and failed lists are read. Each step is shown. Turning
  live pushing on before it passes is warned about.
- **Corrections.** A post that went out is never edited; when a fact it carried
  changes, a correction comment is drafted ("Update: the starting bid is now
  $275,000 (not $300,000).") for a person to post, listed on the calendar and
  in the Monday email, with Comment posted / Not needed.
- **Monthly summary** at the top of the Log screen and in the first weekly
  email of each month: posts by type and division (out, still to come,
  dropped, skipped, cancelled, failed, late) and the site visits each type
  brought — from Google Analytics through the toolkit's Site Kit connection,
  using the utm_campaign / utm_content tags every link already carries.
- **Pause no longer hands Facebook back to the toolkit's webhook** (it would
  have posted what the plugin posts on resume); `ia_social_facebook_managed` is
  true once Listing posts can really reach OneUp.
- Fixes found on the way: OneUp listings answered as `{"accounts": […]}` /
  `{"categories": […]}` (the real shape) were not read, so Check connection
  could show no accounts; a failed post can now be skipped; a post edited in
  OneUp is no longer editable here (change it in OneUp).
- Tests: a fake OneUp shaped like the real account (`ias_t_oneup()`), the
  Phase 5 acceptance test `tests/wp/07-oneup-live.php` (71 checks), and unit
  tests for reading OneUp's answers in any of their shapes.

## 0.4.4 (2026-10-12) — from the Codex review of the build PR
- **Two open-house sessions on the same day** share that day's Open House post
  (the spec's "each open house date") and its brief gives both times, in time
  order. Before, the second session silently replaced the first, so the post
  named only one of them.
- **`ia_ui_empty`** is now on the list of toolkit functions checked before the
  plugin boots (the empty Calendar and Log call it), so a half-loaded toolkit
  leaves the plugin off with its notice instead of a fatal on those screens. A
  new test, `tests/unit/contract.php`, fails on any toolkit call that is neither
  on that list nor an optional helper guarded at every call.

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
