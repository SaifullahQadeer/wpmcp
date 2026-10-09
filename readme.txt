=== WP MCP ===
Author: Saifullah Qadeer
Requires at least: 5.6
Requires PHP: 7.4
Tested with Elementor: 4.x
Stable tag: 3.0.1
License: GPL-2.0-or-later

Turns a WordPress site into its own remote MCP server, so Claude (web, Desktop,
Cowork, Claude Code), Gemini or any MCP client can manage posts and pages, media and
categories, and change the text on Elementor pages. No local software needed. Plus and Pro
plans add page builders, WooCommerce, ACF and more through the separate WP MCP Pro add-on.

== Plans ==

Free (this plugin)
    MCP connection and sign-in, posts and pages, basic media tools, categories and tags,
    reading Elementor pages and changing their text, and the Read only / Read and edit levels.
Plus (WP MCP Pro add-on)
    Everything in Free, plus full Elementor editing, Divi 4 and 5, block editor layouts and
    clearing caches.
Pro (WP MCP Pro add-on)
    Everything in Plus, plus WooCommerce, Advanced Custom Fields, custom post types and
    taxonomies, deleting content, undo, site settings, and plugin and theme tools.

The Plan tab in the WP MCP screen shows what each plan includes and where this site stands.
Anything the plan does not include is not offered to AI apps, and a call to it is refused with an
explanation. Plus and Pro come from the WP MCP Pro add-on, a separate plugin that needs this one
and a license key. The key is checked on your server; nothing is sent anywhere.

== Upgrading to 3.0.0 ==

Version 3.0.0 moves everything beyond the Free plan into the WP MCP Pro add-on. If you used
WooCommerce, ACF, Divi, block editor, cache, settings, delete, undo or plugin and theme tools
with an earlier version, those tools are not part of this plugin any more. Install and activate
the WP MCP Pro add-on and enter a Plus or Pro license to get them back. Nothing is deleted:
your content, history and settings are untouched, and the add-on picks them up again.

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

== Tools in Free (13) ==

wp_ping, wp_list_post_types, wp_list_content, wp_get_content, wp_create_content,
wp_update_content, wp_get_elementor, wp_edit_elementor_text, wp_upload_media, wp_list_media,
wp_list_terms, wp_create_term, wp_list_history.

Free works with the post and page post types and the category and post_tag taxonomies. Other
post types and taxonomies are part of Pro. wp_create_content and wp_update_content do not accept
Elementor layouts (Plus) or ACF values (Pro) on Free; they are refused before anything is saved.

== Elementor text editing ==

wp_get_elementor reads a layout (summary=true for an outline, index=N for one section) and
wp_edit_elementor_text changes the words in it. Give edits, a list of {id, field, value}, where id
is an element id from wp_get_elementor and field is a text setting (title, editor, text, button
labels, list items such as icon_list.0.text), or replace, a list of {find, replace} applied to every
text field on the page. Only text can change. Before saving, the page is compared with the original
with all text blanked, and nothing is saved unless the structure is identical, so layout, styles and
widgets cannot be touched. Changes are recorded in the history. Full layout editing is Plus.

== Access levels ==

Each connected app has an access level, chosen when you approve it and changeable later in
Connected apps:

    Read only      look at content, layouts and history; change nothing (8 tools in Free)
    Read and edit  also create and edit content and media and change Elementor text (13 tools in Free)
    Full access    everything the plan includes and that is switched on

Approval defaults to Read and edit. An app is only offered the tools its level and the site's plan
allow, and a refused call explains what is needed. The API key has its own level under Security; it
stays at Full access unless you lower it, and connections made before levels existed keep Full
access. A tool that has not been placed in a level is available at Full access only.

== Change history ==

Every write made through WP MCP is recorded (the connected app or API key, what changed, when) in
the History tab, and wp_list_history lists it. Rolling a change back, from the screen or with
wp_rollback, is part of Pro. The latest 300 changes are kept for 90 days.

With an API key the requests run without a WordPress user, so WordPress may filter some HTML
(for example scripts or iframes) from content. Connecting through OAuth runs them as the
administrator who approved the app.

== Updates and privacy ==

WP MCP gets its updates from its own update server, updates.wpmcp.co, not from WordPress.org. WordPress asks it
for the latest version about twice a day. The request carries no site address, no key and no user data, only
what any web request carries (your server's IP address). Nothing else is sent anywhere. Add WPMCP_UPDATE_URL to
wp-config.php to point a site at a different update server.

== Fonts ==

The admin screen bundles the Inter typeface (SIL Open Font License 1.1, see
assets/fonts/Inter-LICENSE.txt). Nothing is loaded from external servers.

== Changelog ==

= 3.0.1 =
* The plugin is now named wpmcp: folder wpmcp and main file wpmcp.php (it was wp-mcp), and the WP MCP admin page address is now ?page=wpmcp. WordPress sees this as a different plugin, so a site on 3.0.0 does not update by itself: deactivate and delete the old WP MCP, then upload and activate this version. Settings, API key and connected apps are kept, because they live in the database and deleting the plugin does not remove them.

= 3.0.0 =
* WP MCP is now Free plus an optional WP MCP Pro add-on. Free covers the connection, posts and pages, media, categories and tags, and reading and changing text on Elementor pages. Plus and Pro, from the add-on, add page builders, WooCommerce, ACF and more.
* New: wp_edit_elementor_text changes words on an Elementor page and cannot touch its layout.
* New Plan tab shows what each plan includes. Tools a plan does not include are marked on the Tools tab, are not offered to apps, and are refused with an explanation.
* Updates now come from WP MCP's own update server instead of GitHub. See Updates and privacy.
* Moved to the add-on: WooCommerce, ACF, Divi, block editor tools, cache clearing, site settings, delete, undo and plugin and theme tools. See Upgrading to 3.0.0.

= 2.13.0 =
* WooCommerce support: manage products (simple, variable, grouped and external), variations, categories, coupons and global attributes, update prices and stock in bulk, create and update orders, refund, and read sales, customers and store setup. 19 new tools, offered only while WooCommerce is active.
* Everything goes through WooCommerce's own functions, is checked before it is saved, and is recorded so it can be rolled back (refunds, permanent deletes and deleting an attribute cannot be undone).
* Orders and customers need Read and edit access; refunds, deletes, store settings and attributes need Full access. Declared compatible with High-Performance Order Storage.
* The generic page and post tools now refuse products, orders and coupons while WooCommerce is active.

= 2.12.2 =
* The approve screen for connecting an AI app now matches the rest of WP MCP: brand header, a clear card, access levels as selectable cards, and the same buttons. Other plugins' notices no longer appear on it.

= 2.12.1 =
* Sign-in discovery and token responses are now marked uncacheable for LiteSpeed and other page caches. LiteSpeed had been keeping the discovery data for a week, which could leave a connector with stale sign-in details.

= 2.12.0 =
* Advanced Custom Fields support, for the free plugin and ACF Pro: create and update custom post types, taxonomies and field groups, and read and write field values, through ACF's own functions. Seven new tools (38 in total).
* wp_create_content and wp_update_content take an acf object so a post and its custom fields are saved in one call.
* ACF changes are recorded in the history and can be rolled back; the Works with section and System health show whether ACF is installed.

= 2.11.4 =
* The header icon is now the updated WP MCP mark in the brand orange.

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
