# Intel Auctions Social

A WordPress plugin for intelauctions.com, the sister of the
[Intel Auctions Toolkit](https://github.com/SixGricks/IntelAuction) (**v0.1.0**). It turns
each auction into a set of Facebook posts, plans them three a day through OneUp, and keeps
a person in the loop on the posts that carry hard facts.

- **What it is built to do:** [`docs/build-spec.md`](docs/build-spec.md) — DJ's spec, verbatim.
- **How it works and how to use it:** [`docs/guide.md`](docs/guide.md), also shown in
  WordPress at Auctions → Social Calendar → Guide.
- **What changed:** [`CHANGELOG.md`](CHANGELOG.md).
- **Working on it:** [`CLAUDE.md`](CLAUDE.md) (conventions) and [`tests/README.md`](tests/README.md).

**Needs** the Intel Auctions Toolkit 4.66.0 or newer, PHP 7.4+ and WordPress 5.3+. Without
the toolkit it shows a notice and does nothing.

**Install:** upload `intel-auctions-social.zip` from the latest GitHub release (Plugins → Add
New → Upload) and activate. Updates then arrive through WordPress (`includes/updater.php`,
from this repo's latest non-prerelease). Put the OneUp API key in wp-config as
`INTEL_ONEUP_API_KEY`.

**Safe by default:** the master switch, every post type and the Facebook Event cards are off
at install and dry run is on, so nothing can reach Facebook until DJ turns a switch on.

## Layout

| Path | What |
|---|---|
| `intel-auctions-social.php` | Header, constants, the dependency gate and the loader (`ias_boot()` on `plugins_loaded`) |
| `includes/dependency.php` | The toolkit check and its notice — loaded before anything else |
| `includes/settings.php` | Every setting and its default (`ias_settings`) |
| `includes/db.php` | The two tables, post rows and the usage history |
| `includes/log.php` | `ias_log()` into the toolkit's event log |
| `includes/post-types.php` | The post types and statuses from the spec |
| `includes/admin*.php` | The Social Calendar, Settings, Log and Guide screens, and the listing panel |
| `includes/updater.php` | GitHub updater (same mechanism as the toolkit's) |
| `uninstall.php` | Removes everything the plugin stored — the catalog of it |
| `tests/` | Pure tests (`php tests/run.php`) and in-WordPress tests (`tests/wp-run.php`) |
