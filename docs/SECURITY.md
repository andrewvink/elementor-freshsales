# Security model

## SSRF / request safety

- The account **domain is validated against a fixed allowlist** of Freshworks hosts
  (`freshsales.io`, `myfreshworks.com`, `freshworks.com`). `normalize_domain()` strips scheme/path/port,
  lower-cases, and rejects anything not ending in an allowed suffix — blocking internal hosts, IPs, and
  credential/userinfo tricks (verified against cases like `acme.freshsales.io.evil.com`,
  `169.254.169.254`, `acme.freshsales.io@evil.com`, bare `freshsales.io`).
- Requests use **`wp_safe_remote_request()`** (blocks private/loopback IPs) with **`redirection => 0`**
  (no redirect-based SSRF).
- Never build the API URL from unvalidated input; never enable redirects; never switch to `wp_remote_*`.

## Authentication & authorization

- The **Validate** AJAX endpoint verifies the nonce (`check_ajax_referer`) **and** `current_user_can('manage_options')` before doing anything.
- The editor field-map panel request goes through Elementor's own authenticated editor AJAX.

## Secrets

- The API key is only ever sent in the `Authorization` header. It is **never** echoed to the browser,
  included in an exception message, or exported with templates (`on_export()` strips per-form settings).
- Global credentials live in WordPress options; per-form custom keys live in the page content.

## Input / output

- Every mapped value is sanitized before being sent (`sanitize_text_field` / `sanitize_email` + `is_email`).
- All admin/editor output is escaped (`esc_html*`, `esc_attr`, `esc_url`).
- Every PHP file begins with an `ABSPATH` guard.
- `uninstall.php` uses `$wpdb->prepare()` + `esc_like()` for its transient sweep.

## Resilience

Transient Freshsales failures never break the visitor's submission (fail-soft); persistent
configuration/auth errors are surfaced to admins (fail-loud). See ARCHITECTURE.md → "Submission flow".
