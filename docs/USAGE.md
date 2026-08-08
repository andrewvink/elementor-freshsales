# Elementor Freshsales — Usage

Create a Freshsales CRM **lead** from an Elementor Pro form.

## Requirements
- WordPress 6.0+, PHP 7.4+
- **Elementor Pro** active (the Forms widget provides the action framework)
- A Freshsales account, its **domain**, and an **API key**

## 1. Connect your account
1. Go to **Elementor → Settings → Integrations**.
2. Find the **Freshsales** section and enter:
   - **Domain** — your account domain, e.g. `yourcompany.freshsales.io`
     (also accepts `*.myfreshworks.com` / `*.freshworks.com`).
   - **API Key** — from Freshsales: *Profile picture → Profile Settings → API Settings → Your API key*.
3. Click **Save Changes**, then **Validate Connection**. You should see “Connected to Freshsales successfully.”

> **Validate Connection tests what is typed in the fields, not what is stored.** If you validate
> before saving, it reports *“These credentials work — now click Save Changes to store them.”* in
> amber. Only the green *“Connected to Freshsales successfully.”* means the credentials the forms
> will actually use are in place.

## 2. Add the action to a form
1. Edit a page with an **Elementor Form** (or add one).
2. Select the Form widget → **Content → Actions After Submit** → add **Freshsales**.
3. Open the new **Freshsales** section:
   - **API Key** — leave on **Default** to use the global key, or choose **Custom** to override it for this form.
   - **Field Mapping** — appears once a key is available. Each of **your form fields** is listed on the left; for each one, pick the **Freshsales field** it should fill from the dropdown on the right (or leave it **None**).

     Above the form's fields there is a **Form Name** row, separated by a line. Map it to send the
     form's own name (the widget's *Form Name* setting) to Freshsales — handy for telling enquiries
     from different forms apart. It can feed more than one Freshsales field.

     The dropdown's options are:

     | Freshsales option | Notes |
     |-------------------|-------|
     | First Name        | optional |
     | Last Name         | optional |
     | Email             | required* |
     | Mobile            | required* |
     | Company Name      | optional |
     | Enquiry Type      | optional — the lead's custom "Enquiry Type" field |
     | Product Enquiry   | optional — the lead's custom "Product Enquiry" field |
     | Recent Note       | optional — added to the lead as an activity note (shows under "Recent note") |
     | Notes             | optional — written to the lead's custom "Notes" field |

     **Medium** and **Keyword** are not listed: they are filled automatically from the visitor's
     campaign data (below), so there is nothing to map by hand.

     You can point **more than one form field at the same Freshsales field** — the values are
     combined in the order the rows appear, one per line. Mapping both *Message* and *Product* to
     **Recent Note** writes both into the note rather than one replacing the other.

     \* Freshsales needs **at least one** of Email or Mobile. Point at least one form field at Email or Mobile.

     (Dropdown/reference fields such as **Source** and **Campaign** aren't listed here — they need a Freshsales record ID, not free text. Source is set automatically to "Web Form"; Campaign is matched from the visitor's `utm_campaign`.)
   - **Capture Campaign Data** — on by default. Sends the UTM tags and ad click IDs from the page
     the visitor first arrived on. Nothing to set up: no hidden fields, no mapping.
4. **Update** the page. Submissions now create a lead.

### Campaign tracking

With **Capture Campaign Data** on, the plugin remembers the campaign parameters from the URL a
visitor **first arrives on** and sends them when they eventually submit a form — even if they browse
several pages in between. That is the part hidden form fields normally get wrong: by the time
someone reaches your contact page, the `?utm_...` tags are long gone from the address bar.

