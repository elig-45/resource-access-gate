=== Resource Access Gate ===
Contributors: elig45
Tags: downloads, resources, email, shortcode, lead-generation
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Free forever and free software: unlimited email-gated downloads with no premium tier or paid unlocks.

== Description ==

Resource Access Gate is a free forever and free-software plugin for sharing downloadable resources through a simple email form.

Visitors enter a valid email address, the download link appears on the page, and the same link is sent by email. Site administrators can manage resources, review requests, and export collected data from the WordPress admin area.

= Free means free =

Resource Access Gate is not a freemium plugin. It has no premium tier, paid unlocks, artificial resource limits, submission caps, vendor account requirement, bundled tracking, or front-end branding links.

The only practical limits are your WordPress hosting, database, and email delivery setup.

= Key features =

* Add an email gate with the `[resource_access_gate id="resource-id"]` shortcode.
* Optionally open the email gate in an accessible modal with `mode="modal"` and `modal_id`.
* Show the download link only after a valid email is submitted.
* Send the same download link by email with `wp_mail()`.
* Send a responsive branded HTML email with a plain-text fallback.
* Localize front-end messages and emails in French and English.
* Manage resources from a dedicated WordPress admin page.
* Store contacts and requests in dedicated database tables.
* Display a configurable privacy notice and automatically purge expired data.
* Limit repeated requests and reject automated honeypot submissions.
* Export request data as CSV.
* Keep resource URLs out of the initial page HTML.
* Use a free-software plugin licensed under GPLv3 or later.

= Privacy =

The plugin stores submitted email addresses, requested resource IDs, timestamps, email-send status, and hashed request metadata such as IP address and user agent. It does not send this data to an external service.

Site owners should document this collection in their privacy policy.

= Mail delivery =

Resource Access Gate uses the standard WordPress `wp_mail()` function. For reliable production delivery, configure WordPress with a suitable SMTP or transactional email provider.

== Installation ==

1. Upload the `email-download-gate` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Open `Resources` in the WordPress admin menu.
4. Configure email settings and add at least one resource.
5. Add the shortcode to a post, page, or template: `[resource_access_gate id="resource-id"]`.

== Frequently Asked Questions ==

= Does this plugin require an external email marketing service? =

No. It stores requests in WordPress and sends emails with `wp_mail()`.

= Does it hide the resource URL before submission? =

The initial page HTML contains only the configured resource ID. The download URL is returned after a valid email submission.

= Can I export collected data? =

Yes. The admin page includes a CSV export for resource requests.

= Where are emails stored? =

Emails and request logs are stored in dedicated WordPress database tables created by the plugin.

= Can I use it for PDFs, templates, decks, and other files? =

Yes. Any downloadable file URL can be configured as a resource.

== Changelog ==

= 1.6.1 =
* Matched the translation text domain and install directory to the assigned WordPress.org slug, `email-download-gate`.
* Excluded the repository-only `.gitattributes` file from installable release archives.

= 1.6.0 =
* Replaced short internal identifiers with the distinctive `resoacga_` prefix.
* Moved administration CSS and JavaScript into properly enqueued asset files.
* Updated database table creation to use `dbDelta()`.
* Improved sanitization for submitted and server-provided request values.
* Updated options, AJAX actions, nonces, scheduled hooks, transients, and database tables to use the new prefix.
* Added a one-time, non-destructive migration for settings and request history created by earlier versions.

= 1.5.0 =
* Relicensed the plugin under GNU GPL version 3 or later.
* Added explicit copyright and license notices to all nontrivial source files.
* Documented the all-rights-reserved status of examples visible in screenshots.
* Excluded documentation screenshots from installable release archives.

= 1.4.0 =
* Added localized French and English front-end messages, resource titles, AJAX responses, and emails.
* Synchronized the plugin metadata and documentation with version 1.4.0.

= 1.3.0 =
* Added configurable data-retention information and daily cleanup.
* Added rate limiting and a honeypot field to reduce automated abuse.
* Refined the branded email typography and modal privacy copy.

= 1.2.0 =
* Replaced the plain-text-only message with a responsive branded HTML email.
* Added automatic Bridge logo reuse and a plain-text alternative for compatibility.

= 1.1.0 =
* Added an accessible modal display mode with external trigger support.
* Added focus trapping, Escape-key closing, focus restoration, and responsive modal styles.

= 1.0.0 =
* Initial release.

== Copyright and License ==

Copyright (C) 2026 Eli Gold.

Resource Access Gate source code and original documentation are free software distributed under the GNU General Public License, version 3 or (at your option) any later version.

All examples visible in documentation screenshots, including names, logos, trademarks, publication covers, report content, and site content, remain all rights reserved by their respective rights holders and are used solely for documentation and illustrative purposes. The plugin license grants no rights to this underlying example content.
