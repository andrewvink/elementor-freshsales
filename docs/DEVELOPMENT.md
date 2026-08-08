# Development

## Layout

```
elementor-freshsales.php        bootstrap + get_remote_fields()
includes/
  class-freshsales-handler.php   API client
  class-freshsales-action.php    form action + settings
  class-freshsales-map-control.php  custom mapping control (PHP)
assets/
  js/editor.js                   mapping control view + rebuild module
  js/admin.js                    Validate button
  js/campaign.js                 first-touch campaign capture (front end)
  css/editor.css                 mapping control styling
tests/run-tests.php              dependency-free test suite
uninstall.php                    clean removal
docs/                            this documentation
readme.txt                       WordPress-format readme/changelog
```

## Conventions

- Namespace `Cornerstone\Elementor_Freshsales`.
- No external libraries; vanilla JS/CSS only.
- Keep the version in the plugin header **and** the `VERSION` constant in sync (SemVer).
- Mirror Elementor Pro's forms integration patterns where practical.

## Tests

```sh
php tests/run-tests.php                     # auto-detects the WordPress root
php tests/run-tests.php --wp=/path/to/wp    # or point it at one
```

No Composer and no PHPUnit — the suite is a single dependency-free script, matching the plugin's own
no-libraries rule. It boots a real WordPress so the code runs against the same APIs it uses in
production (`sanitize_text_field`, `wp_unslash` against magic-quoted `$_COOKIE`, `is_email`,
transients). Exits non-zero on failure, so it can gate a release.

Coverage: field mapping (including many-to-one concatenation and the Form Name virtual source), the
lead payload (`company` nesting, email validation, `cf_*` routing, sanitiser choice), campaign
capture against **hostile cookies** (allowlist, length caps, non-scalars, oversized payloads, markup
and CRLF stripping, unslash round-trip), mapping-wins-over-capture precedence, the
`normalize_domain()` SSRF allowlist, and credential handling (config errors use code 400; the API
key never appears in an exception message).

Elementor's editor is a heavy SPA, so the editor-side views (`editor.js`) are **not** covered — verify
those by hand in the panel.

Lint everything:
```sh
php -l elementor-freshsales.php
php -l includes/*.php
node --check assets/js/editor.js
node --check assets/js/admin.js
node --check assets/js/campaign.js
```

Never commit credentials or a real account domain — they are entered in WordPress settings, not code.

## Editor caching

`editor.js` / `editor.css` are versioned by `VERSION`; bump the version (or hard-refresh) to pick up
changes. The injected field list (`window.CornerstoneFreshsalesData`) is inline and always fresh.

## Releasing

1. Run `php tests/run-tests.php` — it must be green.
2. Update `Version:` in the header + `VERSION` + `Stable tag:` in `readme.txt`.
3. Add a `readme.txt` changelog entry.
4. Update docs if behavior changed.
5. Commit, then push a `v*` tag — `.github/workflows/release.yml` builds the zip with
   `git archive` (honouring `export-ignore`, so `docs/`, `tests/` and `CLAUDE.md` stay out) and
   publishes the GitHub release.
