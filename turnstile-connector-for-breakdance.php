<?php
/**
 * Plugin Name:       Turnstile Connector for Breakdance
 * Plugin URI:        https://staffordtechnologies.net/turnstile-connector-for-breakdance/
 * Description:       Cloudflare Turnstile protection for native Breakdance Form Builder submissions.
 * Version:           1.1.0
 * Author:            Stafford Technologies
 * Author URI:        https://staffordtechnologies.net/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       turnstile-connector-for-breakdance
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package TurnstileConnectorForBreakdance
 */

defined( 'ABSPATH' ) || exit;

define( 'STBD_VERSION', '1.1.0' );
define( 'STBD_FILE', __FILE__ );
define( 'STBD_PATH', plugin_dir_path( __FILE__ ) );
define( 'STBD_URL', plugin_dir_url( __FILE__ ) );
define( 'STBD_SUPPORT_EMAIL', 'helpdesk@staffordtechnologies.net' );

require_once STBD_PATH . 'includes/class-stbd-plugin.php';

STBD_Plugin::boot( STBD_FILE );

/**
 * Add Docs and Support links under this plugin on the Plugins screen.
 *
 * @param array  $links       Existing meta links.
 * @param string $plugin_file Plugin basename.
 * @return array
 */
function stbd_plugin_row_meta( $links, $plugin_file ) {
	if ( plugin_basename( STBD_FILE ) !== $plugin_file ) {
		return $links;
	}

	$page_url = 'https://staffordtechnologies.net/turnstile-connector-for-breakdance/';

	$links[] = sprintf(
		'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
		esc_url( $page_url ),
		esc_html__( 'Docs', 'turnstile-connector-for-breakdance' )
	);
	$links[] = sprintf(
		'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
		esc_url( $page_url ),
		esc_html__( 'Support', 'turnstile-connector-for-breakdance' )
	);

	return $links;
}
add_filter( 'plugin_row_meta', 'stbd_plugin_row_meta', 10, 2 );
