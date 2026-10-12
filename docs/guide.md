# Intel Auctions Social — Guide

> **Documented version: 0.1.0.** The canonical user documentation, rendered inside
> WordPress at **Auctions → Social Calendar → Guide**. If you change a post type,
> rule, screen, setting, email or anything the plugin stores, update this file in
> the same commit (see `CLAUDE.md`). What the plugin is built to do is DJ's spec,
> `docs/build-spec.md`.

## 1. What this does

Intel Auctions Social turns each auction on intelauctions.com into a set of Facebook posts, plans them three a day through OneUp, and keeps a person in the loop on the posts that carry hard facts. It is a separate plugin from the Intel Auctions Toolkit on purpose: it calls outside services on a schedule, and if something goes wrong, deactivating it on the Plugins page turns Facebook posting off without touching listings, schema or SEO.

It reads everything from the toolkit — the listing's fields, its stage, its open houses, its lead and their phone, its town and county, its sale price — so nothing is typed twice. **It needs the Intel Auctions Toolkit 4.66.0 or newer.** Without it, it shows a notice at the top of the admin and does nothing at all.

## 2. Build status

The plugin is built in phases. Each ships with publishing off and is checked before the next one starts. Nothing reaches Facebook until DJ turns a switch on.

| Phase | What it adds | Version |
|---|---|---|
| 1. Foundation | The plugin, the dependency check, its tables, settings, the log and the Social Calendar (read-only) | 0.1.0 |
| 2. Content | Drafts for every post type, photo grading, crops, the results graphic, the fair-housing screen | — |
| 3. Scheduler | The 5:00 plan, the rules, cancellations, dry-run payloads | — |
| 4. Approval | The approval emails and their links, morning reminders, the Monday email, Facebook Event cards | — |
| 5. OneUp live | Pushing to OneUp, reading back, cancelling, the first-comment link | — |
| 6. The rest | Each post type switched on in turn | — |

This version is **phase 1**: the screens and switches exist, nothing is planned or drafted yet.

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
| Needs a decision | Stopped for a person: a fair-housing phrase, a missing fact or a `[VERIFY]` marker in the draft |
| Waiting for approval | A roundup or Results post emailed for a yes (Lane A) |
| Approved | Someone said yes; it goes to OneUp on the next run |
| Pushed | On the OneUp calendar |
| Published | Live on the Facebook page |
| Dropped | A post that could be dropped had no room in its window (the reason is logged) |
| Skipped / Cancelled | A person skipped it, or the listing was unpublished or deleted |
| Human | Posted by a person; the plan works around it |

Sundays never carry a post. The hatched days are inside the 48-hour veto window, where nothing new is scheduled. Each day shows how many of its three places are taken.

Click a post to open it: the preview, its window and priority, the listing(s) it covers, and its history.

Across the top of every screen, the **switches strip** shows the master switch, dry run, pause and each post type, so it is always plain whether anything can reach Facebook. **Pause all pushes** on the calendar stops every push at once without deactivating the plugin; **Resume pushes** undoes it. Planning and drafting carry on while paused.

### Settings

- **Safety switches**, all off at install: the **master switch** (off = the plugin plans and logs but never calls OneUp), **dry run** (on = it writes the exact payloads it would send to the log), **paused**, and one switch per post type plus the Facebook Event cards. Turn on Listing posts first, then the rest one at a time as each earns trust. A type that is off is still planned and drafted, so you can read it on the calendar.
- **OneUp**: whether the API key is set in wp-config, the category id and the Facebook page's account id in OneUp, the link to the OneUp calendar for the Monday email, and whether the listing link goes in the first comment (untick only if OneUp cannot post first comments — the link then ends the post).
- **Priority**: each post type's weight and the starting-bid multipliers. Priority = weight × multiplier.
- **Limits and posting windows**: at most 3 posts a day, at least 2 when posts can be pulled in, 3 hours apart, in the windows 6:00–7:30 AM, 11:30 AM–1:00 PM and 7:00–9:00 PM, each time shifted by up to 25 minutes; the 48-hour veto window; how far ahead it plans (30 days) and pushes (21 days), and the hour the morning plan runs (5:00).
- **Approvals and the weekly email**: who approves (dj@ and jacob@intelauctions.com), and when the weekly review email goes (Monday 7:00).
- **Links back to the site**: the tracking tags on every link.

### Log

Everything the plugin did, newest first: each plan, draft, approval, push, read-back, drop and cancel, and every settings change. **Show** narrows it to one kind; `dryrun` shows the exact requests that would have gone to OneUp, each one expandable. The log lives in the toolkit's event log, which keeps the newest 5,000 entries across both plugins.

### Guide

This page.

### On each auction

The edit screen of every auction has a **Social posts (Facebook)** box listing that property's posts — its own and the ones it shares (a same-day sale, the weekly roundup) — with each one's day and status, and a link to the calendar.

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

## 6. What it stores

- `{prefix}ias_posts` — one row per post: its listing(s), type, status, priority, window, scheduled time, OneUp post id, content, the images it used, approvals and its history.
- `{prefix}ias_history` — the openers, calls to action, hashtag sets, templates and images already used, for the no-repeat rules.
- The option `ias_settings` (every setting) and `ias_db_version`.
- Its log entries in the toolkit's `{prefix}ia_events` table, as `ias.*` events.

**Deleting the plugin removes all of it** — both tables, the options, its log entries, its per-image and per-listing data and its generated pictures. The listings themselves and anything already on Facebook are untouched. The full list is `uninstall.php`.

## 7. Requirements

PHP 7.4+, WordPress 5.3+, the Intel Auctions Toolkit 4.66.0+. It uses the toolkit's helpers by name; if a toolkit update ever removes one, the plugin stops with the notice rather than failing half way through a run.
