=== WP MCP ===
Author: Saifullah Qadeer
Requires at least: 5.6
Requires PHP: 7.4
Tested with Elementor: 4.x
Stable tag: 1.0.0
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

= 1.0.0 =
* First public release. WP MCP turns your site into its own MCP server: connect Claude, ChatGPT, Claude Code, Cursor, VS Code or any MCP client over HTTPS, with sign-in through OAuth or an API key.
* Free covers posts and pages, media, categories and tags, reading Elementor pages and changing their text, a change history, and the Read only and Read and edit access levels.
* Plus and Pro add page builders, WooCommerce, ACF, site settings and more through the optional WP MCP Pro add-on. The Plan tab shows what each plan includes.
* Updates come from WP MCP's own update server. See Updates and privacy.
