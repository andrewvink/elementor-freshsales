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
   - **Field Mapping** — appears once a key is available. Each of **your form fields** is listed on the left; for each one, pick the **Freshsales field** it should fill from the dropdown on the right (or leave it **None**):

     | Freshsales option | Notes |
     |-------------------|-------|
     | First Name        | optional |
     | Last Name         | optional |
     | Email             | required* |
     | Mobile            | required* |
     | Company Name      | optional |
     | Medium            | optional — e.g. a UTM medium |
     | Keyword           | optional — e.g. a UTM keyword |
     | Recent Note       | optional — added to the lead as an activity note (shows under "Recent note") |
     | Notes             | optional — written to the lead's custom "Notes" field |

     \* Freshsales needs **at least one** of Email or Mobile. Point at least one form field at Email or Mobile.

     (Dropdown/reference fields such as **Source** and **Campaign** aren't listed here — they need a Freshsales record ID, not free text. Source is set automatically to "Web Form".)
4. **Update** the page. Submissions now create a lead.

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

## Uninstalling
Deleting the plugin from **Plugins** runs `uninstall.php`, which removes its options and cached data. Nothing is left behind. (Per-form mapping lives inside the page content and is removed with the form.)
