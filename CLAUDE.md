# Elementor Freshsales — CLAUDE.md

WordPress plugin by **Cornerstone**. Adds a **Freshsales** submit-action to Elementor Pro forms
that creates a Freshsales CRM **lead** from each submission, with field mapping.

## Repo & workflow rules (mandatory)
- Remote: `git@github.com:andrewvink/elementor-freshsales.git` — **public**.
- After ANY change: update `/docs/` and this `CLAUDE.md` if affected, then **commit**.
- Commit messages: **clean and simple**, no Claude/AI attribution or "Co-Authored-By" lines.
- **Never commit secrets** — no API keys, real account domains, emails, or other personal data.
  Credentials are entered in WordPress settings, not code. Full docs live in `/docs/` (index at `docs/README.md`).

## What it does
- Registers a `freshsales` form action (label "Freshsales") on the Elementor Pro forms actions hook.
- Adds a **Freshsales** section to the form widget's *Actions After Submit* panel: API-key source
  (Default/Custom) + a **Field Mapping** control.
- On submit, maps fields and calls `POST /api/leads` on the account's Freshsales domain.
- Lead source is hardcoded to **"Web Form"** (resolved to its numeric `lead_source_id` at runtime, cached).
- **Notes** (if mapped) are posted best-effort via `POST /api/notes` after the lead is created.

## Architecture (files)
- `elementor-freshsales.php` — bootstrap. Defines constants (`VERSION`, `PLUGIN_PATH`, `PLUGIN_URL`),
  registers the action on `elementor_pro/forms/actions/register` (the action class is required *inside*
  that hook because it extends a Pro class that only exists once Pro is loaded), enqueues the editor
  script, and shows an admin notice if Elementor Pro is missing.
- `includes/class-freshsales-handler.php` — `Freshsales_Handler`: dependency-free API client on the
  WordPress HTTP API. Validates the account domain against an allowlist, then `create_lead()`,
  `get_lead_source_id()`, `add_note()`, `validate()`.
- `includes/class-freshsales-action.php` — `Freshsales_Action extends Integration_Base`: the panel
  controls, `run()`, field mapping, Integrations-tab settings (Domain + API Key + Validate button),
  and the Validate AJAX handler. The Validate endpoint tests the values **typed in the fields**, so
  its success payload is `{ saved, message }` — `saved:false` means "works, but not stored yet" and
  `admin.js` shows it in amber. Never let it report a plain green success for unsaved credentials:
  that is what makes a form fail later with "missing an API key" while the settings screen looks fine.
- `includes/class-freshsales-map-control.php` — `Freshsales_Map_Control`: a custom repeater control
  (type `cornerstone_freshsales_map`) registered on `elementor/controls/register`. Renders the mapping
  **inverted** vs Elementor's default — the form's own fields on the left, a Freshsales-field dropdown
  on the right — which is clearer for users. Stored item shape stays `{ local_id, remote_id }`.
- `assets/js/editor.js` — registers the inverted control's editor view (extends
  `elementor.modules.controls.Repeater`) and a module (extends `elementorModules.editor.utils.Module`)
  that rebuilds the mapping rows from the form's fields when the Freshsales section opens. The dropdown
  uses the SELECT control's native `groups` to render `<optgroup>` sections (from each field's `group`).
- `assets/js/admin.js` — vanilla JS for the "Validate Connection" button.
- `assets/js/campaign.js` — first-touch campaign capture. Enqueued on **every** front-end page and in
  the **header** (the visitor lands on the UTM URL long before they reach a form). Writes the
  `csfs_campaign` cookie only if one does not already exist, so the first touch is never overwritten.
- `tests/run-tests.php` — dependency-free suite (no Composer/PHPUnit); boots real WordPress.
  `php tests/run-tests.php`. Must be green before a release. `export-ignore`d from the zip.
- `assets/css/editor.css` — small stylesheet that spaces out the mapping rows; scoped to
  `.elementor-control-type-cornerstone_freshsales_map` so nothing else in the editor is affected.
- `uninstall.php` — removes all options + cached transients (multisite-aware).

## Field mapping (remote ids)
Defined once in PHP — `Cornerstone\Elementor_Freshsales\get_remote_fields()` in the main file — and injected into
`assets/js/editor.js` via an inline `window.CornerstoneFreshsalesData` script, so the list has a single source.

**Sources (left column).** `get_virtual_fields()` declares mappable sources that are *not* form fields;
they render above the form's fields, separated by a rule (`.cornerstone-freshsales-virtual-row`). Today
that is only **Form Name** (`local_id` = `FORM_NAME_SOURCE` = `__form_name`, read from
`$record->get_form_settings( 'form_name' )` in `get_mapped_value()`). The `__` prefix keeps them from
colliding with a form field's Custom ID. Add one by extending `get_virtual_fields()` *and* resolving it
in `get_mapped_value()` — the editor picks it up from `window.CornerstoneFreshsalesData.virtualFields`.

