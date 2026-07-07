<?php
/**
 * Plugin Name: Boublil Resource Access
 * Description: Garde l'accès aux ressources par email valide, journalise les demandes et exporte les données.
 * Version: 1.0.0
 * Author: Eli Gold
 * Author URI: https://github.com/elig45
 * Text Domain: boublil-resource-access
 */

if (!defined('ABSPATH')) {
	exit;
}

final class Boublil_Resource_Access {
	const VERSION = '1.0.0';
	const OPTION_SETTINGS = 'bra_settings';
	const OPTION_RESOURCES = 'bra_resources';
	const OPTION_SCHEMA = 'bra_schema_version';
	const AJAX_ACTION = 'bra_resource_access';
	const NONCE_ACTION = 'bra_resource_access';

	public static function init() {
		add_action('init', array(__CLASS__, 'maybe_install'));
		add_shortcode('boublil_resource_gate', array(__CLASS__, 'resource_gate_shortcode'));
		add_shortcode('bc_report_gate', array(__CLASS__, 'legacy_report_gate_shortcode'));
		add_action('wp_ajax_' . self::AJAX_ACTION, array(__CLASS__, 'handle_resource_access'));
		add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, array(__CLASS__, 'handle_resource_access'));
		add_action('admin_menu', array(__CLASS__, 'register_admin_page'));
		add_action('admin_post_bra_save_settings', array(__CLASS__, 'handle_save_settings'));
		add_action('admin_post_bra_export_csv', array(__CLASS__, 'handle_export_csv'));
	}

	public static function activate() {
		self::create_tables();
		self::ensure_default_options();
	}

	public static function maybe_install() {
		if (get_option(self::OPTION_SCHEMA) !== self::VERSION) {
			self::create_tables();
		}

		self::ensure_default_options();
	}

	private static function plugin_url($path = '') {
		return plugin_dir_url(__FILE__) . ltrim($path, '/');
	}

	private static function default_settings() {
		$host = wp_parse_url(home_url(), PHP_URL_HOST);
		$default_from = $host ? 'noreply@' . $host : get_option('admin_email');

		return array(
			'from_name' => 'Boublil Conseil',
			'from_email' => $default_from,
			'subject' => '[{site_name}] Votre lien de téléchargement',
			'button_label' => 'Recevoir le lien',
			'success_message' => 'Votre lien de téléchargement est prêt. Il vous a aussi été envoyé par email.',
			'failure_mail_message' => 'Votre lien de téléchargement est prêt. L’email automatique n’a pas pu être envoyé pour le moment.',
			'helper_text' => 'Indiquez votre adresse email pour recevoir le lien de téléchargement.',
		);
	}

	private static function default_resources() {
		return array(
			'les-mutations-du-commerce-et-de-la-consommation-en-12-videos' => array(
				'id' => 'les-mutations-du-commerce-et-de-la-consommation-en-12-videos',
				'title' => 'Les Mutations du commerce et de la consommation en 12 vidéos',
				'url' => home_url('/wp-content/uploads/2026/06/Les-Mutations-du-Commerce-et-de-la-Consommation-en-12-videos.pdf'),
				'enabled' => 1,
			),
			'zara-levolution-du-parc-de-magasins-et-les-impacts-financiers' => array(
				'id' => 'zara-levolution-du-parc-de-magasins-et-les-impacts-financiers',
				'title' => 'Zara : L évolution du parc de magasins et les impacts financiers',
				'url' => home_url('/wp-content/uploads/2026/06/Zara-evolution-parc-magasins-impacts-financiers.pdf'),
				'enabled' => 1,
			),
			'retail-les-nouvelles-strategies-gagnantes' => array(
				'id' => 'retail-les-nouvelles-strategies-gagnantes',
				'title' => 'Retail : les Nouvelles Stratégies Gagnantes',
				'url' => home_url('/wp-content/uploads/2026/06/Retail.-Les-Nouvelles-Strategies-Gagnantes.pdf'),
				'enabled' => 1,
			),
			'cap-vers-la-resilience-augmentee' => array(
				'id' => 'cap-vers-la-resilience-augmentee',
				'title' => 'Cap vers la Résilience Augmentée',
				'url' => home_url('/wp-content/uploads/2026/06/Cap-vers-la-Resilience-Augmentee.pdf'),
				'enabled' => 1,
			),
		);
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
			'contacts' => $wpdb->prefix . 'bra_resource_contacts',
			'requests' => $wpdb->prefix . 'bra_resource_requests',
		);
	}

	private static function create_tables() {
		global $wpdb;

		$tables = self::table_names();
		$charset_collate = $wpdb->get_charset_collate();

		$wpdb->query(
			"CREATE TABLE {$tables['contacts']} (
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
			) $charset_collate;"
		);

		$wpdb->query(
			"CREATE TABLE {$tables['requests']} (
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
			) $charset_collate;"
		);

		update_option(self::OPTION_SCHEMA, self::VERSION, false);
	}

	private static function hash_request_value($value) {
		$value = trim((string) $value);
		if ('' === $value) {
			return '';
		}

		return hash_hmac('sha256', $value, wp_salt('auth'));
	}

	private static function request_fingerprint() {
		return array(
			'ip_hash' => self::hash_request_value($_SERVER['REMOTE_ADDR'] ?? ''),
			'user_agent_hash' => self::hash_request_value($_SERVER['HTTP_USER_AGENT'] ?? ''),
		);
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

	private static function send_resource_email($email, $resource) {
		$settings = self::settings();
		$site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
		$subject = strtr(
			(string) $settings['subject'],
			array(
				'{site_name}' => $site_name,
				'{resource_title}' => $resource['title'],
			)
		);
		$message = sprintf(
			"Bonjour,\n\nVoici votre lien de téléchargement pour :\n%s\n\n%s\n\nBoublil Conseil\n%s\n",
			$resource['title'],
			$resource['url'],
			home_url('/')
		);
		$headers = array('Content-Type: text/plain; charset=UTF-8');
		$from_email = sanitize_email($settings['from_email']);
		$from_name = sanitize_text_field($settings['from_name']);

		if (is_email($from_email) && '' !== $from_name) {
			$headers[] = sprintf('From: %s <%s>', $from_name, $from_email);
		}

		return wp_mail($email, $subject, $message, $headers);
	}

	public static function enqueue_frontend_assets() {
		wp_enqueue_style(
			'boublil-resource-access',
			self::plugin_url('assets/frontend.css'),
			array(),
			self::VERSION
		);
		wp_enqueue_script(
			'boublil-resource-access',
			self::plugin_url('assets/frontend.js'),
			array(),
			self::VERSION,
			true
		);
		wp_localize_script(
			'boublil-resource-access',
			'BoublilResourceAccess',
			array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce(self::NONCE_ACTION),
				'invalidEmail' => 'Veuillez saisir une adresse email valide.',
				'loading' => 'Vérification...',
				'genericError' => 'Le lien n a pas pu être préparé. Vérifiez l adresse ou réessayez plus tard.',
			)
		);
	}

	public static function resource_gate_shortcode($atts) {
		$atts = shortcode_atts(
			array(
				'id' => '',
				'key' => '',
				'title' => '',
			),
			$atts,
			'boublil_resource_gate'
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

		$settings = self::settings();
		$field_id = wp_unique_id('bra-resource-email-');

		ob_start();
		?>
		<div class="bra-resource-gate" data-resource-id="<?php echo esc_attr($resource['id']); ?>">
			<form class="bra-resource-form" novalidate>
				<label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($settings['helper_text']); ?></label>
				<div class="bra-resource-fields">
					<input id="<?php echo esc_attr($field_id); ?>" type="email" name="email" autocomplete="email" required placeholder="nom@entreprise.fr">
					<button type="submit"><?php echo esc_html($settings['button_label']); ?></button>
				</div>
				<p class="bra-resource-message" aria-live="polite" hidden></p>
				<p class="bra-resource-result" hidden>
					<a href="#" rel="noopener" target="_blank" download>Télécharger le document</a>
				</p>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function legacy_report_gate_shortcode($atts) {
		$atts = shortcode_atts(
			array(
				'title' => '',
				'key' => '',
			),
			$atts,
			'bc_report_gate'
		);

		return self::resource_gate_shortcode(
			array(
				'id' => $atts['key'],
				'title' => $atts['title'],
			)
		);
	}

	public static function handle_resource_access() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		$email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
		$resource_id = sanitize_title(wp_unslash($_POST['resource_id'] ?? ''));
		$resource = self::get_resource($resource_id);

		if (!is_email($email)) {
			wp_send_json_error(array('message' => 'Adresse email invalide.'), 400);
		}

		if (!$resource) {
			wp_send_json_error(array('message' => 'Ressource indisponible.'), 404);
		}

		self::create_tables();

		if (!self::save_contact($email, $resource['id'])) {
			wp_send_json_error(array('message' => 'Enregistrement impossible.'), 500);
		}

		$mail_sent = self::send_resource_email($email, $resource);
		self::log_request($email, $resource, $mail_sent);

		$settings = self::settings();

		wp_send_json_success(
			array(
				'message' => $mail_sent ? $settings['success_message'] : $settings['failure_mail_message'],
				'downloadUrl' => esc_url_raw($resource['url']),
				'downloadLabel' => 'Télécharger le document',
				'mailSent' => (bool) $mail_sent,
			)
		);
	}

	public static function register_admin_page() {
		add_menu_page(
			'Boublil Resource Access',
			'Ressources',
			'manage_options',
			'boublil-resource-access',
			array(__CLASS__, 'render_admin_page'),
			'dashicons-download',
			58
		);
	}

	private static function sanitize_settings($raw) {
		$defaults = self::default_settings();
		$settings = array();

		foreach ($defaults as $key => $default) {
			$value = isset($raw[$key]) ? wp_unslash($raw[$key]) : $default;
			$settings[$key] = 'from_email' === $key ? sanitize_email($value) : sanitize_text_field($value);
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
			$title = sanitize_text_field(wp_unslash($row['title'] ?? ''));
			$id = sanitize_title(wp_unslash($row['id'] ?? ''));
			$url = esc_url_raw(wp_unslash($row['url'] ?? ''));

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
			wp_die('Accès refusé.');
		}

		check_admin_referer('bra_save_settings');

		update_option(self::OPTION_SETTINGS, self::sanitize_settings($_POST['bra_settings'] ?? array()), false);
		update_option(self::OPTION_RESOURCES, self::sanitize_resources_for_save($_POST['bra_resources'] ?? array()), false);

		wp_safe_redirect(add_query_arg(array('page' => 'boublil-resource-access', 'updated' => '1'), admin_url('admin.php')));
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
		$export_url = wp_nonce_url(admin_url('admin-post.php?action=bra_export_csv'), 'bra_export_csv');
		?>
		<div class="wrap">
			<h1>Boublil Resource Access</h1>

			<?php if (isset($_GET['updated'])) : ?>
				<div class="notice notice-success is-dismissible"><p>Réglages enregistrés.</p></div>
			<?php endif; ?>

			<p>
				Shortcode principal :
				<code>[boublil_resource_gate id="retail-les-nouvelles-strategies-gagnantes"]</code>
				<br>
				Compatibilité maintenue :
				<code>[bc_report_gate title="Retail : les Nouvelles Stratégies Gagnantes"]</code>
			</p>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="bra_save_settings">
				<?php wp_nonce_field('bra_save_settings'); ?>

				<h2>Réglages email</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bra-from-name">Nom expéditeur</label></th>
						<td><input id="bra-from-name" class="regular-text" type="text" name="bra_settings[from_name]" value="<?php echo esc_attr($settings['from_name']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="bra-from-email">Email expéditeur</label></th>
						<td><input id="bra-from-email" class="regular-text" type="email" name="bra_settings[from_email]" value="<?php echo esc_attr($settings['from_email']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="bra-subject">Objet du mail</label></th>
						<td>
							<input id="bra-subject" class="large-text" type="text" name="bra_settings[subject]" value="<?php echo esc_attr($settings['subject']); ?>">
							<p class="description">Variables disponibles : <code>{site_name}</code>, <code>{resource_title}</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bra-helper">Texte du formulaire</label></th>
						<td><input id="bra-helper" class="large-text" type="text" name="bra_settings[helper_text]" value="<?php echo esc_attr($settings['helper_text']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="bra-button">Texte du bouton</label></th>
						<td><input id="bra-button" class="regular-text" type="text" name="bra_settings[button_label]" value="<?php echo esc_attr($settings['button_label']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="bra-success">Message succès</label></th>
						<td><input id="bra-success" class="large-text" type="text" name="bra_settings[success_message]" value="<?php echo esc_attr($settings['success_message']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="bra-mail-failure">Message si le mail échoue</label></th>
						<td><input id="bra-mail-failure" class="large-text" type="text" name="bra_settings[failure_mail_message]" value="<?php echo esc_attr($settings['failure_mail_message']); ?>"></td>
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
						</tr>
					</thead>
					<tbody>
						<?php foreach ($resources as $index => $resource) : ?>
							<tr>
								<td>
									<input type="hidden" name="bra_resources[<?php echo (int) $index; ?>][enabled]" value="0">
									<input type="checkbox" name="bra_resources[<?php echo (int) $index; ?>][enabled]" value="1" <?php checked(!empty($resource['enabled'])); ?>>
								</td>
								<td><input class="regular-text" type="text" name="bra_resources[<?php echo (int) $index; ?>][id]" value="<?php echo esc_attr($resource['id']); ?>"></td>
								<td><input class="large-text" type="text" name="bra_resources[<?php echo (int) $index; ?>][title]" value="<?php echo esc_attr($resource['title']); ?>"></td>
								<td><input class="large-text code" type="url" name="bra_resources[<?php echo (int) $index; ?>][url]" value="<?php echo esc_url($resource['url']); ?>"></td>
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
			wp_die('Accès refusé.');
		}

		check_admin_referer('bra_export_csv');

		global $wpdb;
		$tables = self::table_names();
		$rows = $wpdb->get_results(
			"SELECT requested_at, email, resource_id, resource_title, mail_sent FROM {$tables['requests']} ORDER BY requested_at DESC",
			ARRAY_A
		);

		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=boublil-resource-access-' . gmdate('Y-m-d') . '.csv');

		$output = fopen('php://output', 'w');
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

		fclose($output);
		exit;
	}
}

register_activation_hook(__FILE__, array('Boublil_Resource_Access', 'activate'));
Boublil_Resource_Access::init();
