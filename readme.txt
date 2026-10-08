=== WP MCP ===
Author: Saifullah Qadeer
Requires at least: 5.6
Requires PHP: 7.4
Tested with Elementor: 4.x
Stable tag: 2.6.1
License: GPL-2.0-or-later

Turns a WordPress site into its own remote MCP server, so Claude (web, Desktop,
Cowork, Claude Code), Gemini or any MCP client can manage pages, posts, custom
post types, media, taxonomies and Elementor layouts. No local software needed.

== Upgrading to 2.1.0 ==

Deactivate the previous plugin before installing and activating this version.
The entry file is now wp-mcp.php, and all internal identifiers use WPMCP/wpmcp.
Activation creates new settings and a new API key; previous settings and keys
are not migrated. Review the enabled setting in the WP MCP admin menu.
Copy the new connection URL into each MCP client. Previous endpoint URLs,
custom header names, query parameters, and filter names are no longer supported.

== Connecting ==

The WP MCP admin menu (also linked as Settings on the Plugins screen) shows
both URLs ready to paste.

Recommended -- key in a request header:
    https://SITE/wp-json/wpmcp/v1/mcp
    header: x-api-key: YOUR_API_KEY
Alternatively use Authorization: Bearer YOUR_API_KEY. Select No sign-in in
clients that distinguish OAuth from API-key authentication. The clean URL
requires a header; it does not allow anonymous access.

Compatibility -- key in the URL (content tools only):
    https://SITE/wp-json/wpmcp/v1/mcp/YOUR_API_KEY
Requires Allow API keys in URLs in settings, which is off on new installs
(sites that already had the plugin keep their current setting). Query-string
keys are not accepted.

Requirements: the site must be on HTTPS, and permalinks must not be set to
"Plain" (otherwise /wp-json/ 404s).

== Tools (20) ==

wp_ping, wp_list_post_types, wp_list_content, wp_get_content, wp_create_content,
wp_update_content, wp_delete_content, wp_get_elementor, wp_set_elementor,
wp_upload_media, wp_list_media, wp_list_terms, wp_create_term,
wp_list_extensions, wp_install_extension, wp_list_extension_files,
wp_read_extension_file, wp_edit_extension_file, wp_list_file_backups,
wp_restore_extension_file.

== File safety and recovery (2.3.0) ==

PHP edits are parsed without execution on the server's PHP version before
writing. Missing or escaped opening tags, BOMs and new non-whitespace text
outside PHP in previously PHP-only files are rejected. functions.php must
remain PHP-only. Existing mixed HTML/PHP templates remain supported.
JSON edits must parse. These checks do not detect every runtime or logic error.

Use dry_run=true on wp_edit_extension_file before applying an edit. Every
write is checked again, then saves the old contents to a non-autoloaded
database option. If the snapshot cannot be saved, the write is refused.
Ten snapshots are retained per file. They can contain sensitive source, are
not placed in a public uploads folder, and are included in database backups.
Storage grows with the number of distinct files edited.

wp_list_file_backups takes kind, extension, file and lists snapshot metadata.
wp_restore_extension_file takes kind, extension, file, backup_id and the
expected_sha256 of the current file. It applies the same permissions and
validation as editing and snapshots the current file before restoring.
These tools require WordPress, the database and the MCP endpoint to work.
They cannot resurrect a failed WordPress bootstrap or repair hosting outages.
Snapshots cover only edits made through this version, not earlier changes.

Read -> dry run -> edit one file -> wp_ping -> inspect affected page.
If responses become malformed or unavailable, stop editing and restore the
last changed file using a known-good hosting backup via file manager/SFTP.
WordPress Recovery Mode may help regain admin access after a fatal error.
Do not blindly decode HTML entities across an entire file.
Keep independent hosting backups and a staging site for runtime testing.
No hosting access, standalone rescue endpoint, or full-site backup is provided.

== Plugin and theme access ==

Version 2.2.0 adds opt-in extension tools. Existing content access is unchanged.
Save extension permissions in the WP MCP admin menu as an administrator. The key
delegates these operations to that administrator; permissions are rechecked
on every call. Installation and editing have separate switches, off by default.
HTTPS and header authentication are mandatory for all extension tools.
Multisite is not supported. WordPress file modification restrictions apply.

Use kind="plugin" or kind="theme". List installed extensions to get identifiers.
Install using a WordPress.org slug. Custom ZIP URLs, activation, updates,
deletion, and creating new source files are not supported in this release.
Installed extensions remain inactive.

List files, then read a file to obtain its sha256. Pass that value as
expected_sha256 with the replacement content when editing. Files are limited
to 100 KB. Existing WordPress editor rules and PHP loopback error checks apply;
these are not a substitute for backups or staging, especially for inactive code.
Remote installation requires direct filesystem access.

Call wp_ping first. It reports the Elementor version and editor generation
(v3-classic vs v4-atomic), which decides the JSON shape wp_set_elementor needs.

== Working with big Elementor pages ==

A single builder page often exceeds a client's tool-result cap (~150,000 chars
on Claude.ai / Desktop). Read a layout in stages:

    wp_get_elementor { id }                    -> whole tree
    wp_get_elementor { id, summary: true }     -> outline only (auto-depth)
    wp_get_elementor { id, index: 3 }          -> one top-level section, in full
    wp_get_elementor { id, depth: 2 }          -> tree cut off below level 2
    wp_get_content   { ..., include_elementor: false }

Write it back in pieces instead of resending the whole tree. Make the first
array item a marker:

    [{"elType":"__append__"}, ...]                  append after the last section
    [{"elType":"__replace__","index":3}, ...]       swap top-level section 3
    [{"elType":"__insert__","index":0}, ...]        insert before section 0

