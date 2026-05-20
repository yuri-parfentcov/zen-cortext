<?php
/**
 * Plugin Name: Zen Cortext
 * Plugin URI: https://zenrepublic.agency
 * Description: Pre-sales technical chat consultant powered by Claude. Indexes WordPress content into a knowledge base, classifies and restructures it via the Anthropic API, and serves a streaming chat UI through a [zen_cortext] shortcode.
 * Version: 1.0.0
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author: Zen Republic Agency
 * Author URI: https://zenrepublic.agency
 * License: GPL v2 or later
 * Text Domain: zen-cortext
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ZEN_CORTEXT_VERSION', '2.34.6');
define('ZEN_CORTEXT_PLUGIN_FILE', __FILE__);
define('ZEN_CORTEXT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZEN_CORTEXT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZEN_CORTEXT_TEXT_DOMAIN', 'zen-cortext');
define('ZEN_CORTEXT_DB_VERSION', '17');

require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext.php';

register_activation_hook(__FILE__, array('Zen_Cortext', 'activate'));
register_deactivation_hook(__FILE__, array('Zen_Cortext', 'deactivate'));

add_action('plugins_loaded', array('Zen_Cortext', 'get_instance'));