**Many-to-one.** `get_mapped_value()` collects *every* row mapped to a `remote_id` and joins the
non-empty values with a newline — it must never return on the first match (that silently dropped the
second mapping, and an empty first field killed the rest). Note destinations (`notes`, `cf_*`) are
sanitised with `sanitize_textarea_field()` so the breaks survive; other fields use
`sanitize_text_field()`, which collapses the break to a space. Keep that pairing — it is what makes
one join rule correct for both notes and single-line fields.

**Remote ids.** `first_name`, `last_name`, `mobile_number`, `medium`, `keyword` (plain top-level text), `email`
(validated), `company_name` (→ `company.name`), `notes` ("Recent Note" — written via the Notes API after
lead creation), and custom fields prefixed `cf_` (e.g. `cf_notes` = "Notes") which `build_lead()` writes into
the lead's `custom_field` object. Add more of the account's custom fields to `get_remote_fields()` with a
`cf_` id and they map automatically. These populate the dropdown on each form-field row (inverted control).
Do not re-hardcode the list in JS. Dropdown/reference fields (e.g. `campaign_id`, `lead_source_id`) are NOT
offered as free-text — they need an id (Source is resolved by name to "Web Form").

## Error handling (`run()`)
- **Fail-loud** (re-thrown → shown to logged-in admins, submission marked failed): config/auth errors —
  missing key/domain (handler throws with exception code `400`), or a 4xx from Freshsales; also the
  "requires Email or Mobile" guard.
- **Fail-soft** (swallowed → visitor's submission still succeeds, that lead skipped): transient errors —
  transport (code `0`), `408`, `429`, `5xx`. Notes + lead-source lookups are always best-effort (own try/catch).
- The handler encodes the HTTP status as the exception code so `run()` can classify. Keep that contract.

## Freshsales API (classic — developer.freshsales.io)
- Base: `https://<domain>/api/` where `<domain>` ends in `freshsales.io` / `myfreshworks.com` / `freshworks.com`.
- Auth header: `Authorization: Token token=<API_KEY>`.
- Create lead: `POST leads` body `{ "lead": { first_name, last_name, email, mobile_number, company:{name}, lead_source_id } }` → `{ "lead": { "id": N } }`.
- Lead sources: `GET selector/lead_sources` → `{ "lead_sources": [ { id, name } ] }`.
- Note: `POST notes` body `{ "note": { description, targetable_type:"Lead", targetable_id } }`.

## Campaign capture (UTM)
- `get_campaign_params()` (main file) is the single source for the tracked query params; it is
  injected into `campaign.js` **and** reused as the allowlist when the cookie is read back.
- **The `csfs_campaign` cookie is visitor-controlled — treat it as hostile.** `get_campaign_data()`
  rejects payloads over 4096 bytes *before* decoding, accepts only allowlisted keys, requires
  `is_scalar`, truncates to `CAMPAIGN_VALUE_MAX`, and runs `sanitize_text_field()`. Do not relax any
  of these; `tests/run-tests.php` locks the behaviour in.
- Cookies arrive magic-quoted (`wp_magic_quotes()`), so `wp_unslash()` must run **before**
  `json_decode()` — otherwise every cookie containing a quote silently fails to parse.
- Precedence: `apply_campaign_fields()` only fills `medium`/`keyword` when the field mapping left
  them empty. An explicit mapping always wins.
- `utm_campaign` is resolved to a numeric `campaign_id` via `selector/campaigns` (reference field,
  best-effort, cached). Lead Source stays "Web Form" — `utm_source` goes in the campaign note, so
  existing source reporting is not silently rewritten.
- Attribution is written as its **own** note so it never dilutes the enquiry note.

## Security rules (do not regress)
- **SSRF**: the domain is validated against a fixed Freshworks-host allowlist (`normalize_domain()`),
  requests use `wp_safe_remote_request()` with `redirection => 0`. Never build the API URL from
  unvalidated input, never enable redirects, never switch to `wp_remote_*`.
- **AJAX**: the Validate handler checks the nonce (`check_ajax_referer`) *and* `manage_options` before doing anything.
- **Secrets**: the API key is only sent in the Authorization header — never echoed to the browser or included in an exception message.
- **Escaping/sanitizing**: all admin/editor output is escaped; all mapped form values are sanitized before sending.
- Every PHP file starts with an `ABSPATH` guard.

## Global settings (options)
- `elementor_cornerstone_freshsales_domain`, `elementor_cornerstone_freshsales_api_key`.
  The unprefixed keys are `Freshsales_Handler::OPTION_DOMAIN` / `OPTION_API_KEY` (single source, on the
  Pro-independent handler so `uninstall.php` can reference them). Elementor's Settings API adds the `elementor_` prefix.
- Cached lookups: transients prefixed `Freshsales_Handler::SOURCE_TRANSIENT_PREFIX` (`csfs_source_`, 12h).
  Uninstall sweeps DB-backed transients; object-cache-backed ones self-expire.

## Conventions
- Namespace `Cornerstone\Elementor_Freshsales`. Version in the plugin header **and** the `VERSION` constant — keep in sync (SemVer).
- No external libraries. Vanilla JS/CSS only. Mirror Elementor Pro's own integration patterns (see `elementor-pro/modules/forms`).
