CUSTOM DOWNLOAD MANAGER  (v1.4.1)

QUICK START
1. Plugins > Add New > Upload Plugin, upload the ZIP, activate.
2. Download Manager > Resources: add a resource (name, unique ID, download URL; optionally assign a Snapshot).
3. In the page or Snapshot, add a Shortcode block with:  [custom_download_form]
   (or pick a resource explicitly: [custom_download_form resource="my-id"])
4. Download Manager > Webhook Settings: paste your webhook URL, save, then send a test.

SHORTCODES
[custom_download_form]
[custom_download_form resource="my-id" title="Get the guide" description="..." button="Get the PDF" class="my-form"]
[custom_download_button resource="my-id" text="Download" class="my-button"]   (opens the form in a popup)

CUSTOMIZING THE FORM  (Download Manager > Form Settings)
- Text & messages: title, description, button text, success/error messages, Etsy offer text.
- Fields: show/hide and require Name, Phone, Company, Message and a consent checkbox. Email is always required.
  The extra values are saved in Form Submissions, included in the CSV export and sent in the webhook.
- Design: accent/background/text/border colors, corner radius, spacing, width, text size, shadow,
  alignment, solid or outline button. Presets: Modern, Minimal, Dark, Soft, Bold.
- Live preview updates while you type.
- Custom CSS: any selector containing a plugin class (.cdm-...) is allowed, plus @media and @keyframes.
  Example:
    .cdm-inline { border-width: 2px; }
    .cdm-submit { background: linear-gradient(90deg,#7c3aed,#ec4899); }
    @media (max-width: 600px) { .cdm-inline { margin: 0; } }

Useful classes: .cdm-inline .cdm-form .cdm-title .cdm-description .cdm-field .cdm-field-name .cdm-field-email
.cdm-field-phone .cdm-field-company .cdm-field-message .cdm-field-consent .cdm-required .cdm-submit
.cdm-message .cdm-success .cdm-error .cdm-limit-offer .cdm-etsy-button .cdm-open-popup .cdm-modal-panel

WEBHOOK  (Download Manager > Webhook Settings)
No email is sent. Each successful submission is POSTed as JSON to your webhook URL (Zapier, Make, n8n, ...).
Your automation decides what happens next (for example emailing the link) - the payload includes resource.download_url.
Payload: event, request_id, submitted_at, site_url, resource {id, name, download_url, etsy_url, snapshot_id},
name, email, phone, company, message, consent, ip_address.
Optional secret: requests carry X-CDM-Signature = "sha256=" + HMAC-SHA256(raw body, secret).
The endpoint must answer 2xx. Otherwise the submission is marked Failed (with the reason) and the visitor sees
the "Submission failed message" from Form Settings. Use "Send Test Webhook" to check the connection.

FORM SUBMISSIONS  (Download Manager > Form Submissions, formerly "Download Logs")
Search (name, email, phone, company, message, IP, resource), filter by status / resource / date range,
sort by date, name, email, resource or status, choose 10/25/50/100 rows per page, and page through results.
Export CSV respects the current search and filters. The Overview page shows the 10 most recent submissions.

SNAPSHOTS
The "Associated Snapshot" field is a searchable dropdown that lists only your Snapshot custom post type.
The plugin looks for the post type slug "snapshot" (then "snapshots", then any type labelled Snapshot).
If yours differs, add:  add_filter('cdm_snapshot_post_type', function () { return 'your_slug'; });

SENDING PROGRESS BAR  (Download Manager > Form Settings > 4. Sending progress bar)
When the visitor clicks the button, a progress bar with your text (e.g. "Sending your download link...") is shown. The data is sent to
the webhook only when the bar finishes. The form is validated first, so nobody waits on an incomplete form.
If the visitor is already over the limit (or outside the allowed hours), the limit message is shown immediately and no progress bar is shown.
Editable: on/off, duration (seconds), text ({seconds} and {percent} allowed), bar color, bar height, and its own custom CSS
(.cdm-progress .cdm-progress-text .cdm-progress-bar .cdm-progress-fill). "Play progress demo" in the live preview plays the bar.

LIMITS  (Download Manager > Restrictions)
- Limit window (hours): 0 = per calendar day (default). Set e.g. 6 with limit 2 = max 2 downloads per rolling 6 hours.
- Allowed hours: only accept submissions between two times (site timezone, overnight ranges work, exempt IPs ignore it).
Default: 2 successful downloads per day per email and per IP. Configurable. When the limit is reached the form
shows your message, and an Etsy button if the resource has an Etsy listing URL.

NOTES
- The download URL is sent in the webhook payload. This plugin does not protect private files or create expiring links.
- Submissions do not use a WordPress nonce, so the form keeps working on pages cached by caching plugins.
  Spam is limited by a honeypot field, daily limits and a per-IP burst limit.
- Optional: Restrictions > Uninstall removes all data when the plugin is deleted.

CHANGELOG 1.4.1
- Changed: visitors over the limit (or outside allowed hours) see the message at once, with no progress bar.

CHANGELOG 1.4.0
- New: sending progress bar after the click; data goes to the webhook when it finishes (text, duration, color, height, custom CSS).
- New: limit window in hours (rolling) and allowed hours of the day, under Restrictions.
- Removed: the before-submit timer and the after-submit cooldown from 1.3.0.

CHANGELOG 1.2.0
- Changed: email delivery removed; submissions are sent to a webhook (Webhook Settings, test button, optional HMAC secret).
- New: Form Submissions page (was Download Logs) with pagination, search, filters, sorting, rows-per-page, filtered CSV export.
- New: Overview shows recent submissions; "Failed Email Deliveries" is now "Failed Webhook Deliveries".
- New: Associated Snapshot is a searchable select that lists only the Snapshot post type.
- Note: existing logs keep working; the old email status column now stores the webhook status.

CHANGELOG 1.1.0
- Fixed: saving Form/Email settings no longer turns off the email/IP restriction checkboxes.
- Fixed: form works in widgets, page builders and cached pages (assets load from the shortcode; no nonce).
- Fixed: custom CSS was stripped unless it used exact selectors; now flexible and still sanitized.
- Fixed: database table definitions (dbDelta format) and daily cleanup cron on deactivate.
- Fixed: settings "saved" notice and test-email result/error notice now shown.
- Fixed: CSV export protected against spreadsheet formula injection.
- New: field builder, design controls, presets, live preview, shortcode overrides, extra fields in logs,
  email logo/button color, real mail error in logs, accessible popup.
