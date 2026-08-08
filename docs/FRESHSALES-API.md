# Freshsales API usage

Classic Freshsales API — <https://developer.freshsales.io/api/>.

## Base URL & auth

- Base: `https://<domain>/api/` where `<domain>` is the account domain and must end in one of
  `freshsales.io`, `myfreshworks.com`, `freshworks.com` (enforced by `Freshsales_Handler::normalize_domain()`).
- Header: `Authorization: Token token=<API_KEY>`
- All requests use `wp_safe_remote_request()` with `redirection => 0`, `timeout => 20`, `sslverify => true`.

## Endpoints used

### Create a lead — `POST leads`
Request:
```json
{ "lead": {
  "first_name": "Jane", "last_name": "Doe",
  "email": "jane@example.com", "mobile_number": "555-0100",
  "company": { "name": "Acme" },
  "medium": "cpc", "keyword": "cleaning",
  "lead_source_id": 180663,
  "custom_field": { "cf_notes": "Please call me" }
} }
```
Response (2xx): `{ "lead": { "id": 123, ... } }` — the plugin reads `lead.id`.
Requirement: at least one of email / mobile.

### Lead sources — `GET selector/lead_sources`
`{ "lead_sources": [ { "id": 180663, "name": "Web Form" }, ... ] }` — used to resolve the hardcoded
"Web Form" source name to its id (cached 12h). Also used by `validate()` as a lightweight auth check.

### Note — `POST notes`
```json
{ "note": { "description": "…", "targetable_type": "Lead", "targetable_id": 123 } }
```
Used for the mapped "Recent Note". Best-effort — never blocks lead creation.

## Error handling

`request()` throws with the HTTP status as the exception code (transport failure → code `0`). `run()`
classifies: `0/408/429/5xx` are transient (swallowed, form still succeeds); other `4xx` are config/auth
errors (re-thrown, shown to admins).

### Campaigns — `GET selector/campaigns`
`{ "campaigns": [ { id, name }, ... ] }` — resolves a `utm_campaign` name to `campaign_id` (cached
12h, best-effort). Returns an empty list when the account has no campaigns defined, in which case the
lead's Campaign field is simply not set.

## Verified lead attributes

Confirmed against a live account by reading a real lead (`GET leads/filters` → `GET leads/view/{id}`),
because this API version has no lead-field-definition endpoint (`settings/lead_fields` and friends
all 404).

Writable attributes the plugin targets: `first_name`, `last_name`, `email`, `mobile_number`,
`company.name`, `medium`, `keyword`, `lead_source_id`, `campaign_id`, `custom_field`.

`medium` and `keyword` are genuine top-level lead attributes — they are what the captured
`utm_medium` / `utm_term` are written to. There is **no** native lead attribute for `utm_source`,
`utm_content` or the ad click IDs, which is why those go into a note instead.

Custom fields present on the reference account: `cf_notes`, `cf_enquiry_type`, `cf_product_enquiry`.
Other accounts will differ — add theirs to `get_remote_fields()` with the `cf_` prefix.

## Not used / intentionally omitted

- Dropdown/reference fields (`campaign_id`, `owner_id`, …) require ids, not free text, so they are not
  offered for free-text mapping.
- Read-only/system fields (`recent_note`, `created_at`, `lead_score`, …) are not writable.
