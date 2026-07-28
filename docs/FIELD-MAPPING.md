# Field mapping

## The control

Mapping is **form-first**: each of the form's own fields is a row on the left, with a Freshsales-field
dropdown on the right. The dropdown options are grouped with `<optgroup>` into sections.

Stored data is a repeater of `{ local_id, remote_id }` items (form field id → Freshsales field id).

## Fields offered

Defined once in `get_remote_fields()` (`elementor-freshsales.php`) and injected into the editor:

| Group | Label | `remote_id` | Placement in the lead payload |
|-------|-------|-------------|-------------------------------|
| Contact | First Name | `first_name` | top-level |
| Contact | Last Name | `last_name` | top-level |
| Contact | Email\* | `email` | top-level (validated with `is_email`) |
| Contact | Mobile\* | `mobile_number` | top-level |
| Company | Company Name | `company_name` | `company: { name }` |
| Source information | Medium | `medium` | top-level |
| Source information | Keyword | `keyword` | top-level |
| Notes | Recent Note | `notes` | separate `POST /api/notes` after create (best-effort) |
| Notes | Notes | `cf_notes` | `custom_field: { cf_notes }` |

\* Freshsales requires **at least one** of Email or Mobile; otherwise `run()` throws a config error.

**Source** is not a mapped option — it is hardcoded to **"Web Form"** and resolved to its `lead_source_id`
at submit time (cached 12h).

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

### A dropdown / reference field (e.g. Campaign, Owner)
These need a Freshsales record **id**, not free text, so they are intentionally not offered as text.
To support one, resolve its name → id via a selector endpoint (as Source does with `get_lead_source_id()`)
and set the id on the lead before `create_lead()`.

## Notes: two destinations

- **Recent Note** (`notes`) writes an activity note via the Notes API; it appears as the lead's "Recent note".
- **Notes** (`cf_notes`) writes the custom "Notes" lead field under *Additional information*.
