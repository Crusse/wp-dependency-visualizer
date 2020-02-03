<?php
/*
Plugin Name: Dependency visualizer
Plugin URI: https://github.com/crusse/wp-dependency-visualizer
Description: Visualizes a WordPress site's WP hook, PHP function and PHP class dependencies for better reflection about your code.
Version: 1.0.0
Author: Tony Martin
Author URI: https://github.com/crusse
License: GPLv2
*/

namespace DependencyVisualizer;

// Only load the plugin's bundled Composer dependencies if they weren't already
// loaded via another vendor directory
if ( !class_exists( '\\PhpParser' ) && file_exists( __DIR__ .'/vendor/autoload.php' ) ) {
  require_once __DIR__ .'/vendor/autoload.php';
}

define( 'DEPVIS_REQUIRED_USER_CAPABILITY', 'activate_plugins' );

require_once __DIR__ .'/includes/functions.php';
require_once __DIR__ .'/includes/ajax.php';

add_action( 'admin_menu', function() {
  add_menu_page(
    'Dependency visualizer',
    'Dependency visualizer',
    DEPVIS_REQUIRED_USER_CAPABILITY,
    'dependency-visualizer',
    function() {
      require __DIR__ .'/includes/admin-page.php';
    }
  );
} );

create_or_update_database_tables();
