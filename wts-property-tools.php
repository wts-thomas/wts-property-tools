<?php
/**
 * Plugin Name: WTS Property Tools
 * Description: Custom notifications, property cleanup, and utility functions for real estate websites.
 * Version: 2.2.1
 * Author: Thomas Rainer
 * Author URI: https://wtsks.com
 * Plugin URI: https://github.com/wts-thomas/wts-property-tools
 * License: GPL2
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

// ======================================
// INCLUDE CORE FUNCTIONS
// ======================================
require_once plugin_dir_path(__FILE__) . 'includes/auto-draft.php';
require_once plugin_dir_path(__FILE__) . 'includes/orphaned-media.php';
require_once plugin_dir_path(__FILE__) . 'includes/notifications.php';



// ======================================
// PLUGIN UPDATE CHECKER (GitHub Integration)
// ======================================
require 'vendor/plugin-update-checker/plugin-update-checker.php';
$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
	'https://github.com/wts-thomas/wts-property-tools/',
	__FILE__,
	'wts-property-tools'
);

//Set the branch that contains the stable release.
$myUpdateChecker->setBranch('main');