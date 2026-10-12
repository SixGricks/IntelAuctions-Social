# Intel Social Posting — Build Spec

Oct 12, 2026 · @DJ

<!-- DJ's spec as reviewed on Oct 12, 2026, kept verbatim as the record of
     what this plugin was built to do. Change the plugin's behaviour in
     docs/guide.md and the code, not here. -->

## Summary

Build a sister plugin, Intel Auctions Social. It turns each auction on intelauctions.com into a set of Facebook posts, schedules them three a day through OneUp, and keeps a person in the loop on the posts that carry hard facts. This spec is for DJ's review before anything goes to Claude Code. It replaces the build prompt drafted earlier today.

Decisions it rests on (Oct 12):

- OneUp is the scheduler. Jacob has a client seat, and DJ and Jacob review the calendar weekly.
- Three posts a day at most.
- A 48-hour veto window on scheduled posts.
- No boosting for now.
- Every property is guaranteed a listing post and a results post. Starting bid only breaks ties between the other posts.
- Blog posts are fill-in only and stay paused.
- New today: no individual Recently Announced posts. Those listings rarely have photos yet, so they roll into one weekly roundup post instead (see Post types).

Settled in review (Oct 12):

- Never post on Sunday.
- The roundup stays Friday-only and uses a collage of brochure covers.
- Results posts keep nudging until someone approves them.
- Facebook Events are in this build.
- The code lives in a new repo, SixGricks/IntelAuctions-Social.
- Jacob sees it after the build.

## Sister plugin, not part of the main toolkit

- **It's safer.** This code calls outside services (OneUp, Claude, image processing) on a daily schedule. If a OneUp change or a bad cron run breaks it, deactivating the social plugin turns it off without touching listings, schema, SEO or the auction display.
- **It can be switched off as one unit.** Pausing social becomes one click on the Plugins page, not a hunt through settings.
- **It ships on its own schedule.** It'll change weekly while you tune it. The main toolkit, at 80+ files and version 4.64, shouldn't take a release every time a post template changes.
- **It still reuses everything.** It declares a dependency on the toolkit and calls its helpers: listing fields, stages, team leads, geocoded location, the Claude engine and the event log. If the toolkit is missing or too old, it shows an admin notice and does nothing.
- **The cost:** one more repo and updater to maintain, and a version check between the two. That's small next to the safety gain.

The toolkit keeps its existing webhook push for Google Business Profile. The social plugin takes over Facebook only.

## Architecture

Listings stay the single source of truth in the toolkit. The social plugin writes the posts, plans them each morning, and pushes Listing, Open House and Auction Week posts straight to OneUp. Only the weekly roundup and Results posts stop for an emailed yes first. OneUp reports human posts and hand edits back, so the next plan works around them.

## Post types

Each property gets four posts of its own, and its announcement shares a weekly roundup with that week's other new listings. The listing post is now the first post a property gets, because that's when photos exist.

| Post | Trigger | Window (earliest → ideal → latest) | Content | Weight | Guaranteed | Approval |
|---|---|---|---|---|---|---|
| New This Week roundup | Every Friday, if any listing entered Recently Announced that week | Fri → Fri → Sat | One post naming every new sale (address, date, town). A collage of the new listings' brochure covers, with a branded card filling in where a cover doesn't exist yet. Link to the Recently Announced page in the first comment | 60 | Yes, if there are any new listings | Email (Lane A) |
| Listing Posted | Listing moves to Upcoming | promotion day → same day → +7 days | Up to 10 photos, key details, open houses, auction type, lead and phone | 70 | Yes | Scheduled (Lane B) |
| Open House | Each open house date | 7 days before → 5 → 3 | AI video if one exists, otherwise 3–5 photos not used in the listing post. Time range and duration | 90 | No | Scheduled (Lane B) |
| Auction Week | Auction date | 10 days before → 7 → 3 | Different photos, an urgency angle, the auction time and bidding type | 80 | No | Scheduled (Lane B) |
| Results | Listing moves to Past and has a sale price | next day → next day → whenever approved | Featured photo with the sale price over it in white. Name, address, date, result | 100 | Yes | Email (Lane A) |
| Human post | A person posts directly | — | Whatever they wrote | outside scoring | Never moved or dropped | None |
| Blog launch, excerpt, re-share | Paused | — | — | fill-in only | No | Email for launch |

Special cases:

- **Same-day sales.** When two or more properties sell the same day (the three Sunbury twins on Nov 18, both Lila Lane duplexes on Nov 4), their Auction Week and Results posts merge into one post.
- **Multi-parcel sales** (393 Boyer Rd) lead with the parcel breakdown and starting bids.
- **Liquidation and contents sales** (Superior Roofing, the Boyer Rd contents) have no acreage or single sale price. Their Results post becomes a "thank you, great turnout" post with no price graphic.
- **Bidding type.** Every post states exactly one: in person or Intel Phone Bidding. A post never implies buyers can choose.
- **Late listing post.** If a listing's photos never arrive before its auction week, the Auction Week post runs anyway using the brochure cover, and the Listing post is skipped.

