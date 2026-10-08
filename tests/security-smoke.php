<?php
// CLI-only isolated tests. No WordPress site or credentials required.
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ . '/fixtures/' );
class WP_Error {
	public $code;
	public function __construct( $code, $message = '', $data = null ) { $this->code = $code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
$options = array( 'wpmcp_api_key' => 'wpmcp_test_secret' );
function get_option( $k, $d = false ) { global $options; return isset( $options[$k] ) ? $options[$k] : $d; }
$storage_ok = true;
function update_option( $k, $v, $autoload = null ) { global $options, $storage_ok; if ( ! $storage_ok ) { return false; } $options[$k] = $v; return true; }
function wp_generate_uuid4() { static $n = 0; return 'test-backup-' . ++$n; }
$ssl = true;
function is_ssl() { global $ssl; return $ssl; }
function is_multisite() { return false; }
function get_user_by( $field, $id ) { return false; }
define( 'WP_PLUGIN_DIR', __DIR__ . '/fixtures/plugins' );
function get_plugins() { return array( 'sample/main.php' => array( 'Name' => 'Sample', 'Version' => '1.0' ) ); }
function get_plugin_files( $id ) { return array( 'sample/main.php', 'sample/readme.txt' ); }
function validate_file( $file ) { return strpos( $file, '..' ) !== false || strpos( $file, ':' ) !== false || substr( $file, 0, 1 ) === '/' ? 1 : 0; }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', $p ); }
function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
function wp_is_file_mod_allowed( $context ) { return true; }
function wp_create_nonce( $action ) { return $action; }
$editor_called = false;
function wp_edit_theme_plugin_file( $args ) { global $editor_called; $editor_called = $args; return true; }
class Request {
	private $headers; private $path;
	public function __construct( $headers = array(), $path = array() ) { $this->headers = $headers; $this->path = $path; }
	public function get_header( $key ) { return isset( $this->headers[$key] ) ? $this->headers[$key] : ''; }
	public function get_url_params() { return $this->path; }
}
require __DIR__ . '/../includes/class-wpmcp-auth.php';
require __DIR__ . '/../includes/class-wpmcp-file-safety.php';
require __DIR__ . '/../includes/class-wpmcp-extensions.php';
require __DIR__ . '/../includes/class-wpmcp-mcp.php';
$count = 0;
function check( $ok, $label ) { global $count; if ( ! $ok ) { throw new Exception( $label ); } ++$count; echo "PASS: $label\n"; }
check( is_wp_error( WPMCP_Auth::check( new Request() ) ), 'Missing credential rejected' );
check( is_wp_error( WPMCP_Auth::check( new Request( array( 'x_api_key' => 'bad' ) ) ) ), 'Invalid credential rejected' );
check( true === WPMCP_Auth::check( new Request( array( 'x_api_key' => 'wpmcp_test_secret' ) ) ), 'Header credential accepted' );
check( WPMCP_Auth::$header_authenticated, 'Header provenance retained' );
check( true === WPMCP_Auth::check( new Request( array( 'authorization' => 'Bearer wpmcp_test_secret' ) ) ), 'Bearer accepted' );
check( true === WPMCP_Auth::check( new Request( array(), array( 'key' => 'wpmcp_test_secret' ) ) ), 'Enabled URL compatibility accepted' );
check( ! WPMCP_Auth::$header_authenticated, 'URL credentials do not confer extension access' );
check( WPMCP_Extensions::run( 'wp_install_extension', array( 'kind' => 'plugin' ) )->code === 'wpmcp_secure_auth', 'URL-key installation rejected' );
$options['wpmcp_allow_url_key'] = '0';
check( is_wp_error( WPMCP_Auth::check( new Request( array(), array( 'key' => 'wpmcp_test_secret' ) ) ) ), 'Disabled URL authentication rejected' );
WPMCP_Auth::check( new Request( array( 'x_api_key' => 'wpmcp_test_secret' ) ) );
check( WPMCP_Extensions::run( 'wp_install_extension', array( 'kind' => 'plugin' ) )->code === 'wpmcp_extensions_disabled', 'Extension access off by default' );
$ssl = false;
check( WPMCP_Extensions::run( 'wp_list_extensions', array( 'kind' => 'plugin' ) )->code === 'wpmcp_secure_auth', 'HTTP extension access rejected' );
$ssl = true; $options['wpmcp_extensions_enabled'] = '1';
check( WPMCP_Extensions::run( 'wp_list_extensions', array( 'kind' => 'plugin' ) )->code === 'wpmcp_owner', 'Missing authorizing administrator rejected' );
$options['wpmcp_enabled'] = '0';
check( is_wp_error( WPMCP_Auth::check( new Request( array( 'x_api_key' => 'wpmcp_test_secret' ) ) ) ), 'Paused server rejects valid keys' );
check( ! WPMCP_Auth::$header_authenticated, 'Failed authentication clears provenance' );
$specs = WPMCP_MCP::tools_spec();
check( count( $specs ) === 20, 'All 20 tools exposed' );
check( count( array_unique( array_column( $specs, 'name' ) ) ) === 20, 'Tool names unique' );
foreach ( WPMCP_Extensions::tools_spec() as $spec ) { check( isset( $spec['inputSchema']['required'], $spec['annotations']['destructiveHint'] ), $spec['name'] . ' schema and annotations present' ); }
$execute = new ReflectionMethod( 'WPMCP_Extensions', 'execute' );
$execute->setAccessible( true );
$args = array( 'extension' => 'sample/main.php', 'file' => 'sample/readme.txt' );
$read = $execute->invoke( null, 'wp_read_extension_file', 'plugin', $args );
check( $read['sha256'] === hash( 'sha256', $read['content'] ), 'Read supplies content hash' );
$args['file'] = '../outside.php';
check( is_wp_error( $execute->invoke( null, 'wp_read_extension_file', 'plugin', $args ) ), 'Traversal rejected' );
$args['file'] = 'another-plugin/main.php';
check( is_wp_error( $execute->invoke( null, 'wp_read_extension_file', 'plugin', $args ) ), 'Files outside selected plugin rejected' );
$args['file'] = 'sample/readme.txt';
$args['content'] = 'replacement'; $args['expected_sha256'] = 'stale';
check( is_wp_error( $execute->invoke( null, 'wp_edit_extension_file', 'plugin', $args ) ) && ! $editor_called, 'Stale content cannot reach editor' );
$args['expected_sha256'] = $read['sha256'];
$result = $execute->invoke( null, 'wp_edit_extension_file', 'plugin', $args );
check( $result['updated'] && $editor_called['plugin'] === 'sample/main.php' && $editor_called['newcontent'] === 'replacement', 'Valid edit delegates to WordPress core editor' );
$safe = "<?php\nfunction sample_value() { return 1; }\n";
check( true === WPMCP_File_Safety::validate( 'functions.php', $safe, $safe ), 'Valid PHP passes without execution' );
check( is_wp_error( WPMCP_File_Safety::validate( 'functions.php', '&lt;?php return 1; ?&gt;', $safe ) ), 'Screenshot failure: escaped PHP opening tag rejected' );
check( is_wp_error( WPMCP_File_Safety::validate( 'functions.php', 'function example() {}', $safe ) ), 'Missing PHP tag rejected' );
check( is_wp_error( WPMCP_File_Safety::validate( 'functions.php', "<?php function broken( {", $safe ) ), 'PHP parse error rejected' );
check( is_wp_error( WPMCP_File_Safety::validate( 'functions.php', "oops\n" . $safe, $safe ) ), 'Raw output before opening tag rejected' );
check( is_wp_error( WPMCP_File_Safety::validate( 'functions.php', $safe . "?>unexpected output", $safe ) ), 'Raw output after closing tag rejected' );
check( is_wp_error( WPMCP_File_Safety::validate( 'config.json', '{"broken":' ) ), 'Invalid JSON rejected' );
check( true === WPMCP_File_Safety::validate( 'template.php', '<h1><?php echo 1; ?></h1>', '<h1><?php echo 0; ?></h1>' ), 'Existing mixed PHP templates supported' );
$backups = WPMCP_File_Safety::listing( 'plugin', 'sample/main.php', 'sample/readme.txt' );
check( count( $backups['backups'] ) === 1 && ! isset( $backups['backups'][0]['content'] ), 'Successful edit saved a snapshot without exposing contents in listing' );
$editor_called = false;
$args['dry_run'] = true;
$dry = $execute->invoke( null, 'wp_edit_extension_file', 'plugin', $args );
check( $dry['validated'] && ! $dry['written'] && ! $editor_called, 'Dry run does not call WordPress editor' );
unset( $args['dry_run'] );
$storage_ok = false;
check( is_wp_error( $execute->invoke( null, 'wp_edit_extension_file', 'plugin', $args ) ) && ! $editor_called, 'Failed backup blocks file modification' );
$storage_ok = true;
$args['backup_id'] = $backups['backups'][0]['id'];
$restore = $execute->invoke( null, 'wp_restore_extension_file', 'plugin', $args );
check( $restore['updated'] && $editor_called['newcontent'] === $read['content'], 'Restore delegates original content through the WordPress editor' );
$args['backup_id'] = 'missing';
check( is_wp_error( $execute->invoke( null, 'wp_restore_extension_file', 'plugin', $args ) ), 'Unknown snapshot rejected' );
for ( $i = 0; $i < 12; $i++ ) { WPMCP_File_Safety::save( 'plugin', 'sample/main.php', 'sample/readme.txt', 'version ' . $i ); }
check( count( WPMCP_File_Safety::snapshots( 'plugin', 'sample/main.php', 'sample/readme.txt' ) ) === 10, 'Snapshot retention bounded per file' );
check( empty( WPMCP_File_Safety::snapshots( 'theme', 'sample/main.php', 'sample/readme.txt' ) ), 'Snapshots scoped to exact extension kind and file' );
echo "$count checks passed.\n";
