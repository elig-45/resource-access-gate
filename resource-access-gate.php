<?php
/**
 * Plugin Name: Resource Access Gate
 * Description: Free forever and free-software plugin for unlimited email-gated resource downloads, with no premium tier or paid unlocks.
 * Version: 1.6.1
 * Requires at least: 5.8
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * Author: Eli Gold
 * Author URI: https://github.com/elig-45
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: email-download-gate
 */

/**
 * Copyright (C) 2026 Eli Gold
 *
 * This file is part of Resource Access Gate.
 *
 * Resource Access Gate is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 3 of the License, or (at your option)
 * any later version.
 *
 * Resource Access Gate is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General
 * Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * Resource Access Gate. If not, see <https://www.gnu.org/licenses/>.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

final class Resource_Access_Gate {
	const VERSION = '1.6.1';
	const OPTION_SETTINGS = 'resoacga_settings';
	const OPTION_RESOURCES = 'resoacga_resources';
	const OPTION_SCHEMA = 'resoacga_schema_version';
	const OPTION_LEGACY_MIGRATION = 'resoacga_legacy_migration_complete';
	const AJAX_ACTION = 'resoacga_resource_access';
	const NONCE_ACTION = 'resoacga_resource_access';
	const CRON_HOOK = 'resoacga_cleanup_expired_data';
	const ADMIN_SAVE_ACTION = 'resoacga_save_settings';
	const ADMIN_EXPORT_ACTION = 'resoacga_export_csv';
	const MENU_SLUG = 'resource-access-gate';
	const RATE_LIMIT_MAX = 5;
	const RATE_LIMIT_WINDOW = 15 * MINUTE_IN_SECONDS;
	const LEGACY_OPTION_SETTINGS = 'rag_settings';
	const LEGACY_OPTION_RESOURCES = 'rag_resources';
	const LEGACY_CRON_HOOK = 'rag_cleanup_expired_data';
	const LEGACY_CONTACTS_TABLE = 'rag_resource_contacts';
	const LEGACY_REQUESTS_TABLE = 'rag_resource_requests';

	public static function init() {
		add_action('init', array(__CLASS__, 'maybe_install'));
		add_shortcode('resource_access_gate', array(__CLASS__, 'resource_gate_shortcode'));
		add_action('wp_ajax_' . self::AJAX_ACTION, array(__CLASS__, 'handle_resource_access'));
		add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, array(__CLASS__, 'handle_resource_access'));
		add_action('admin_menu', array(__CLASS__, 'register_admin_page'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
		add_action('admin_post_' . self::ADMIN_SAVE_ACTION, array(__CLASS__, 'handle_save_settings'));
		add_action('admin_post_' . self::ADMIN_EXPORT_ACTION, array(__CLASS__, 'handle_export_csv'));
		add_action(self::CRON_HOOK, array(__CLASS__, 'cleanup_expired_data'));
	}

	public static function activate() {
		self::create_tables();
		self::migrate_legacy_storage();
		self::ensure_default_options();
		self::schedule_cleanup();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook(self::CRON_HOOK);
	}

	public static function maybe_install() {
		if (get_option(self::OPTION_SCHEMA) !== self::VERSION) {
			self::create_tables();
		}

		self::migrate_legacy_storage();
		self::ensure_default_options();
		self::schedule_cleanup();
	}

	private static function plugin_url($path = '') {
		return plugin_dir_url(__FILE__) . ltrim($path, '/');
	}

	private static function current_language($requested_language = '') {
		$requested_language = sanitize_key((string) $requested_language);
		if (in_array($requested_language, array('fr', 'en'), true)) {
			return $requested_language;
		}

		if (function_exists('pll_current_language')) {
			$polylang_language = sanitize_key((string) pll_current_language('slug'));
			if (in_array($polylang_language, array('fr', 'en'), true)) {
				return $polylang_language;
			}
		}

		$locale = function_exists('determine_locale') ? determine_locale() : get_locale();

		return 0 === strpos(strtolower((string) $locale), 'en') ? 'en' : 'fr';
	}

	private static function translated_resource($resource, $language) {
		if ('en' !== $language || !is_array($resource)) {
			return $resource;
		}

		$titles = array(
			'les-mutations-du-commerce-et-de-la-consommation-en-12-videos' => 'Trade and Consumer Shifts in 12 Videos',
			'retail-les-nouvelles-strategies-gagnantes' => 'Retail: New Winning Strategies',
			'zara-levolution-du-parc-de-magasins-et-les-impacts-financiers' => 'Zara: Store Footprint Evolution and Financial Impacts',
			'cap-vers-la-resilience-augmentee' => 'Towards Augmented Resilience',
		);

		if (isset($titles[$resource['id']])) {
			$resource['title'] = $titles[$resource['id']];
		}

		return $resource;
	}

	private static function translated_settings($settings, $language) {
		if ('en' !== $language) {
			return $settings;
		}

		return array_merge(
			$settings,
			array(
				'subject' => '[{site_name}] Your download link',
				'button_label' => 'Receive the link',
				'success_message' => 'Your download link is ready and has also been sent to you by email.',
				'failure_mail_message' => 'Your download link is ready. The automatic email could not be sent at this time.',
				'helper_text' => 'Enter your email address to receive the download link.',
				'privacy_notice' => 'Your address is used only to send you the document and is retained for 12 months.',
			)
		);
	}

	private static function default_settings() {
		$host = wp_parse_url(home_url(), PHP_URL_HOST);
		$default_from = $host ? 'noreply@' . $host : get_option('admin_email');

		return array(
			'from_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
			'from_email' => $default_from,
			'subject' => '[{site_name}] Votre lien de téléchargement',
			'button_label' => 'Recevoir le lien',
			'success_message' => 'Votre lien de téléchargement est prêt. Il vous a aussi été envoyé par email.',
			'failure_mail_message' => 'Votre lien de téléchargement est prêt. L’email automatique n’a pas pu être envoyé pour le moment.',
			'helper_text' => 'Indiquez votre adresse email pour recevoir le lien de téléchargement.',
			'privacy_notice' => 'Votre adresse est utilisée uniquement pour vous envoyer le document et conservée pendant 12 mois.',
			'retention_days' => 365,
		);
	}

	private static function default_resources() {
		return array();
	}

	/**
	 * Copies data created before 1.6.0 to the newly prefixed storage.
	 *
	 * The legacy options and tables are deliberately retained so an administrator
	 * can roll back to 1.5.x without losing configuration or request history.
	 */
	private static function migrate_legacy_storage() {
		if (get_option(self::OPTION_LEGACY_MIGRATION)) {
			return;
		}

		$current_settings = get_option(self::OPTION_SETTINGS, null);
		$legacy_settings = get_option(self::LEGACY_OPTION_SETTINGS, null);
		if (!is_array($current_settings) && is_array($legacy_settings)) {
			update_option(
				self::OPTION_SETTINGS,
				self::sanitize_settings($legacy_settings),
				false
			);
		}

		$current_resources = get_option(self::OPTION_RESOURCES, null);
		$legacy_resources = get_option(self::LEGACY_OPTION_RESOURCES, null);
		if (!is_array($current_resources) && is_array($legacy_resources)) {
			update_option(
				self::OPTION_RESOURCES,
				self::sanitize_resources_for_save($legacy_resources),
				false
			);
		}

		if (!self::migrate_legacy_tables()) {
			return;
		}

		wp_clear_scheduled_hook(self::LEGACY_CRON_HOOK);
		update_option(self::OPTION_LEGACY_MIGRATION, '1', false);
	}

	private static function ensure_default_options() {
		if (!is_array(get_option(self::OPTION_SETTINGS))) {
			add_option(self::OPTION_SETTINGS, self::default_settings(), '', false);
		}

		if (!is_array(get_option(self::OPTION_RESOURCES))) {
			add_option(self::OPTION_RESOURCES, self::default_resources(), '', false);
		}
	}

	private static function settings() {
		return wp_parse_args((array) get_option(self::OPTION_SETTINGS, array()), self::default_settings());
	}

	private static function resources() {
		$saved = get_option(self::OPTION_RESOURCES, array());
		$resources = is_array($saved) && !empty($saved) ? $saved : self::default_resources();
		$normalized = array();

		foreach ($resources as $resource) {
			if (!is_array($resource)) {
				continue;
			}

			$id = sanitize_title($resource['id'] ?? '');
			$title = sanitize_text_field($resource['title'] ?? '');
			$url = esc_url_raw($resource['url'] ?? '');

			if ('' === $id || '' === $title || '' === $url) {
				continue;
			}

			$normalized[$id] = array(
				'id' => $id,
				'title' => $title,
				'url' => $url,
				'enabled' => empty($resource['enabled']) ? 0 : 1,
			);
		}

		return $normalized;
	}

	private static function get_resource($resource_id) {
		$resources = self::resources();
		$resource_id = sanitize_title((string) $resource_id);

		if (!isset($resources[$resource_id]) || empty($resources[$resource_id]['enabled'])) {
			return null;
		}

		return $resources[$resource_id];
	}

	private static function table_names() {
		global $wpdb;

		return array(
			'contacts' => $wpdb->prefix . 'resoacga_resource_contacts',
			'requests' => $wpdb->prefix . 'resoacga_resource_requests',
		);
	}

	private static function legacy_table_names() {
		global $wpdb;

		return array(
			'contacts' => $wpdb->prefix . self::LEGACY_CONTACTS_TABLE,
			'requests' => $wpdb->prefix . self::LEGACY_REQUESTS_TABLE,
		);
	}

	private static function database_table_exists($table_name) {
		global $wpdb;

		$matched_table = $wpdb->get_var(
			$wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))
		);

		return $table_name === $matched_table;
	}

	private static function migrate_legacy_tables() {
		global $wpdb;

		$tables = self::table_names();
		$legacy_tables = self::legacy_table_names();
		$migration_succeeded = true;

		if (self::database_table_exists($legacy_tables['contacts'])) {
			// Table identifiers are assembled exclusively from the trusted WordPress prefix and class constants.
			$contacts_result = $wpdb->query(
				"INSERT IGNORE INTO {$tables['contacts']}
					(email, first_resource_id, last_resource_id, request_count, created_at, updated_at, ip_hash, user_agent_hash)
				SELECT email, first_resource_id, last_resource_id, request_count, created_at, updated_at, ip_hash, user_agent_hash
				FROM {$legacy_tables['contacts']}"
			);
			$migration_succeeded = false !== $contacts_result;
		}

		if (self::database_table_exists($legacy_tables['requests'])) {
			// Table identifiers are assembled exclusively from the trusted WordPress prefix and class constants.
			$requests_result = $wpdb->query(
				"INSERT INTO {$tables['requests']}
					(email, resource_id, resource_title, requested_at, mail_sent, ip_hash, user_agent_hash)
				SELECT email, resource_id, resource_title, requested_at, mail_sent, ip_hash, user_agent_hash
				FROM {$legacy_tables['requests']}"
			);
			$migration_succeeded = $migration_succeeded && false !== $requests_result;
		}

		return $migration_succeeded;
	}

	private static function create_tables() {
		global $wpdb;

		$tables = self::table_names();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$contacts_sql = "CREATE TABLE {$tables['contacts']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			first_resource_id varchar(190) DEFAULT '',
			last_resource_id varchar(190) DEFAULT '',
			request_count int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			ip_hash varchar(64) DEFAULT '',
			user_agent_hash varchar(64) DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY updated_at (updated_at)
		) $charset_collate;";

		$requests_sql = "CREATE TABLE {$tables['requests']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			resource_id varchar(190) NOT NULL,
			resource_title text NOT NULL,
			requested_at datetime NOT NULL,
			mail_sent tinyint(1) NOT NULL DEFAULT 0,
			ip_hash varchar(64) DEFAULT '',
			user_agent_hash varchar(64) DEFAULT '',
			PRIMARY KEY  (id),
			KEY email (email),
			KEY resource_id (resource_id),
			KEY requested_at (requested_at)
		) $charset_collate;";

		dbDelta($contacts_sql);
		dbDelta($requests_sql);

		update_option(self::OPTION_SCHEMA, self::VERSION, false);
	}

	private static function schedule_cleanup() {
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
		}
	}

	public static function cleanup_expired_data() {
		global $wpdb;

		$settings = self::settings();
		$retention_days = min(3650, max(1, (int) $settings['retention_days']));
		$cutoff = (new DateTimeImmutable('now', wp_timezone()))
			->modify('-' . $retention_days . ' days')
			->format('Y-m-d H:i:s');
		$tables = self::table_names();

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tables['requests']} WHERE requested_at < %s",
				$cutoff
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tables['contacts']} WHERE updated_at < %s",
				$cutoff
			)
		);
	}

	private static function hash_request_value($value) {
		$value = trim((string) $value);
		if ('' === $value) {
			return '';
		}

		return hash_hmac('sha256', $value, wp_salt('auth'));
	}

	private static function request_fingerprint() {
		$remote_addr = isset($_SERVER['REMOTE_ADDR']) && is_scalar($_SERVER['REMOTE_ADDR'])
			? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
			: '';
		$user_agent = isset($_SERVER['HTTP_USER_AGENT']) && is_scalar($_SERVER['HTTP_USER_AGENT'])
			? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
			: '';

		return array(
			'ip_hash' => self::hash_request_value($remote_addr),
			'user_agent_hash' => self::hash_request_value($user_agent),
		);
	}

	private static function rate_limit_key($email) {
		$fingerprint = self::request_fingerprint();
		$source = strtolower(trim((string) $email)) . '|' . $fingerprint['ip_hash'];

		return 'resoacga_rate_' . substr(hash('sha256', $source), 0, 40);
	}

	private static function is_rate_limited($email) {
		return (int) get_transient(self::rate_limit_key($email)) >= self::RATE_LIMIT_MAX;
	}

	private static function hit_rate_limit($email) {
		$key = self::rate_limit_key($email);
		$count = (int) get_transient($key);
		set_transient($key, $count + 1, self::RATE_LIMIT_WINDOW);
	}

	private static function save_contact($email, $resource_id) {
		global $wpdb;

		$tables = self::table_names();
		$now = current_time('mysql');
		$fingerprint = self::request_fingerprint();
		$existing = $wpdb->get_row(
			$wpdb->prepare("SELECT id, request_count FROM {$tables['contacts']} WHERE email = %s", $email),
			ARRAY_A
		);

		if ($existing) {
			return false !== $wpdb->update(
				$tables['contacts'],
				array(
					'last_resource_id' => $resource_id,
					'request_count' => max(1, (int) $existing['request_count'] + 1),
					'updated_at' => $now,
					'ip_hash' => $fingerprint['ip_hash'],
					'user_agent_hash' => $fingerprint['user_agent_hash'],
				),
				array('id' => (int) $existing['id']),
				array('%s', '%d', '%s', '%s', '%s'),
				array('%d')
			);
		}

		return false !== $wpdb->insert(
			$tables['contacts'],
			array(
				'email' => $email,
				'first_resource_id' => $resource_id,
				'last_resource_id' => $resource_id,
				'request_count' => 1,
				'created_at' => $now,
				'updated_at' => $now,
				'ip_hash' => $fingerprint['ip_hash'],
				'user_agent_hash' => $fingerprint['user_agent_hash'],
			),
			array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
		);
	}

	private static function log_request($email, $resource, $mail_sent) {
		global $wpdb;

		$tables = self::table_names();
		$fingerprint = self::request_fingerprint();

		return false !== $wpdb->insert(
			$tables['requests'],
			array(
				'email' => $email,
				'resource_id' => $resource['id'],
				'resource_title' => $resource['title'],
				'requested_at' => current_time('mysql'),
				'mail_sent' => $mail_sent ? 1 : 0,
				'ip_hash' => $fingerprint['ip_hash'],
				'user_agent_hash' => $fingerprint['user_agent_hash'],
			),
			array('%s', '%s', '%s', '%s', '%d', '%s', '%s')
		);
	}

	private static function send_resource_email($email, $resource, $language = 'fr') {
		$language = self::current_language($language);
		$resource = self::translated_resource($resource, $language);
		$settings = self::translated_settings(self::settings(), $language);
		$site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
		$site_url = home_url('/');
		$subject = strtr(
			(string) $settings['subject'],
			array(
				'{site_name}' => $site_name,
				'{resource_title}' => $resource['title'],
			)
		);
		$plain_message = 'en' === $language
			? sprintf(
				"Hello,\n\nThank you for your interest.\n\nYour document:\n%s\n\nDownload the document:\n%s\n\nKind regards,\n%s\n%s\n",
				$resource['title'],
				$resource['url'],
				$site_name,
				$site_url
			)
			: sprintf(
				"Bonjour,\n\nMerci pour votre intérêt.\n\nVotre étude :\n%s\n\nTélécharger l’étude :\n%s\n\nBien cordialement,\n%s\n%s\n",
				$resource['title'],
				$resource['url'],
				$site_name,
				$site_url
			);
		$message = self::html_email_message($resource, $site_name, $site_url, $language);
		$headers = array('Content-Type: text/html; charset=UTF-8');
		$from_email = sanitize_email($settings['from_email']);
		$from_name = sanitize_text_field($settings['from_name']);

		if (is_email($from_email) && '' !== $from_name) {
			$headers[] = sprintf('From: %s <%s>', $from_name, $from_email);
		}

		$set_alt_body = static function ($phpmailer) use ($plain_message) {
			$phpmailer->AltBody = $plain_message;
		};

		add_action('phpmailer_init', $set_alt_body);

		try {
			return wp_mail($email, $subject, $message, $headers);
		} finally {
			remove_action('phpmailer_init', $set_alt_body);
		}
	}

	private static function email_logo_url() {
		$theme_options = get_option('qode_options_proya', array());

		if (is_array($theme_options)) {
			foreach (array('logo_image', 'logo_image_sticky', 'logo_image_mobile') as $option_key) {
				$logo_url = esc_url_raw($theme_options[$option_key] ?? '');
				if ('' !== $logo_url) {
					return $logo_url;
				}
			}
		}

		$custom_logo_id = (int) get_theme_mod('custom_logo');
		if ($custom_logo_id) {
			$custom_logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
			if ($custom_logo_url) {
				return esc_url_raw($custom_logo_url);
			}
		}

		return '';
	}

	private static function html_email_message($resource, $site_name, $site_url, $language = 'fr') {
		$logo_url = self::email_logo_url();
		$resource_title = esc_html($resource['title']);
		$download_url = esc_url($resource['url']);
		$safe_site_name = esc_html($site_name);
		$safe_site_url = esc_url($site_url);
		$logo_markup = '' !== $logo_url
			? sprintf(
				'<img src="%s" width="250" alt="%s" style="display:block;width:250px;max-width:100%%;height:auto;border:0;color:#ffffff;font-family:Oswald,Arial Narrow,Arial,sans-serif;font-size:22px;font-weight:700;">',
				esc_url($logo_url),
				esc_attr($site_name)
			)
			: sprintf(
				'<span style="color:#ffffff;font-family:Oswald,Arial Narrow,Arial,sans-serif;font-size:24px;font-weight:700;letter-spacing:.02em;">%s</span>',
				$safe_site_name
			);

		if ('en' === self::current_language($language)) {
			return sprintf(
				'<!doctype html>
<html lang="en">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>%1$s</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f7fb;color:#323547;">
	<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">Your document %2$s is ready to download.</div>
	<table role="presentation" width="100%%" cellspacing="0" cellpadding="0" border="0" style="width:100%%;background-color:#f5f7fb;">
		<tr>
			<td align="center" style="padding:28px 12px;">
				<table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%%;max-width:640px;background-color:#ffffff;border-collapse:collapse;">
					<tr>
						<td style="height:4px;background-color:#c9a24f;font-size:0;line-height:0;">&nbsp;</td>
					</tr>
					<tr>
						<td style="padding:28px 40px;background-color:#121066;">%3$s</td>
					</tr>
					<tr>
						<td style="padding:42px 40px 38px;background-color:#ffffff;font-family:Oswald,Arial Narrow,Arial,sans-serif;">
							<p style="margin:0 0 10px;color:#c9a24f;font-size:12px;font-weight:700;letter-spacing:1.8px;line-height:18px;text-transform:uppercase;">Studies &amp; reports</p>
							<h1 style="margin:0 0 18px;color:#121066;font-family:Oswald,Arial Narrow,Arial,sans-serif;font-size:30px;font-weight:500;line-height:38px;">Your document is ready</h1>
							<p style="margin:0 0 8px;color:#5d6374;font-size:15px;line-height:24px;">Hello,</p>
							<p style="margin:0 0 24px;color:#323547;font-size:16px;line-height:26px;">Thank you for your interest. You can now access the requested document.</p>
							<table role="presentation" width="100%%" cellspacing="0" cellpadding="0" border="0" style="width:100%%;margin:2px 0 30px;border-collapse:collapse;">
								<tr>
									<td style="padding:17px 0 18px;border-top:1px solid #d9dce7;border-bottom:1px solid #d9dce7;">
										<p style="margin:0 0 5px;color:#5d6374;font-size:13px;font-weight:400;line-height:19px;">Requested document</p>
										<p style="margin:0;color:#121066;font-family:Oswald,Arial Narrow,Arial,sans-serif;font-size:20px;font-weight:500;line-height:28px;">%2$s</p>
									</td>
								</tr>
							</table>
							<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
								<tr>
									<td bgcolor="#121066" style="background-color:#121066;">
										<a href="%4$s" target="_blank" style="display:inline-block;padding:15px 24px;color:#ffffff;font-family:Oswald,Arial Narrow,Arial,sans-serif;font-size:15px;font-weight:500;line-height:20px;text-decoration:none;">Download the document&nbsp;&nbsp;→</a>
									</td>
								</tr>
							</table>
							<p style="margin:24px 0 0;color:#77798b;font-size:12px;line-height:19px;">If the button does not work, copy this link into your browser:<br><a href="%4$s" style="color:#121066;text-decoration:underline;word-break:break-all;">%4$s</a></p>
						</td>
					</tr>
					<tr>
						<td style="padding:24px 40px;background-color:#121066;font-family:Oswald,Arial Narrow,Arial,sans-serif;">
							<p style="margin:0 0 5px;color:#ffffff;font-size:14px;font-weight:700;line-height:20px;">%1$s</p>
							<p style="margin:0 0 10px;color:#c8c7df;font-size:12px;line-height:18px;">Strategic advisory</p>
							<a href="%5$s" style="color:#6bc6b4;font-size:12px;line-height:18px;text-decoration:underline;">%5$s</a>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>',
				$safe_site_name,
				$resource_title,
				$logo_markup,
				$download_url,
				$safe_site_url
			);
		}

		return sprintf(
			'<!doctype html>
<html lang="fr">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>%1$s</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f7fb;color:#323547;">
	<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">Votre étude %2$s est prête à être téléchargée.</div>
	<table role="presentation" width="100%%" cellspacing="0" cellpadding="0" border="0" style="width:100%%;background-color:#f5f7fb;">
		<tr>
			<td align="center" style="padding:28px 12px;">
				<table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%%;max-width:640px;background-color:#ffffff;border-collapse:collapse;">
					<tr>
						<td style="height:4px;background-color:#c9a24f;font-size:0;line-height:0;">&nbsp;</td>
					</tr>
					<tr>
						<td style="padding:28px 40px;background-color:#121066;">%3$s</td>
					</tr>
					<tr>
						<td style="padding:42px 40px 38px;background-color:#ffffff;font-family:Oswald,Arial Narrow,Arial,sans-serif;">
							<p style="margin:0 0 10px;color:#c9a24f;font-size:12px;font-weight:700;letter-spacing:1.8px;line-height:18px;text-transform:uppercase;">Études &amp; rapports</p>
							<h1 style="margin:0 0 18px;color:#121066;font-family:Oswald,Arial Narrow,Arial,sans-serif;font-size:30px;font-weight:500;line-height:38px;">Votre étude est prête</h1>
							<p style="margin:0 0 8px;color:#5d6374;font-size:15px;line-height:24px;">Bonjour,</p>
							<p style="margin:0 0 24px;color:#323547;font-size:16px;line-height:26px;">Merci pour votre intérêt. Vous pouvez maintenant accéder au document demandé.</p>
							<table role="presentation" width="100%%" cellspacing="0" cellpadding="0" border="0" style="width:100%%;margin:2px 0 30px;border-collapse:collapse;">
								<tr>
									<td style="padding:17px 0 18px;border-top:1px solid #d9dce7;border-bottom:1px solid #d9dce7;">
										<p style="margin:0 0 5px;color:#5d6374;font-size:13px;font-weight:400;line-height:19px;">Document demandé</p>
										<p style="margin:0;color:#121066;font-family:Oswald,Arial Narrow,Arial,sans-serif;font-size:20px;font-weight:500;line-height:28px;">%2$s</p>
									</td>
								</tr>
							</table>
							<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
								<tr>
									<td bgcolor="#121066" style="background-color:#121066;">
										<a href="%4$s" target="_blank" style="display:inline-block;padding:15px 24px;color:#ffffff;font-family:Oswald,Arial Narrow,Arial,sans-serif;font-size:15px;font-weight:500;line-height:20px;text-decoration:none;">Télécharger l’étude&nbsp;&nbsp;→</a>
									</td>
								</tr>
							</table>
							<p style="margin:24px 0 0;color:#77798b;font-size:12px;line-height:19px;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br><a href="%4$s" style="color:#121066;text-decoration:underline;word-break:break-all;">%4$s</a></p>
						</td>
					</tr>
					<tr>
						<td style="padding:24px 40px;background-color:#121066;font-family:Oswald,Arial Narrow,Arial,sans-serif;">
							<p style="margin:0 0 5px;color:#ffffff;font-size:14px;font-weight:700;line-height:20px;">%1$s</p>
							<p style="margin:0 0 10px;color:#c8c7df;font-size:12px;line-height:18px;">Conseil stratégique indépendant</p>
							<a href="%5$s" style="color:#6bc6b4;font-size:12px;line-height:18px;text-decoration:underline;">%5$s</a>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>',
			$safe_site_name,
			$resource_title,
			$logo_markup,
			$download_url,
			$safe_site_url
		);
	}

	public static function enqueue_frontend_assets() {
		$language = self::current_language();
		$is_english = 'en' === $language;

		wp_enqueue_style(
			'resource-access-gate',
			self::plugin_url('assets/frontend.css'),
			array(),
			self::VERSION
		);
		wp_enqueue_script(
			'resource-access-gate',
			self::plugin_url('assets/frontend.js'),
			array(),
			self::VERSION,
			true
		);
		wp_localize_script(
			'resource-access-gate',
			'ResourceAccessGate',
			array(
				'action' => self::AJAX_ACTION,
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce(self::NONCE_ACTION),
				'invalidEmail' => $is_english ? 'Please enter a valid email address.' : 'Veuillez saisir une adresse email valide.',
				'loading' => $is_english ? 'Checking...' : 'Vérification...',
				'genericError' => $is_english
					? 'The link could not be prepared. Check the address or try again later.'
					: 'Le lien n’a pas pu être préparé. Vérifiez l’adresse ou réessayez plus tard.',
			)
		);
	}

	public static function enqueue_admin_assets($hook_suffix) {
		if ('toplevel_page_' . self::MENU_SLUG !== $hook_suffix) {
			return;
		}

		wp_enqueue_style(
			'resource-access-gate-admin',
			self::plugin_url('assets/admin.css'),
			array(),
			self::VERSION
		);
		wp_enqueue_script(
			'resource-access-gate-admin',
			self::plugin_url('assets/admin.js'),
			array(),
			self::VERSION,
			true
		);
		wp_localize_script(
			'resource-access-gate-admin',
			'ResourceAccessGateAdmin',
			array(
				'shortcodeTemplate' => '[resource_access_gate id="%s"]',
				'missingId' => 'Ajoutez un ID.',
				'copied' => 'Copié.',
				'copyFailed' => 'Copie impossible.',
			)
		);
	}

	public static function resource_gate_shortcode($atts) {
		$atts = shortcode_atts(
			array(
				'id' => '',
				'key' => '',
				'title' => '',
				'mode' => 'inline',
				'modal_id' => '',
			),
			$atts,
			'resource_access_gate'
		);

		$resource_id = $atts['id'] ?: $atts['key'];
		if ('' === (string) $resource_id && '' !== (string) $atts['title']) {
			$resource_id = sanitize_title((string) $atts['title']);
		}

		$resource = self::get_resource($resource_id);
		if (!$resource) {
			return '';
		}

		self::enqueue_frontend_assets();

		$language = self::current_language();
		$is_english = 'en' === $language;
		$resource = self::translated_resource($resource, $language);
		$settings = self::translated_settings(self::settings(), $language);
		$field_id = wp_unique_id('rag-resource-email-');
		$is_modal = 'modal' === strtolower((string) $atts['mode']);
		$modal_id = sanitize_html_class((string) $atts['modal_id']);

		if ($is_modal && '' === $modal_id) {
			$modal_id = 'rag-resource-modal-' . $resource['id'];
		}

		$modal_title_id = $modal_id . '-title';
		$modal_resource_id = $modal_id . '-resource';

		ob_start();
		?>
		<div class="rag-resource-gate<?php echo $is_modal ? ' rag-resource-gate--modal' : ''; ?>" data-resource-id="<?php echo esc_attr($resource['id']); ?>">
			<?php if ($is_modal) : ?>
				<div class="rag-resource-modal" id="<?php echo esc_attr($modal_id); ?>" hidden>
					<div class="rag-resource-modal-backdrop" data-rag-close aria-hidden="true"></div>
					<div
						class="rag-resource-dialog"
						role="dialog"
						aria-modal="true"
						aria-labelledby="<?php echo esc_attr($modal_title_id); ?>"
						aria-describedby="<?php echo esc_attr($modal_resource_id); ?>"
						tabindex="-1"
					>
						<button class="rag-resource-modal-close" type="button" data-rag-close aria-label="<?php echo esc_attr($is_english ? 'Close window' : 'Fermer la fenêtre'); ?>">
							<span aria-hidden="true">&times;</span>
						</button>
						<p class="rag-resource-modal-kicker"><?php echo esc_html($is_english ? 'Studies & reports' : 'Études & rapports'); ?></p>
						<h2 id="<?php echo esc_attr($modal_title_id); ?>"><?php echo esc_html($is_english ? 'Receive the report' : 'Recevoir l’étude'); ?></h2>
						<p class="rag-resource-modal-title" id="<?php echo esc_attr($modal_resource_id); ?>"><?php echo esc_html($resource['title']); ?></p>
			<?php endif; ?>
			<form class="rag-resource-form" novalidate>
				<label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($settings['helper_text']); ?></label>
				<input class="rag-resource-honeypot" type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true">
				<input type="hidden" name="resource_language" value="<?php echo esc_attr($language); ?>">
				<div class="rag-resource-fields">
					<input id="<?php echo esc_attr($field_id); ?>" type="email" name="email" autocomplete="email" required placeholder="<?php echo esc_attr($is_english ? 'name@company.com' : 'nom@entreprise.fr'); ?>">
					<button type="submit"><?php echo esc_html($settings['button_label']); ?></button>
				</div>
				<p class="rag-resource-privacy"><?php echo esc_html($settings['privacy_notice']); ?></p>
				<p class="rag-resource-message" aria-live="polite" hidden></p>
			</form>
			<div class="rag-resource-result" hidden tabindex="-1" aria-live="polite">
				<p class="rag-resource-result-message"></p>
				<a href="#" rel="noopener" target="_blank" download><?php echo esc_html($is_english ? 'Download the document' : 'Télécharger le document'); ?></a>
			</div>
			<?php if ($is_modal) : ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function handle_resource_access() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		$email = isset($_POST['email']) && is_scalar($_POST['email'])
			? sanitize_email(wp_unslash($_POST['email']))
			: '';
		$resource_id = isset($_POST['resource_id']) && is_scalar($_POST['resource_id'])
			? sanitize_title(wp_unslash($_POST['resource_id']))
			: '';
		$requested_language = isset($_POST['resource_language']) && is_scalar($_POST['resource_language'])
			? sanitize_key(wp_unslash($_POST['resource_language']))
			: '';
		$language = self::current_language($requested_language);
		$is_english = 'en' === $language;
		$honeypot = isset($_POST['website']) && is_scalar($_POST['website'])
			? sanitize_text_field(wp_unslash($_POST['website']))
			: '';
		$resource = self::get_resource($resource_id);

		if ('' !== $honeypot) {
			wp_send_json_error(array('message' => $is_english ? 'Invalid request.' : 'Demande invalide.'), 400);
		}

		if (!is_email($email)) {
			wp_send_json_error(array('message' => $is_english ? 'Invalid email address.' : 'Adresse email invalide.'), 400);
		}

		if (!$resource) {
			wp_send_json_error(array('message' => $is_english ? 'Resource unavailable.' : 'Ressource indisponible.'), 404);
		}

		if (self::is_rate_limited($email)) {
			wp_send_json_error(
				array('message' => $is_english
					? 'Too many successive requests. Please try again in a few minutes.'
					: 'Trop de demandes successives. Réessayez dans quelques minutes.'),
				429
			);
		}

		$resource = self::translated_resource($resource, $language);
		self::hit_rate_limit($email);
		self::create_tables();

		if (!self::save_contact($email, $resource['id'])) {
			wp_send_json_error(array('message' => $is_english ? 'The request could not be saved.' : 'Enregistrement impossible.'), 500);
		}

		$mail_sent = self::send_resource_email($email, $resource, $language);
		self::log_request($email, $resource, $mail_sent);

		$settings = self::translated_settings(self::settings(), $language);

		wp_send_json_success(
			array(
				'message' => $mail_sent ? $settings['success_message'] : $settings['failure_mail_message'],
				'downloadUrl' => esc_url_raw($resource['url']),
				'downloadLabel' => $is_english ? 'Download the document' : 'Télécharger le document',
				'mailSent' => (bool) $mail_sent,
			)
		);
	}

	public static function register_admin_page() {
		add_menu_page(
			'Resource Access Gate',
			'Ressources',
			'manage_options',
			self::MENU_SLUG,
			array(__CLASS__, 'render_admin_page'),
			'dashicons-download',
			58
		);
	}

	private static function sanitize_settings($raw) {
		$defaults = self::default_settings();
		$settings = array();
		$raw = is_array($raw) ? $raw : array();

		foreach ($defaults as $key => $default) {
			$value = isset($raw[$key]) && is_scalar($raw[$key]) ? $raw[$key] : $default;

			if ('from_email' === $key) {
				$settings[$key] = sanitize_email($value);
			} elseif ('retention_days' === $key) {
				$settings[$key] = min(3650, max(1, (int) $value));
			} else {
				$settings[$key] = sanitize_text_field($value);
			}
		}

		if (!is_email($settings['from_email'])) {
			$settings['from_email'] = $defaults['from_email'];
		}

		return $settings;
	}

	private static function sanitize_resources_for_save($raw_resources) {
		$resources = array();

		foreach ((array) $raw_resources as $row) {
			$row = (array) $row;
			$title = isset($row['title']) && is_scalar($row['title'])
				? sanitize_text_field($row['title'])
				: '';
			$id = isset($row['id']) && is_scalar($row['id'])
				? sanitize_title($row['id'])
				: '';
			$url = isset($row['url']) && is_scalar($row['url'])
				? esc_url_raw($row['url'])
				: '';

			if ('' === $id && '' !== $title) {
				$id = sanitize_title($title);
			}

			if ('' === $id || '' === $title || '' === $url) {
				continue;
			}

			$resources[$id] = array(
				'id' => $id,
				'title' => $title,
				'url' => $url,
				'enabled' => empty($row['enabled']) ? 0 : 1,
			);
		}

		return $resources ?: self::default_resources();
	}

	public static function handle_save_settings() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Access denied.', 'email-download-gate'));
		}

		check_admin_referer(self::ADMIN_SAVE_ACTION);

		$raw_settings = isset($_POST[self::OPTION_SETTINGS]) && is_array($_POST[self::OPTION_SETTINGS])
			? map_deep(wp_unslash($_POST[self::OPTION_SETTINGS]), 'sanitize_text_field')
			: array();
		$raw_resources = isset($_POST[self::OPTION_RESOURCES]) && is_array($_POST[self::OPTION_RESOURCES])
			? map_deep(wp_unslash($_POST[self::OPTION_RESOURCES]), 'sanitize_text_field')
			: array();

		update_option(self::OPTION_SETTINGS, self::sanitize_settings($raw_settings), false);
		update_option(self::OPTION_RESOURCES, self::sanitize_resources_for_save($raw_resources), false);

		wp_safe_redirect(add_query_arg(array('page' => self::MENU_SLUG, 'updated' => '1'), admin_url('admin.php')));
		exit;
	}

	private static function stats() {
		global $wpdb;
		$tables = self::table_names();

		return array(
			'contacts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['contacts']}"),
			'requests' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['requests']}"),
			'mail_failures' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['requests']} WHERE mail_sent = 0"),
		);
	}

	private static function recent_requests($limit = 50) {
		global $wpdb;
		$tables = self::table_names();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT requested_at, email, resource_title, resource_id, mail_sent FROM {$tables['requests']} ORDER BY requested_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	public static function render_admin_page() {
		if (!current_user_can('manage_options')) {
			return;
		}

		self::create_tables();

		$settings = self::settings();
		$resources = array_values(self::resources());
		$resources[] = array('id' => '', 'title' => '', 'url' => '', 'enabled' => 1);
		$stats = self::stats();
		$requests = self::recent_requests();
		$export_url = wp_nonce_url(
			admin_url('admin-post.php?action=' . self::ADMIN_EXPORT_ACTION),
			self::ADMIN_EXPORT_ACTION
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag set after a nonce-verified save.
		$updated = isset($_GET['updated']) && is_scalar($_GET['updated']) ? sanitize_key(wp_unslash($_GET['updated'])) : '';
		?>
		<div class="wrap resource-access-gate-admin">
			<h1>Resource Access Gate</h1>
			<?php if ('1' === $updated) : ?>
				<div class="notice notice-success is-dismissible"><p>Réglages enregistrés.</p></div>
			<?php endif; ?>

			<p>
				Shortcode principal :
				<code>[resource_access_gate id="resource-id"]</code>
			</p>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr(self::ADMIN_SAVE_ACTION); ?>">
				<?php wp_nonce_field(self::ADMIN_SAVE_ACTION); ?>

				<h2>Réglages email</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="resoacga-from-name">Nom expéditeur</label></th>
						<td><input id="resoacga-from-name" class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[from_name]" value="<?php echo esc_attr($settings['from_name']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="resoacga-from-email">Email expéditeur</label></th>
						<td><input id="resoacga-from-email" class="regular-text" type="email" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[from_email]" value="<?php echo esc_attr($settings['from_email']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="resoacga-subject">Objet du mail</label></th>
						<td>
							<input id="resoacga-subject" class="large-text" type="text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[subject]" value="<?php echo esc_attr($settings['subject']); ?>">
							<p class="description">Variables disponibles : <code>{site_name}</code>, <code>{resource_title}</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="resoacga-helper">Texte du formulaire</label></th>
						<td><input id="resoacga-helper" class="large-text" type="text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[helper_text]" value="<?php echo esc_attr($settings['helper_text']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="resoacga-privacy">Information sur les données</label></th>
						<td>
							<input id="resoacga-privacy" class="large-text" type="text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[privacy_notice]" value="<?php echo esc_attr($settings['privacy_notice']); ?>">
							<p class="description">Ce texte est affiché sous le champ email. Les adresses ne sont pas utilisées pour de la prospection.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="resoacga-retention">Durée de conservation</label></th>
						<td>
							<input id="resoacga-retention" class="small-text" type="number" min="1" max="3650" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[retention_days]" value="<?php echo (int) $settings['retention_days']; ?>"> jours
							<p class="description">Les contacts et demandes plus anciens sont supprimés automatiquement chaque jour.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="resoacga-button">Texte du bouton</label></th>
						<td><input id="resoacga-button" class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[button_label]" value="<?php echo esc_attr($settings['button_label']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="resoacga-success">Message succès</label></th>
						<td><input id="resoacga-success" class="large-text" type="text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[success_message]" value="<?php echo esc_attr($settings['success_message']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="resoacga-mail-failure">Message si le mail échoue</label></th>
						<td><input id="resoacga-mail-failure" class="large-text" type="text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[failure_mail_message]" value="<?php echo esc_attr($settings['failure_mail_message']); ?>"></td>
					</tr>
				</table>

				<h2>Ressources</h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col">Actif</th>
							<th scope="col">ID shortcode</th>
							<th scope="col">Titre</th>
							<th scope="col">URL du fichier</th>
							<th scope="col">Shortcode</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($resources as $index => $resource) : ?>
							<?php
							$resource_shortcode = '' !== (string) $resource['id']
								? sprintf('[resource_access_gate id="%s"]', (string) $resource['id'])
								: '';
							?>
							<tr>
								<td>
									<input type="hidden" name="<?php echo esc_attr(self::OPTION_RESOURCES); ?>[<?php echo (int) $index; ?>][enabled]" value="0">
									<input type="checkbox" name="<?php echo esc_attr(self::OPTION_RESOURCES); ?>[<?php echo (int) $index; ?>][enabled]" value="1" <?php checked(!empty($resource['enabled'])); ?>>
								</td>
								<td><input class="regular-text resoacga-resource-id-input" type="text" name="<?php echo esc_attr(self::OPTION_RESOURCES); ?>[<?php echo (int) $index; ?>][id]" value="<?php echo esc_attr($resource['id']); ?>"></td>
								<td><input class="large-text" type="text" name="<?php echo esc_attr(self::OPTION_RESOURCES); ?>[<?php echo (int) $index; ?>][title]" value="<?php echo esc_attr($resource['title']); ?>"></td>
								<td><input class="large-text code" type="url" name="<?php echo esc_attr(self::OPTION_RESOURCES); ?>[<?php echo (int) $index; ?>][url]" value="<?php echo esc_url($resource['url']); ?>"></td>
								<td class="resoacga-shortcode-cell">
									<div class="resoacga-shortcode-tools">
										<input class="regular-text code resoacga-shortcode-preview" type="text" value="<?php echo esc_attr($resource_shortcode); ?>" readonly aria-label="Shortcode de la ressource">
										<button type="button" class="button button-secondary resoacga-copy-shortcode" aria-label="Copier le shortcode" title="Copier le shortcode">
											<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
											<span class="screen-reader-text">Copier le shortcode</span>
										</button>
										<span class="resoacga-copy-feedback" aria-live="polite"></span>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button('Enregistrer'); ?>
			</form>

			<hr>

			<h2>Donnees collectees</h2>
			<p>
				<strong><?php echo (int) $stats['contacts']; ?></strong> emails uniques,
				<strong><?php echo (int) $stats['requests']; ?></strong> demandes,
				<strong><?php echo (int) $stats['mail_failures']; ?></strong> envois email en échec.
			</p>
			<p><a class="button button-secondary" href="<?php echo esc_url($export_url); ?>">Télécharger les données CSV</a></p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col">Date</th>
						<th scope="col">Email</th>
						<th scope="col">Ressource</th>
						<th scope="col">Mail envoyé</th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($requests)) : ?>
						<tr><td colspan="4">Aucune demande pour le moment.</td></tr>
					<?php else : ?>
						<?php foreach ($requests as $request) : ?>
							<tr>
								<td><?php echo esc_html($request['requested_at']); ?></td>
								<td><?php echo esc_html($request['email']); ?></td>
								<td><?php echo esc_html($request['resource_title']); ?><br><code><?php echo esc_html($request['resource_id']); ?></code></td>
								<td><?php echo (int) $request['mail_sent'] ? 'Oui' : 'Non'; ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function handle_export_csv() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Access denied.', 'email-download-gate'));
		}

		check_admin_referer(self::ADMIN_EXPORT_ACTION);

		global $wpdb;
		$tables = self::table_names();
		$rows = $wpdb->get_results(
			"SELECT requested_at, email, resource_id, resource_title, mail_sent FROM {$tables['requests']} ORDER BY requested_at DESC",
			ARRAY_A
		);

		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=resource-access-gate-' . gmdate('Y-m-d') . '.csv');

		$output = fopen('php://output', 'w');
		if (false === $output) {
			wp_die(esc_html__('CSV export could not be opened.', 'email-download-gate'));
		}

		echo "\xEF\xBB\xBF";
		fputcsv($output, array('requested_at', 'email', 'resource_id', 'resource_title', 'mail_sent'));

		foreach ($rows as $row) {
			fputcsv(
				$output,
				array(
					$row['requested_at'],
					$row['email'],
					$row['resource_id'],
					$row['resource_title'],
					(int) $row['mail_sent'],
				)
			);
		}

		// The php://output stream is closed automatically when this request ends.
		exit;
	}
}

register_activation_hook(__FILE__, array('Resource_Access_Gate', 'activate'));
register_deactivation_hook(__FILE__, array('Resource_Access_Gate', 'deactivate'));
Resource_Access_Gate::init();

