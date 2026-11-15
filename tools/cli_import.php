<?php
/**
 * The DoorKing import, run from the command line (core's cutover-v2.sh uses it): it hands a RAM
 * export to the importer as the Import page would, as user 1.
 *
 * Usage: wp eval-file tools/cli_import.php <RAM export CSV> <dry|real> [residents|vendors]
 * A dry run prints the importer's report lines ([SKIP], [CONFLICT], ...); a real one prints
 * "redirect: ...imported=N...".
 */
$csv    = $args[0] ?? '';
$mode   = $args[1] ?? '';
$target = $args[2] ?? 'residents';
if ( ! is_readable( $csv ) || ! in_array( $mode, [ 'dry', 'real' ], true ) || ! in_array( $target, [ 'residents', 'vendors' ], true ) ) {
    fwrite( STDERR, "Usage: wp eval-file cli_import.php <csv> <dry|real> [residents|vendors]\n" );
    exit( 1 );
}
wp_set_current_user( 1 );
$tmp = wp_tempnam( 'dk' );
copy( $csv, $tmp );
$_FILES['dk_csv'] = [ 'tmp_name' => $tmp, 'name' => basename( $csv ) ];
$_POST = [ '_wpnonce' => wp_create_nonce( 'fsbhoa_doorking_import_nonce' ), 'import_target' => $target ];
if ( 'dry' === $mode ) {
    $_POST['dry_run']         = 1;
    $_POST['log_only_errors'] = 1;
}
$_REQUEST                = $_POST;
$_SERVER['HTTP_REFERER'] = admin_url( 'admin.php' );
// The importer ends with a redirect: catch it instead of leaving
add_filter( 'wp_redirect', function ( $location ) {
    throw new Exception( $location );
} );
$importer = new Fsbhoa_DoorKing_Importer();
try {
    $importer->process_import();
} catch ( Exception $e ) {
    echo 'redirect: ', $e->getMessage(), "\n";
}
if ( 'dry' === $mode ) {
    foreach ( (array) get_transient( 'fsbhoa_dk_dry_run_1' ) as $line ) {
        echo $line, "\n";
    }
}
@unlink( $tmp );
