=== Resource Access Gate ===
Contributors: elig45
Tags: downloads, resources, email, shortcode, lead-generation
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Protect downloadable resources behind a simple email gate, then show and email the download link.

== Description ==

Resource Access Gate is a lightweight plugin for sharing downloadable resources through a simple email form.

Visitors enter a valid email address, the download link appears on the page, and the same link is sent by email. Site administrators can manage resources, review requests, and export collected data from the WordPress admin area.

= Key features =

* Add an email gate with the `[resource_access_gate id="resource-id"]` shortcode.
* Show the download link only after a valid email is submitted.
* Send the same download link by email with `wp_mail()`.
* Manage resources from a dedicated WordPress admin page.
* Store contacts and requests in dedicated database tables.
* Export request data as CSV.
* Keep resource URLs out of the initial page HTML.
* Avoid bundled third-party services, tracking, or front-end branding links.

= Privacy =

The plugin stores submitted email addresses, requested resource IDs, timestamps, email-send status, and hashed request metadata such as IP address and user agent. It does not send this data to an external service.

Site owners should document this collection in their privacy policy.

= Mail delivery =

Resource Access Gate uses the standard WordPress `wp_mail()` function. For reliable production delivery, configure WordPress with a suitable SMTP or transactional email provider.

== Installation ==

1. Upload the `resource-access-gate` folder to the `/wp-content/plugins/` directory.
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

= 1.0.0 =
* Initial release.
