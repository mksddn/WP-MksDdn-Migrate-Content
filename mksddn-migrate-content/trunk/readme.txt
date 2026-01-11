=== MksDdn Migrate Content ===
Contributors: mksddn
Tags: migration, export, import, backup, wpbkp
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reliable chunked migrations and scheduled backups powered by custom `.wpbkp` archives.

== Description ==

MksDdn Migrate Content is a clean-room migration suite that packages your site into deterministic `.wpbkp` archives. Each bundle contains a manifest, checksum, database segments, media, selected files, and user decisions, so imports are predictable and reversible.

= Why MksDdn Migrate Content? =

* **Dual export modes** – choose Full Site (database + uploads/plugins/themes) or Selected Content (multi-select posts/pages/CPTs) with or without referenced media.
* **Chunked pipeline** – large archives stream through AJAX endpoints with resume tokens, so multi‑GB transfers survive flaky networks.
* **Snapshots, history & rollback** – every import stores a snapshot, logs metadata, enforces a global job lock, and lets you roll back with one click.
* **User merge control** – compare archive vs current users, decide how to merge/conflict, and log every change for audit.
* **Automation & retention** – schedule recurring full-site exports via WP-Cron, cap storage with retention rules, and review run logs in the admin UI.
* **Integrity & safety** – `.wpbkp` archives ship with manifests and checksums; imports verify capabilities, nonces, and disk space before touching data.

= Feature Highlights =

- Archive format with manifest, checksum, and payload folders (`content.json`, `media/`, `options/`, filesystem slices).
- Media scanner that collects featured images, galleries, attachments referenced inside blocks or shortcodes.
- File-system coverage for `wp-content/uploads`, `wp-content/plugins`, `wp-content/themes` with filters to skip VCS/system files.
- Chunked upload/download JS client with live progress, auto-resume, and graceful fallback to direct transfer.
- Recovery center showing history, statuses, archive paths, rollback controls, and inline notices when a job lock is active.
- Scheduler UI to enable cron-based backups, tweak recurrence, and enforce “keep last N archives”.
- Custom `.wpbkp` drag-and-drop uploader with checksum guardrails (UI polish deferred to next milestone, functionality already complete).

== Installation ==

1. Upload the `mksddn-migrate-content` folder to the `/wp-content/plugins/` directory, or install via the Plugins page in WordPress.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to Tools → Migrate Content to run exports, imports, snapshots, or scheduling.

== Frequently Asked Questions ==

= What is inside a `.wpbkp` archive? =
Each archive stores a manifest (checksums, metadata, timestamps), JSON payloads for selected entities, optional filesystem slices (uploads/plugins/themes), media binaries, snapshot info, and user-merge selections. Imports verify the manifest before processing.

= How is `.wpbkp` different from `.json` exports? =
`.json` exports are lightweight (content only) and convenient for quick edits. `.wpbkp` adds media, filesystem slices, checksums, and recovery metadata. Use `.wpbkp` for full fidelity or when you plan to roll back.

= Does it support ACF and custom post types? =
Yes. Any public post type plus Advanced Custom Fields metadata is exported/imported. Taxonomies, menus, widgets, and serialized options are also covered.

= How do chunked uploads resume? =
The JS client splits files into 5–10 MB chunks (auto-tuned by server limits). Each chunk is hashed and acknowledged via `wp_ajax_mksddn_mc_*` endpoints. If the browser reloads, the resume token restarts from the last confirmed chunk.

= Where are scheduled backups stored? =
Scheduled full-site backups live in `wp-content/uploads/mksddn-mc/schedules/{timestamp}/`. Retention automatically deletes archives beyond the configured limit.

= Can I merge users without overwriting existing accounts? =
Yes. The user merge dialog shows archive/current rows with conflict indicators. You can keep current roles, replace metadata, or skip entire accounts. Actions are logged in the migration history.

= Is there rollback support? =
Every import creates a snapshot saved under `wp-content/uploads/mksddn-mc/snapshots/{timestamp}`. The history table exposes “Rollback” buttons that re-import the snapshot if something goes wrong.

