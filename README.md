# Notify Telegram

Notifications for WordPress events, delivered to the channels you configure: Telegram, email or any
incoming webhook. Scaffolded from the lab's `starter-plugin` template: namespace
`Wpseed\NotifyTelegram`, text domain `notify-telegram`, package `wpseed/notify-telegram`.

## What is inside

| File / directory | Purpose |
| --- | --- |
| `notify-telegram.php` | Plugin header, autoloader include, `Plugin::boot()` |
| `src/Plugin.php` | Singleton entry point: builds the objects, registers the hooks, activation |
| `src/Message.php` | The text that leaves the site: `%placeholder%` rendering and validation |
| `src/Settings/Settings.php` | The single option: switches, channel values, message templates |
| `src/Channel/` | `Channel` interface, `Result`, `ChannelRegistry`, `TelegramChannel`, `EmailChannel`, `WebhookChannel` |
| `src/Event/` | `Event`, `EventRegistry`, `EventSource`, `UserEvents` (registration, failed login), `CommentEvents` |
| `src/Delivery/` | `Router` (what goes out), `Queue` (WP-Cron, retries), `Log` (last 20 attempts) |
| `src/Admin/AdminPage.php` | The single menu entry and the React boot behind it: Events and Settings are tabs on that one screen |
| `src/Rest/SettingsController.php` | The routes the screen reads and writes through: `/settings`, `/test`, `/log` |
| `src/Delivery/TestSender.php` | The test button: one message through every configured channel |
| `admin-ui/`, `package.json`, `vite.config.mjs` | React + antd sources and the Vite build of the screen |
| `bin/build` | Archive builder (`composer build`): a copy without the development files + zip into `dist/` |
| `.github/workflows/build-plugin.yml` | The same build in CI, triggered by a version tag |
| `.github/workflows/php-floor.yml` | The PHP floor check: install and lint on 8.2, on every push |
| `tests/unit/` | Fast unit tests (PHPUnit, WordPress is not loaded) |
| `tests/integration/` | Integration tests (`WP_UnitTestCase`, real WordPress + MySQL) |

## Setup and commands

```bash
cd web/app/plugins/notify-telegram

composer install          # development dependencies (phpunit, wp-phpunit, WPCS)
composer test             # every test
composer test:unit        # unit only
composer test:integration # integration only (boots WordPress)
composer lint             # phpcs (WordPress Coding Standards)
composer lint:fix         # phpcbf, auto-fix
composer build            # shippable archive: dist/notify-telegram/ + dist/notify-telegram.zip

npm install               # front-end dependencies (React, antd, Vite)
npm run build             # bundle the admin screen into assets/admin/
npm run watch             # same, rebuilding on every change
```

## Events, channels and delivery

```
WordPress hook  →  EventSource  (declares the event, fills its placeholders)
                →  Router       (master switch, event toggle, channels that are enabled and configured)
                →  Queue        (one WP-Cron event per channel, retries, log)
                →  Channel      (Telegram / email / webhook)
```

Adding a channel is one class: implement `Channel` (`id`, `label`, `fields`, `is_configured`, `send`)
and add it to the array in `Plugin::create_channels()`, or hand it in through the
`notify_telegram_channels` filter. `fields()` is what the settings screen renders, so a channel brings
its own form. Adding an event is one class too: implement `EventSource` and register it through the
`notify_telegram_event_sources` filter — the placeholders an event declares are both its documentation
and the validation rules for its message template.

## The screen

```
Notify Telegram  →  admin.php?page=notify-telegram               Events    (default)
                    admin.php?page=notify-telegram&tab=settings  Settings
```

One menu entry, no submenu: **Events** and **Settings** are tabs inside the page. WordPress prints the
submenu list only when `$submenu` holds entries for the parent slug (`wp-admin/menu-header.php`), so the
plugin registers a single `add_menu_page()` and no `add_submenu_page()` at all — not even one that
reuses the parent slug, which is the usual trick for labelling the first entry and is exactly what
unfolds the list on hover. The sidebar shows one plain link, and `toplevel_page_notify-telegram` is the
only screen the bundle is enqueued on.

