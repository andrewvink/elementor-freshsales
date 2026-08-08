# Field mapping

## The control

Mapping is **form-first**: each of the form's own fields is a row on the left, with a Freshsales-field
dropdown on the right. The dropdown options are grouped with `<optgroup>` into sections.

Stored data is a repeater of `{ local_id, remote_id }` items (form field id → Freshsales field id).

Every submission creates a **lead** (`POST /api/leads`). No contact, account or deal is created.

## Sources (the left-hand column)

Rows come from two places, virtual sources first, separated by a rule:

| Source | `local_id` | Where the value comes from |
|--------|-----------|----------------------------|
| Form Name | `__form_name` | the widget's **Form Name** setting — `$record->get_form_settings( 'form_name' )` |
| *(each form field)* | the field's Custom ID | the submitted value |

Virtual sources are declared in `get_virtual_fields()` (`elementor-freshsales.php`) and resolved in
`Freshsales_Action::get_mapped_value()`. They use a `__` prefix so they can never collide with a
form field's Custom ID. One source may be mapped to several Freshsales fields at once, and a source
with no value contributes nothing to the payload.

## Fields offered

Defined once in `get_remote_fields()` (`elementor-freshsales.php`) and injected into the editor:

| Group | Label | `remote_id` | Placement in the lead payload |
|-------|-------|-------------|-------------------------------|
| Name & contact | First Name | `first_name` | top-level |
| Name & contact | Last Name | `last_name` | top-level |
| Name & contact | Email\* | `email` | top-level (validated with `is_email`) |
| Name & contact | Mobile\* | `mobile_number` | top-level |
| Company | Company Name | `company_name` | `company: { name }` |
| Enquiry | Enquiry Type | `cf_enquiry_type` | `custom_field: { cf_enquiry_type }` |
| Enquiry | Product Enquiry | `cf_product_enquiry` | `custom_field: { cf_product_enquiry }` |
| Notes | Recent Note | `notes` | separate `POST /api/notes` after create (best-effort) |
| Notes | Notes | `cf_notes` | `custom_field: { cf_notes }` |

The lead's `medium` and `keyword` attributes are **not** offered here — they are written from the
captured campaign data (`utm_medium` / `utm_term`) instead of by hand. `apply_campaign_fields()` is
their only writer.

\* Freshsales requires **at least one** of Email or Mobile; otherwise `run()` throws a config error.

**Source** is not a mapped option — it is hardcoded to **"Web Form"** and resolved to its `lead_source_id`
at submit time (cached 12h).

## Mapping several sources to one Freshsales field

Sources are **not** mutually exclusive. Point Message *and* Product at **Recent Note** and both are
sent, joined **in panel order, one per line**:

```
I need a quote for 50 units
Garment Steamer
```

`get_mapped_value()` collects every row matching the `remote_id`, drops empty values, and joins with a newline. Whether that break survives is decided by the sanitiser the destination uses:

| Destination | Sanitiser | Result of a doubled-up mapping |
|-------------|-----------|--------------------------------|
| `notes` (Recent Note), `cf_*` (Notes) | `sanitize_textarea_field()` | line breaks kept — one value per line |
| everything else | `sanitize_text_field()` | break collapses to a space — "John" + "Smith" → `John Smith` |

That is one rule with the right outcome at both ends, so no per-field special-casing is needed.
An empty source contributes nothing and never suppresses the others.

## How the payload is built (`build_lead()`)

- **Plain top-level text fields** (`first_name`, `last_name`, `mobile_number`, `medium`, `keyword`) →
  `$lead[remote_id] = value`.
- **Email** → validated then `$lead['email']`.
- **Company Name** → `$lead['company'] = ['name' => value]`.
- **Custom fields** — any `remote_id` starting with `cf_` → collected into `$lead['custom_field']`.

## Adding fields

### A plain text lead field
1. Add an entry to `get_remote_fields()` with the Freshsales field name as `remote_id`, a label, `remote_type`, and a `group`.
2. Add the `remote_id` to the plain-text list in `Freshsales_Action::build_lead()`.

### A custom field
Add an entry with a `remote_id` prefixed `cf_` (the account's custom field name). No `run()` change is
needed — `build_lead()` routes every `cf_*` field into `custom_field` automatically.

### A source that is not a form field
Add an entry to `get_virtual_fields()` with a `__`-prefixed `local_id` and a label, then resolve it in
`Freshsales_Action::get_mapped_value()` alongside `FORM_NAME_SOURCE`. The editor picks it up
automatically — the list is injected as `window.CornerstoneFreshsalesData.virtualFields`.

### A dropdown / reference field (e.g. Campaign, Owner)
These need a Freshsales record **id**, not free text, so they are intentionally not offered as text.
To support one, resolve its name → id via a selector endpoint (as Source does with `get_lead_source_id()`)
and set the id on the lead before `create_lead()`.

## Notes: two destinations

- **Recent Note** (`notes`) writes an activity note via the Notes API; it appears as the lead's "Recent note".
- **Notes** (`cf_notes`) writes the custom "Notes" lead field under *Additional information*.