= Does it touch production files directly? =
Filesystem operations run through `WP_Filesystem`, honor capability checks, and avoid `.git`, `.svn`, and OS temp files. Full-site imports stage files before replacing anything critical.

== Screenshots ==

1. Export dashboard with “Full Site” and “Selected Content” cards.
2. Selected Content picker with multi-select lists and media toggles.
3. History & Recovery log with rollback controls and job lock notice.
4. User merge dialog showing archive/current comparison.
5. Scheduling screen with cron settings and retention status.

== Architecture ==

The plugin follows SOLID principles and WordPress Coding Standards with a clean, modular architecture:

= Service Container & Dependency Injection =
* Centralized `ServiceContainer` manages all dependencies
* Service Providers (`CoreServiceProvider`, `AdminServiceProvider`, `ExportServiceProvider`, `ImportServiceProvider`, `ChunkServiceProvider`) register services
* All dependencies resolved through container, eliminating direct instantiation
* Full support for interface-based dependency injection

= Request Handlers =
* `ExportRequestHandler` - handles export requests
* `ImportRequestHandler` - delegates to specialized import services
* `RecoveryRequestHandler` - manages snapshots and rollbacks
* `ScheduleRequestHandler` - handles automation scheduling
* `UserMergeRequestHandler` - processes user merge operations
* All handlers implement corresponding interfaces for testability

= Service Layer =
* `SelectedContentImportService` - handles selected content imports
* `FullSiteImportService` - manages full site imports
* `ImportFileValidator` - validates uploaded files
* `ImportPayloadPreparer` - prepares import payloads
* `ResponseHandler` - manages redirects and status messages
* `NotificationService` - handles user notifications
* `ProgressService` - tracks operation progress

= Contracts (Interfaces) =
All key components implement interfaces:
* `ExporterInterface`, `ImporterInterface`
* `MediaCollectorInterface`, `SnapshotManagerInterface`
* `HistoryRepositoryInterface`, `ChunkJobRepositoryInterface`
* `ScheduleManagerInterface`, `UserPreviewStoreInterface`
* `NotificationServiceInterface`, `ProgressServiceInterface`
* Request handler interfaces for all handlers

= Error Handling =
* Specialized exceptions: `ValidationException`, `FileOperationException`, `DatabaseOperationException`, `ImportException`, `ExportException`
* Centralized `ErrorHandler` for consistent error processing
* Proper logging and user-friendly error messages

= Performance =
* `BatchLoader` for optimized database queries (prevents N+1 problems)
* Efficient media collection with batch processing
* Chunked transfer for large files
* Memory-efficient streaming for large archives

= Security =
* All admin operations check `current_user_can('manage_options')`
* Nonce verification for all forms and AJAX requests
* Input sanitization using WordPress sanitization functions
* Output escaping with `esc_html()`, `esc_attr()`, `esc_url()`
* File upload validation with MIME type checking

== Changelog ==

= 1.3.1 =
* Fixed: Improved compliance with WordPress.org plugin guidelines - all scripts/styles now properly enqueued, conditional loading for admin files, removed inline code.
* Enhanced: Real-time import progress tracking via REST API polling without page refresh.
* Enhanced: Improved background import execution with fastcgi_finish_request() support for uninterrupted processing.

= 1.3.0 =
* Added MKSDDN_MC_BASENAME constant for plugin path management.
* Introduced dedicated CSS file for import progress styling.
* Added user warnings to prevent accidental lockouts when deselecting all users.
* Enhanced user selection handling to preserve current admin user during imports.
* Improved data sanitization across handlers.
* Added import completion notices in NotificationService.
* Implemented backup and restore of critical options during full database imports.
* Enhanced file verification in FullContentImporter.
* Refactored FilesystemHelper to initialize permissions dynamically.
* Enhanced ImportProgressPage with static CSS/JS generation methods.

= 1.2.5 =
* Refactored BatchLoader to enhance database query efficiency by replacing placeholder-based queries with sanitized ID strings, improving security and performance.
* Implemented dedicated admin styles and scripts with separate CSS and JavaScript files for better code organization and maintainability.
* Added CSS for progress bars, sections, grids, and cards to enhance the admin interface.
* Introduced JavaScript for progress bar functionality with dynamic updates during content migration.
* Refactored code to remove deprecated inline styles and scripts, promoting better separation of concerns.
* Updated comments for clarity on caching and sanitization practices.
* Added PHPCS ignore comments for specific cases to address coding standards compliance.

