# Resource Access Gate

Free forever, open source, and built for unlimited email-gated resource downloads in WordPress.

Resource Access Gate is a lightweight plugin for publishers, consultants, agencies, creators, and teams who want to share PDFs, reports, templates, white papers, decks, or private files without adding a heavy marketing platform.

Visitors enter a valid email address, the download link appears instantly, and the same link is sent by email. Requests are stored in WordPress and can be reviewed or exported from the admin area.

## Free Means Free

This is not a freemium plugin.

- No premium tier
- No paid unlocks
- No artificial resource limits
- No submission caps
- No vendor account required
- No bundled tracking or front-end branding links
- Free and open source under GPL-2.0-or-later

The only practical limits are your WordPress hosting, database, and email delivery setup.

## Why Use It

- No third-party service required
- Works with a simple shortcode
- Keeps resource URLs out of the initial page HTML
- Sends download links with WordPress mail
- Stores contacts and requests in dedicated database tables
- Provides CSV export for follow-up workflows
- Ships as a small, understandable WordPress plugin

## Quick Start

1. Copy `resource-access-gate` into `wp-content/plugins/`.
2. Activate **Resource Access Gate** in WordPress.
3. Open **Resources** in the admin menu.
4. Add a resource ID, title, and file URL.
5. Place the shortcode where the form should appear:

```text
[resource_access_gate id="resource-id"]
```

## How It Works

The public page only receives the resource ID. After a valid email is submitted, WordPress validates the request, logs the contact and request, sends the email, and returns the download URL to the browser.

This keeps the visitor flow simple while avoiding resource links being exposed in the initial HTML.

## Admin Features

- Configure sender name and sender email
- Customize email subject and front-end messages
- Add, edit, enable, and disable resources
- View recent download requests
- Track whether the email was sent successfully
- Export all requests as CSV

## Privacy

Resource Access Gate stores submitted email addresses, requested resource IDs, timestamps, email-send status, and hashed request metadata such as IP address and user agent. It does not send this data to an external service.

Sites using this plugin should mention the collection of email addresses in their own privacy policy.

## Mail Delivery

The plugin uses `wp_mail()`. For reliable delivery in production, configure WordPress with a proper SMTP or transactional email setup.

## WordPress.org

The repository includes a `readme.txt` formatted for the WordPress.org Plugin Directory.

For WordPress.org submissions, the `Contributors` field uses the WordPress.org username. GitHub links use the GitHub username.

## Requirements

- WordPress 5.8 or later
- PHP 7.4 or later

Tested locally up to WordPress 7.0.

## License

GPL-2.0-or-later. Free for everyone to use, modify, and redistribute under the license terms.

## Author

Eli Gold  
GitHub: https://github.com/elig-45