**Events** lists every registered event with its switch, its message
and the placeholders that event accepts; **Settings** holds the master switch, one card per channel with
the fields the channel itself declares, the test button and the last twenty delivery attempts. The state
lives in the root component, so switching tabs never loses an edit and one Save button stores both — the
plugin keeps all of it in a single option anyway.

The open tab is addressable: `AdminPage::initial_tab()` puts the requested `tab` query argument (only
`events` or `settings` pass; anything else falls back to the first tab) into the configuration the screen
is booted with, and the application writes the chosen tab back into the address bar with
`history.replaceState()`. A reload or a shared link therefore opens the same tab, without a WordPress
page per tab.

### The menu entry

One entry, and `AdminPage::MENU_POSITION` is the only knob for where it sits: `75.9` gives it its own
slot under Tools (75) and above Settings (80), while core's other sections are at 4 (a separator),
10 (Media), 20 (Pages), 60 (Appearance), 65 (Plugins) and 70 (Users) — a plugin that passes no position
at all lands after all of them. Move the number to move the entry: 3 is directly under Dashboard, 59.9
just above Appearance. A position another plugin has already taken costs nothing: core checks the key
before writing and gives the later registration a small offset instead of a shared slot, which is why
positions live in the menu as string keys and why a float is a legitimate value here.

The icon is the core bell (`dashicons-bell`), so the entry is painted by the admin colour scheme exactly
like the rest of the sidebar — including the dimmed state, the hover colour and the highlight of the open
screen, none of which an image of our own would follow. `AdminPage::ICON` is the one place to change it:
any `dashicons-*` class works, and WordPress renders it through `div.wp-menu-image:before`
(`wp-admin/menu-header.php`). An icon of our own is possible — a `data:image/svg+xml;base64,…` URI is the
one form core special-cases, and it keeps whatever palette the SVG declares — but that icon then ignores
the colour scheme entirely, so it is not what ships here.

PHP prints the WordPress heading, the description and the mount element; the application fills the mount
element and nothing else. The lab's `starter-plugin` keeps a working reference of the build setup —
including the part that is easy to get wrong, that the bundle must be an **IIFE**, because
`wp_enqueue_script()` prints a classic `<script>` and a classic script cannot parse an ES module.

Things that are easy to get wrong:

- **The bundle is a build artefact.** After a fresh clone the screen shows a developer notice until
  `npm run build` runs, and `composer build` refuses to produce an archive without it — the archive can
  never ship an empty screen.
- Vite gets an explicit entry (`admin-ui/main.jsx`) instead of an `index.html`, and `assetsDir: ''`, so the
  hashed files land flat in `assets/admin/`.
- The bundle is built as an **IIFE**: `wp_enqueue_script()` prints a classic `<script>`, and a classic script
  cannot parse an ES module — the screen would stay empty with nothing but a console error. `bin/build`
  refuses a bundle that looks like a module, and an IIFE keeps the CSS inside the bundle, which is fine.
- `wp_add_inline_script()` replaces `wp_localize_script()` here: it keeps the types of the values, so the
  application receives an object rather than strings.
- **Message templates are `%name%` tokens, not `printf` specifiers.** `Message::validate_template()`
  rejects an unknown placeholder and a stray `%` when the form is saved, and the rejected field keeps
  the value that was stored before. The matching WPCS sniff is excluded in `phpcs.xml.dist` because it
  reads the `%d` inside `%display_name%` as a printf specifier and asks for `%1$d` — and `phpcbf`
  happily "fixes" it into `%1$display_name%`.
- **Translated strings belong on `init`.** The event sources register their titles and default messages
  on `init` (`Plugin::register_sources()`): loading a text domain before `init` is an error since
  WordPress 6.7 and shows up as `_load_textdomain_just_in_time was called incorrectly`.
- **`wp_schedule_single_event()` drops a second identical event within ten minutes.** Two identical
  messages (the same comment text twice, a repeated test message) would silently collapse into one, so
  every queued payload carries a unique token.
