# Intel Auctions Social — Guide

> **Documented version: 0.4.3.** The canonical user documentation, rendered inside
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
| 5. OneUp live | Pushing to OneUp, reading back, cancelling, the first-comment link | — |
| 6. The rest | Each post type switched on in turn | — |

This version is **phase 4** — everything up to going live: every current listing's posts are drafted, checked and planned to the minute every morning; the roundup and Results posts are emailed to DJ and Jacob for a yes; the Monday email goes out; and what would go to OneUp is written to the log as a dry run. Nothing is sent to OneUp or Facebook yet — that is Phase 5.

## 3. Installing it

1. Make sure the **Intel Auctions Toolkit** is active at **4.66.0** or newer.
2. Plugins → Add New → Upload Plugin, choose `intel-auctions-social.zip` from the latest release at github.com/SixGricks/IntelAuctions-Social/releases, then **Activate**. Updates then arrive through WordPress like any plugin's.
3. Add the OneUp API key to `wp-config.php` (from oneupapp.io → API access): `define('INTEL_ONEUP_API_KEY', '…');` — it is never stored in the database.

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
| Pushed | On the OneUp calendar |
| Published | Live on the Facebook page. In the dry run, a post whose time came while it was "sent" shows as Published too, marked **dry run** — nothing went anywhere |
| Dropped | A post that could be dropped had no room in its window (the reason is logged) |
| Skipped / Cancelled | A person skipped it, or the listing was unpublished or deleted |
| Human | Posted by a person; the plan works around it |

Sundays never carry a post. The hatched days are inside the 48-hour veto window, where nothing new is scheduled. Each day shows how many of its three places are taken.

Click a post to open it: the preview as it would appear (text, pictures with their alt text and crops, the first comment), its window and priority, the listing(s) it covers, and its history. From there:

- **Save text** — change the words (and the first comment). The new text is checked again like a fresh draft, and saving it counts as checking it against the listing as it is now. Once a person has edited a post, the plugin never rewrites it behind their back: if the listing changes afterwards, the post stops (**Needs a decision**, even if it was already approved) until you save it again, keep it as written, or press **Rewrite**.
- **Rewrite** — have Claude write it again (at most once an hour per post; it counts against the monthly Claude cap). **Write it now** does the same for a post with no draft yet.
- **Skip this post** — take it off the plan, with a reason if you like. A skipped post stays skipped until you press **Restore to the plan**. Restore also overrides a skip the plugin made itself (say, "Facebook already got the full listing from the webhook"): it stays on the plan, with a week from today to go out.
- **Keep as written** — for a post that stopped for a decision: you have read why (including "the listing changed"), and it is right as it is. A post that stopped stays stopped until someone decides — being dropped and brought back, restored, or called for again never turns it into Planned; and a later edit that stops it again needs a new decision.
- For a roundup or Results post: **Approve**, **Send for approval now** (before the hourly run would), **Send a reminder now**, and **Save and approve** beside Save text. Its **Approval** box shows who was emailed when and who said yes.

Above the grid, when the plan last ran and what it did; beside **Pause**, **Plan now**. Under the grid: **Waiting for approval** (roundup and Results posts emailed for a yes, how long ago and how many emails), **Needs a decision** (drafts that stopped, with why), **Drafts** (how many planned posts have no draft yet, and **Write drafts now**, which writes up to four on the spot), **Waiting for photos** (Upcoming listings whose Listing post waits for a gallery), **Dropped in the last 30 days** (with each reason) and **Running late or squeezed**. Each post's view also shows **What would go to OneUp**.

Across the top of every screen, the **switches strip** shows the master switch, dry run, pause and each post type, so it is always plain whether anything can reach Facebook. **Pause all pushes** on the calendar stops every push at once without deactivating the plugin; **Resume pushes** undoes it. Planning and drafting carry on while paused.

### Settings

