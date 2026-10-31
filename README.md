# Turnstile Connector for Breakdance

Cloudflare Turnstile protection for native Breakdance Form Builder forms, with server-side verification that fails closed.

Turnstile Connector for Breakdance is a free plugin from Stafford Technologies. It protects all native Breakdance Form Builder submissions, including logged-in visitors, by:

* Rendering a Cloudflare Turnstile widget on each Breakdance form
* Requiring a verification token before submit
* Verifying the token on the server with Cloudflare's siteverify API over HTTPS
* Checking success, the expected hostname (WordPress Home URL), and a form-specific action
* Failing closed on connection errors, invalid tokens, hostname/action mismatches, or replayed tokens
* Using an atomic local replay lock so each verified token is accepted only once

Breakdance must be installed and active. Cloudflare Turnstile credentials are required. This plugin is not affiliated with, endorsed by, or sponsored by Breakdance, Soflyy, Cloudflare, or Cloudflare Turnstile.

Plugin docs and support: https://staffordtechnologies.net/turnstile-connector-for-breakdance/

## License

GPLv2+ (GNU General Public License v2.0 or later). See https://www.gnu.org/licenses/gpl-2.0.html.