- **Delivery is deferred on purpose.** A 15 second HTTP timeout in the middle of a registration, a
  comment or a checkout is worse than a late notification. The queue retries three times with a growing
  pause and only then writes the failure to the log; the `notify_telegram_send_async` filter (`false`)
  makes the tests deliver inside the request. A failure a channel reports as permanent (`Result::fail()`
  with `$permanent`) is not retried at all — a blocked bot or an endpoint that moved costs one log entry,
  not three — and a failure that comes with a pause (Telegram answers a rate limit with
  `parameters.retry_after`) is retried after exactly that pause.
- **Telegram gets plain text.** A parse mode would turn every value the site interpolates (a customer
  name, a comment excerpt) into a parsing hazard, and Telegram answers a broken entity with a 400
  instead of the message. The webhook channel is the JSON one, with an optional HMAC-SHA256 signature.
- **A message longer than 4096 units is sent as several messages, never cut.** The end of a notification
  (the last order line, the tail of a comment) is the part someone reads, so `TelegramChannel::split()`
  breaks at the last newline, then at the last space, and only cuts mid-word when there is no break at
  all. Length is counted the way the API counts it — in UTF-16 code units, so an emoji costs two and a
  cut never lands inside a surrogate pair (`TelegramChannel::length()`).
- **Every chat and every webhook URL is independent.** One target that refuses a message does not stop
  the others: both channels collect the failures they met and report them together (`Result::combine()`),
  so a deleted Slack hook cannot silence a working one.
- **A channel with an empty field is skipped, not reported.** A fresh install is not an error: the
  settings screen shows such a channel as *not configured yet*, and the router logs `Skipped: no channel
  is enabled and configured` when a real event finds nothing to send through.
- Cookie-authenticated REST requests need the `X-WP-Nonce` header; the application sends the nonce from its
  configuration. The integration tests cover the capability check, the nonce itself is verified against the
  live screen (the test environment authenticates through the current-user global and bypasses it).
- Assets inside a plugin are served straight from the plugin directory, so there is no asset-publishing step
  after `npm run build` (unlike a theme).

## How the integration tests work

`tests/bootstrap.php` boots the official WordPress test suite
(`wp-phpunit/wp-phpunit`) and loads the plugin through the `muplugins_loaded` filter —
exactly the way WordPress itself loads it. The core comes from the project's `web/wp`,
the database is a separate `wordpress_tests` schema (tables are recreated on every run),
and the config is `tests/wp-tests-config.php` (its defaults target the lab and can be
overridden with the `WP_TESTS_DB_*`, `WP_CORE_DIR` and `WP_TESTS_DIR` environment
variables).

The content directory in tests is the same `web/app` the dev environment uses, so
`WP_PLUGIN_DIR`, `WP_CONTENT_URL` and the plugin file paths match the real ones.

## PHP version floor

The plugin targets **PHP 8.2**, and the floor lives in four places so it cannot drift:

| Where | What it does |
| --- | --- |
| `composer.json` → `"require": { "php": ">=8.2" }` | Composer refuses to install under an older PHP, and the `vendor/composer/platform_check.php` it generates into the archive stops WordPress with a clear message instead of a half-loaded fatal error |
| `notify-telegram.php` → `Requires PHP: 8.2` | the plugin header WordPress (and the plugin directory, on upload) reads |
| `phpcs.xml.dist` → `testVersion 8.2-` | `PHPCompatibilityWP` flags syntax and functions **removed** before the floor — but not newer additions: verified that the stable line (9.3.5) flags *none* of `readonly`/`enum`/`array_is_list()` (8.1), `readonly class`/DNF types (8.2), typed class constants/`json_validate()` (8.3) at `testVersion 8.0-`, `8.1-`, `8.2-` or `8.3-` |
| `composer.json` → `"config": { "platform": { "php": "8.2" } }` | dependency **resolution** runs as if PHP 8.2 were installed, so `composer require` cannot pull a runtime package that needs a newer PHP — the case the sniffs cannot cover, because `vendor/` is outside the sniffed paths |

