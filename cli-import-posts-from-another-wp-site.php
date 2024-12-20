<?php
/**
 * Plugin Name:     Cli Import Posts From Another Wp Site
 * Plugin URI:      PLUGIN SITE HERE
 * Description:     PLUGIN DESCRIPTION HERE
 * Author:          YOUR NAME HERE
 * Author URI:      YOUR SITE HERE
 * Text Domain:     cli-import-posts-from-another-wp-site
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Cli_Import_Posts_From_Another_Wp_Site
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require plugin_dir_path( __FILE__ ) . 'cli/manage-posts.php';
new Manage_Posts();
