=== MksDdn Migrate Content ===
Contributors: mksddn
Tags: migration, export, import, backup, wpbkp, staging
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
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

== Changelog ==

= 1.0.0 =
* Clean-room `.wpbkp` archive format with manifest + checksum validator.
* Full-site export/import (database + uploads/plugins/themes) with streaming chunked transfer.
* Selected content export with multi-select CPT picker, media toggle, and JSON fallback.
* Media collector/restorer for featured images, galleries, and inline attachments.
* Chunked upload/download pipeline with resume tokens, progress UI, and safe fallbacks.
* Snapshots, history log, global job lock, and one-click rollback for every import.
* User merge matrix covering new, conflicting, and existing accounts with role/metasync choices.
* Automation & scheduling module with cron frequency selector, retention, and manual “Run now”.