**What actually holds the floor:** the `platform_check.php` guard (a hard, readable stop on anything below
8.2), the CI job in `.github/workflows/php-floor.yml`, and running the code on 8.2 itself — the sniffer is
not a gate for newer syntax. The job closes the syntax half: on every push it installs the dependencies on
PHP 8.2, asserts that no installed package wants a newer PHP (`composer why-not php 8.2.0`, which exits
non-zero on a conflict — verified), and runs `php -l` over the shipped files. What it still does not cover
is a *function* or *behaviour* that only exists in newer PHP (`json_validate()`, `array_is_list()`,
property hooks): `php -l` is happy with those, and detecting them needs the test suite executed on 8.2,
which needs the WordPress test suite and a MySQL service in CI. The alternative — the alpha sniffer line
(`phpcompatibility/php-compatibility 10.0.0-alpha2` + `phpcompatibility/phpcompatibility-wp 3.0.0-alpha2`),
which does know 8.1+ — was rejected: it resolves only with `minimum-stability: dev`, since it pulls in
`phpcompatibility/phpcompatibility-paragonie ^2.0@dev`.

Why 8.2 and not 7.4 (September 2026 data, `https://api.wordpress.org/stats/php/1.0/`): 25.7% of
WordPress sites run PHP 8.3, 24.5% run 8.2, 16.8% run 7.4, 11.2% run 8.1, 8.7% run 8.4, 4.0% run
8.0, 3.2% run 8.5 — so **62.2% of sites are on 8.2+** while **37.8% are on a branch that has reached
end of life** (PHP 8.1 security support ended 31 Dec 2025, 8.0 in Nov 2023, 7.4 in Nov 2022,
`https://www.php.net/supported-versions.php`); 8.2 itself is security-only and ends 31 Dec 2026.
WordPress recommends PHP 8.3+ for hosts and keeps 7.4 only as a legacy allowance
(`https://wordpress.org/about/requirements/`), and WP 7.0 dropped 7.2/7.3 outright. A floor of 7.4
would therefore buy ~5.8% more install base in exchange for the whole modern type system — enums,
readonly properties, constructor promotion, named arguments, union types, nullsafe access, `match` —
on machines that no longer receive security fixes. Raise the floor when 8.2 goes end of life
(31 Dec 2026): editing these four places is the whole change.

## Prefixes and namespaces — protection against collisions with other plugins

These are two different mechanisms and both are needed:

1. **Your own code.** The `Wpseed\NotifyTelegram` namespace plus prefixes for everything
   WordPress sees globally: hooks, options, transients, post types and taxonomies, the
   REST namespace, cron hooks, the text domain, asset handles. This is enforced
   automatically by the `WordPress.NamingConventions.PrefixAllGlobals` sniff — the prefix
   list lives in `phpcs.xml.dist` (`prefixes`) and has to be updated for your own slug
   when the template is copied: a global `my_helper()` function or an `my_setting` option
   without a prefix fails `composer lint`.
2. **Vendor packages** (Guzzle, Symfony and other shared libraries) — this is where real
   collisions happen: two plugins pull different versions of the same library and the one
   whose autoloader registered first wins. The fix is prefixing the dependencies, but there is
   nothing to prefix here: `composer.json` requires PHP and nothing else, so the plugin ships
   no third-party code at all.

## No prefixing step, because there is nothing to prefix

The build used to run PHP-Scoper over the copy and rename a `NotifyTelegram\Dependencies`
namespace. With no runtime dependency inside, every step of that pipeline was a step that
could drop a file from the archive without failing, while the collision it protected against
could not happen. It was removed; the pipeline still lives in the lab's `starter-plugin`
template for the day a library actually arrives.

If that day comes, bring over `humbug/php-scoper`, **not** `brianhenryie/strauss`: Strauss
does not run on Windows at all (`FileSystem::getFsRoot()` matches forward slashes only, while
`getcwd()` returns backslashes there) and this dev machine is Windows. Whatever the tool,
keep `bin/build`'s leak check — it is what catches a build step that drops or adds a file
instead of failing.

### What `composer build` does