With no marker, "elements" replaces the entire layout (v1.x behaviour).
wp_set_elementor also accepts "page_settings" for _elementor_page_settings, on
its own or alongside elements.

== Changelog ==

= 2.6.1 =
* Connect with Claude is now one button: it opens Claude's add-connector dialog with this site's name and URL already filled in (Anthropic's documented install link), including a link for Team and Enterprise owners. The server URL moved under Manual setup.

= 2.6.0 =
* New connect screen: pick your AI app (Claude, ChatGPT, Claude Code, Cursor, VS Code or other) and follow one-click steps. Copy-and-open for Claude and ChatGPT, a ready command for Claude Code, Add to Cursor and Add to VS Code links.
* Connected apps list with a Revoke button that cuts access immediately.
* Setting to turn OAuth sign-in on or off. The API key moved under Advanced.
* Consent page recognizes Cursor and VS Code return addresses.

= 2.5.0 =
* Sign in with OAuth: add the server URL as a connector, click Connect and approve in wp-admin. No API key to copy. Administrators approve; the app acts as that administrator.
* Requests with no credentials get a 401 challenge that starts OAuth. A wrong API key is still 403, and API keys keep working.
* Discovery documents at /.well-known/oauth-authorization-server and /.well-known/oauth-protected-resource. Disable with the wpmcp_oauth_enabled option.

= 2.4.2 =
* Check for updates link on the Plugins screen looks up the latest GitHub release immediately.

= 2.4.1 =
* Update checks refresh hourly, and Dashboard > Updates > Check Again bypasses the cache.

= 2.4.0 =
* Updates are offered in the WordPress Plugins screen from GitHub releases
  (SaifullahQadeer/wpmcp). Publish a release tagged vX.Y.Z with a version
  higher than the installed one. For a private repository, define
  WPMCP_GITHUB_TOKEN in wp-config.php with a read-only token.
* WP MCP now has its own top-level admin menu instead of a Settings submenu,
  plus a Settings link before Deactivate on the Plugins screen.
* Missing or invalid API keys return 403 on every route, not 401.
* New installs start with API keys in URLs disabled; existing sites are unchanged.
* Ten invalid keys from one address within 15 minutes block further attempts
  from it for 15 minutes (HTTP 429). Behind a proxy or CDN, return the real
  client address from the wpmcp_client_ip filter.

= 2.3.0 =
* PHP and JSON preflight validation, including escaped/missing PHP tag checks.
* Dry-run validation and required pre-edit snapshots (ten per file).
* Snapshot listing and restore tools with existing authentication and permissions.
* Recovery guidance in admin and MCP instructions.

= 2.2.0 =
* Redesigned settings page with masked keys, copy controls and access settings.
* Header-first authentication and optional URL authentication.
* Five opt-in MCP tools for plugin/theme inventory, installation and file editing.
* Administrator capability checks, HTTPS/header requirements and stale-edit hashes.

= 2.1.0 =
* Renamed identifiers, entry file, routes and API-key prefix to WP MCP.

= 2.0.1 =
* A wrong or missing API key now returns 403, not 401. A 401 made Claude treat
  the endpoint as an OAuth server: it probed for protected-resource metadata,
  found none, fell back to Dynamic Client Registration and failed with
  "Couldn't register with the sign-in service". This server uses a static API
  key and has no authorization server, so 403 is the honest answer and Claude
  reports a plain connection error instead of a broken sign-in flow.
* Removed the WWW-Authenticate challenge header for the same reason.
* Admin page makes Option A (key in the URL) the default and warns that the
  header URL must not be pasted into Claude on its own.

= 2.0.0 =
* MCP protocol is now negotiated, not echoed. Supports 2026-07-28 (stateless),
  2025-11-25, 2025-06-18 and 2025-03-26; answers 2025-11-25 to unknown clients
  instead of blindly agreeing to a revision it does not implement.
* Added server/discover, resultType, and ttlMs/cacheScope on tools/list for the
  2026-07-28 stateless revision. MCP-Protocol-Version is validated and echoed.
* Header auth: x-api-key (Claude's static_headers option), X-WPMCP-Key, or
  Authorization: Bearer. The key-in-URL endpoint still works unchanged.
* Origin header is validated and rejected with 403 (DNS-rebinding protection),
  as the 2025-11-25 revision requires. Filter: wpmcp_allowed_origins.
* 401 responses now carry a WWW-Authenticate challenge, so a wrong key reports
  as an auth failure instead of "couldn't reach the server".
* Tool-result size guard at 140,000 characters (filter: wpmcp_max_result_chars).
  Oversized reads return an actionable error naming the arguments to retry with,
  instead of a payload the client silently truncates.
* Every tool now carries a title and behaviour annotations (readOnlyHint,
  destructiveHint, idempotentHint, openWorldHint). Small results also return
  structuredContent alongside the text block.
* wp_get_elementor gained summary / index / depth, and returns page settings and
  the Elementor environment. Outline depth degrades automatically to fit.
* wp_set_elementor gained __replace__ and __insert__ markers plus page_settings.
* wp_ping reports PHP version, Elementor version, and the editor generation
  (v3-classic / v4-atomic) so generated JSON matches the site.
* wp_get_content gained include_elementor.
* prompts/list, resources/list and DELETE are answered instead of erroring.
* Admin page warns about non-HTTPS sites and Plain permalinks, shows the
  Elementor generation, and drops the obsolete Node server instructions.
* _elementor_page_assets is cleared on write so Elementor rebuilds its asset map.

= 1.0.2 =
* Embedded MCP server (Streamable HTTP, JSON mode), 13 tools, Elementor
  read/write, admin settings page.
