=== Elementor Freshsales ===
Contributors: cornerstone
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
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

= 1.0.0 =
* Initial release: Freshsales form action, field mapping, global/per-form API key, "Web Form" lead
  source, best-effort notes, connection validator, and clean uninstall.
