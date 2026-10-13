# Intel Auctions Social — Guide

> **Documented version: 0.6.0.** The canonical user documentation, rendered inside
> WordPress at **Auctions → Social Calendar → Guide**. If you change a post type,
> rule, screen, setting, email or anything the plugin stores, update this file in
> the same commit (see `CLAUDE.md`). What the plugin is built to do is DJ's spec,
> `docs/build-spec.md` — apart from Facebook Events, which DJ dropped on
> Oct 12, 2026: the plugin makes no events and prepares none.

## 1. What this does

Intel Auctions Social turns each auction on intelauctions.com into a set of Facebook posts, plans them three a day through OneUp, and keeps a person in the loop on the posts that carry hard facts. It is a separate plugin from the Intel Auctions Toolkit on purpose: it calls outside services on a schedule, and if something goes wrong, deactivating it on the Plugins page turns Facebook posting off without touching listings, schema or SEO.

It reads everything from the toolkit — the listing's fields, its stage, its open houses, its lead and their phone, its town and county, its sale price — so nothing is typed twice. **It needs the Intel Auctions Toolkit 4.66.0 or newer.** Without it, it shows a notice at the top of the admin and does nothing at all.

## 2. Build status

The plugin is built in phases. Each ships with publishing off and is checked before the next one starts. Nothing reaches Facebook until DJ turns a switch on.

| Phase | What it adds | Version |
|---|---|---|
| 1. Foundation | The plugin, the dependency check, its tables, settings, the log and the Social Calendar (read-only) | 0.1.0 |
| 2. Content | Drafts for every post type, photo grading, crops, the results graphic, the fair-housing screen | 0.2.0 |
| 3. Scheduler | The 5:00 plan, the rules, cancellations, dry-run payloads | 0.3.0 |
| 4. Approval | The approval emails and their links, morning reminders, the Monday email | 0.4.0 |
| 5. OneUp live | Pushing to OneUp, reading back, cancelling, Verify OneUp, corrections, the monthly summary | 0.5.0–0.5.2 |
| More networks, pictures by hand | Instagram, X and LinkedIn planned and dry-run (not sent yet); add, reorder and remove a post's pictures; the keys in Settings; a cleaner post view | 0.6.0 |
| 6. The rest | Each post type switched on in turn | — |

This version is **phase 5, built and switched off**: every current listing's posts are drafted, checked and planned to the minute every morning; the roundup and Results posts are emailed to DJ and Jacob for a yes; the Monday email goes out; and the pushes to OneUp, the hourly read-back, cancelling, corrections and the monthly summary are all in place. With the switches as they ship, what would go to OneUp is written to the log as a dry run and nothing reaches Facebook. Phase 5 is done when one week of Listing posts publishes as planned — **Going live** (§7) is the checklist for starting that week.

## 3. Installing it

1. Make sure the **Intel Auctions Toolkit** is active at **4.66.0** or newer, with its **Anthropic API key** set (Auctions → AI Content): every draft and photo grade is a Claude call through the toolkit, and without the key nothing is written (§6).
2. Plugins → Add New → Upload Plugin, choose `intel-auctions-social.zip` from the latest release at github.com/SixGricks/IntelAuctions-Social/releases, then **Activate**. A zip of the repository's `main` branch (Code → Download ZIP) installs too, as `IntelAuctions-Social-main`; updates keep whichever folder it is in. Updates then arrive through WordPress like any plugin's: the updater takes the latest release that is not a pre-release (its tag with or without a `v`), installs the release file named exactly `intel-auctions-social.zip`, and falls back to the release's source zip when there is none.
3. Paste the OneUp API key (from oneupapp.io → API access) into **Settings → OneUp → API key**. It is kept for this site only, never shown again or written to the log. Or define `INTEL_ONEUP_API_KEY` in `wp-config.php`, which wins over the one in Settings.
4. In **Settings**, press **Check connection**, copy in the OneUp category id and the Facebook page's account id (for Intel: category **Intel**, page **Intel Auctions (Narvon)**), save, and press **Verify OneUp** (§7). Everything stays off until you turn it on.

If the toolkit is missing, too old, or failed to load part of itself, a yellow notice says **Intel Auctions Social is doing nothing** and why. No menu appears and nothing is planned until the toolkit is back.

## 4. The screens

Everything is under **Auctions → Social Calendar**, one menu item with four tabs. It stays in the menu in simple mode.

### Calendar

The next 30 days at a glance, Monday to Sunday. Each post is a chip with its type, its time and the property, coloured by status:

| Status | Means |
|---|---|
| Planned | In the plan, not yet sent anywhere |
| Needs a decision | Stopped for a person: a fair-housing phrase, a missing fact or a `[VERIFY]` marker in the draft, or the listing changed after a person edited the post |
| Waiting for approval | A roundup or Results post emailed for a yes (Lane A) |
| Approved | Someone said yes; it goes to OneUp on the next run |
| Pushed | On the OneUp calendar (live pushing on); its view shows the OneUp post id |
| Published | Live on the Facebook page. In the dry run, a post whose time came while it was "sent" shows as Published too, marked **dry run** — nothing went anywhere |
| Dropped | A post that could be dropped had no room in its window (the reason is logged) |
| Skipped / Cancelled | A person skipped it (here, or by deleting it in OneUp), or the listing was unpublished or deleted |
| Failed | OneUp refused it three times in a row, or could not publish it — it waits for a person (retry it in OneUp, or skip it) |
| Human | A post a person scheduled for the page in OneUp; the plan works around it and never touches it |

