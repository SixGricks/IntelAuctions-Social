# Intel Auctions Social changelog

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
- Each draft now keeps Claude's words as written (`content.claude_text`).
- Guide: §3 named the old place for the Anthropic key; it is Services &
  Budgets from toolkit 4.67.0.
- DB 0.2.0 (two new tables); uninstall removes them, the three `ias_voice_*`
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