1. copies the plugin into `dist/notify-telegram/`, leaving out the development-only files;
2. runs `composer install --no-dev` **there**: the dev packages the copy inherited are
   removed and `vendor/autoload.php` is written from the copied `composer.json`;
3. drops that `composer.json`/`composer.lock` again — they were only needed for step 2;
4. checks that no development-only entry (tests, build config, the screen's sources, `bin/`)
   reached the artifact, and **fails** if one did;
5. zips `dist/notify-telegram/` into `dist/notify-telegram.zip`.

The source tree is never modified, so `composer test` and `composer lint` keep working while
the archive is built.

### What ships, and what does not

| In `dist/notify-telegram.zip` | Left out |
| --- | --- |
| `notify-telegram.php` (the plugin file), `src/`, `assets/admin/` with the built screen and its manifest, `vendor/autoload.php` with Composer's class map | `tests/`, `bin/`, `.github/`, `.gitignore`, `admin-ui/`, `node_modules/`, `package.json`, `package-lock.json`, `vite.config.mjs`, `composer.json`, `composer.lock`, `phpcs.xml.dist`, `phpunit.xml.dist`, `README.md` |

### The Composer autoloader suffix

`composer.json` sets `"config": { "autoloader-suffix": "NotifyTelegram" }`. Composer names its
autoloader class `ComposerAutoloaderInit<suffix>`, and the default suffix is derived from the
resolved package set — so two plugins that resolve to the same packages generate the **same class
name**, and the second one to load kills the site with
`Cannot redeclare class ComposerAutoloaderInit…`. Verified: with the default suffix, activating the
built copy next to the source plugin produced exactly that fatal error. A per-plugin suffix fixes
it, and the lab's `bin/new-plugin` sets one when a plugin is created from the template, so plugins
built that way never collide with each other. Two copies of the *same* plugin (a dev tree and
its own `dist/` build) still share the name by design — do not run both at once.

### What was verified on this host

The archive was not only built but installed. `dist/notify-telegram/` was copied to
`web/app/plugins/notify-telegram-built` on the lab site — with the source plugin deactivated
first, because both copies share the Composer autoloader class name — activated, and the
screen was fetched with an administrator's cookies. It came back with the mount element, the
inline configuration and no "bundle is missing" notice, and the script URL printed on that
page served the bundle byte-identical to the source build.

The leak check was verified by breaking it: `composer install` recreates `composer.json` inside
the artifact, and with that cleanup disabled the build stops with `The artifact contains
development files: composer.json` instead of shipping it — and leaves no archive behind.

### Where the build runs: locally and in CI

Locally — `composer build`, on Windows too: the archive is built and inspected on the dev
machine, with no CI round trip.

In CI — `.github/workflows/build-plugin.yml` runs the very same `composer build` on
ubuntu-latest and attaches the zip to the GitHub release when a version tag (`v1.2.3`) is
pushed (a manual run uploads it as a build artifact instead). One prerequisite: the plugin
must be its own repository with `.github/` at its root — a plugin sitting in a subdirectory
of the lab repository is never built by GitHub.

**`composer.lock` is git-ignored here** — the dependencies are needed for development and
tests only — so a CI build resolves the dev toolchain fresh every time. That cannot change
what the archive carries today (it holds no third-party code), but commit the lock file if
that ever changes.

## Pitfalls worth knowing (verified in practice)

* `add_submenu_page()`/`add_menu_page()` return `false` when the current user lacks the
  capability — tests need `wp_set_current_user()` with an administrator.
* A top-level entry with **no** submenu is rendered as a plain link: core adds
  `wp-has-submenu` and the `<ul class="wp-submenu">` list only when `$submenu` has entries
  for the parent slug. `add_menu_page()` itself still puts the item into `$menu` for every
  user (core filters `$menu` by capability later, in `wp-admin/menu.php`), so a capability
  assertion has to target the page callback, not the menu array.
* The WordPress test suite reads the config path from the **constant**
  `WP_TESTS_CONFIG_FILE_PATH`, not from an environment variable.
* The plugin's own tests run against the **source** tree, not against `dist/`: the artifact
  is checked by installing it (see above), never by reading it.
