<?php
/**
 * Plugin Name:       AutoLoad Forge
 * Plugin URI:        https://github.com/gunjanjaswal/AutoLoadForge
 * Description:       See which options are bloating your autoloaded data, trace each one to a likely plugin, and switch autoload off (reversibly) from a single Tools screen.
 * Version:           1.1.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Gunjan Jaswal
 * Author URI:        https://gunjanjaswal.me
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       autoloadforge
 *
 * @package AutoloadForge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AUTOLOADFORGE_VERSION', '1.1.1' );

/**
 * Store or retrieve the admin page hook suffix.
 *
 * @param string|null $set Hook suffix to store, or null to read the stored value.
 * @return string
 */
function autoloadforge_page_hook( $set = null ) {
	static $hook = '';
	if ( null !== $set ) {
		$hook = $set;
	}
	return $hook;
}

/**
 * Register the Tools submenu page.
 */
function autoloadforge_admin_menu() {
	$hook = add_management_page(
		__( 'AutoLoad Forge', 'autoloadforge' ),
		__( 'AutoLoad Forge', 'autoloadforge' ),
		'manage_options',
		'autoloadforge',
		'autoloadforge_render_page'
	);
	autoloadforge_page_hook( $hook );
}
add_action( 'admin_menu', 'autoloadforge_admin_menu' );

/**
 * Enqueue the admin stylesheet, only on the plugin's own screen.
 *
 * @param string $hook_suffix Current admin page hook suffix.
 */
function autoloadforge_enqueue_assets( $hook_suffix ) {
	if ( $hook_suffix !== autoloadforge_page_hook() ) {
		return;
	}

	wp_enqueue_style(
		'autoloadforge-admin',
		plugins_url( 'assets/css/admin.css', __FILE__ ),
		array(),
		AUTOLOADFORGE_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'autoloadforge_enqueue_assets' );

/**
 * Handle the stop / restore autoload action, then redirect back.
 */
function autoloadforge_handle_toggle() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'autoloadforge' ) );
	}

	check_admin_referer( 'autoloadforge_toggle' );

	$option = isset( $_POST['option_name'] ) ? sanitize_text_field( wp_unslash( $_POST['option_name'] ) ) : '';
	$op     = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
	$msg    = 'none';

	if ( '' !== $option && in_array( $op, array( 'stop', 'restore' ), true ) ) {
		$tracked = autoloadforge_get_disabled();

		if ( 'stop' === $op ) {
			// Capture the size from the already-loaded autoload bundle before
			// switching it off, so the size can be shown later without reading
			// the value back by a request-supplied option name.
			$alloptions = wp_load_alloptions();
			$size       = isset( $alloptions[ $option ] ) ? autoloadforge_value_size( $alloptions[ $option ] ) : 0;

			wp_set_option_autoload( $option, false );
			$tracked[ $option ] = $size;
			$msg                = 'stopped';
		} else {
			wp_set_option_autoload( $option, true );
			unset( $tracked[ $option ] );
			$msg = 'restored';
		}

		update_option( 'autoloadforge_disabled', $tracked, false );
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'    => 'autoloadforge',
				'alf_msg' => $msg,
			),
			admin_url( 'tools.php' )
		)
	);
	exit;
}
add_action( 'admin_post_autoloadforge_toggle', 'autoloadforge_handle_toggle' );

/**
 * Build a map of plugin option prefixes to readable plugin names.
 *
 * @return array<string,string>
 */
function autoloadforge_plugin_map() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$all    = get_plugins();
	$active = (array) get_option( 'active_plugins', array() );
	$map    = array();

	foreach ( $active as $file ) {
		$slug = dirname( $file );
		if ( '.' === $slug || '' === $slug ) {
			$slug = basename( $file, '.php' );
		}

		$name = isset( $all[ $file ]['Name'] ) ? $all[ $file ]['Name'] : $slug;

		foreach ( array( $slug, str_replace( '-', '_', $slug ) ) as $key ) {
			if ( strlen( $key ) > 2 ) {
				$map[ $key ] = $name;
			}
		}
	}

	return $map;
}

/**
 * Best-effort guess of where an option came from.
 *
 * @param string               $name Option name.
 * @param array<string,string> $map  Prefix to plugin-name map.
 * @return string
 */
function autoloadforge_guess_source( $name, $map ) {
	$best_key = '';
	foreach ( $map as $key => $label ) {
		if ( 0 === strpos( $name, $key ) && strlen( $key ) > strlen( $best_key ) ) {
			$best_key = $key;
		}
	}

	if ( '' !== $best_key ) {
		return $map[ $best_key ];
	}

	$core_prefixes = array( '_transient_', '_site_transient_', 'theme_mods_', 'widget_', 'wp_', '_wp_', 'rewrite_rules', 'cron', 'siteurl', 'home', 'blogname', 'blogdescription', 'template', 'stylesheet', 'active_plugins', 'recently_edited', 'can_compress_scripts', 'db_version', 'nonce_key' );
	foreach ( $core_prefixes as $prefix ) {
		if ( 0 === strpos( $name, $prefix ) ) {
			return __( 'WordPress core', 'autoloadforge' );
		}
	}

	return __( 'Unknown / theme', 'autoloadforge' );
}