Sundays never carry a post. The hatched days are inside the 48-hour veto window, where nothing new is scheduled. Each day shows how many of its three places are taken.

Click a post to open it. Under the title, one line says where it stands: its status, when it goes out, its county and division, and the listing. Then:

- **A bar across the top** when the post waits for something — a decision (with the reasons, any `[VERIFY: …]` marker picked out in red), an approval, a rewrite after the listing changed, photos, or a person who skipped it — with the buttons that settle it right beside the reason. A post that waits for nothing has no bar.
- **Edit** (left): the post text and the first comment, full width, and **Save text**.
- **Pictures** (left, under Edit): the post's pictures in posting order, square, each with its alt text underneath; the first one is marked **Lead**. Drag a picture to move it (or use ‹ ›), × takes it out of this post (not out of the Media Library), **+ Add pictures** opens the Media Library — any picture there, not only the listing's gallery. **Save pictures** lights up once something changed; a post carries at most 10. Pictures chosen by hand hold: a rewrite, or the listing changing, keeps them, and **Let the plugin choose again** hands the choice back. A roundup or Results post needs approving again with new pictures. Leaving the page with unsaved words or pictures asks first.
- **Preview** (middle): the post as Facebook shows it — the page's name and time, the words with any `[VERIFY]` marked, up to five pictures as Facebook lays them out, and the first comment as a comment. With Instagram, X or LinkedIn switched on (§7), a tab for each shows the post as that network would get it.
- **About this post**, **What would go to OneUp** (the request for each network, folded), **Approval** for a roundup or Results post, and **History** (newest first, with people's names) on the right.

On a wide screen these are three columns; on a laptop, two (the preview over the details); on a phone, one, with the preview first. From the bar and the Edit card:

- **Save text** — change the words (and the first comment). The new text is checked again like a fresh draft, and saving it counts as checking it against the listing as it is now. Once a person has edited a post, the plugin never rewrites it behind their back: if the listing changes afterwards, the post stops (**Needs a decision**, even if it was already approved) until you save it again, keep it as written, or press **Rewrite**.
- **Rewrite** — have Claude write it again (at most once an hour per post; it counts against the monthly Claude cap). **Write it now** does the same for a post with no draft yet.
- **Skip this post…** — opens to take it off the plan, with a reason if you like. A skipped post stays skipped until you press **Restore to the plan**. Restore also overrides a skip the plugin made itself (say, "Facebook already got the full listing from the webhook"): it stays on the plan, with a week from today to go out.
- **Keep as written** — for a post that stopped for a decision: you have read why (including "the listing changed"), and it is right as it is. Not for a `[VERIFY: …]` marker: Claude left it where a fact was missing, and a marked fact never goes out as it is — put the fact in its place and save, or fix the listing and let the post be rewritten. A post that stopped stays stopped until someone decides — being dropped and brought back, restored, or called for again never turns it into Planned; and a later edit that stops it again needs a new decision.
- For a roundup or Results post: **Approve**, **Send for approval now** (before the hourly run would), **Send a reminder now**, and **Save and approve** beside Save text. Its **Approval** box shows who was emailed when and who said yes.
- A post on OneUp shows its **On OneUp** box: its OneUp post id and time, and whether a person moved or edited it there. A post a person changed in OneUp is no longer edited here — change it in OneUp; the plugin keeps their version and never replaces it.

Above the grid, when the plan last ran and what it did; beside **Pause**, **Plan now**. Then, only when there is any, what waits on a person: **Needs a decision** (drafts that stopped and posts that failed, with why), **Waiting for approval** (roundup and Results posts emailed for a yes, how long ago and how many emails) and **Corrections to post as a comment** (§7). Under the grid: **Drafts** (how many planned posts have no draft yet, and **Write drafts now**, which writes up to four on the spot), **Waiting for photos** (Upcoming listings whose Listing post waits for a gallery), **Dropped in the last 30 days** (with each reason) and **Running late or squeezed** — one line when there are none.

Across the top of every screen, the **switches strip** shows the master switch, dry run, pause, each post type and any other network switched on (always "dry run only" for now), so it is always plain whether anything can reach Facebook. **Pause all pushes** on the calendar stops every new post going to OneUp at once without deactivating the plugin; **Resume pushes** undoes it. Planning, drafting and the read-back carry on while paused, a cancelled listing's post is still taken off OneUp, and Facebook stays with this plugin (a pause must not let the toolkit's webhook post what this plugin posts on resume).

### Settings

