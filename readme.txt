=== WP MCP ===
Author: Saifullah Qadeer
Requires at least: 5.6
Requires PHP: 7.4
Tested with Elementor: 4.x
Stable tag: 2.11.3
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

== Tools (31) ==

wp_ping, wp_list_post_types, wp_list_content, wp_get_content, wp_create_content,
wp_update_content, wp_delete_content, wp_get_elementor, wp_set_elementor,
wp_upload_media, wp_list_media, wp_list_terms, wp_create_term,
wp_list_extensions, wp_install_extension, wp_list_extension_files,
wp_read_extension_file, wp_edit_extension_file, wp_list_file_backups,
wp_restore_extension_file, wp_set_extension_active, wp_list_history, wp_rollback,
wp_list_block_types, wp_get_blocks, wp_set_blocks, wp_get_divi, wp_set_divi,
wp_clear_cache, wp_get_settings, wp_update_settings.

== Access levels ==

Each connected app has an access level, chosen when you approve it and changeable later in
Connected apps:

    Read only      look at content, layouts, settings and history; change nothing (12 tools)
    Read and edit  also create and edit content, layouts and media, clear caches and undo (21 tools)
    Full access    everything that is switched on, including delete, settings and plugin tools (all)

Approval defaults to Read and edit. An app is only offered the tools its level allows, and a
refused call explains which level is needed. Undoing a change needs the level that could have
made it. The API key has its own level under Security; it stays at Full access unless you lower
it, and connections made before levels existed keep Full access. A tool that has not been
placed in a level is available at Full access only.

== Page builders, cache and settings ==

wp_ping reports each page's editor ("builder": gutenberg, elementor, divi or classic), the
Divi version and generation, and which cache plugins were found. Use the tools that match
the builder and do not mix editors on one page.

Gutenberg: wp_get_blocks (summary, then one block by index) and wp_set_blocks write block
markup (replace, append, prepend, insert, replace_block). Every block comment must be closed
in order and its attributes must be valid JSON, otherwise nothing is saved. Unregistered blocks
are reported as warnings. wp_list_block_types shows what is available.

Divi 4: wp_get_divi and wp_set_divi read and write shortcode layouts section by section. Nesting
is checked, the Divi Builder is switched on for the page and Divi's cached CSS is cleared.
Divi 5 stores layouts as blocks, so use the Gutenberg tools there.

wp_clear_cache clears the object cache, expired transients, Elementor CSS, Divi static
resources and the page cache plugin if present (LiteSpeed, WP Rocket, W3 Total Cache, WP Super
Cache, WP Fastest Cache, Autoptimize, SiteGround, Cache Enabler, Breeze, Hummingbird, Nginx
Helper). CDN and host-level caches are outside WordPress.

wp_get_settings and wp_update_settings cover title, tagline, timezone, date and time format,
posts per page, homepage and posts page, search engine visibility, comment defaults and the
permalink structure. Values are validated together, previous values are saved for rollback,
and URLs, the admin email, registration and roles cannot be changed.

wp_set_extension_active activates or deactivates a plugin or switches the theme. It is off
until you turn on Allow activation, and WP MCP cannot deactivate itself.

With an API key the requests run without a WordPress user, so WordPress may filter some HTML
(for example scripts or iframes) from content. Connecting through OAuth runs them as the
administrator who approved the app.

== Change history and rollback ==

Every write made through WP MCP is recorded (the connected app or API key, what
changed, when) in the History tab, with what is needed to undo it: content and
meta fields, terms, featured image and Elementor layout are restored from a
snapshot of only what the call touched; created items are trashed; deleted items
are restored (permanent deletes come back with the same ID); uploads and terms
are removed; file edits restore their pre-edit snapshot. If an item was edited
after the change, rollback asks for confirmation before overwriting those edits.
The latest 300 changes are kept for 90 days. AI apps can use wp_list_history
and wp_rollback. Plugin and theme installs are recorded but cannot be undone.

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

== Fonts ==

