<?php
/**
 * Plugin Name:       easyVerein Membership Form
 * Plugin URI:        https://github.com/your-github-user/easyverein-membership-form
 * Description:       Membership application form that creates applications in easyVerein via the easyVerein API v3.0, with automatic token refresh, optional SEPA mandate, family members and spam protection.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Saurabh Dubey
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       easyverein-membership-form
 * Domain Path:       /languages
 *
 * This is an unofficial, community-maintained sample plugin. It is not affiliated
 * with or endorsed by SD Software-Design GmbH, the maker of easyVerein.
 *
 * @package EasyVereinMembershipForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EVMF_VERSION', '1.0.0' );
define( 'EVMF_PLUGIN_FILE', __FILE__ );
define( 'EVMF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EVMF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EVMF_PLUGIN_DIR . 'includes/class-evmf-plugin.php';
require_once EVMF_PLUGIN_DIR . 'includes/class-evmf-logger.php';
require_once EVMF_PLUGIN_DIR . 'includes/class-evmf-token-store.php';
require_once EVMF_PLUGIN_DIR . 'includes/class-evmf-api-client.php';
require_once EVMF_PLUGIN_DIR . 'includes/class-evmf-validator.php';
require_once EVMF_PLUGIN_DIR . 'includes/class-evmf-membership-service.php';
require_once EVMF_PLUGIN_DIR . 'includes/class-evmf-mailer.php';
require_once EVMF_PLUGIN_DIR . 'includes/class-evmf-form.php';

if ( is_admin() ) {
	require_once EVMF_PLUGIN_DIR . 'includes/class-evmf-admin.php';
}

register_activation_hook( __FILE__, array( 'EVMF_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EVMF_Plugin', 'deactivate' ) );

EVMF_Plugin::init();