- **Safety switches**, all off at install: the **master switch** (off = the plugin plans and logs but never calls OneUp; on = it reads back what OneUp holds for the page every hour), **dry run** (on = it writes the exact payloads it would send to the log; off, with the master switch on, it sends them), **paused**, and one switch per post type. Turn on Listing posts first, then the rest one at a time as each earns trust. A type that is off is still planned and drafted, so you can read it on the calendar.
- **OneUp**: the API key (paste it here — blank keeps the saved one, *remove key* clears it; a key defined in wp-config wins), the category id and the Facebook page's account id in OneUp (with the page's name as OneUp lists it, looked up by Check connection), the link to the OneUp calendar for the Monday email, and whether the listing link goes in the first comment (untick only if OneUp cannot post first comments — the link then ends the post).
- **Priority**: each post type's weight and the starting-bid multipliers. Priority = weight × multiplier.
- **Limits and posting windows**: at most 3 posts a day, at least 2 when posts can be pulled in, 3 hours apart, in the windows 6:00–7:30 AM, 11:30 AM–1:00 PM and 7:00–9:00 PM, each time shifted by up to 25 minutes; the 48-hour veto window; how far ahead it plans (30 days) and pushes (21 days), and the hour the morning plan runs (5:00).
- **Approvals and the weekly email**: who approves (dj@ and jacob@intelauctions.com), and when the weekly review email goes (Monday 7:00; any day but Sunday). Below the form: **Send the weekly email now**, **Check connection** and **Verify OneUp** (both need the master switch on; Verify OneUp shows the result of its last run and **passed for this page** once it has, §7), and when the read-back last ran and what it found.
- **More networks**: Instagram, X and LinkedIn — a switch and the OneUp account id for each (§7). Off at install.
- **Links back to the site**: the tracking tags on every link.
- **Copy and pictures**: the brand font for the graphics, more fair-housing phrases to stop on, the size photos are graded at (bigger costs more), and how many drafts are written an hour — with **Claude**'s state beside them: ready, or stopped and why (no Anthropic key in the toolkit, or the month's cap spent), the tokens used this month against the toolkit's cap, and a link to where the toolkit keeps both (Intel SEO → Settings & Data → Services & Budgets, from toolkit 4.67.0).

A second **Save settings** button sits under the safety switches, so the top of the page saves without scrolling.

### Log

It opens with the **monthly summary** (§8) — this month so far and last month. Then everything the plugin did, newest first: each plan, draft, approval, push, read-back, drop and cancel, and every settings change. Each entry says what happened in words, who did it (by name; "the plugin" for the hourly run) and which post. **Show** narrows it to one kind; *Dry run* shows the exact requests that would have gone to OneUp, each one expandable, marked with the network for Instagram, X and LinkedIn. The log lives in the toolkit's event log, which keeps the newest 5,000 entries across both plugins.

### Guide

This page.

### On each auction

The edit screen of every auction has a **Social posts (Facebook)** box with **Cancel posts** (and **Resume posts** once pressed), listing that property's posts — its own and the ones it shares (a same-day sale, the weekly roundup) — with each one's day and status, and a link to the calendar. Below the list, three things the site has no field for, saved with the auction:

- **Sale kind** — real estate, or a liquidation / contents sale (its Results post is a thank-you with no price graphic). Left alone, the title decides: "liquidation", "contents", "equipment", "tools", "personal property" and the like with no acreage mean a liquidation.
- **Parcels** — for a multi-parcel sale, one per line: `name | size | starting bid`. The Listing post then leads with the parcel breakdown.
- **Brochure cover** — the Media Library id of the brochure's cover (an image, or the brochure PDF itself: WordPress keeps a picture of its first page). Used in the Friday collage, and for a listing whose photos never arrived. Left blank, the auction's `brochure` field is used if it has one, else a branded card stands in.

## 5. Post types

From the build spec. Weight sets priority; a **guaranteed** post can slide within its window but is never dropped; **Lane A** posts wait for an emailed yes, **Lane B** posts are scheduled with a 48-hour window to veto them.

| Post | When | Window | Weight | Guaranteed | Lane |
|---|---|---|---|---|---|
| New This Week roundup | Fridays, when any listing entered Recently Announced that week | Fri → Fri → Sat | 60 | Yes, when there are new listings | A |
| Listing Posted | The listing moves to Upcoming | promotion day → same day → +7 days | 70 | Yes | B |
| Open House | Each open house | 7 days before → 5 → 3 | 90 | No | B |
| Auction Week | The sale | 10 days before → 7 → 3 | 80 | No | B |
| Results | The listing moves to Past with a sale price | next day → next day → whenever approved | 100 | Yes | A |
| Human post | A person posts directly | — | outside scoring | Never moved | — |

Starting-bid multipliers: $500,000 and up ×1.2; $250,000–500,000 ×1.1; $100,000–250,000 ×1.0; under $100,000 or none ×0.9.

## 6. How posts are written

### Which posts each listing gets

The plugin checks every hour which posts the listings call for (the table in §5) and keeps the Social Calendar in step:

