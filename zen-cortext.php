<?php
/**
 * Plugin Name: Zen Cortext - Your AI SDR for inbound
 * Plugin URI: https://zenrepublic.agency
 * Description: An AI SDR that reads your site, talks to your visitors, and knows when to call you. Indexes your published content into a knowledge base and serves a streaming chat through a [zen_cortext] shortcode. You bring your own Anthropic API key. Not affiliated with Anthropic, Groq, OpenAI, or Google.
 * Version: 2.35.0
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author: Zen Republic Agency
 * Author URI: https://zenrepublic.agency
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zen-cortext
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ZEN_CORTEXT_VERSION', '2.35.0');
define('ZEN_CORTEXT_PLUGIN_FILE', __FILE__);
define('ZEN_CORTEXT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZEN_CORTEXT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZEN_CORTEXT_TEXT_DOMAIN', 'zen-cortext');
define('ZEN_CORTEXT_DB_VERSION', '17');

require_once ZEN_CORTEXT_PLUGIN_DIR . 'includes/class-zen-cortext.php';

register_activation_hook(__FILE__, array('Zen_Cortext', 'activate'));
register_deactivation_hook(__FILE__, array('Zen_Cortext', 'deactivate'));

add_action('plugins_loaded', array('Zen_Cortext', 'get_instance'));

// Plugins-list row — prepend a "Getting Started" link before "Deactivate"
// so first-time setup is one click from the WP plugins list.
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url  = admin_url('admin.php?page=zen-cortext-init');
    $html = '<a href="' . esc_url($url) . '">' . esc_html__('Getting Started', 'zen-cortext') . '</a>';
    array_unshift($links, $html);
    return $links;
});