The admin screen bundles the Inter typeface (SIL Open Font License 1.1, see
assets/fonts/Inter-LICENSE.txt). Nothing is loaded from external servers.

== Changelog ==

= 2.11.3 =
* The admin header now shows the WP MCP icon instead of a generic bolt. The image is bundled with the plugin.

= 2.11.2 =
* The note beside Save settings now says activation, as well as installation and editing, needs extension access.

= 2.11.1 =
* Fixed: in the Security tab the API key field and the key-in-URL showed "full" instead of the key (the access level picker reused the same variable). The stored key was never changed; it is shown correctly again. If you copied the key from 2.11.0, copy it again.
* No focus outline is left on icon buttons after a mouse click.

= 2.11.0 =
* Access levels for connected apps: Read only, Read and edit, or Full access. Choose one when you approve an app (Read and edit is preselected) and change it any time in Connected apps; it applies on the app's next request.
* Apps are only offered the tools their level allows, and refused calls say which level is needed. Undoing a change needs the level that could have made it.
* The API key has its own level in the Security tab. Existing connections and the API key stay at Full access until you change them.

= 2.10.2 =
* New Works with section on the Connect tab: Gutenberg, Elementor 3 and 4, Divi 4 and 5, content, caches and settings, with what is active on this site.
* Health checks moved to the System tab, with optional items (Elementor, Divi, page cache) shown as information instead of warnings.
* The blue focus ring that WordPress adds to buttons and links no longer appears after a click; keyboard focus still shows a coral outline.

= 2.10.1 =
* Cleaner admin screen: notices from other plugins no longer appear on it, the server status pill and footer credit are gone, and the focus outline on tabs no longer shows a blue box.
* The Tools tab is now a compact list (name, access level, and why a tool is off) instead of a table with descriptions; hover a row for what it does.
* Show and copy are icon buttons, and collapsible sections use a chevron.

= 2.10.0 =
* Block editor (Gutenberg) tools: read blocks as an outline or one block at a time, and write block markup with replace, append, prepend, insert and replace-block modes. Markup is validated before saving.
* Divi 4 tools: read and write shortcode layouts section by section, with nesting checks. Divi 5 sites are pointed at the block tools.
* Clear caches from the assistant: object cache, transients, Elementor, Divi and eleven page cache plugins.
* Read and change common site settings, with validation and rollback.
* Activate or deactivate plugins and switch themes (off until you turn on Allow activation).
* wp_ping and wp_get_content now say which editor built each page and which cache plugins are installed.

= 2.9.2 =
* Cleaner typography: the admin screen now uses the Inter typeface, bundled with the plugin (no external font requests), with regular, medium, semibold and bold weights used consistently.

= 2.9.1 =
* Removed the headline banner from the Connect tab so the connect panel is the first thing you see.

= 2.9.0 =
* New look for the admin screen: warm off-white with coral and outlined buttons, a headline banner on the Connect tab, and icons throughout.
* A sidebar on every tab with update notice, What's new (read from this changelog), a health checklist and links to documentation and issues.
* After each update a What's new banner shows the latest changes, with a Got it button to dismiss it.

= 2.8.0 =
* History tab and rollback: every change made through WP MCP is recorded and can be undone from the admin screen, or by an app with wp_list_history and wp_rollback.
* Fixed: backslashes in post content and custom fields were removed when saving through WP MCP. They are now kept.

= 2.7.0 =
* Redesigned admin screen with four tabs: Connect (status tiles, connect an app, connected apps), Tools (all 20 tools with access level and whether each is on), Security (access switches, API key, built-in protection) and System (version and update check, site details, endpoints, file safety and recovery).
* The sidebar panels and long sections were removed from the main screen. Settings forms return to the tab they were saved from.
* The header shows when an update is available.

= 2.6.2 =
* After you click Connect with Claude, the WP MCP page watches for the connection to finish, tries to close the Claude tab it opened, and reloads with a Claude is connected notice and the Connected apps list updated.

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
