=== MksDdn Migrate Content ===
Contributors: mksddn
Tags: migration, export, import, backup, restore
Requires at least: 6.2
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Professional WordPress migration plugin for exporting and importing sites with selective content support.

== Description ==

MksDdn Migrate Content is a powerful WordPress migration plugin that allows you to export and import your WordPress site with ease. It provides both full site migration and selective content export/import capabilities.

= Key Features =

* **Full Site Export/Import**: Export and import your entire WordPress site including database, plugins, themes, and media files
* **Selective Export/Import**: Export and import specific posts and pages by slug
* **URL Replacement**: Automatically replaces URLs during import to match the new site
* **Rollback Support**: Automatic backup and rollback functionality in case of errors
* **Migration History**: Track all export and import operations
* **User-Friendly Interface**: Clean and intuitive admin interface with drag & drop support
* **Progress Tracking**: Real-time progress bars for export and import operations

= Export Options =

* Full site export (database, plugins, themes, options)
* Selective export by post type and slug
* JSON format for easy handling

= Import Features =

* File validation before import
* Step-by-step processing
* Serialized data handling
* Automatic URL replacement
* Error handling with rollback

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/mksddn-migrate-content` directory, or install the plugin through the WordPress plugins screen directly
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Migrate Content menu in the WordPress admin to access export and import features

== Frequently Asked Questions ==

= What WordPress version is required? =

WordPress 6.2 or higher is required.

= What PHP version is required? =

PHP 7.4 or higher is required. PHP 8.1+ is recommended.

= Can I export only specific pages or posts? =

Yes, you can use the selective export feature to export specific posts and pages by their slug.

= What happens to URLs during import? =

URLs are automatically replaced to match the new site's domain and structure.

= Is there a way to undo an import? =

The plugin creates automatic backups before import and can rollback changes if errors occur.

== Changelog ==

= 1.0.0 =
* Initial release
* Full site export/import
* Selective content export/import by slug
* URL replacement functionality
* Migration history tracking
* Admin interface with drag & drop support

== Upgrade Notice ==

= 1.0.0 =
Initial release of MksDdn Migrate Content.