- A listing in **Recently Announced** gets no post of its own; it goes into that Friday's **New This Week** roundup (announced from the Friday before through Thursday). Listings already posted in full to Facebook by the toolkit's webhook before this plugin took Facebook over don't get a second Listing post — theirs shows as skipped, with the date.
- Moving to **Upcoming** (Publish Upcoming) starts the **Listing** post's window that day — or the day photos arrive, if they come later. A listing that is already Upcoming when the plugin first sees it counts from that day. With no photos yet, the Listing post waits (under **Waiting for photos**). If the photos still aren't there when its auction week starts, the Listing post is skipped and the **Auction Week** post runs anyway, guaranteed, with the brochure cover.
- Each **open house** day gets its post 7 → 5 → 3 days before (never before the listing went Upcoming) — two sessions on the same day share it, and it gives both times; the **sale** gets its Auction Week post 10 → 7 → 3 days before. Two or more listings selling the **same day at the same place** (the same town — the Sunbury twins, both Lila Lane duplexes, 393 Boyer Rd and its contents) share one Auction Week post and one Results post. Two unrelated sales that only share a date keep their own posts.
- **Results** come the day after the sale once it is in Past with a price (a Saturday sale's on the Monday), and wait as long as it takes for a yes — a Results post on the plan never expires. A liquidation needs no price: its post is a thank-you for the turnout. Installing the plugin does not dig up old results — only sales from a week before it arrived on.
- A listing that is unpublished, made private, password-protected, trashed or deleted loses its posts **at once** — before the page that did it has finished loading — and a shared post simply loses that listing; republish it and they come back. **Cancel posts** on the listing panel does the same on purpose (for the rare sale that is called off); **Resume posts** undoes it. A post that is no longer called for (a date or an open house changed) is cancelled. What a person decided — skipped, restored, cancelled, edited — is never undone. A post already sent is never changed, and neither is an approved one, except that losing a listing or a fact behind it costs it its approval (§8): a listing that joins its sale day afterwards gets its own post.
- A listing's **Open House** and **Auction Week** posts never go before its Listing post: while the Listing post waits for photos they wait too (saying so), and they are planned once the photos arrive — or on their own once the Listing post is skipped.

Each post has a window and an ideal day (a Sunday ideal moves to Monday, or Saturday when Monday is past the window); the morning plan (below) gives each one its day and minute.

### The words

Claude writes every post through the toolkit's Claude engine, from the listing's facts and nothing else: the address, town and county from the geocoded listing (the county is left out rather than guessed), the auction date and time (a sale with a date and no time gives no time — the site's date-only field reads as midnight, and Claude is never told "12:00 am") and how bidding works, the starting bid and size, the open houses with their times and lengths, the sellers, the lead and their direct phone from the team record, and the sale price from the results. Every prompt forbids inventing anything — a missing fact comes back as a `[VERIFY: …]` marker, which stops the post.

On top of the post type's brief go:

- **one of five templates**, rotating: place first, date first, person first, question first, numbers first (never the template this property's last post used, and the least used this month first);
- **the division's voice**: Farm & Home, Investment & Recreation, or Intel general (liquidations, equipment), from the listing's Auction Category;
- **a call to action**, rotating: call the lead, join the email or call list, comment with a question, tag someone who'd want it, reply for the brochure (each where it fits the post type);
- **the house style**: plainspoken and local, no stock marketing gloss, at most one emoji, never two exclamation points in a row, no links, no hashtags — and how bidding works stated once, exactly: an in-person auction or Intel Phone Bidding, never both, never online.

**No opening line, call to action or hashtag set repeats within 30 days.** The plugin keeps every one it has used (`{prefix}ias_history`), hands Claude the recent ones to avoid, and asks once more if one repeats anyway; a second repeat stops the post for a decision. Hashtags are chosen by the plugin, not Claude: `#IntelAuctions` plus two of the town, the county, the post type and the division, in a combination not used in 30 days.

The post is the opening line, the body, the call to action and the hashtags. **The listing link goes in the first comment**, tagged `utm_source=facebook&utm_medium=social&utm_campaign=social_<post type>&utm_content=p<post id>`; a shared post lists each property's link, the roundup links to the Recently Announced page, and a listing with a video link (YouTube, Vimeo, Facebook) gets it in the comment too. With **Listing link** unticked in Settings, the link ends the post instead.

### The pictures

- **Photo grading.** The first time a listing's photos are needed, Claude looks at each one once — in batches of 8, at the small grading size — and scores it 1–10 for a Facebook post, tags its kind (aerial, exterior, interior, barn or outbuilding, land, other) and writes alt text from what it can see. The grade is kept on the photo, so it is never paid for twice. The first 40 photos of a gallery are graded.
- **Choosing.** Only photos Claude has graded are chosen once a gallery is partly graded (at least six, or all of a small one), so every picture comes with its kind and alt text known. **Never the same view twice in one post:** every photo gets a fingerprint (a tiny grey thumbnail, worked out once), and two shots of the same view — the house front from a step to the left — never share a post; an Open House post also skips the views the Listing post used. Every post about a property leads with a photo that property hasn't used yet — nobody sees the same aerial twice. The Listing post mixes kinds, leading with the best aerial or exterior and following it with a different kind, and takes up to 10 while leaving enough for the property's later posts (3 per open house, 2 for auction week). Open House posts favour interiors and use none of the Listing post's photos (3–5; a small gallery fills out with the Listing post's rather than posting one picture). Auction Week posts lead with the strongest aerial not yet used. A shared post takes one or two of each property's. Photos scoring 3 or less are left out while better ones exist.
- **Video.** When the listing's video is a video file (mp4, mov), the Open House post is that video. A YouTube, Vimeo or Facebook link goes in the first comment instead.
- **Three crops.** Every chosen photo is saved as landscape 1200×628, square 1080×1080 and vertical 1080×1350 (never enlarged past the photo), under `uploads/intel-social/crops/`, ready for Instagram later.
- **Chosen by hand.** A person can choose any post's pictures on its page (§4); their choice then holds through rewrites.
- **Alt text** on every picture: from grading, else the photo's own alt text, else the listing's name; the plugin's own graphics describe themselves ("Sold for $425,000: 22 Mill Road, Paradise, PA, October 31, 2026").

### The pictures the plugin draws

- **Results graphic** — the featured photo, a dark gradient up from the bottom, "SOLD" and the price in white, the street, and the town and date. Two or three same-day sales share one picture side by side, one price each. A liquidation never gets one: its thank-you carries a plain photo.
- **New This Week collage** — the week's brochure covers on Intel navy under a "NEW THIS WEEK" heading; a listing with no cover yet gets a branded card (street, town, date) in its place. Up to nine; more shows "+N more".
- The font is the **brand font** set in Settings (a .ttf or .otf in the Media Library), else the bundled **Inter** (SIL Open Font License, `assets/fonts/OFL.txt`). The pictures are 1080×1080 JPEGs in `uploads/intel-social/graphics/`.

### The checks before anything goes anywhere

Every draft — written by Claude or edited by a person — is checked, and stops for a decision (**Needs a decision**, with the reasons) when:

- the listing changed after a person edited the post, or while the draft was already waiting for a decision (a draft nobody touched is simply rewritten on the next run, and goes nowhere until it is);
- it carries a `[VERIFY]` marker;
- a Listing, Open House or Auction Week post doesn't say how bidding works, says it wrongly, says both (buyers would think they can choose), or the listing itself doesn't say — its own Auction type field and its Auction Type category are read, and if they disagree the post stops; any post mentioning online bidding stops;
- it gives a dollar figure that isn't on the listing (starting bid, sale price, parcel bids, or its own description) or a phone number that isn't the lead's or the office's;
- the **fair-housing screen** finds language about who should live there rather than the property: "perfect for families", "family-friendly", "safe neighborhood", "great for retirees", "adults only", a religion, nationality or ability, pointing to a house of worship, "bachelor pad" — plus any phrases added in Settings. Describing the property (a white barn, Amish-built, a wheelchair ramp, a family room) is never flagged;
- it has more than three emoji.

Two things are fixed without asking: a web address in the text is removed (the link belongs in the first comment), and stacked exclamation points become one.

### When drafts are written, and what they cost

The plugin's job runs every hour on the toolkit's dispatcher (`ias_social`, listed with the toolkit's jobs): it updates the plan, then writes up to **8 drafts** (Settings), approve-first posts (Results, the roundup) first and then by day, for the next 30 days. The roundup is written on its Friday, once the week's announcements are in. Each draft is one Claude call (two if the first answer was unusable or repeated itself), each photo batch another, all counted against the toolkit's monthly Claude cap — when the cap is reached, drafting stops until next month or until the cap is raised.

Nothing is paid for twice: a draft is rewritten only when the listing's facts behind it change (and nobody has edited it) — on the next run, at most once an hour — or when a person presses **Rewrite** (at most hourly). A failed call waits 6 hours before trying again. Until a draft whose facts changed has been rewritten, it is never pushed.

**Without Claude** — the toolkit has no Anthropic key, or the month's cap is spent — nothing is tried: no draft, no photo grade, no post marked failed. A notice under the switches on every Social screen says **Drafts are stopped** and why, and **Settings → Claude** shows it too, with the month's tokens against the cap. The plan carries on (posts without words are never pushed), and the next hourly run after the key is back, or the cap raised, writes the drafts.

## 7. The plan

### The morning run

Every morning from **5:00** (Settings: the planning hour) the plugin's hourly job re-plans the next **30 days**. **Plan now** on the calendar runs the same plan on the spot. In order:

1. **What is fixed stays put.** A post already published, a human post, a post someone edited in OneUp, and a post already on the OneUp calendar inside the 48-hour veto window (in the dry run: one it logged as sent) are never moved or dropped; the plan works around them (the hourly read-back from OneUp keeps them current).
2. **Cancelled listings are already gone** — cancelling happens the moment a listing is unpublished or deleted (§6).
3. **Every other post goes on its ideal day** if it can. **Guaranteed posts first** (Listing, Results, the roundup), by priority; then the rest by priority.
4. **A full day** (three posts) pushes the newcomer to the nearest day in its window with room. A post that finds no room anywhere makes a lower one move: a guaranteed post can move any Open House or Auction Week post, and a higher-priority Open House or Auction Week post can move a lower one. The post that made way looks again elsewhere in its own window.
5. **A droppable post with no room anywhere in its window is dropped**, with the reason, under **Dropped in the last 30 days** on the calendar and in the log. A guaranteed post is never dropped: with no room in its window it **runs late** (up to 14 days after it, but a Listing post never later than the day before its sale), and only if even that fails is the same-county rule bent for it — both are listed under **Running late or squeezed**.
6. **A day with fewer than two posts** pulls in the best post whose window reaches it — a dropped one first, else one moved from a day with three. (Blog fill-ins join here once blogs resume.)
7. **The sense rules hold throughout**: one post per property per day, no two posts about the same county on one day, a property's Open House and Auction Week posts after its Listing post (and not at all while the Listing post waits for photos), and when two days are equally good, the one without that division already.
8. **The pushes** (below).

A post keeps the day and minute it already has whenever they still work, so the calendar — and later OneUp — only changes when something forces it. A dropped post comes back by itself if room opens up. A post the plan can't place at all loses its old time, saying why, so nothing goes out at a time the plan no longer holds.

### Times

- **Never on Sunday.** Monday to Saturday, at most **3 posts a day**, at least **3 hours apart**.
- In the posting windows **6:00–7:30 AM, 11:30 AM–1:00 PM and 7:00–9:00 PM**: the day's highest-priority post takes the evening, the next midday, the third the morning (working around any fixed post's time).
- Each post's time is shifted by **up to 25 minutes** from the window's start — the same shift for the same post every day — and never lands on the hour.
- **Nothing new closer than 48 hours** for Listing, Open House and Auction Week posts (Lane B): that is the window to veto it in OneUp. A roundup or Results post (Lane A) is planned at least an hour ahead, because it goes nowhere until someone approves it; once approved, it takes the next free time.

### The pushes

After planning, every post scheduled in the next **21 days** that is ready — a Lane B post with a draft that passed its checks, or an approved Lane A post — is turned into the exact request for OneUp's API, and every post that went out and is no longer ready — dropped, skipped, cancelled, stopped for a decision, its listing changed, its approval withdrawn, or moved past the 21 days — is turned into the request that takes it back:

- A Listing, Open House or Auction Week post that has not gone out yet goes only while its time is still at least **48 hours** away (it can be released late, say by Keep as written on a Monday afternoon for a Wednesday morning): otherwise it waits, and the next morning's plan gives it a time outside the veto window. A post already on OneUp whose words change (a corrected fact, an edit) is replaced whenever it changes, so it never goes out with the old facts.
- A post with pictures is `scheduleimagepost` (up to 10 pictures, their addresses joined by `~~`), a video `schedulevideopost`, words alone `scheduletextpost`, each with the OneUp category id, the Facebook account id, the time as `YYYY-MM-DD HH:MM` (site time — OneUp's account time zone must match; Verify OneUp checks it), the text, and the tagged link as `first_comment`.
- A moved or changed post is deleted and scheduled again (OneUp cannot change a scheduled post's time). Live posts are never edited.
- The API key (`INTEL_ONEUP_API_KEY`) is added only when sending and never appears in the log or on screen.

**Dry run** (the master switch off, or dry run on, or no key or ids, or Verify OneUp not yet passed for the page set in Settings): nothing is sent. Each request goes to the log as a `dryrun` entry, once when it is new and again only when it changes, with what is stopping it. Each post's view shows **What would go to OneUp** too. A Results post or roundup waiting for approval never appears there. The dry run behaves the way live pushing does, so the calendar settles as it will when it is live: a post it logged counts as sent — inside the 48 hours it stays put, and once its time passes it shows as **Published** (marked dry run) — and a sent post that stops being ready gets a logged delete.

**Live** (the master switch on, dry run off, the key and ids set, and **Verify OneUp passed for that page and key** — set another category, account or key and it must pass again; Facebook only — §7, More networks): each request goes to OneUp, for the post types switched on. Pause and a type that is off stop new posts, never a take-back — and a post already on OneUp that changes while they do is **taken back** rather than left to go out with the old words; it goes again when they allow. A post is **Pushed** once OneUp has it; its OneUp post id is kept, and the calendar shows it. If OneUp refuses a post, it is tried again on the next hourly runs; the third refusal in a row makes it **Failed**, waiting for a person. A cancelled listing's posts come off OneUp **at once**, before the page that cancelled them has finished. Switching from the dry run to live clears the dry run's record, so nothing counts as sent that was not; switching back to the dry run sends nothing more, and posts already on OneUp stay there until they are deleted in OneUp — the calendar lists the ones the plan no longer wants as they are, to delete there.

Nothing is ever sent twice, and nothing is taken off by guesswork:

- **One run at a time.** The hourly run, Plan now and an emailed Approve can meet; the second waits for the next hour.
- **An answer that never came back** (no answer, a server error) is not taken for a refusal: the plugin remembers the attempt, looks for the post on OneUp's list first, and keeps it if it arrived — only a post that is not there is sent again.
- **A post is replaced or taken back only by its OneUp id.** When OneUp's answer carried no id, the post is found on OneUp's list by its text and time and the id is kept; until then it is not replaced, and a post that must come off with its id still unknown says so on the calendar — delete it in OneUp by hand if it is there.
- **"Not found" from OneUp** to a delete is believed only when OneUp's list agrees.
- **The key** never reaches the log, a note or an email — not even inside an error that repeats the address it was sent to.

### Reading back from OneUp

Every hour while the master switch is on — and first thing in every push, so a person's change is never overwritten — the plugin reads the Facebook page's **scheduled**, **published** and **failed** posts in OneUp (only that page — the category's Google Business Profile and YouTube accounts are not Facebook and are left alone). Its own posts are known by their OneUp ids; everything else on the page is a person's:

- **A post a person moved or edited in OneUp** keeps their time and words. It is locked: the plan works around it and never replaces it. Change it in OneUp from then on. If its listing changes afterwards, it stops as **Needs a decision** — fix it in OneUp (or skip it here), then press Keep as written; the plugin does not rewrite it.
- **A post a person deleted in OneUp** stays off the plan, as Skipped by a person, until someone presses Restore. It is believed only when the post is missing from OneUp's whole list on **two read-backs at least half an hour apart**. An answer that is not a list (an error, "too many requests") is a failed read, and if none of the plugin's posts are on the list at all, nothing is concluded — the log says to check the page and run Verify OneUp.
- **A post a person scheduled for the page in OneUp themselves** shows as a **Human** post, and the plan works around it — it counts toward the day's three and the 3-hour gaps. A Human post that is deleted in OneUp before its time leaves the plan.
- **Published** posts are confirmed (the time they went out is recorded); posts OneUp **could not publish** become Failed, with OneUp's reason. A post whose time passed a day ago and that OneUp has still not confirmed gets a note, once, to check the page.
- **A renamed page** is followed: when the plugin's own posts show up under a new page name, Settings takes it.

OneUp lists scheduled posts in its account's own time, and published ones in UTC; the plugin converts both to the site's time.

### Corrections

A post that has gone out is never edited — editing resets its reach. When a fact it carried changes afterwards (the auction date or time, the starting bid, the sale price, the address, the phone number, an open house), the plugin drafts the correction — "Update: the starting bid is now $275,000 (not $300,000)." — and lists it under **Corrections to post as a comment** on the calendar and in the Monday email. Post it as a comment on the Facebook post, then press **Comment posted** (or **Not needed**).

### More networks: Instagram, X and LinkedIn

DJ, Oct 13, 2026: provision for more networks now. Each is set up but **not sent to yet** — that comes in a later release, network by network, once each has its own Verify the way Facebook has. Switch one on under **Settings → More networks** with its OneUp account id (from **Check connection**, in the same OneUp category as the Facebook page; Intel's account has none of the three connected yet), and from then on every post is also planned for it:

- **Instagram**: the Facebook words, with "Photos, terms and the full listing are on intelauctions.com — link in our bio" in place of a link (Instagram captions carry no clickable links), the square crops, up to 10 pictures as a carousel. A post with no picture, or with only a video link, is flagged: Instagram needs a picture.
- **X**: one short line made from the listing's facts — never from Claude's words — such as "Just listed: 5940 Plank Road, Narvon. Auction Sat, Nov 28 at 10:00 am. Starting bid $650,000." with the link and up to two hashtags, kept within 280 characters (X counts every web address as 23), and up to 4 pictures.
- **LinkedIn**: the Facebook words with the link at the end, up to 9 pictures.

Each network's link is tagged with its own `utm_source`, so the monthly summary can tell them apart later. A person edits the Facebook words, and the others follow. Each post's page shows a preview tab per network (with X's character count and anything missing), and **What would go to OneUp** lists each network's request. The dry run logs each network's request once per change, and its take-back when the post stops being ready or the network is switched off — and nothing is ever sent to these networks, even with Facebook live.

### Going live

1. Put the OneUp key in **Settings → OneUp** (or wp-config). Turn on the **master switch** and leave **dry run on**. The plugin may now call OneUp, but only to read: the hourly read-back starts, and nothing is written.
2. **Settings → Check connection**, copy in the category id and the Facebook page's account id, save.
3. **Verify OneUp**: it saves a test **draft** for the page (drafts never publish; it is set a month out), reads it back, checks that OneUp's time zone matches the site's, deletes it, and reads the scheduled, published and failed lists. Every step should say **ok**, and the button then shows **passed for this page**. Nothing is written to OneUp for real until it has — for the category, account and key set now; change any of them and it must pass again. If OneUp scheduled the draft as a real post, Verify fails and takes it off at once (if even that fails, it says to delete it in OneUp now). If a step fails, nothing goes live until it is fixed (the plugin's calls to OneUp are named in one filterable list, `ias_oneup_endpoints`).
4. If the toolkit's social webhook is in use (Settings & Data → Automation has a URL), make the **Zapier/Make scenario** behind it post to Facebook only when the payload's `channels` contains `facebook` (below). With no URL there, nothing else posts listings to Facebook and there is nothing to change.
5. Turn **dry run** off with **Listing posts** the only post type on (switch any others off first), and save. The next hourly run schedules the Listing posts outside the 48-hour window on OneUp; check them on the OneUp calendar.
6. When the first Listing post publishes, check on Facebook that its link is in the first comment. If OneUp did not post it, untick **Put the link in the first comment**: the link then ends the post.
7. One week of Listing posts publishing as planned finishes Phase 5. Then turn on the other types one at a time (Phase 6).

**Handing Facebook over from the toolkit.** If the toolkit's social webhook is set up (Settings & Data → Automation has a URL), it sends each listing to Zapier/Make, which may post it to Facebook. This plugin tells the toolkit when it has taken Facebook over (the toolkit's `ia_social_facebook_managed`): only when Listing posts can really reach OneUp — the master switch on, dry run off, the key and the ids set, Verify OneUp passed for that page, and Listing posts on (Pause does not change it). Until then the webhook carries Facebook as before; from then on its payload says `channels: ["gbp"]`, the toolkit's promo steps ask for the Google Business Profile post only, and promo tasks already in the queue drop their Facebook step. **Before Listing posts go live, make the Zapier/Make scenario post to Facebook only when `channels` contains `facebook`**, or Facebook gets the listing twice. With no webhook URL, the toolkit sends nothing, and the promo steps are the only part of the handover that changes anything.

**Check connection** under Settings asks OneUp for its categories and their accounts with the key from wp-config — a read-only call that changes nothing — so the category id and the Facebook account id can be copied in. Like every call to OneUp, it needs the master switch on.

## 8. Approval and the weekly review

### Lane A: approve first

The **roundup** and **Results** posts carry hard facts, so they go nowhere until DJ or Jacob says yes (Settings: approvers). As soon as one has a draft that passed its checks, it becomes **Waiting for approval** and each approver gets an email with the full text, the picture, the first comment and two buttons:

- **Approve** — opens a page showing the post with an **Approve this post** button; that click approves it. (Just opening the link changes nothing, so an email security scanner that follows links can't approve anything.) **The first yes wins**: every other link for that post stops working, and the next person to click is told who approved it and when.
- **Edit in WordPress** — opens the post on the Social Calendar (login needed): change the words, then **Save and approve**.

The links are **signed one-time links**: each approver gets their own, random and kept only as a hash, good for one use and for **4 days**. A link that is used, expired, altered, from before the post's words were changed, or from before a fact behind it changed (a corrected price while it waited) **fails safely** — it explains why on a plain page and changes nothing, and the post keeps waiting. Refused links that belong to no approver (altered, or for a post that is gone) are not logged.

A post still waiting gets a **reminder every morning** (from the planning hour, with fresh links) until someone answers. **It never expires.** **No approval email or reminder goes out on Sunday** — Sunday's go on Monday morning.

Approving puts the post on the plan **at once**, at the next free time at least an hour ahead (the approval was the yes, so it doesn't wait out the 48-hour veto window), and it joins the pushes. Changing the words of a waiting or approved post — by Edit, Rewrite, or because the listing changed and the draft was rewritten — needs a fresh yes: the old links stop working and new emails go out. That includes a fact corrected **after** the yes (say, the sale price on Post Auction Results), and a shared post losing one of its sales (one of two same-day listings unpublished): the approval is withdrawn, the post is rewritten with the new facts and sent for approval again, and it cannot be pushed in between — or, if a person had edited it, it stops for that person instead of being rewritten. A post whose draft is being rewritten gets no reminder and cannot be approved until the new version is out.

### The Monday email

Every **Monday at 7:00** (Settings: day and hour; never Sunday) DJ and Jacob get one email: **this week's posts** day by day with their status, **waiting for approval**, anything that **needs a decision** (failed posts included), **corrections to post as a comment**, **new since last week**, **dropped this week** with the reasons, and buttons to **open the OneUp calendar** and the Social Calendar. The first one of each month adds **last month's summary**. **Send the weekly email now** under Settings sends it on demand.

### The monthly summary

Which post types and divisions earn their slots: for each, how many posts went out, are still to come, were dropped, skipped, cancelled or failed, and ran late — and the **site visits** they brought. Every link a post carries is tagged with its post type (`utm_campaign=social_listing`, …) and the post itself (`utm_content=p<id>`), and when Google Analytics is connected through **Site Kit**, the toolkit reads those visits for the summary. Without Site Kit the summary shows the counts alone and says so. It is at the top of the Log screen (this month and last) and in the first weekly email of each month.

## 9. What it stores

- `{prefix}ias_posts` — one row per post: its listing(s), type, status, priority, window, scheduled time, OneUp post id, content, the images it used, approvals and its history.
- `{prefix}ias_history` — the openers, calls to action, hashtag sets, templates and images already used, for the no-repeat rules.
- The option `ias_settings` (every setting) and `ias_db_version`; `ias_installed_at` (the day it first ran — Results go back only a week before it); `ias_last_plan` and `ias_last_plan_summary` (the morning plan's day and counts); `ias_last_review` (the day the weekly email last went); `ias_live_since` (the day Listing posts first went to OneUp live), `ias_push_mode` (dry run or live, to notice the switch), `ias_readback_last` (the last read-back), `ias_oneup_verify` (the last Verify OneUp, its steps, and the page and key fingerprint it was run with), `ias_oneup_api_key` (the OneUp key when it is saved in Settings; not autoloaded) and, while a push run is going, `ias_push_lock`. Site visits for the monthly summary are cached for six hours (`ias_visits_*` transients).
- On each post: its approvals — the hashed links (approver, round, expiry, used), the emails sent, and who approved it; once on OneUp, its OneUp post id and what was sent (the request's fingerprint, the text and time, the facts it carried — for corrections), an attempt whose answer has not come back yet, whether a person moved or edited it there, and any correction drafted for it; who chose its pictures by hand; and, for Instagram, X and LinkedIn, what the dry run logged.
- On each listing: `_ias_announced_at` and `_ias_promoted_at` (the days it entered Recently Announced and Upcoming), `_ias_photos_at` (the day its photos were first seen), the panel's `_ias_sale_kind`, `_ias_parcels` and `_ias_brochure_cover`, and `_ias_posts_cancelled` while Cancel posts is on.
- On each photo: `_ias_photo` (its grade, kind and alt text), `_ias_crops` (the three crops) and `_ias_dhash` (its fingerprint, for telling the same view twice).
- Pictures in `uploads/intel-social/` (`graphics/` and `crops/`).
- Its log entries in the toolkit's `{prefix}ia_events` table, as `ias.*` events.

**Deleting the plugin removes all of it** — both tables, the options, its log entries, its per-image and per-listing data and its generated pictures. The listings themselves and anything already on Facebook are untouched. The full list is `uninstall.php`.

## 10. Requirements

PHP 7.4+, WordPress 5.3+ sending email (wp_mail — the approval emails and the Monday email), the Intel Auctions Toolkit 4.66.0+ with its Anthropic key set (drafts and photo grading use the toolkit's Claude engine and monthly cap), GD with FreeType for the pictures it draws (without it, a Results post goes out with each property's plain photo and the roundup with the brochure covers themselves), and a OneUp account with the Facebook page connected and an API key. Optional: Google Analytics connected through Site Kit in the toolkit, for the site visits in the monthly summary. It uses the toolkit's helpers by name; if a toolkit update ever removes one, the plugin stops with the notice rather than failing half way through a run.
