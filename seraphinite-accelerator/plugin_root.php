<?php
/*
Plugin Name: Seraphinite Accelerator (Base, cache only)
Plugin URI: http://wordpress.org/plugins/seraphinite-accelerator
Description: Turns on site high speed to be attractive for people and search engines.
Text Domain: seraphinite-accelerator
Domain Path: /languages
Version: 2.27.38
Author: Seraphinite Solutions
Author URI: https://www.s-sols.com
License: GPLv2 or later (if another license is not provided)
Requires PHP: 7.1
Requires at least: 4.5
*/



// #######################################################################

if( !defined( 'SERAPH_ACCEL_PLUGIN_DIR' ) ) define( 'SERAPH_ACCEL_PLUGIN_DIR', __DIR__ ); else if( SERAPH_ACCEL_PLUGIN_DIR != __DIR__ ) return;

// #######################################################################

include( __DIR__ . '/main.php' );

// #######################################################################

register_activation_hook( __FILE__, 'seraph_accel\\Plugin::OnActivate' );
register_deactivation_hook( __FILE__, 'seraph_accel\\Plugin::OnDeactivate' );
//register_uninstall_hook( __FILE__, 'seraph_accel\\Plugin::OnUninstall' );

// #######################################################################
// #######################################################################