**Facebook Events (in this build).** Each open house and each auction day gets a Facebook Event, created when the Listing post goes out. Anyone who taps Interested gets Facebook's own reminder.

One catch to verify first: as far as I know, Facebook no longer lets outside apps create Page events through its API, and OneUp may not offer it either. If that holds, the plugin still prepares each event: title, date and time, address, description and cover photo. It puts a "Create event" card on the Social Calendar and in the Monday email, so making the event in Meta Business Suite takes about a minute of copy and paste.

## Copy and photos

Claude writes every post through the toolkit's existing Claude engine. All facts come from the listing, and nothing is typed twice.

Where the facts come from:

- Address, town and county come from the geocoded listing. The county is never guessed.
- Auction date, time and bidding type.
- Starting bid and acreage.
- Open houses, with duration.
- Sellers.
- The lead's name and direct phone, from the team record.
- Sale price, from the auction results field.

Templates:

- Five rotating post templates.
- Each division gets its own voice on top of them: Farm & Home, Investment & Recreation, and Intel general (liquidations, equipment).
- No opening line, call to action or hashtag set repeats within 30 days. The plugin keeps a history to check against.

Calls to action rotate:

- Call the lead.
- Join the email or call list.
- Comment with a question.
- Tag someone who'd want it.
- Reply for the brochure.

Voice: plainspoken and local, the way Intel talks at a sale. No stock marketing gloss, no emoji walls, no stacked exclamation points.

Links:

- The listing link goes in the first comment, not the post body, because Facebook shows link posts to fewer people.
- Every link is tagged so website traffic can be traced back to the post type.

Photo grading:

- When a listing gets its gallery, Claude scores each photo once for quality and tags it by kind: aerial, exterior, interior, barn or outbuilding, land.
- Each post for a property leads with a photo that property hasn't used yet. Nobody sees the same aerial twice.
- Listing posts mix kinds. Open House posts favor interiors. Auction Week posts favor the strongest aerial not yet used.
- The AI property video is used for the Open House post when the listing has one.
- Every chosen image is saved in three crops (landscape, square, vertical) from day one, so adding Instagram later doesn't mean re-cropping a season of photos.
- Every image gets alt text.

Results graphic: the sale price goes over the featured photo in white, in Intel's brand font, with a dark gradient behind it for legibility. The plugin builds it, so no Canva step is needed. The weekly roundup collage is built the same way, from the new listings' brochure covers.

## Scheduler

Every morning at 5:00 the plugin re-plans the next 30 days, pushes the next 21 to OneUp, and never schedules anything less than 48 hours out.

Limits:

- Never on Sunday. Monday through Saturday, at most 3 posts a day, at least 3 hours apart. A post window that includes a Sunday skips it, and a Results post for a Saturday sale goes out Monday.
- Posting windows: 6:00–7:30 AM, 11:30 AM–1:00 PM, and 7:00–9:00 PM.
- Each post time shifts by up to 25 minutes, so nothing lands on the hour.

Priority = post weight (table above) × starting-bid multiplier:

| Starting bid | Multiplier |
|---|---|
| $500,000 and up | ×1.2 |
| $250,000–500,000 | ×1.1 |
| $100,000–250,000 | ×1.0 |
| Under $100,000, or none listed | ×0.9 |

Guarantees: the Listing, Results and weekly roundup posts can slide within their windows but are never dropped. Only Open House and Auction Week posts can be dropped, plus blog fill-ins.

The daily run, in order:

1. Read what's already on the page. Human posts, and any posts Jacob or DJ edited in OneUp, are locked in place.
2. Cancel every pending post for a listing that was unpublished or deleted.
3. Place every remaining post on its ideal day.
4. Any day over three: the lowest-priority post slides to the nearest day in its window with room. A higher-priority post can bump a lower one.
5. A droppable post with no room anywhere in its window is dropped and logged with the reason.
6. Any day with fewer than two posts pulls in the best post whose window reaches it, then blog fill-ins once blogs resume.
7. Apply the sense rules: one post per property per day, no two same-county posts on one day, and a mix of divisions within a day where possible.
8. Push changes to OneUp: new posts, moved posts, and cancellations.

## Approval and review

Posts that carry hard facts need a yes from DJ or Jacob before they're scheduled. Everything else is scheduled with a 48-hour window to veto it.

**Lane A: approve first.** For the weekly roundup and Results posts. The email goes to dj@ and jacob@intelauctions.com with the full text, an image preview, and two buttons:

- Approve queues the post. The first person to approve wins.
- Edit opens the post in WordPress so you can change the text and then approve it.
- The buttons are signed one-time links, and each reminder carries fresh ones.
- Nothing in Lane A is pushed until someone approves it.
- A post waiting for approval gets a reminder every morning until someone answers. It never expires. No approval emails or reminders go out on Sunday.

