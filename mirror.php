<?php

/**
 * Functions.
 */
function escape_sequence( $code ) {
	return "\e[" . $code . 'm';
}

function format_command( $value ) {
	return escape_sequence( '36' ) . $value . escape_sequence( '0' );
}

function format_error( $value ) {
	return escape_sequence( '31' ) . escape_sequence( '1' ) . 'Error:' . escape_sequence( '0' ) . ' ' . $value;
}

function run_command( $command, $expected_result_code = 0 ) {
	echo format_command( $command ), PHP_EOL;

	passthru( $command, $result_code );

	if ( null !== $expected_result_code && $expected_result_code !== $result_code ) {
		exit( $result_code );
	}

	return $result_code;
}

function run_shell_exec( $command ) {
	echo format_command( $command ), PHP_EOL;

	return shell_exec( $command );
}

function start_group( $name ) {
	echo '::group::', $name, PHP_EOL;
}

function end_group() {
	echo '::endgroup::', PHP_EOL;
}

/**
 * Get input.
 * 
 * @link https://docs.github.com/en/actions/creating-actions/metadata-syntax-for-github-actions#inputs
 * @link https://docs.github.com/en/actions/using-workflows/workflow-syntax-for-github-actions#jobsjob_idstepswith
 * @link https://github.com/actions/checkout/blob/cd7d8d697e10461458bc61a30d094dc601a8b017/dist/index.js#L2699-L2717
 * @param string $name
 * @return string|array|false
 */
function get_input( $name ) {
	$env_name = 'INPUT_' . strtoupper( $name );

	return getenv( $env_name );
}

function get_required_input( $name ) {
	$value = get_input( $name );

	if ( false === $value || '' === $value ) {
		echo format_error( escape_sequence( '90' ) . 'Input required and not supplied:' . escape_sequence( '0' ) . ' ' . $name );

		exit( 1 );
	}

	return $value;
}

/**
 * Setup.
 */
$yoast_url       = get_required_input( 'yoast-url' );
$plugin_basename = get_required_input( 'plugin-basename' );
$plugin_slug     = dirname( $plugin_basename );

/**
 * Check Yoast.com.
 */
start_group( '🌐 Check Yoast.com' );

$url = 'https://my.yoast.com/api/sites/current';

$url .= '?' . http_build_query(
	[ 
		'url' => $yoast_url,
	]
);

$data = run_shell_exec(
	sprintf(
		'curl --request GET %s',
		escapeshellarg( $url )
	)
);

$result = json_decode( $data );

if ( ! is_object( $result ) ) {
	throw new Exception(
		sprintf(
			'Unknow response from: %s.',
			$url 
		)
	);

	exit( 1 );
}

if ( ! property_exists( $result, 'subscriptions' ) ) {
	echo format_error( 'No subscriptions information.' );

	exit( 1 );
}

$subscriptions = $result->subscriptions;

$subscriptions = array_filter(
	$subscriptions,
	function ( $ubscription ) use ( $plugin_slug ) {
		return ( $plugin_slug === $ubscription->product->slug );
	}
);

$subscription = reset( $subscriptions );

if ( false === $subscription ) {
	echo format_error( 'No subscription found.' );

	exit( 1 );
}

$version = $subscription->product->version;
$zip_url = $subscription->product->download;

end_group();

$tag = 'v' . $version;

/**
 * GitHub release view.
 */
$result_code = run_command( "gh release view $tag", null );

$release_not_found = ( 1 === $result_code );

if ( ! $release_not_found ) {
	echo 'Release exists.';

	exit( 0 );
}

/**
 * Files.
 */
$work_dir = tempnam( sys_get_temp_dir(), '' );

unlink( $work_dir );

mkdir( $work_dir );

$archives_dir = $work_dir . '/archives';
$plugins_dir  = $work_dir . '/plugins';

mkdir( $archives_dir );
mkdir( $plugins_dir );

$plugin_dir = $plugins_dir . '/' . $plugin_slug;

$zip_file = $archives_dir . '/' . $plugin_slug . '-' . $version . '.zip';

/**
 * Download ZIP.
 */
start_group( '📥 Download plugin' );

run_command(
	sprintf(
		'curl %s --output %s',
		escapeshellarg( $zip_url ),
		escapeshellarg( $zip_file )
	)
);

end_group();

/**
 * Unzip.
 */
start_group( '📦 Unzip plugin' );

run_command(
	sprintf(
		'unzip %s -d %s',
		escapeshellarg( $zip_file ),
		escapeshellarg( $plugins_dir )
	)
);

end_group();

/**
 * Synchronize.
 * 
 * @link http://stackoverflow.com/a/14789400
 * @link http://askubuntu.com/a/476048
 */
start_group( '🔄 Synchronize plugin' );

run_command(
	sprintf(
		'rsync --archive --delete-before --exclude=%s --exclude=%s --verbose %s %s',
		escapeshellarg( '.git' ),
		escapeshellarg( '.github' ),
		escapeshellarg( $plugin_dir . '/' ),
		escapeshellarg( '.' )
	)
);

end_group();

/**
 * Git user.
 * 
 * @link https://github.com/roots/wordpress/blob/13ba8c17c80f5c832f29cf4c2960b11489949d5f/bin/update-repo.php#L62-L67
 */
start_group( '🔏 Version control' );

run_command(
	sprintf(
		'git config user.email %s',
		escapeshellarg( 'info@yoast.com' )
	)
);

run_command(
	sprintf(
		'git config user.name %s',
		escapeshellarg( 'Yoast' )
	)
);

/**
 * Git commit.
 * 
 * @link https://git-scm.com/docs/git-commit
 */

run_command( 'git add --all' );

run_command(
	sprintf(
		'git commit --all -m %s',
		escapeshellarg(
			sprintf(
				'Updates to %s',
				$version
			)
		)
	),
	null
);

run_command( 'gh auth status' );

run_command( 'git push origin main', null );

end_group();

/**
 * Notes.
 */
$notes = $subscription->product->changelog;

/**
 * GitHub release.
 * 
 * @todo https://memberpress.com/wp-json/wp/v2/pages?slug=change-log
 * @link https://cli.github.com/manual/gh_release_create
 */
start_group( '🚀 GitHub release' );

run_command(
	sprintf(
		'gh release create %s %s --title %s --notes %s',
		$tag,
		$zip_file,
		escapeshellarg( $version ),
		escapeshellarg( $notes )
	)
);

end_group();

/**
 * Cleanup.
 */
start_group( '🗑️ Clean up' );

run_command(
	sprintf(
		'rm -f -R %s',
		escapeshellarg( $work_dir )
	)
);

end_group();
