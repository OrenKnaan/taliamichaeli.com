# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A full WordPress installation (core + plugins + theme, not a scaffolded project) for **taliamichaeli.com** — a Hebrew/RTL site ("Techo Spiritual") built on Elementor. This directory is a snapshot of the live site's files: there is no git repo, no composer.json, no package.json, no build step, no linter, and no test suite. Changes here are direct edits to the PHP that (eventually) runs the production site — there is no "run the dev server" or "run the tests" step to verify correctness; verification means reading the code carefully against WordPress/Elementor/PHP semantics.

Stack facts (from `wp-config.php` and `wp-includes/version.php`):
- WordPress 7.0, requires PHP 7.4+, MySQL 5.5.5+
- DB name `taliamic_up`, table prefix `tqd_`
- `WP_DEBUG` is off; a plugin-level debug log exists at `wp-content/uploads/cmlw-log.txt` (MailerLite plugin) and `wp-content/debug.log`

## Where the actual custom code lives

Everything under `wp-content/plugins/*` and `wp-content/themes/hello-elementor` (the parent theme) is third-party/vendored (Elementor, Elementor Pro, ACF, qi-addons-for-elementor, ooohboi-steroids-for-elementor, templately, redirection, imagify, etc.) — don't modify those unless specifically asked to patch a vendor bug. The two places with **hand-written site-specific code** are:

- `wp-content/themes/hello-elementor-talia/functions.php` — the child theme's entire custom logic, one long procedural file
- `wp-content/plugins/custom-mailerlite-integration/custom-mailerlite-integration.php` — a bespoke Elementor→MailerLite webhook plugin

`wp-content/themes/hello-elementor-talia/style.css` only carries the theme header block (name/version/template) plus real CSS — it has no PHP logic.

Nearly all actual page layout and content lives in Elementor's serialized JSON stored in postmeta (in the database), not in theme template files — the child theme has no page templates of its own, it inherits everything from `hello-elementor` and only enqueues styles + adds behavior via `functions.php`.

## `functions.php` architecture (child theme)

This file is organized as a sequence of independent, self-contained feature blocks (each hooked separately), roughly in this order:

1. **Aggressive tracking blocker** — when GDPR consent is missing/essential-only, strips Facebook SDK `<script>` tags from output buffer, deactivates known FB pixel plugins, deregisters FB scripts, and monkey-patches `Node.prototype.appendChild` client-side to block FB SDK injection.
2. WP Rocket textdomain load-order fix (moves `rocket_load_textdomain` from `plugins_loaded` to `init`).
3. Parent + child stylesheet enqueueing (`talia_enqueue_styles`).
4. **Auto-categorization for the `video` CPT** — on `acf/save_post`, if a video post has no category yet, searches existing categories by regex for Hebrew "סרטו" or "video" and assigns the first match.
5. `[all_tags]` shortcode — renders a linked list of all tags, with `sorting` (alphabetical/popular) and `amount` attributes.
6. **Instagram share button integration** for the Elementor Pro Share Buttons widget: uses `ReflectionClass` to inject an `instagram` entry into the module's private `networks` property (Elementor Pro has no native Instagram share target), then adds client-side JS that intercepts clicks and uses the Web Share API on mobile / clipboard-copy + popup on desktop.
7. **Elementor/Google Fonts 404 workarounds** — proactively fetches and locally caches Google Fonts CSS under `uploads/elementor/google-fonts/css/`, rewrites `fonts.gstatic.com` URLs to local ones, serves missing font CSS on-the-fly via `template_redirect`, adds an admin notice + one-click "Fix CSS Files" action that clears Elementor's CSS cache, and suppresses a couple of known console errors (an undefined Hebrew-named JS variable, FB/extension-related errors).
8. **Custom GDPR consent system** (not a plugin — fully bespoke): defines cookie categories (`essential`/`analytics`/`marketing`), conditionally emits GA/GTM/Facebook Pixel script tags only when `gdpr_consent_type=all`, actively removes/disables other trackers (Site Kit, MonsterInsights, Jetpack stats, WooCommerce analytics, PixelYourSite) when consent isn't full, clears tracking cookies/localStorage when consent is "essential", and renders the actual consent banner UI (HTML/CSS/JS) plus a `[gdpr_reset_button]` shortcode. State is read/written via the `gdpr_consent` / `gdpr_consent_type` cookies; helper functions `get_gdpr_consent_type()`, `has_gdpr_consent()`, `has_full_consent()` are available globally.
9. A one-off `noindex, nofollow` meta tag override hardcoded to a single URL (`/shadow-talk/`).

When adding new site behavior, follow this file's existing pattern: a small, independent, hook-based block with a Hebrew or English comment header — don't introduce a framework/autoloader for what's currently ~1 file.

## MailerLite plugin architecture

`custom-mailerlite-integration.php` (class `CMLW_Integration_Plugin_V6`) bridges Elementor form submissions to MailerLite:

- Registers `POST /wp-json/mailerlite-webhook/v1/submit`, which Elementor Pro forms call via their "Webhook" action.
- Maps each Elementor `form_id` → a MailerLite `group_id` via the `cmlw_form_groups_v6` option. Unknown form IDs are auto-registered (empty group) and trigger an admin-notification email to `orenknaan@gmail.com` (cc `taliamichaeli@gmail.com`).
- Validates the submitted email, POSTs the subscriber to `https://connect.mailerlite.com/api/subscribers`, and logs every attempt (success/failure/unassigned) to `wp-content/uploads/cmlw-log.txt`.
- Ships its own wp-admin page ("MailerLite Webhook" menu) with Dashboard (webhook URL + form→group mapping table) and Logs tabs, plus a hand-rolled Hebrew translation table (`translate_text`) instead of `.po`/`.mo` files.

**Security note:** the MailerLite API key is hardcoded as a plaintext class property in this file rather than pulled from config/env. Treat it as a live secret — never echo, log, or paste it in full, and flag to the user if asked about secrets hygiene rather than silently "fixing" it.

## Working conventions for this repo

- There's no dependency manager and no autoloading — new PHP goes directly into one of the two files above (or a new plugin/mu-plugin file if it's substantial and unrelated to existing features), using plain `add_action`/`add_filter`/`add_shortcode` calls.
- No test suite exists. "Verifying" a change means tracing the WordPress hook lifecycle and Elementor's expected request/response shapes by reading code, not running anything.
- This is not a git repository — there's no commit history or branches to check; treat any existing but unfamiliar files/state as prior manual edits to preserve, not artifacts to clean up.
- Much of the codebase's comments and user-facing strings are in Hebrew (RTL); match that convention for site-facing copy (shortcode output, admin notices meant for the site owner) and keep code-facing comments in whichever language the surrounding block already uses.
