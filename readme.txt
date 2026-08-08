=== Elementor Freshsales ===
Contributors: cornerstone
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create a Freshsales CRM lead from an Elementor Pro form submission, with field mapping.

== Description ==

Adds a "Freshsales" action to Elementor Pro forms. On each submission it creates a lead in your
Freshsales (Freshworks CRM) account via the Freshsales API, mapping form fields to lead fields:
First Name, Last Name, Email, Mobile, Company Name and Notes. The lead source is set to "Web Form".

Requires Elementor Pro (Forms widget). Configure your Freshsales domain and API key under
Elementor → Settings → Integrations, then add the Freshsales action to any form and map the fields.

No external libraries. Vanilla JS/CSS. Built with strict security: the API domain is restricted to
Freshworks hosts, all requests use WordPress's safe HTTP client with redirects disabled, the admin
"Validate" endpoint enforces a nonce and capability check, and all output is escaped.

== Changelog ==

= 1.3.0 =
* Automatic campaign capture. The UTM tags and ad click IDs on the page a visitor first arrives on
  are remembered for the rest of their visit and sent with the lead — no hidden fields and no
  mapping to set up. Medium and Keyword are filled, the campaign is matched to a Freshsales
  campaign, and the full attribution is written to the lead's timeline as its own note.
* First-touch attribution: the campaign that won the visitor is kept even if they browse the site
  or return later through a different link.
* Anything you have mapped by hand always wins — capture only fills fields the mapping left empty.
* New "Capture Campaign Data" switch on the Freshsales section, on by default.
* Medium and Keyword are no longer offered for manual mapping — they are filled from the captured
  campaign data instead. Added the account's "Enquiry Type" and "Product Enquiry" custom fields.
* Campaign and lead-source lookups now cache the whole list once per account instead of one entry
  per name, so a campaign created in Freshsales is picked up straight away rather than after the
  12-hour cache expires.
* The capture script is not loaded until a Freshsales domain is saved, and can be switched off
  site-wide with the `cornerstone_freshsales_capture_campaign` filter.
* Added a dependency-free test suite (`php tests/run-tests.php`) — 47 tests.

= 1.2.0 =
* Mapping several form fields to the same Freshsales field now keeps all of them instead of only
  the first. Values are joined in panel order, one per line — so mapping Message and Product both
  to "Recent Note" writes both. Previously the second was silently dropped, and an empty first
  field discarded the rest too.
* The "Notes" custom field keeps its line breaks, so a note built from several fields stays readable.

= 1.1.0 =
* Field Mapping can now map the form's own **Form Name** to any Freshsales field. It appears as the
  first row, above the form's fields.
* Renamed the dropdown's "Contact" group to "Name & contact", and stated on the mapping control that
  each submission creates a lead — "Contact" read as though it created a Freshsales contact.

= 1.0.1 =
* "Validate Connection" now says whether the credentials it just tested are the ones actually
  saved. Testing values that have been typed but not yet saved reports "click Save Changes to
  store them" instead of a green "Connected successfully", which previously made an unsaved key
  look configured while forms still failed with "missing an API key".

= 1.0.0 =
* Initial release: Freshsales form action, field mapping, global/per-form API key, "Web Form" lead
  source, best-effort notes, connection validator, and clean uninstall.
