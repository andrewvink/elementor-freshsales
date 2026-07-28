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
  css/editor.css                 mapping control styling
uninstall.php                    clean removal
docs/                            this documentation
readme.txt                       WordPress-format readme/changelog
```

## Conventions

- Namespace `Cornerstone\Elementor_Freshsales`.
- No external libraries; vanilla JS/CSS only.
- Keep the version in the plugin header **and** the `VERSION` constant in sync (SemVer).
- Mirror Elementor Pro's forms integration patterns where practical.

## Local testing

Any WordPress with Elementor Pro works; this was built on Laravel Herd.

Lint everything:
```sh
php -l elementor-freshsales.php
php -l includes/*.php
node --check assets/js/editor.js
node --check assets/js/admin.js
```

Because Elementor's editor is a heavy SPA, the integration was verified **server-side** by booting
WordPress from the CLI and asserting behavior directly, e.g.:

- the action registers with Elementor Pro (`instanceof Integration_Base`);
- the custom control registers (`controls_manager->get_control('cornerstone_freshsales_map')`);
- `normalize_domain()` accepts valid hosts and rejects SSRF-hostile ones;
- `build_lead()` places each mapped field correctly (top-level / company / custom_field);
- a live **read-only** call (`GET selector/lead_sources`) authenticates.

Never commit credentials or a real account domain — they are entered in WordPress settings, not code.

## Editor caching

`editor.js` / `editor.css` are versioned by `VERSION`; bump the version (or hard-refresh) to pick up
changes. The injected field list (`window.CornerstoneFreshsalesData`) is inline and always fresh.

## Releasing

1. Update `Version:` in the header + `VERSION` + `Stable tag:` in `readme.txt`.
2. Add a `readme.txt` changelog entry.
3. Update docs if behavior changed.
4. Commit.