- **Safety switches**, all off at install: the **master switch** (off = the plugin plans and logs but never calls OneUp), **dry run** (on = it writes the exact payloads it would send to the log), **paused**, and one switch per post type. Turn on Listing posts first, then the rest one at a time as each earns trust. A type that is off is still planned and drafted, so you can read it on the calendar.
- **OneUp**: whether the API key is set in wp-config, the category id and the Facebook page's account id in OneUp, the link to the OneUp calendar for the Monday email, and whether the listing link goes in the first comment (untick only if OneUp cannot post first comments — the link then ends the post).
- **Priority**: each post type's weight and the starting-bid multipliers. Priority = weight × multiplier.
- **Limits and posting windows**: at most 3 posts a day, at least 2 when posts can be pulled in, 3 hours apart, in the windows 6:00–7:30 AM, 11:30 AM–1:00 PM and 7:00–9:00 PM, each time shifted by up to 25 minutes; the 48-hour veto window; how far ahead it plans (30 days) and pushes (21 days), and the hour the morning plan runs (5:00).
- **Approvals and the weekly email**: who approves (dj@ and jacob@intelauctions.com), and when the weekly review email goes (Monday 7:00; any day but Sunday). Below the form: **Send the weekly email now** and **Check connection** (OneUp).
- **Links back to the site**: the tracking tags on every link.
- **Copy and pictures**: the brand font for the graphics, more fair-housing phrases to stop on, the size photos are graded at (bigger costs more), and how many drafts are written an hour.

### Log