= 1.2.4 =
* Enhanced ACF field export and import support for groups and repeaters.
* Improved ACF field handling in BatchLoader by using get_fields() directly for better efficiency.
* Enhanced link field processing in repeaters and groups during import operations.
* Refactored ACF field retrieval in WpFunctionsWrapper to ensure consistent array return types.
* Improved data integrity during ACF imports with better field value handling and recursive media remapping.

= 1.2.3 =
* Enhanced FilesystemHelper with dynamic definition of FS_CHMOD_FILE and FS_CHMOD_DIR based on existing WordPress file permissions, improving filesystem operations compatibility.
* Added ensure_directory() method to FilesystemHelper for consistent directory creation with proper error handling.
* Refactored directory creation logic in ChunkJobRepository, ChunkRestController, and FullContentExporter to utilize FilesystemHelper for improved error handling and consistency.
* Improved file permissions handling in put_stream() method to ensure proper chmod after streaming operations.

= 1.2.2 =
* Fixed history entries filtering to exclude records with missing archive files.
* Improved RecoveryRequestHandler with enhanced error handling and validation.
* Refactored file handling in ExportImportAdmin and ScheduleRequestHandler.
* Enhanced ScheduledBackupRunner reliability and HistoryRepository functionality.
* Improved ScheduleManager with better job status tracking.

= 1.2.1 =
* Added automatic filtering of history entries with missing archive files.
* Refactored HistoryRepository to use PluginConfig for consistent path handling.
* Updated PHPCS ignore comments for set_time_limit and ini_set to current standards.

= 1.2.0 =
* Added real-time progress tracking for import operations with visual progress bar.
* Introduced ImportProgressPage for streaming progress updates during long-running imports.
* Implemented incremental database import to optimize memory usage for large backups.
* Enhanced memory and time limit management based on file size to prevent failures.
* Added output flushing for better responsiveness during import.
* Improved error handling and logging for large file imports.
* Removed unused auto-increment key handling code for cleaner codebase.

= 1.1.0 =
* Added server file import feature - users can now select backup files directly from the server imports directory.
* Introduced ServerBackupScanner service for scanning and validating backup files on the server.
* Added JavaScript module (server-file-selector.js) for dynamic file selection with AJAX loading.
* Updated import forms (full site and selected content) with source toggle (upload vs server).
* Enhanced FullSiteImportService and SelectedContentImportService to support server file imports.
* Added AJAX endpoint (mksddn_mc_get_server_backups) for retrieving available backup files.
* Implemented file caching via WordPress transients to improve performance.
* Added path traversal protection for secure server file access.
* Server file selector displays file size and modification date for better user experience.

= 1.0.1 =
* Fixed duplicate PRIMARY KEY errors during full site import (wp_postmeta, wp_actionscheduler_actions).
* Auto-increment PRIMARY KEY fields are now excluded from inserts to let database generate new values.
* Added INSERT IGNORE fallback for handling duplicate key conflicts during import.

= 1.0.0 =
* Clean-room `.wpbkp` archive format with manifest + checksum validator.
* Full-site export/import (database + uploads/plugins/themes) with streaming chunked transfer.
* Selected content export with multi-select CPT picker, media toggle, and JSON fallback.
* Media collector/restorer for featured images, galleries, and inline attachments.
* Chunked upload/download pipeline with resume tokens, progress UI, and safe fallbacks.
* Snapshots, history log, global job lock, and one-click rollback for every import.
* User merge matrix covering new, conflicting, and existing accounts with role/metasync choices.
* Automation & scheduling module with cron frequency selector, retention, and manual "Run now".
* Refactored architecture following SOLID principles with Service Container and Dependency Injection.
* All components use interfaces (Contracts) for improved testability and maintainability.
* Separated concerns: Request Handlers, Services, and Views are clearly divided.
* Specialized exception handling for better error management.
* Optimized database queries using BatchLoader to prevent N+1 problems.


