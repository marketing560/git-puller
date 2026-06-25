<?php
/**
 * Plugin Name: Git Puller
 * Description: Clone and force-pull multiple Git-backed WordPress plugins from the admin panel.
 * Version: 0.1.1
 * Author: OpenClaw
 * Text Domain: git-puller
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GIT_PULLER_VERSION', '0.1.1');
define('GIT_PULLER_PLUGIN_FILE', __FILE__);
define('GIT_PULLER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GIT_PULLER_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once GIT_PULLER_PLUGIN_DIR . 'includes/class-git-puller.php';

register_activation_hook(__FILE__, ['Git_Puller', 'activate']);

function git_puller_boot(): void
{
    Git_Puller::instance();
}

add_action('plugins_loaded', 'git_puller_boot');
