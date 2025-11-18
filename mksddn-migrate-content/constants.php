<?php
/**
 * Plugin constants
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

// Plugin debug mode
define( 'MKSDDN_MC_DEBUG', false );

// Plugin version
define( 'MKSDDN_MC_VERSION', '1.0.0' );

// Plugin name
define( 'MKSDDN_MC_PLUGIN_NAME', 'mksddn-migrate-content' );

// Storage path
define( 'MKSDDN_MC_STORAGE_PATH', MKSDDN_MC_PATH . DIRECTORY_SEPARATOR . 'storage' );

// Error log path
define( 'MKSDDN_MC_ERROR_FILE', MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . 'error.log' );

// Status file path
define( 'MKSDDN_MC_STATUS_FILE', MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . 'status.js' );

// Lib path
define( 'MKSDDN_MC_LIB_PATH', MKSDDN_MC_PATH . DIRECTORY_SEPARATOR . 'lib' );

// Controller path
define( 'MKSDDN_MC_CONTROLLER_PATH', MKSDDN_MC_LIB_PATH . DIRECTORY_SEPARATOR . 'controller' );

// Model path
define( 'MKSDDN_MC_MODEL_PATH', MKSDDN_MC_LIB_PATH . DIRECTORY_SEPARATOR . 'model' );

// View path
define( 'MKSDDN_MC_TEMPLATES_PATH', MKSDDN_MC_LIB_PATH . DIRECTORY_SEPARATOR . 'view' );

// Core path
define( 'MKSDDN_MC_CORE_PATH', MKSDDN_MC_LIB_PATH . DIRECTORY_SEPARATOR . 'core' );

// Vendor path
define( 'MKSDDN_MC_VENDOR_PATH', MKSDDN_MC_LIB_PATH . DIRECTORY_SEPARATOR . 'vendor' );

// Archive backups name
define( 'MKSDDN_MC_BACKUPS_NAME', 'mksddn-backups' );

// Archive database name
define( 'MKSDDN_MC_DATABASE_NAME', 'database.sql' );

// Archive package name
define( 'MKSDDN_MC_PACKAGE_NAME', 'package.json' );

// Archive settings name
define( 'MKSDDN_MC_SETTINGS_NAME', 'settings.json' );

// Archive multipart name
define( 'MKSDDN_MC_MULTIPART_NAME', 'multipart.list' );

// Archive content list name
define( 'MKSDDN_MC_CONTENT_LIST_NAME', 'content.list' );

// Archive media list name
define( 'MKSDDN_MC_MEDIA_LIST_NAME', 'media.list' );

// Archive plugins list name
define( 'MKSDDN_MC_PLUGINS_LIST_NAME', 'plugins.list' );

// Archive themes list name
define( 'MKSDDN_MC_THEMES_LIST_NAME', 'themes.list' );

// Archive tables list name
define( 'MKSDDN_MC_TABLES_LIST_NAME', 'tables.list' );

// Secret key option name
define( 'MKSDDN_MC_SECRET_KEY', 'mksddn_mc_secret_key' );

// Status option name
define( 'MKSDDN_MC_STATUS', 'mksddn_mc_status' );

// Messages option name
define( 'MKSDDN_MC_MESSAGES', 'mksddn_mc_messages' );

// Max chunk size (5MB)
define( 'MKSDDN_MC_MAX_CHUNK_SIZE', 5 * 1024 * 1024 );

// Max chunk retries
define( 'MKSDDN_MC_MAX_CHUNK_RETRIES', 10 );

// Cipher name for encryption
define( 'MKSDDN_MC_CIPHER_NAME', 'AES-256-CBC' );

// Max transaction queries
if ( ! defined( 'MKSDDN_MC_MAX_TRANSACTION_QUERIES' ) ) {
	define( 'MKSDDN_MC_MAX_TRANSACTION_QUERIES', 1000 );
}

// Max select records
if ( ! defined( 'MKSDDN_MC_MAX_SELECT_RECORDS' ) ) {
	define( 'MKSDDN_MC_MAX_SELECT_RECORDS', 1000 );
}

// Max storage cleanup (24 hours)
define( 'MKSDDN_MC_MAX_STORAGE_CLEANUP', 24 * 60 * 60 );

// Max log cleanup (7 days)
define( 'MKSDDN_MC_MAX_LOG_CLEANUP', 7 * 24 * 60 * 60 );

// Disk space factor
define( 'MKSDDN_MC_DISK_SPACE_FACTOR', 2 );

// Disk space extra (300MB)
define( 'MKSDDN_MC_DISK_SPACE_EXTRA', 300 * 1024 * 1024 );

// WP_CONTENT_DIR constant
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

// Backups default path
if ( ! defined( 'MKSDDN_MC_DEFAULT_BACKUPS_PATH' ) ) {
	define( 'MKSDDN_MC_DEFAULT_BACKUPS_PATH', WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'mksddn-backups' );
}

// Backups path option name
define( 'MKSDDN_MC_BACKUPS_PATH_OPTION', 'mksddn_mc_backups_path' );

// Backups path
define( 'MKSDDN_MC_BACKUPS_PATH', get_option( MKSDDN_MC_BACKUPS_PATH_OPTION, MKSDDN_MC_DEFAULT_BACKUPS_PATH ) );

// Storage index.php file
define( 'MKSDDN_MC_STORAGE_INDEX_PHP', MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . 'index.php' );

// Storage index.html file
define( 'MKSDDN_MC_STORAGE_INDEX_HTML', MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . 'index.html' );

// Storage .htaccess file
define( 'MKSDDN_MC_STORAGE_HTACCESS', MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . '.htaccess' );

// Storage web.config file
define( 'MKSDDN_MC_STORAGE_WEBCONFIG', MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . 'web.config' );

// Backups index.php file
define( 'MKSDDN_MC_BACKUPS_INDEX_PHP', MKSDDN_MC_BACKUPS_PATH . DIRECTORY_SEPARATOR . 'index.php' );

// Backups index.html file
define( 'MKSDDN_MC_BACKUPS_INDEX_HTML', MKSDDN_MC_BACKUPS_PATH . DIRECTORY_SEPARATOR . 'index.html' );

// Backups robots.txt file
define( 'MKSDDN_MC_BACKUPS_ROBOTS_TXT', MKSDDN_MC_BACKUPS_PATH . DIRECTORY_SEPARATOR . 'robots.txt' );

// Backups .htaccess file
define( 'MKSDDN_MC_BACKUPS_HTACCESS', MKSDDN_MC_BACKUPS_PATH . DIRECTORY_SEPARATOR . '.htaccess' );

// Backups web.config file
define( 'MKSDDN_MC_BACKUPS_WEBCONFIG', MKSDDN_MC_BACKUPS_PATH . DIRECTORY_SEPARATOR . 'web.config' );

