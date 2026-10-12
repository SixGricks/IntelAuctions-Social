# Testing Intel Auctions Social

Two runners, both must print **ALL PASSED**:

```
php tests/run.php                                   # pure tests, no WordPress
php tests/wp-run.php /path/to/wordpress             # every tests/wp/*.php inside WordPress
```

## The pure tests

`tests/unit/*.php`, loaded after `tests/stubs.php` (just enough WordPress for the plugin's
pure parts). The planner and scheduler are written so they run here: no database, no
clock but the one they are handed.

## The in-WordPress tests

`tests/wp/*.php` run inside a throwaway WordPress with the **Intel Auctions Toolkit** and
this plugin active. **Never point the runner at a live site** — the tests create and delete
auctions, posts and options.

A throwaway site, the way these tests were run:

1. A database: `apt-get install mariadb-server`, then `mysql_install_db` into a scratch
   directory and `mysqld_safe --datadir=… --port=3307 &`; create a `wp` database and user.
2. WordPress core: `composer require johnpbloch/wordpress-core` and copy
   `vendor/johnpbloch/wordpress-core` to the site directory; a `wp-config.php` with
   `DISABLE_WP_CRON` and `WP_HTTP_BLOCK_EXTERNAL` set (no request leaves the box); run
   `wp_install()` once.
3. Symlink the toolkit and this plugin into `wp-content/plugins/`, and copy
   `tests/fixtures/site-stub.php` into `wp-content/mu-plugins/` — it registers what
   intelauctions.com registers itself (the `auction`, `team-member` and `town` post types,
   the `role` taxonomy, `category` on auctions) and a `get_field()` that reads the meta ACF
   would have written.
4. Activate both plugins, then run `php tests/wp-run.php /path/to/site`.

`IAS_TEST_TOOLKIT_OFF=1` loads WordPress with the toolkit filtered out of the active plugins
for that one process; `tests/wp/01-foundation.php` uses it to check that the plugin then
shows its notice and does nothing.

No test reaches the real OneUp. `ias_t_oneup()` in `tests/fixtures/wp-fixtures.php` answers
`pre_http_request` for `oneupapp.io/api/` with lists shaped like the real account's, and has a
switch for each way OneUp can let the plugin down (a refusal, an answer without a post id or
lost to a 502, a request that never arrives, a "not found" delete, a list body with no list
in it, a draft scheduled as a real post, a renamed page). `tests/wp/07-oneup-live.php` is
Phase 5's acceptance test; `tests/wp/08-oneup-safety.php` runs each of those failures.
