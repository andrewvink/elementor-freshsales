# Architecture

## Overview

The plugin registers a **Freshsales** submit-action on Elementor Pro forms. On submission it maps the
form fields and creates a lead via the Freshsales API. It mirrors Elementor Pro's own integration
patterns (MailerLite/MailChimp) but ships a **custom, inverted** field-mapping control.

## Files

| File | Responsibility |
|------|----------------|
| `elementor-freshsales.php` | Bootstrap: constants (`VERSION`, `PLUGIN_PATH`, `PLUGIN_URL`), registers the action + custom control, enqueues editor JS/CSS, injects the field list, admin notice when Pro is missing, and `get_remote_fields()` (the single source of truth for mappable fields). |
| `includes/class-freshsales-handler.php` | `Freshsales_Handler` — dependency-free API client on the WordPress HTTP API. Domain allowlist, `create_lead()`, `get_lead_source_id()`, `add_note()`, `validate()`. Holds the option-key + transient-prefix constants. |
| `includes/class-freshsales-action.php` | `Freshsales_Action extends Integration_Base` — panel controls, `run()`, field mapping, the Integrations-tab settings (Domain + API Key + Validate), and the Validate AJAX handler. |
| `includes/class-freshsales-map-control.php` | `Freshsales_Map_Control` — a custom repeater control (`cornerstone_freshsales_map`) whose rows are the **form's fields** with a Freshsales dropdown each. |
| `assets/js/editor.js` | Registers the control's editor view (grouped dropdown, form-first rows) and a module that rebuilds the rows when the Freshsales section opens. |
| `assets/js/admin.js` | Vanilla JS for the "Validate Connection" button. |
| `assets/css/editor.css` | Spacing/alignment for the mapping control (scoped to its control type). |
| `uninstall.php` | Removes options + cached transients (multisite-aware). |

## Hooks & load order

- `elementor_pro/forms/actions/register` → requires the handler + control + action classes and registers
  the action. The action class extends a Pro base class, so it is only loaded inside this hook (Pro is
  guaranteed loaded by then).
- `elementor/controls/register` → registers `Freshsales_Map_Control`.
- `elementor/editor/after_enqueue_scripts` → enqueues `editor.js` + `editor.css` and injects
  `window.CornerstoneFreshsalesData.remoteFields` (from `get_remote_fields()`) via an inline script.
- `elementor/admin/after_create_settings/{PAGE_ID}` → adds the Integrations-tab fields.
- `wp_ajax_cornerstone_freshsales_validate` → the Validate endpoint.

### Validate endpoint contract

The button posts the values **currently typed in the two inputs** (falling back to the saved options
when a box is left blank), so it can be used to test credentials before committing them. Because a
working key is not the same thing as a *stored* key, the success payload reports both:

```json
{ "success": true, "data": { "saved": true|false, "message": "…" } }
```

`saved` is false when the tested values differ from `elementor_cornerstone_freshsales_api_key` /
`_domain`; `admin.js` then renders the message in amber rather than green. Failures still return a
plain string in `data`. Keep this shape in sync between `ajax_validate()` and `assets/js/admin.js`.

## Editor integration

`getControlView(type)` resolves a control type to a JS view via `upperCaseWords()` (capitalizes the first
letter only), so control type `cornerstone_freshsales_map` resolves to the view registered under that name.

The control view extends `elementor.modules.controls.Repeater`:
- `rebuild()` — resets the repeater collection to one row per form field (skipping `step`/`html`),
  preserving any saved selection. Triggered on `section:activated` for `section_freshsales`.
- `onRender()` — for each row sets the label to the form field's label and the dropdown to grouped
  Freshsales options using the SELECT control's native `groups` (`<optgroup>`).

Stored item shape is `{ local_id, remote_id }` — identical to Elementor Pro's `Fields_Map`, so mappings
survive and `run()` reads them the same way.

## Submission flow (`run()`)

1. Resolve the API key (global, or per-form custom) and the global domain.
2. `build_lead()` — map plain top-level fields, validate email, nest company, and collect any `cf_*`
   custom fields into `custom_field`.
3. Require at least one of email/mobile (else throw — a config/mapping error).
4. Construct the handler, resolve the "Web Form" source (best-effort, cached), `create_lead()`.
5. If a "Recent Note" is mapped, `add_note()` (best-effort).
6. **Error policy:** transient errors (transport/408/429/5xx) are swallowed (visitor's form still
   succeeds); config/auth errors (missing key/domain, 4xx) are re-thrown and shown to admins.