Captured: `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, and the `gclid`,
`gbraid`, `wbraid`, `fbclid`, `msclkid` and `ttclid` ad click IDs, plus the landing page and
referring site.

Where it goes in Freshsales:

| Captured | Lands in |
|----------|----------|
| `utm_medium` | the lead's **Medium** field |
| `utm_term` | the lead's **Keyword** field |
| `utm_campaign` | the lead's **Campaign**, matched by name to a campaign in your account — see the note below |
| everything else | a note on the lead's timeline, headed "Campaign data captured from the visitor's first visit" |

Notes on behaviour:

- **First touch wins.** The campaign that originally brought the visitor is kept; a later visit
  through a different link does not overwrite it. Remembered for 180 days.
- **Your mapping always wins.** If you have mapped a form field to Medium or Keyword, capture leaves
  it alone and only fills what you left empty.
- **Campaign must already exist** in Freshsales for the lead's Campaign field to be set — Freshsales
  needs a real campaign record, not free text. Create campaigns whose names match your `utm_campaign`
  values (Freshsales → Campaigns). Until then the Campaign field stays empty and the name is recorded
  in the campaign note instead, so nothing is lost.
- **Lead Source stays "Web Form."** `utm_source` is recorded in the note rather than replacing it,
  so your existing source reporting keeps working.
- Nothing in the URL means nothing is sent — no blank fields and no empty notes.
- It relies on a first-party cookie named **`csfs_campaign`**, set for **180 days**, holding only the
  captured parameters plus the landing page and referring site (query strings stripped from both, so
  personal data and session tokens in URLs are never copied into the CRM). It is only ever written on
  a visit that actually carries a tracked parameter — organic and direct visitors get no cookie at
  all. A visitor who blocks cookies is simply not attributed; the form still works normally.
- The script is not loaded until a Freshsales **Domain** is saved under Integrations, so an
  unconfigured install never sets a cookie.
- To suppress capture site-wide (for example from a consent-management plugin), return false from the
  `cornerstone_freshsales_capture_campaign` filter.

The **Lead Source** is always set to **“Web Form.”** (If that source doesn't exist in your account, the lead is still created without a source.)

### How errors behave
- **Temporary Freshsales problems** (network blip, rate-limit, server 5xx) **do not break the form** — the visitor's submission still succeeds; only that one lead is skipped.
- **Configuration problems** (missing/invalid API key or domain, a rejected request, or no Email/Mobile mapped) **fail the submission**: the visitor sees a generic error, and logged-in admins see the specific reason appended under the form so they can fix it.

## Troubleshooting
- **Submissions say “missing an API key” even though the key is on the settings screen** — the key
  is typed into the field but not stored. Reload **Elementor → Settings → Integrations** without
  typing anything: if the Domain and API Key boxes come back empty, nothing was ever saved. Enter
  them again and click **Save Changes** (the browser URL should come back with `settings-updated=true`),
  *then* **Validate Connection** — it must go green.
  The same symptom shows up in the editor: the Freshsales section displays orange “Set your
  Freshsales API Key / Domain in the Integrations Settings” alerts whenever the stored values are empty.
- **“Validate Connection” fails** — re-check the domain (`https://` and a trailing slash are tolerated) and the API key. The domain must be a Freshsales/Freshworks host.
- **Submission fails with an error** — log in as an admin and submit again; the specific Freshsales reason is shown under the form (e.g. missing key/domain, or no Email/Mobile mapped). Fix it under Elementor → Settings → Integrations or in the form's field mapping.
- **Form succeeds but a lead is missing** — this happens when Freshsales was briefly unreachable (the form is intentionally not broken for visitors). Use **Validate Connection** to confirm connectivity.
- **Notes not attached** — notes are best-effort and never block lead creation; verify the Notes field is mapped.

## Privacy

Campaign capture stores the `csfs_campaign` first-party cookie described above and sends its
contents to your Freshsales account with the lead. Nothing is sent to any third party, and no cookie
is written for visitors who arrive without campaign parameters. If your privacy policy lists cookies,
add `csfs_campaign` (purpose: marketing attribution; lifetime: 180 days). Turn it off per form with
the **Capture Campaign Data** switch, or site-wide with the
`cornerstone_freshsales_capture_campaign` filter.

## Updating
Install the new zip with **Plugins → Add New → Upload Plugin**, and choose **“Replace current with
uploaded”** when WordPress offers it. That path never runs `uninstall.php`, so the saved domain and
API key survive the update and the plugin stays active.

**Do not deactivate and delete the old copy first** — deleting a plugin runs `uninstall.php`, which
removes the saved Freshsales credentials, and the forms will then fail with “missing an API key”
until you enter and save them again. Field mappings are unaffected either way: they live inside the
page content, not in the options table.

## Uninstalling
Deleting the plugin from **Plugins** runs `uninstall.php`, which removes its options and cached data. Nothing is left behind. (Per-form mapping lives inside the page content and is removed with the form.)