Everything the plugin did, newest first: each plan, draft, approval, push, read-back, drop and cancel, and every settings change. **Show** narrows it to one kind; `dryrun` shows the exact requests that would have gone to OneUp, each one expandable. The log lives in the toolkit's event log, which keeps the newest 5,000 entries across both plugins.

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
- Each **open house** gets its post 7 → 5 → 3 days before (never before the listing went Upcoming); the **sale** gets its Auction Week post 10 → 7 → 3 days before. Two or more listings selling the **same day at the same place** (the same town — the Sunbury twins, both Lila Lane duplexes, 393 Boyer Rd and its contents) share one Auction Week post and one Results post. Two unrelated sales that only share a date keep their own posts.
- **Results** come the day after the sale once it is in Past with a price (a Saturday sale's on the Monday), and wait as long as it takes for a yes — a Results post on the plan never expires. A liquidation needs no price: its post is a thank-you for the turnout. Installing the plugin does not dig up old results — only sales from a week before it arrived on.
- A listing that is unpublished, made private, password-protected, trashed or deleted loses its posts **at once** — before the page that did it has finished loading — and a shared post simply loses that listing; republish it and they come back. **Cancel posts** on the listing panel does the same on purpose (for the rare sale that is called off); **Resume posts** undoes it. A post that is no longer called for (a date or an open house changed) is cancelled. What a person decided — skipped, restored, cancelled, edited — is never undone. A post already sent is never changed, and neither is an approved one, except that losing a listing or a fact behind it costs it its approval (§8): a listing that joins its sale day afterwards gets its own post.
- A listing's **Open House** and **Auction Week** posts never go before its Listing post: while the Listing post waits for photos they wait too (saying so), and they are planned once the photos arrive — or on their own once the Listing post is skipped.

Each post has a window and an ideal day (a Sunday ideal moves to Monday, or Saturday when Monday is past the window); the morning plan (below) gives each one its day and minute.

### The words

Claude writes every post through the toolkit's Claude engine, from the listing's facts and nothing else: the address, town and county from the geocoded listing (the county is left out rather than guessed), the auction date and time and how bidding works, the starting bid and size, the open houses with their times and lengths, the sellers, the lead and their direct phone from the team record, and the sale price from the results. Every prompt forbids inventing anything — a missing fact comes back as a `[VERIFY: …]` marker, which stops the post.

On top of the post type's brief go:

- **one of five templates**, rotating: place first, date first, person first, question first, numbers first (never the template this property's last post used, and the least used this month first);
- **the division's voice**: Farm & Home, Investment & Recreation, or Intel general (liquidations, equipment), from the listing's Auction Category;
- **a call to action**, rotating: call the lead, join the email or call list, comment with a question, tag someone who'd want it, reply for the brochure (each where it fits the post type);
- **the house style**: plainspoken and local, no stock marketing gloss, at most one emoji, never two exclamation points in a row, no links, no hashtags — and how bidding works stated once, exactly: an in-person auction or Intel Phone Bidding, never both, never online.

**No opening line, call to action or hashtag set repeats within 30 days.** The plugin keeps every one it has used (`{prefix}ias_history`), hands Claude the recent ones to avoid, and asks once more if one repeats anyway; a second repeat stops the post for a decision. Hashtags are chosen by the plugin, not Claude: `#IntelAuctions` plus two of the town, the county, the post type and the division, in a combination not used in 30 days.

The post is the opening line, the body, the call to action and the hashtags. **The listing link goes in the first comment**, tagged `utm_source=facebook&utm_medium=social&utm_campaign=social_<post type>&utm_content=p<post id>`; a shared post lists each property's link, the roundup links to the Recently Announced page, and a listing with a video link (YouTube, Vimeo, Facebook) gets it in the comment too. With **Listing link** unticked in Settings, the link ends the post instead.

### The pictures

- **Photo grading.** The first time a listing's photos are needed, Claude looks at each one once — in batches of 8, at the small grading size — and scores it 1–10 for a Facebook post, tags its kind (aerial, exterior, interior, barn or outbuilding, land, other) and writes alt text from what it can see. The grade is kept on the photo, so it is never paid for twice. The first 40 photos of a gallery are graded.
- **Choosing.** Every post about a property leads with a photo that property hasn't used yet — nobody sees the same aerial twice. The Listing post mixes kinds, leading with the best aerial or exterior, and takes up to 10 while leaving enough for the property's later posts (3 per open house, 2 for auction week). Open House posts favour interiors and use none of the Listing post's photos (3–5; a small gallery fills out with the Listing post's rather than posting one picture). Auction Week posts lead with the strongest aerial not yet used. A shared post takes one or two of each property's. Photos scoring 3 or less are left out while better ones exist.
- **Video.** When the listing's video is a video file (mp4, mov), the Open House post is that video. A YouTube, Vimeo or Facebook link goes in the first comment instead.
- **Three crops.** Every chosen photo is saved as landscape 1200×628, square 1080×1080 and vertical 1080×1350 (never enlarged past the photo), under `uploads/intel-social/crops/`, ready for Instagram later.
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

## 7. The plan

### The morning run

Every morning from **5:00** (Settings: the planning hour) the plugin's hourly job re-plans the next **30 days**. **Plan now** on the calendar runs the same plan on the spot. In order:

1. **What is fixed stays put.** A post already published, a human post, a post someone edited in OneUp, and a post already on the OneUp calendar inside the 48-hour veto window (in the dry run: one it logged as sent) are never moved or dropped; the plan works around them (reading them back from OneUp is Phase 5).
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

### The pushes — a dry run in this version

After planning, every post scheduled in the next **21 days** that is ready — a Lane B post with a draft that passed its checks, or an approved Lane A post — is turned into the exact request for OneUp's API, and every post that went out and is no longer ready — dropped, skipped, cancelled, stopped for a decision, its listing changed, its approval withdrawn, or moved past the 21 days — is turned into the request that takes it back:

- A Listing, Open House or Auction Week post that has not gone out yet goes only while its time is still at least **48 hours** away (it can be released late, say by Keep as written on a Monday afternoon for a Wednesday morning): otherwise it waits, and the next morning's plan gives it a time outside the veto window.
- A post with pictures is `scheduleimagepost` (up to 10 pictures, their addresses joined by `~~`), a video `schedulevideopost`, words alone `scheduletextpost`, each with the OneUp category id, the Facebook account id, the time as `YYYY-MM-DD HH:MM` (site time — OneUp's account time zone must match), the text, and the tagged link as `first_comment`.
- A moved or changed post is deleted and scheduled again (OneUp cannot change a scheduled post's time). Live posts are never edited.
- The API key (`INTEL_ONEUP_API_KEY`) is added only when sending and never appears in the log or on screen.

In this version **nothing is sent**: each request goes to the log as a `dryrun` entry, once when it is new and again only when it changes, with what is stopping it (master switch off, dry run on, paused, the post type off, no key or ids — and, until Phase 5, that live pushing is not built yet). Each post's view shows **What would go to OneUp** too. A Results post or roundup waiting for approval never appears there.

The dry run behaves the way live pushing will, so the calendar settles as it will when it is live: a post it logged counts as sent — inside the 48 hours it stays put, and once its time passes it shows as **Published** (marked dry run) — and a sent post that stops being ready gets a logged delete.

**Handing Facebook over from the toolkit.** The toolkit's social webhook (Settings & Data → Automation) still posts each listing to Facebook through Zapier/Make. This plugin tells the toolkit when it has taken Facebook over (the toolkit's `ia_social_facebook_managed`): only when posts can really reach OneUp — live pushing built (Phase 5), the master switch on, dry run off, not paused, and Listing posts on. Until then the webhook carries Facebook as before; from then on its payload says `channels: ["gbp"]` and the toolkit's promo steps ask for the Google Business Profile post only. **Before Listing posts go live, make the Zapier/Make scenario post to Facebook only when `channels` contains `facebook`**, or Facebook gets the listing twice.

**Check connection** under Settings asks OneUp for its categories and their accounts with the key from wp-config — a read-only call that changes nothing — so the category id and the Facebook account id can be copied in.

## 8. Approval and the weekly review

### Lane A: approve first

The **roundup** and **Results** posts carry hard facts, so they go nowhere until DJ or Jacob says yes (Settings: approvers). As soon as one has a draft that passed its checks, it becomes **Waiting for approval** and each approver gets an email with the full text, the picture, the first comment and two buttons:

- **Approve** — opens a page showing the post with an **Approve this post** button; that click approves it. (Just opening the link changes nothing, so an email security scanner that follows links can't approve anything.) **The first yes wins**: every other link for that post stops working, and the next person to click is told who approved it and when.
- **Edit in WordPress** — opens the post on the Social Calendar (login needed): change the words, then **Save and approve**.

The links are **signed one-time links**: each approver gets their own, random and kept only as a hash, good for one use and for **4 days**. A link that is used, expired, altered, from before the post's words were changed, or from before a fact behind it changed (a corrected price while it waited) **fails safely** — it explains why on a plain page and changes nothing, and the post keeps waiting. Refused links that belong to no approver (altered, or for a post that is gone) are not logged.

A post still waiting gets a **reminder every morning** (from the planning hour, with fresh links) until someone answers. **It never expires.** **No approval email or reminder goes out on Sunday** — Sunday's go on Monday morning.

Approving puts the post on the plan **at once**, at the next free time at least an hour ahead (the approval was the yes, so it doesn't wait out the 48-hour veto window), and it joins the pushes. Changing the words of a waiting or approved post — by Edit, Rewrite, or because the listing changed and the draft was rewritten — needs a fresh yes: the old links stop working and new emails go out. That includes a fact corrected **after** the yes (say, the sale price on Post Auction Results), and a shared post losing one of its sales (one of two same-day listings unpublished): the approval is withdrawn, the post is rewritten with the new facts and sent for approval again, and it cannot be pushed in between — or, if a person had edited it, it stops for that person instead of being rewritten. A post whose draft is being rewritten gets no reminder and cannot be approved until the new version is out.

### The Monday email

Every **Monday at 7:00** (Settings: day and hour; never Sunday) DJ and Jacob get one email: **this week's posts** day by day with their status, **waiting for approval**, anything that **needs a decision**, **new since last week**, **dropped this week** with the reasons, and buttons to **open the OneUp calendar** and the Social Calendar. **Send the weekly email now** under Settings sends it on demand.

## 9. What it stores

- `{prefix}ias_posts` — one row per post: its listing(s), type, status, priority, window, scheduled time, OneUp post id, content, the images it used, approvals and its history.
- `{prefix}ias_history` — the openers, calls to action, hashtag sets, templates and images already used, for the no-repeat rules.
- The option `ias_settings` (every setting) and `ias_db_version`; `ias_installed_at` (the day it first ran — Results go back only a week before it); `ias_last_plan` and `ias_last_plan_summary` (the morning plan's day and counts); `ias_last_review` (the day the weekly email last went).
- On each post: its approvals — the hashed links (approver, round, expiry, used), the emails sent, and who approved it.
- On each listing: `_ias_announced_at` and `_ias_promoted_at` (the days it entered Recently Announced and Upcoming), `_ias_photos_at` (the day its photos were first seen), the panel's `_ias_sale_kind`, `_ias_parcels` and `_ias_brochure_cover`, and `_ias_posts_cancelled` while Cancel posts is on.
- On each photo: `_ias_photo` (its grade, kind and alt text) and `_ias_crops` (the three crops).
- Pictures in `uploads/intel-social/` (`graphics/` and `crops/`).
- Its log entries in the toolkit's `{prefix}ia_events` table, as `ias.*` events.

**Deleting the plugin removes all of it** — both tables, the options, its log entries, its per-image and per-listing data and its generated pictures. The listings themselves and anything already on Facebook are untouched. The full list is `uninstall.php`.

## 10. Requirements

PHP 7.4+, WordPress 5.3+ sending email (wp_mail — the approval emails and the Monday email), the Intel Auctions Toolkit 4.66.0+ with its Anthropic key set (drafts and photo grading use the toolkit's Claude engine and monthly cap), and GD with FreeType for the pictures it draws (without it, a Results post goes out with each property's plain photo and the roundup with the brochure covers themselves). It uses the toolkit's helpers by name; if a toolkit update ever removes one, the plugin stops with the notice rather than failing half way through a run.