**Lane B: veto window.** For Listing, Open House and Auction Week posts. They go straight onto the OneUp calendar at least 48 hours out. Either of you can edit, move or delete them there, and the plugin respects that on its next run.

Weekly review:

- A Monday 7:00 AM email to both of you covers the week ahead, what's new since last week, any posts dropped (with the reason), anything waiting for approval, and a link to the OneUp calendar.
- You and Jacob go over it together once a week.

## OneUp and safety switches

The plugin talks to OneUp's public API with an API key stored in wp-config. Facebook is the only channel at launch.

What it uses OneUp for:

- Scheduling image, text and video posts to the Intel Facebook page.
- Editing and deleting scheduled posts when the plan changes.
- Reading back scheduled and published posts, which catches human posts and edits made in OneUp.
- Posting the first comment that carries the link. If OneUp can't do that, the link goes at the end of the post instead.

Built to grow: each post is stored as plain content (text, images, video, link), and a small renderer turns it into a Facebook post. Instagram and LinkedIn are later renderers, not a rebuild.

Safety switches, all off at install:

- **Master switch.** Off means the plugin plans and logs but never calls OneUp.
- **Dry run.** Writes the exact payloads it would send to a log screen.
- **Per post type.** Turn on Listing posts first, then the rest one at a time as each earns trust.
- **Pause button** on the calendar screen. Stops all pushes immediately, without deactivating the plugin.

## Admin screens, data and safeguards

Screens (under Auctions in wp-admin):

- **Social Calendar:** 30 days at a glance, with each post marked planned, waiting for approval, approved, pushed, published, dropped or human. Click a post to preview, edit, approve, skip or regenerate it.
- **Listing panel:** on each auction's edit screen, that property's posts and their status.
- **Settings:** OneUp key status, the switches, weights and multipliers, daily cap, posting windows, and approval emails.
- **Log:** every generate, approve, push, edit read-back, drop and cancel.

Data stored (two custom tables, removed cleanly on uninstall):

- Posts: listing, type, status, score, window, scheduled time, OneUp post ID, content, images used, approvals.
- Usage history: openers, calls to action, hashtags, and images already used, for the no-repeat rules.
- Photo grading results are stored per image, so the grading runs only once.

Safeguards:

- **Fair-housing screen** on every draft. It catches phrases like "perfect for families" or "safe neighborhood", and a flagged post stops for a human decision.
- **Cancellation on status change.** Unpublishing or deleting a listing cancels its posts at once, without waiting for the morning run. Intel doesn't postpone sales, so there's no postponed status to watch. A "Cancel posts" button on the listing panel covers a rare exception.
- **Never edit a live post.** Editing resets its reach, so corrections are posted as a comment.
- **Logging.** Every post records when it went out and how it did. A monthly summary shows which post types and divisions earn their slots.
- **Cost cap.** Claude usage runs through the toolkit's existing monthly token cap.

## Build phases and acceptance tests

Each phase ships with publishing off and is checked before the next one starts. Nothing reaches Facebook until DJ turns a switch on.

| Phase | What's built | Done when |
|---|---|---|
| 1. Foundation | Sister plugin shell, dependency check on the toolkit, tables, settings, log, Social Calendar (read-only) | Activates cleanly next to the toolkit. With the toolkit off, it shows a notice and does nothing |
| 2. Content | Post generation for every type, photo grading, three crops, results graphic, fair-housing screen | Every current listing shows a full, readable set of drafts on the calendar. DJ reviews 10 for voice |
| 3. Scheduler | The daily run, guarantees, windows, sense rules, cancellations, dry-run payloads | A test with a busy November (35 auctions) drops no guaranteed post, keeps the 3-a-day cap and the 48-hour floor, and puts no same-county posts on one day |
| 4. Approval | Lane A emails with Approve/Edit links, the Monday email | A test Results post round-trips: email, approve, then queued. An expired link fails safely |
| 5. OneUp live | Push, edit read-back, cancel, first-comment link. Listing posts switched on first | One week of Listing posts publishes as planned, and a human post in OneUp moves the schedule around it |
| 6. The rest | Open House, Auction Week, Results, the roundup and Facebook Events switched on one at a time | Two weeks clean per type before the next one turns on |

Phases 1–4 can be built and tested in a few Claude Code sessions with no risk to the page. Phase 5 is the first point where anything is public.

## Answers from DJ (Oct 12)

- [x] Weekly roundup: Fridays only, even in heavy weeks.
- [x] Roundup image: a collage of the new listings' brochure covers.
- [x] Postponed listings: this doesn't happen at Intel. No postponed status is needed; a manual "Cancel posts" button covers the rare exception.
- [x] Results without approval: keep nudging every morning until someone answers. The post never expires.
- [x] Facebook Events: in this build. If Facebook's API won't create them, the plugin preps each event for a one-minute manual create.
- [x] Repo: a new SixGricks/IntelAuctions-Social repo with its own updater, the same pattern as the toolkit.
- [x] Jacob: shown after the build.
- [x] Sundays: never post on Sunday, and send no approval emails or reminders that day.
