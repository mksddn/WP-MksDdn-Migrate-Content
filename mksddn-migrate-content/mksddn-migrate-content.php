<?php
/**
 * Plugin Name: MksDdn Migrate Content
 * Plugin URI: https://github.com/mksddn
 * Description: Complete WordPress site migration plugin. Export or import your database, media, plugins, and themes with ease.
 * Author: mksddn
 * Author URI: https://github.com/mksddn
 * Version: 1.0.0
 * Text Domain: mksddn-migrate-content
 * Domain Path: /languages
 * Network: True
 * License: GPLv3
 * Requires at least: 6.2
 * Requires PHP: 7.4
 *
 * Copyright (C) 2025 mksddn
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

// Check SSL mode
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && ( $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ) ) {
	$_SERVER['HTTPS'] = 'on';
}

// Plugin basename
define( 'MKSDDN_MC_PLUGIN_BASENAME', basename( __DIR__ ) . '/' . basename( __FILE__ ) );

// Plugin path
define( 'MKSDDN_MC_PATH', __DIR__ );

// Plugin URL
define( 'MKSDDN_MC_URL', plugins_url( '', MKSDDN_MC_PLUGIN_BASENAME ) );

// Include constants
require_once __DIR__ . DIRECTORY_SEPARATOR . 'constants.php';

// Load error handler early
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . 'class-mksddn-mc-handler.php';
if ( class_exists( 'MksDdn_MC_Handler' ) ) {
	MksDdn_MC_Handler::register();
}

// Include functions
require_once __DIR__ . DIRECTORY_SEPARATOR . 'functions.php';

// Include loader
require_once __DIR__ . DIRECTORY_SEPARATOR . 'loader.php';

// Plugin initialization
$main_controller = new MksDdn_MC_Main_Controller();

