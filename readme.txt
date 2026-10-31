=== Turnstile Connector for Breakdance ===
Contributors: staffordtechnologies
Donate link: https://staffordtechnologies.net/
Tags: turnstile, breakdance, captcha, cloudflare, forms
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cloudflare Turnstile protection for native Breakdance Form Builder forms, with server-side verification that fails closed.

== Description ==

Turnstile Connector for Breakdance is a free plugin from Stafford Technologies. It is not affiliated with, endorsed by, or sponsored by Breakdance, Soflyy, Cloudflare, or Cloudflare Turnstile. Breakdance (a commercial page builder) must be installed and active for this plugin to protect forms. Cloudflare Turnstile credentials are required.

It protects all native Breakdance Form Builder (custom) submissions, including logged-in visitors, by:

* Rendering a Cloudflare Turnstile widget on each Breakdance form
* Requiring a verification token before submit
* Verifying the token on the server with Cloudflare's siteverify API over HTTPS
* Checking success, the expected hostname (WordPress Home URL), and a form-specific action
* Failing closed on connection errors, invalid tokens, hostname/action mismatches, or replayed tokens
* Using an atomic local replay lock so each verified token is accepted only once

Optional: reuse keys already saved by Simple CAPTCHA with Cloudflare Turnstile, or store this plugin's own keys. `STBD_SITE_KEY` and `STBD_SECRET_KEY` constants in wp-config.php override the settings pair.

The browser receives only the public site key. The secret key is never output to the front end or shown in the settings form after it is saved.

Scope: Breakdance Form Builder only. Does not protect WordPress login, comments, WooCommerce, Breakdance Login/Register elements, or other form plugins.

Author: Stafford Technologies — https://staffordtechnologies.net/
Plugin docs and support: https://staffordtechnologies.net/turnstile-connector-for-breakdance/
Support email: helpdesk@staffordtechnologies.net

This plugin is free ($0). No license activation or site-count limit.

== External services ==

This plugin connects to Cloudflare Turnstile to challenge and verify form submissions.

* **When:** On pages that display a Breakdance Form Builder form while protection is active, the browser loads Cloudflare's Turnstile script from `https://challenges.cloudflare.com/turnstile/v0/api.js` and renders a widget. On form submit (and on the admin connection test), the WordPress server calls `https://challenges.cloudflare.com/turnstile/v0/siteverify` via `wp_remote_post`.
* **What is sent:** The public site key is used in the browser. The server sends the secret key, the one-time response token, and optionally the visitor IP (`remoteip`) to siteverify. Form field contents, email addresses, and other submission data are not sent to Cloudflare by this plugin.
* **Why:** To confirm a real visitor completed the Turnstile challenge before Breakdance runs submission actions.
* **Terms:** [Cloudflare Website Terms](https://www.cloudflare.com/website-terms/)
* **Privacy:** [Cloudflare Privacy Policy](https://www.cloudflare.com/privacypolicy/)

== Installation ==

1. Ensure Breakdance is installed and active.
2. Go to Plugins → Add New → Upload Plugin, or install from the WordPress.org plugin directory.
3. Upload `turnstile-connector-for-breakdance-1.1.0.zip` (or search for Turnstile Connector for Breakdance) and click Install Now, then Activate.
4. Open Settings → Turnstile Connector.
5. Use keys already saved in Simple CAPTCHA with Cloudflare Turnstile, or choose this plugin's own keys and enter the Cloudflare site key and secret key.
6. Add the site's exact hostname to that Cloudflare Turnstile widget. Staging hostnames must be added separately.
7. Enable protection and save. Use Test connection, then run the four security checks.
8. Purge page/CDN caches and check public forms, including popup/mobile forms.

== Frequently Asked Questions ==

= Does this require Breakdance? =

Yes. Breakdance must be installed and active. This plugin protects Breakdance Form Builder submissions and will not protect forms without it. It is not affiliated with Breakdance or Soflyy.

= Does this require Cloudflare Turnstile? =

Yes. You need a Cloudflare account and a Turnstile widget (site key + secret key). It is not affiliated with Cloudflare.

= Is this free? =

Yes. Turnstile Connector for Breakdance is free ($0).

= What if keys are missing? =

If either key is missing, the plugin does nothing: no widget and no server check, so forms submit normally. An admin notice points to Settings → Turnstile Connector.

= What happens when verification fails? =

Submissions are blocked (fail closed). There is no bypass for logged-in administrators.

= Where do I get support? =

Email Stafford Technologies helpdesk at helpdesk@staffordtechnologies.net.
Plugin docs and support page: https://staffordtechnologies.net/turnstile-connector-for-breakdance/
Company site: https://staffordtechnologies.net/

== Changelog ==

= 1.1.0 =
* Security hardening: sanitize posted tokens; never display any characters of the saved secret; send optional remoteip to siteverify; fail closed on siteverify errors; document External services for WordPress.org.
* WordPress.org directory preparation: renamed plugin to "Turnstile Connector for Breakdance" (slug `turnstile-connector-for-breakdance`) so the name does not begin with the Breakdance or Cloudflare trademarks.
* Updated text domain to match the new slug; Stafford branding (author, URIs, Docs/Support plugin_row_meta, icons from the Stafford brand mark).
* Settings page setup instructions, siteverify connection test, and inert behavior when keys are missing (no widget / no server check).
* Tested up to WordPress 7.1.

= 1.0.0 =
* Initial standalone connector with per-form widgets, server verification, support for dynamically inserted forms, responsive layout, replay prevention, retry handling, and authenticated diagnostics.

== Upgrade Notice ==

= 1.1.0 =
WordPress.org naming and slug update plus security hardening. Installs of the previous folder name `breakdance-turnstile-connector` must be swapped manually (deactivate/delete old, install this zip). Settings stored in `stbd_settings` are kept.