/**
 * Read the tracked "switched off" options as an option-name => size map.
 *
 * A defensive check keeps a legacy flat list of names working.
 *
 * @return array<string,int>
 */
function autoloadforge_get_disabled() {
	$tracked = get_option( 'autoloadforge_disabled', array() );
	if ( ! is_array( $tracked ) ) {
		return array();
	}

	$normalized = array();
	foreach ( $tracked as $key => $value ) {
		if ( is_int( $key ) ) {
			$normalized[ (string) $value ] = 0;
		} else {
			$normalized[ $key ] = (int) $value;
		}
	}

	return $normalized;
}

/**
 * Byte length of an option value as stored.
 *
 * @param mixed $value Option value.
 * @return int
 */
function autoloadforge_value_size( $value ) {
	return strlen( is_string( $value ) ? $value : maybe_serialize( $value ) );
}

/**
 * Render the admin page.
 */
function autoloadforge_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$alloptions = wp_load_alloptions();
	$map        = autoloadforge_plugin_map();

	$sizes = array();
	$total = 0;
	foreach ( $alloptions as $name => $value ) {
		$size          = autoloadforge_value_size( $value );
		$sizes[ $name ] = $size;
		$total         += $size;
	}
	arsort( $sizes );

	$count     = count( $sizes );
	$limit     = 100;
	$shown     = array_slice( $sizes, 0, $limit, true );
	$post_url  = admin_url( 'admin-post.php' );

	// Health state.
	if ( $total < 512 * 1024 ) {
		$health_label = __( 'Healthy', 'autoloadforge' );
		$health_class = 'alf-health-good';
	} elseif ( $total < 1024 * 1024 ) {
		$health_label = __( 'Getting heavy', 'autoloadforge' );
		$health_class = 'alf-health-warn';
	} else {
		$health_label = __( 'Too heavy', 'autoloadforge' );
		$health_class = 'alf-health-bad';
	}

	$disabled = autoloadforge_get_disabled();

	// Viewing the screen counts as seeing the alert, so clear the nudge.
	if ( get_option( 'autoloadforge_alert' ) ) {
		update_option( 'autoloadforge_alert', 0, false );
	}

	$last_audit = get_option( 'autoloadforge_last_audit', array() );
	$history    = get_option( 'autoloadforge_history', array() );
	$trend      = '';
	if ( is_array( $history ) && count( $history ) >= 2 ) {
		$prev  = $history[ count( $history ) - 2 ]['total'];
		$delta = $total - (int) $prev;
		if ( 0 !== $delta ) {
			$trend = sprintf(
				/* translators: 1: up or down word, 2: formatted size change since the previous check. */
				esc_html__( '%1$s %2$s since the previous check.', 'autoloadforge' ),
				$delta > 0 ? esc_html__( 'Up', 'autoloadforge' ) : esc_html__( 'Down', 'autoloadforge' ),
				esc_html( size_format( abs( $delta ), 1 ) )
			);
		}
	}

	$msg = isset( $_GET['alf_msg'] ) ? sanitize_key( wp_unslash( $_GET['alf_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<div class="wrap autoloadforge">
		<h1><?php esc_html_e( 'AutoLoad Forge', 'autoloadforge' ); ?></h1>

		<?php if ( 'stopped' === $msg ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Autoload switched off for that option.', 'autoloadforge' ); ?></p></div>
		<?php elseif ( 'restored' === $msg ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Autoload switched back on.', 'autoloadforge' ); ?></p></div>
		<?php endif; ?>

		<div class="alf-cards">
			<div class="alf-card">
				<span class="alf-card-num"><?php echo esc_html( size_format( $total, 1 ) ); ?></span>
				<span class="alf-card-label"><?php esc_html_e( 'Total autoloaded', 'autoloadforge' ); ?></span>
			</div>
			<div class="alf-card">
				<span class="alf-card-num"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
				<span class="alf-card-label"><?php esc_html_e( 'Autoloaded options', 'autoloadforge' ); ?></span>
			</div>
			<div class="alf-card">
				<span class="alf-card-num <?php echo esc_attr( $health_class ); ?>"><?php echo esc_html( $health_label ); ?></span>
				<span class="alf-card-label"><?php esc_html_e( 'Under ~800KB is the goal', 'autoloadforge' ); ?></span>
			</div>
		</div>

		<p class="description">
			<?php esc_html_e( 'Autoloaded options load on every single page request. Switching off the ones you do not need on every page trims that weight. It is reversible, so nothing here is permanent.', 'autoloadforge' ); ?>
		</p>

		<?php if ( ! empty( $last_audit['time'] ) ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: human-readable time difference, e.g. "2 days". */
					esc_html__( 'Last automatic check: %s ago.', 'autoloadforge' ),
					esc_html( human_time_diff( (int) $last_audit['time'], time() ) )
				);
				if ( '' !== $trend ) {
					echo ' ' . esc_html( $trend );
				}
				?>
				<?php esc_html_e( 'AutoLoad Forge checks weekly and nudges you if it grows too heavy.', 'autoloadforge' ); ?>
			</p>
		<?php endif; ?>

		<h2>
			<?php
			/* translators: %d: number of options shown in the table. */
			printf( esc_html__( 'Largest autoloaded options (top %d)', 'autoloadforge' ), (int) $limit );
			?>
		</h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Option', 'autoloadforge' ); ?></th>
					<th><?php esc_html_e( 'Size', 'autoloadforge' ); ?></th>
					<th><?php esc_html_e( 'Likely source', 'autoloadforge' ); ?></th>
					<th><?php esc_html_e( 'Action', 'autoloadforge' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $shown as $name => $size ) : ?>
				<tr>
					<td><code><?php echo esc_html( $name ); ?></code></td>
					<td><?php echo esc_html( size_format( $size, 1 ) ); ?></td>
					<td><?php echo esc_html( autoloadforge_guess_source( $name, $map ) ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="alf-inline-form">
							<?php wp_nonce_field( 'autoloadforge_toggle' ); ?>
							<input type="hidden" name="action" value="autoloadforge_toggle" />
							<input type="hidden" name="op" value="stop" />
							<input type="hidden" name="option_name" value="<?php echo esc_attr( $name ); ?>" />
							<button type="submit" class="button button-small"><?php esc_html_e( 'Stop autoloading', 'autoloadforge' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( ! empty( $disabled ) ) : ?>
			<h2><?php esc_html_e( 'Switched off by AutoLoad Forge', 'autoloadforge' ); ?></h2>
			<p class="description"><?php esc_html_e( 'These options no longer autoload. Restore any of them if you notice something needs it back.', 'autoloadforge' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Option', 'autoloadforge' ); ?></th>
						<th><?php esc_html_e( 'Size', 'autoloadforge' ); ?></th>
						<th><?php esc_html_e( 'Action', 'autoloadforge' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $disabled as $name => $size ) : ?>
					<tr>
						<td><code><?php echo esc_html( $name ); ?></code></td>
						<td><?php echo esc_html( size_format( (int) $size, 1 ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="alf-inline-form">
								<?php wp_nonce_field( 'autoloadforge_toggle' ); ?>
								<input type="hidden" name="action" value="autoloadforge_toggle" />
								<input type="hidden" name="op" value="restore" />
								<input type="hidden" name="option_name" value="<?php echo esc_attr( $name ); ?>" />
								<button type="submit" class="button button-small"><?php esc_html_e( 'Restore autoloading', 'autoloadforge' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Scheduled audit + Site Health (1.1.0)
 * ---------------------------------------------------------------------- */

define( 'AUTOLOADFORGE_CRON_HOOK', 'autoloadforge_weekly_audit' );
define( 'AUTOLOADFORGE_GOAL', 800 * 1024 );    // Recommended ceiling.
define( 'AUTOLOADFORGE_HEAVY', 1024 * 1024 );  // Raise an alert past this.

/**
 * Measure the current autoloaded total and option count.
 *
 * @return array{total:int,count:int}
 */
function autoloadforge_measure() {
	$alloptions = wp_load_alloptions();
	$total      = 0;
	$count      = 0;
	foreach ( $alloptions as $value ) {
		$total += autoloadforge_value_size( $value );
		++$count;
	}
	return array(
		'total' => $total,
		'count' => $count,
	);
}

/**
 * Run an audit: record the reading, keep a short history, and flag when heavy.
 */
function autoloadforge_run_audit() {
	$now  = time();
	$snap = autoloadforge_measure();

	update_option(
		'autoloadforge_last_audit',
		array(
			'time'  => $now,
			'total' => $snap['total'],
			'count' => $snap['count'],
		),
		false
	);

	$history = get_option( 'autoloadforge_history', array() );
	if ( ! is_array( $history ) ) {
		$history = array();
	}
	$history[] = array(
		'time'  => $now,
		'total' => $snap['total'],
	);
	if ( count( $history ) > 12 ) {
		$history = array_slice( $history, -12 );
	}
	update_option( 'autoloadforge_history', $history, false );

	update_option( 'autoloadforge_alert', $snap['total'] >= AUTOLOADFORGE_HEAVY ? 1 : 0, false );
}
add_action( AUTOLOADFORGE_CRON_HOOK, 'autoloadforge_run_audit' );

/**
 * Make sure the weekly audit is scheduled (self-healing on every load).
 */
function autoloadforge_schedule() {
	if ( ! wp_next_scheduled( AUTOLOADFORGE_CRON_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'weekly', AUTOLOADFORGE_CRON_HOOK );
	}
}
add_action( 'init', 'autoloadforge_schedule' );

/**
 * Schedule and take a first reading on activation.
 */
function autoloadforge_activate() {
	autoloadforge_schedule();
	autoloadforge_run_audit();
}
register_activation_hook( __FILE__, 'autoloadforge_activate' );

/**
 * Clear the scheduled audit on deactivation.
 */
function autoloadforge_deactivate() {
	$timestamp = wp_next_scheduled( AUTOLOADFORGE_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, AUTOLOADFORGE_CRON_HOOK );
	}
}
register_deactivation_hook( __FILE__, 'autoloadforge_deactivate' );

/**
 * Show a dismissible nudge when the last audit found autoload too heavy.
 */
function autoloadforge_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! get_option( 'autoloadforge_alert' ) ) {
		return;
	}

	// Never nag on the plugin's own screen; it clears the flag itself.
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && autoloadforge_page_hook() === $screen->id ) {
		return;
	}

	$last  = get_option( 'autoloadforge_last_audit', array() );
	$total = isset( $last['total'] ) ? (int) $last['total'] : 0;
	$url   = admin_url( 'tools.php?page=autoloadforge' );
	?>
	<div class="notice notice-warning is-dismissible">
		<p>
			<strong><?php esc_html_e( 'AutoLoad Forge', 'autoloadforge' ); ?>:</strong>
			<?php
			/* translators: %s: formatted autoloaded data size, e.g. 1.2 MB. */
			printf( esc_html__( 'Your autoloaded options have grown to %s, which loads on every request.', 'autoloadforge' ), esc_html( size_format( $total, 1 ) ) );
			?>
			<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Review and trim', 'autoloadforge' ); ?></a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'autoloadforge_admin_notice' );

/**
 * Register a Site Health test for the autoloaded options size.
 *
 * @param array $tests Existing Site Health tests.
 * @return array
 */
function autoloadforge_health_tests( $tests ) {
	$tests['direct']['autoloadforge_autoload'] = array(
		'label' => __( 'Autoloaded options size', 'autoloadforge' ),
		'test'  => 'autoloadforge_health_test',
	);
	return $tests;
}
add_filter( 'site_status_tests', 'autoloadforge_health_tests' );

/**
 * Site Health test callback.
 *
 * @return array
 */
function autoloadforge_health_test() {
	$snap  = autoloadforge_measure();
	$total = $snap['total'];
	$url   = admin_url( 'tools.php?page=autoloadforge' );

	if ( $total < AUTOLOADFORGE_GOAL ) {
		$status = 'good';
		$color  = 'green';
		$label  = __( 'Your autoloaded options are a healthy size', 'autoloadforge' );
	} else {
		$status = 'recommended';
		$color  = 'orange';
		$label  = __( 'Your autoloaded options are heavier than recommended', 'autoloadforge' );
	}

	return array(
		'label'       => $label,
		'status'      => $status,
		'badge'       => array(
			'label' => __( 'Performance', 'autoloadforge' ),
			'color' => $color,
		),
		'description' => sprintf(
			'<p>%s</p>',
			sprintf(
				/* translators: 1: current autoloaded size, 2: recommended ceiling. */
				esc_html__( 'Autoloaded options total %1$s and load on every request. Keeping this under %2$s is the goal.', 'autoloadforge' ),
				esc_html( size_format( $total, 1 ) ),
				esc_html( size_format( AUTOLOADFORGE_GOAL ) )
			)
		),
		'actions'     => sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( $url ),
			esc_html__( 'Review autoloaded options', 'autoloadforge' )
		),
		'test'        => 'autoloadforge_autoload',
	);
}

/**
 * Add support and contact links to the plugin's action links.
 *
 * @param array $links Existing action links.
 * @return array
 */
function autoloadforge_action_links( $links ) {
	$links[] = sprintf(
		'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
		esc_url( 'https://ko-fi.com/gunjanjaswal' ),
		esc_html__( 'Support on Ko-fi', 'autoloadforge' )
	);
	$links[] = sprintf(
		'<a href="%s">%s</a>',
		esc_url( 'mailto:hello@gunjanjaswal.me' ),
		esc_html__( 'Contact developer', 'autoloadforge' )
	);

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'autoloadforge_action_links' );
