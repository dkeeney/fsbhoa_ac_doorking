<?php
/**
 * Legacy RAM entries: credentials RAM holds that the resident and vendor importers didn't
 * place on a cardholder.
 *
 * Every export to RAM is a full replacement, so a tag or gate code missing from the database
 * stops working at the first push. Such values are kept here, as RAM had them, and the export
 * sends them after the real cardholders (Fsbhoa_DoorKing_Export::export_legacy()). Once a
 * value belongs to a real cardholder, the export's duplicate check sends it for that
 * cardholder only, and it can be removed from here. Nothing expires on its own: a value leaves
 * when an admin assigns it to a cardholder or removes it.
 *
 * The same page checks a RAM export against what the export would send now ("Check a RAM
 * export"): every value RAM holds that the export wouldn't send is listed with the reason.
 * That is also how to confirm, after the first push, that RAM holds exactly what was sent.
 */
if ( ! defined( 'ABSPATH' ) ) {
    die;
}

class Fsbhoa_DoorKing_Legacy {

    const ENTRIES_TABLE = 'ac_dk_legacy_entries';
    const VALUES_TABLE  = 'ac_dk_legacy_values';
    const DB_VERSION    = '1';
    const DB_OPTION     = 'fsbhoa_dk_legacy_db_version';
    const PAGE_SLUG     = 'fsbhoa_doorking_legacy';
    const REPORT_TTL    = 1800;

    // Why a value RAM holds would not be sent. Only 'missing' is kept by default.
    const REASONS = [
        'missing'    => 'Not in the database',
        'archived'   => 'Held only by archived or purged cardholders',
        'inactive'   => 'Held by a current cardholder, but the credential is disabled, inactive or expired',
        'superseded' => 'Held by a current cardholder with a valid credential, but not sent (for example, the household has another directory code)',
        'test'       => 'Only on test-account rows (blank or "." name); never kept',
    ];

    const KIND_LABELS = [
        'dir' => 'Directory code',
        'ent' => 'Gate code',
        'dev' => 'Device',
    ];

    /** @var Fsbhoa_DoorKing_Export */
    private $export;

    public function __construct( Fsbhoa_DoorKing_Export $export ) {
        $this->export = $export;
        self::maybe_create_tables();

        add_action( 'fsbhoa_register_admin_submenus', [ $this, 'add_submenu' ] );
        add_action( 'admin_post_fsbhoa_dk_legacy_check', [ $this, 'handle_check' ] );
        add_action( 'admin_post_fsbhoa_dk_legacy_remove', [ $this, 'handle_remove' ] );
        add_action( 'admin_post_fsbhoa_dk_legacy_remove_claimed', [ $this, 'handle_remove_claimed' ] );
        add_action( 'admin_post_fsbhoa_dk_legacy_assign', [ $this, 'handle_assign' ] );
        add_action( 'admin_post_fsbhoa_dk_legacy_note', [ $this, 'handle_note' ] );
    }

    /**
     * Creates or updates the two tables when DB_VERSION changes.
     */
    public static function maybe_create_tables() {
        if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta( "CREATE TABLE " . self::ENTRIES_TABLE . " (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  ram_name varchar(30) NOT NULL DEFAULT '',
  ram_account varchar(50) NOT NULL DEFAULT '',
  h_flag char(1) NOT NULL DEFAULT 'Y',
  aac varchar(5) NOT NULL DEFAULT '',
  phone varchar(12) NOT NULL DEFAULT '',
  is_vendor char(1) NOT NULL DEFAULT 'N',
  source_file varchar(255) NOT NULL DEFAULT '',
  captured_at datetime NOT NULL,
  admin_note varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id)
) $charset;" );

        dbDelta( "CREATE TABLE " . self::VALUES_TABLE . " (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  entry_id int(10) unsigned NOT NULL,
  value_kind varchar(4) NOT NULL,
  value varchar(10) NOT NULL,
  ram_note varchar(100) NOT NULL DEFAULT '',
  reason varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY kind_value (value_kind,value),
  KEY entry_id (entry_id)
) $charset;" );

        update_option( self::DB_OPTION, self::DB_VERSION, true );
    }

    // ------------------------------------------------------------------
    // Values: one normal form for RAM's file, the export and the database
    // ------------------------------------------------------------------

    /**
     * The value as the export writes it: directory and gate codes padded to 4 digits,
     * devices as their last 5 digits. '' if there are no digits.
     */
    private static function normalize( $kind, $raw ) {
        $digits = preg_replace( '/[^0-9]/', '', (string) $raw );
        if ( '' === $digits ) {
            return '';
        }
        if ( 'dev' === $kind ) {
            return str_pad( substr( $digits, -5 ), 5, '0', STR_PAD_LEFT );
        }
        return str_pad( $digits, 4, '0', STR_PAD_LEFT );
    }

    /**
     * Comparison key of a normalized value. A device 0xxxx is how the export writes a
     * household's second and later gate codes, so it compares as gate code xxxx.
     */
    private static function key( $kind, $value ) {
        if ( 'dir' === $kind ) {
            return 'dir:' . $value;
        }
        if ( 'ent' === $kind ) {
            return 'pin:' . $value;
        }
        return ( '0' === $value[0] ) ? 'pin:' . substr( $value, 1 ) : 'tag:' . $value;
    }

    /**
     * Reads a RAM-format CSV (RAM's own export, or ours). Rows of the given account go in
     * 'rows'; rows of any other account in 'other'.
     *
     * @return array|WP_Error
     */
    private static function parse_csv( $handle, $account ) {
        $headers = fgetcsv( $handle, 0, ',', '"', '' );
        if ( empty( $headers ) ) {
            return new WP_Error( 'empty', 'The file is empty.' );
        }
        $col = [];
        foreach ( $headers as $idx => $name ) {
            $name = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $name );
            $col[ strtoupper( trim( $name, " \t\n\r\0\x0B\"'/" ) ) ] = $idx;
        }
        $name_idx = $col['RESIDENT'] ?? ( $col['NAME'] ?? null );
        if ( ! isset( $col['ACCOUNT'] ) || null === $name_idx || ! isset( $col['DEVICE#'] ) ) {
            return new WP_Error( 'format', 'Not a RAM export: the ACCOUNT, NAME (or Resident) and DEVICE# columns are required.' );
        }

        $device_cols = [ [ $col['DEVICE#'], $col['NOTES'] ?? null ] ];
        for ( $i = 2; $i <= 26; $i++ ) {
            if ( isset( $col[ 'DEVICE' . $i ] ) ) {
                $device_cols[] = [ $col[ 'DEVICE' . $i ], $col[ 'NOTES' . $i ] ?? null ];
            }
        }
        $cell = function ( $data, $idx ) {
            return ( null === $idx ) ? '' : trim( (string) ( $data[ $idx ] ?? '' ) );
        };

        $out = [ 'rows' => [], 'other' => [] ];
        while ( ( $data = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
            if ( [ null ] === $data ) {
                continue;
            }
            $row_account = trim( $cell( $data, $col['ACCOUNT'] ), " \t\n\r\0\x0B\"'" );
            $name        = $cell( $data, $name_idx );
            $values      = [];

            $dir = self::normalize( 'dir', $cell( $data, $col['DIR'] ?? null ) );
            if ( '' !== $dir ) {
                $values[] = [ 'kind' => 'dir', 'value' => $dir, 'note' => '' ];
            }
            $ent = self::normalize( 'ent', $cell( $data, $col['ENT'] ?? null ) );
            if ( '' !== $ent ) {
                $values[] = [ 'kind' => 'ent', 'value' => $ent, 'note' => '' ];
            }
            foreach ( $device_cols as $dc ) {
                $dev = self::normalize( 'dev', $cell( $data, $dc[0] ) );
                if ( '' !== $dev ) {
                    $values[] = [ 'kind' => 'dev', 'value' => $dev, 'note' => $cell( $data, $dc[1] ) ];
                }
            }
            foreach ( $values as &$v ) {
                $v['key'] = self::key( $v['kind'], $v['value'] );
            }
            unset( $v );

            $row = [
                'account' => $row_account,
                'name'    => $name,
                'h'       => ( 'N' === strtoupper( substr( $cell( $data, $col['H'] ?? null ), 0, 1 ) ) ) ? 'N' : 'Y',
                'aac'     => preg_replace( '/[^0-9]/', '', $cell( $data, $col['AAC'] ?? null ) ),
                'phone'   => substr( $cell( $data, $col['PHONE'] ?? null ), 0, 12 ),
                'vendor'  => ( 'Y' === strtoupper( substr( $cell( $data, $col['VENDOR'] ?? null ), 0, 1 ) ) ) ? 'Y' : 'N',
                // The contractor's test accounts (the importers skip these too)
                'test'    => ( '' === $name || '.' === $name ),
                'values'  => $values,
            ];
            if ( 0 === strcasecmp( $row_account, $account ) ) {
                $out['rows'][] = $row;
            } else {
                $out['other'][] = $row;
            }
        }
        return $out;
    }

    /**
     * Every DoorKing credential in the database by comparison key, with who holds it.
     */
    private static function database_map() {
        global $wpdb;
        $rows = $wpdb->get_results( "
            SELECT cr.credential_type, cr.credential_value, cr.status, cr.expiration_date,
                   c.id, c.first_name, c.last_name, c.company, c.cardholder_status, c.cardholder_type
            FROM ac_credentials cr
            JOIN ac_cardholders c ON c.id = cr.cardholder_id
            WHERE cr.credential_type IN ('DK_DIR_CODE', 'DK_ENTRY_CODE', 'DK_WINDSHIELD', 'RFID', 'WIEGAND_26')
        " );
        $kinds = [ 'DK_DIR_CODE' => 'dir', 'DK_ENTRY_CODE' => 'ent' ];
        $today = current_time( 'Y-m-d' );
        $map   = [];
        foreach ( (array) $rows as $r ) {
            $kind  = $kinds[ strtoupper( $r->credential_type ) ] ?? 'dev';
            $value = self::normalize( $kind, $r->credential_value );
            if ( '' === $value ) {
                continue;
            }
            $expiry = (string) $r->expiration_date;
            $map[ self::key( $kind, $value ) ][] = [
                'id'      => (int) $r->id,
                'name'    => self::cardholder_label( $r ),
                'current' => ( 'active' === $r->cardholder_status ),
                'status'  => $r->cardholder_status,
                'valid'   => in_array( $r->status, [ 'active', 'valid' ], true )
                             && ( '' === $expiry || $expiry < '1000-01-01' || substr( $expiry, 0, 10 ) >= $today ),
            ];
        }
        return $map;
    }

    private static function cardholder_label( $c ) {
        $name = trim( trim( (string) $c->last_name ) . ', ' . trim( (string) $c->first_name ), ', ' );
        if ( 'vendor' === $c->cardholder_type && ! empty( $c->company ) ) {
            $name = trim( $c->company ) . ( '' !== $name ? ' (' . $name . ')' : '' );
        }
        return '' !== $name ? $name : 'Cardholder #' . (int) $c->id;
    }

    private static function classify( $holders ) {
        if ( empty( $holders ) ) {
            return 'missing';
        }
        $current = array_filter( $holders, function ( $h ) { return $h['current']; } );
        if ( empty( $current ) ) {
            return 'archived';
        }
        foreach ( $current as $h ) {
            if ( $h['valid'] ) {
                return 'superseded';
            }
        }
        return 'inactive';
    }

    private static function holders_text( $holders ) {
        $parts = [];
        foreach ( array_slice( (array) $holders, 0, 3 ) as $h ) {
            $parts[] = $h['name'] . ' #' . $h['id'] . ( $h['current'] ? '' : ' (' . $h['status'] . ')' ) . ( $h['valid'] ? '' : ' [credential not valid]' );
        }
        if ( count( (array) $holders ) > 3 ) {
            $parts[] = '…';
        }
        return implode( '; ', $parts );
    }

    // ------------------------------------------------------------------
    // Check a RAM export against what the export would send now
    // ------------------------------------------------------------------

    /**
     * @return array|WP_Error The report.
     */
    public function compare( $file_path, $file_name ) {
        $account = trim( (string) get_option( 'fsbhoa_dk_account_name', '' ) );
        if ( '' === $account ) {
            return new WP_Error( 'config', 'The DoorKing Account Name is not set in the DoorKing settings.' );
        }

        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'open', 'Could not open the uploaded file.' );
        }
        $ram = self::parse_csv( $handle, $account );
        fclose( $handle );
        if ( is_wp_error( $ram ) ) {
            return $ram;
        }
        if ( empty( $ram['rows'] ) ) {
            return new WP_Error( 'account', sprintf( 'The file has no rows for the account "%s" (DoorKing settings).', $account ) );
        }

        $built = $this->export->build_csv();
        if ( is_wp_error( $built ) ) {
            return $built;
        }
        $mem = fopen( 'php://temp', 'w+' );
        fwrite( $mem, $built['csv'] );
        rewind( $mem );
        $ours = self::parse_csv( $mem, $account );
        fclose( $mem );
        if ( is_wp_error( $ours ) ) {
            return $ours;
        }

        // What RAM holds, by key: the first row that has it, preferring a real row to a test row
        $ram_values = [];
        $ram_count  = 0;
        foreach ( $ram['rows'] as $ri => $row ) {
            foreach ( $row['values'] as $v ) {
                $ram_count++;
                if ( ! isset( $ram_values[ $v['key'] ] ) || ( $ram_values[ $v['key'] ]['test'] && ! $row['test'] ) ) {
                    $ram_values[ $v['key'] ] = $v + [ 'row' => $ri, 'name' => $row['name'], 'test' => $row['test'] ];
                }
            }
        }
        $our_values = [];
        foreach ( $ours['rows'] as $row ) {
            foreach ( $row['values'] as $v ) {
                if ( ! isset( $our_values[ $v['key'] ] ) ) {
                    $our_values[ $v['key'] ] = $v + [ 'name' => $row['name'] ];
                }
            }
        }

        $db   = self::database_map();
        $lost = [];
        foreach ( $ram_values as $key => $v ) {
            if ( isset( $our_values[ $key ] ) ) {
                continue;
            }
            $holders = $db[ $key ] ?? [];
            $lost[]  = $v + [
                'reason'  => $v['test'] ? 'test' : self::classify( $holders ),
                'holders' => self::holders_text( $holders ),
            ];
        }
        $added = [];
        foreach ( $our_values as $key => $v ) {
            if ( ! isset( $ram_values[ $key ] ) ) {
                $added[] = $v;
            }
        }
        // Shared accounts hold the same data; anything only in another account is worth a look
        $other_only = [];
        $other_accounts = [];
        foreach ( $ram['other'] as $row ) {
            $other_accounts[ $row['account'] ] = true;
            foreach ( $row['values'] as $v ) {
                if ( ! isset( $ram_values[ $v['key'] ] ) && ! isset( $other_only[ $v['key'] ] ) ) {
                    $other_only[ $v['key'] ] = $v + [ 'name' => $row['name'], 'account' => $row['account'] ];
                }
            }
        }

        return [
            'file'           => $file_name,
            'checked_at'     => current_time( 'mysql' ),
            'account'        => $account,
            'ram_rows'       => count( $ram['rows'] ),
            'ram_values'     => count( $ram_values ),
            'ram_count'      => $ram_count,
            'our_rows'       => count( $ours['rows'] ),
            'our_values'     => count( $our_values ),
            'our_cards'      => $built['cards'],
            'lost'           => $lost,
            'added'          => $added,
            'other_accounts' => array_keys( $other_accounts ),
            'other_only'     => array_values( $other_only ),
            'ram_rows_data'  => $ram['rows'],
        ];
    }

    /**
     * Keeps the values of a compare report whose reason is in $reasons as legacy entries,
     * one entry per RAM row.
     *
     * @return int The number of values kept.
     */
    private function capture( array $report, array $reasons, $file_name ) {
        global $wpdb;
        $by_row = [];
        foreach ( $report['lost'] as $v ) {
            if ( 'test' !== $v['reason'] && in_array( $v['reason'], $reasons, true ) ) {
                $by_row[ $v['row'] ][] = $v;
            }
        }

        $kept = 0;
        foreach ( $by_row as $ri => $values ) {
            $row = $report['ram_rows_data'][ $ri ];
            $wpdb->insert( self::ENTRIES_TABLE, [
                'ram_name'    => substr( $row['name'], 0, 30 ),
                'ram_account' => substr( $row['account'], 0, 50 ),
                'h_flag'      => $row['h'],
                'aac'         => substr( $row['aac'], 0, 5 ),
                'phone'       => $row['phone'],
                'is_vendor'   => $row['vendor'],
                'source_file' => substr( $file_name, 0, 255 ),
                'captured_at' => current_time( 'mysql' ),
            ] );
            $entry_id = (int) $wpdb->insert_id;
            if ( ! $entry_id ) {
                error_log( 'FSBHOA DoorKing legacy: could not save the entry for RAM row "' . $row['name'] . '": ' . $wpdb->last_error );
                continue;
            }
            $saved = 0;
            foreach ( $values as $v ) {
                $ok = $wpdb->insert( self::VALUES_TABLE, [
                    'entry_id'   => $entry_id,
                    'value_kind' => $v['kind'],
                    'value'      => $v['value'],
                    'ram_note'   => substr( $v['note'], 0, 100 ),
                    'reason'     => $v['reason'],
                ] );
                if ( $ok ) {
                    $saved++;
                }
            }
            if ( 0 === $saved ) {
                $wpdb->delete( self::ENTRIES_TABLE, [ 'id' => $entry_id ] );
            }
            $kept += $saved;
        }
        if ( $kept > 0 ) {
            error_log( sprintf( 'FSBHOA DoorKing legacy: kept %d value(s) from %s (%s).', $kept, $file_name, implode( ', ', $reasons ) ) );
        }
        return $kept;
    }

    // ------------------------------------------------------------------
    // Read by the export
    // ------------------------------------------------------------------

    /**
     * The legacy entries with their values, in the order they were kept.
     */
    public static function get_export_entries() {
        global $wpdb;
        $suppress = $wpdb->suppress_errors( true );
        $entries  = $wpdb->get_results( "SELECT * FROM " . self::ENTRIES_TABLE . " ORDER BY id ASC" );
        $values   = $wpdb->get_results( "SELECT * FROM " . self::VALUES_TABLE . " ORDER BY id ASC" );
        $wpdb->suppress_errors( $suppress );

        $out = [];
        foreach ( (array) $entries as $e ) {
            $out[ (int) $e->id ] = [ 'entry' => $e, 'values' => [] ];
        }
        foreach ( (array) $values as $v ) {
            if ( isset( $out[ (int) $v->entry_id ] ) ) {
                $out[ (int) $v->entry_id ]['values'][] = $v;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Admin actions
    // ------------------------------------------------------------------

    private function verify( $nonce_action ) {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $nonce_action ) ) {
            wp_die( 'Unauthorized' );
        }
    }

    private function back( $args = [] ) {
        $url = add_query_arg( array_merge( [ 'page' => self::PAGE_SLUG ], array_map( 'rawurlencode', $args ) ), admin_url( 'admin.php' ) );
        wp_safe_redirect( $url );
        exit;
    }

    private function changed() {
        // Fsbhoa_DoorKing_Push rebuilds the export at the end of the request
        do_action( 'fsbhoa_dk_credentials_changed' );
    }

    public function handle_check() {
        $this->verify( 'fsbhoa_dk_legacy_check' );
        if ( empty( $_FILES['ram_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['ram_csv']['tmp_name'] ) ) {
            $this->back( [ 'dk_error' => 'No file uploaded.' ] );
        }
        $file_name = sanitize_file_name( wp_unslash( $_FILES['ram_csv']['name'] ?? 'export.csv' ) );
        $tmp       = $_FILES['ram_csv']['tmp_name'];

        $report = $this->compare( $tmp, $file_name );
        if ( is_wp_error( $report ) ) {
            $this->back( [ 'dk_error' => $report->get_error_message() ] );
        }

        $kept = null;
        if ( isset( $_POST['mode'] ) && 'capture' === $_POST['mode'] ) {
            $reasons = array_intersect(
                array_map( 'sanitize_key', (array) wp_unslash( $_POST['reasons'] ?? [] ) ),
                [ 'missing', 'archived', 'inactive', 'superseded' ]
            );
            if ( empty( $reasons ) ) {
                $this->back( [ 'dk_error' => 'Choose at least one kind of value to keep.' ] );
            }
            $kept = $this->capture( $report, array_values( $reasons ), $file_name );
            if ( $kept > 0 ) {
                $this->changed();
            }
            // Check again, now that the legacy entries are part of the export
            $report = $this->compare( $tmp, $file_name );
            if ( is_wp_error( $report ) ) {
                $this->back( [ 'dk_error' => $report->get_error_message() ] );
            }
        }

        unset( $report['ram_rows_data'] );
        $report['kept'] = $kept;
        set_transient( 'fsbhoa_dk_legacy_report_' . get_current_user_id(), $report, self::REPORT_TTL );
        $this->back( [ 'dk_report' => '1' ] );
    }

    public function handle_remove() {
        $this->verify( 'fsbhoa_dk_legacy_edit' );
        global $wpdb;
        $value_id = absint( $_POST['value_id'] ?? 0 );
        $entry_id = absint( $_POST['entry_id'] ?? 0 );

        if ( $value_id ) {
            $entry_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT entry_id FROM " . self::VALUES_TABLE . " WHERE id = %d", $value_id ) );
            $wpdb->delete( self::VALUES_TABLE, [ 'id' => $value_id ] );
            $this->drop_empty_entry( $entry_id );
            $msg = 'Removed the value. It stops working at the gates at the next push.';
        } elseif ( $entry_id ) {
            $wpdb->delete( self::VALUES_TABLE, [ 'entry_id' => $entry_id ] );
            $wpdb->delete( self::ENTRIES_TABLE, [ 'id' => $entry_id ] );
            $msg = 'Removed the entry. Its values stop working at the gates at the next push.';
        } else {
            $this->back( [ 'dk_error' => 'Nothing to remove.' ] );
        }
        error_log( sprintf( 'FSBHOA DoorKing legacy: %s removed %s %d.', wp_get_current_user()->user_login, $value_id ? 'value' : 'entry', $value_id ?: $entry_id ) );
        $this->changed();
        $this->back( [ 'dk_msg' => $msg ] );
    }

    public function handle_remove_claimed() {
        $this->verify( 'fsbhoa_dk_legacy_edit' );
        global $wpdb;
        $db      = self::database_map();
        $removed = 0;
        foreach ( self::get_export_entries() as $entry_id => $item ) {
            foreach ( $item['values'] as $v ) {
                if ( self::claimed_by( $db, $v ) ) {
                    $wpdb->delete( self::VALUES_TABLE, [ 'id' => (int) $v->id ] );
                    $removed++;
                }
            }
            $this->drop_empty_entry( $entry_id );
        }
        if ( $removed ) {
            error_log( sprintf( 'FSBHOA DoorKing legacy: %s removed %d value(s) now held by cardholders.', wp_get_current_user()->user_login, $removed ) );
            $this->changed();
        }
        $this->back( [ 'dk_msg' => sprintf( 'Removed %d value(s) that now belong to cardholders.', $removed ) ] );
    }

    public function handle_note() {
        $this->verify( 'fsbhoa_dk_legacy_edit' );
        global $wpdb;
        $entry_id = absint( $_POST['entry_id'] ?? 0 );
        $note     = substr( sanitize_text_field( wp_unslash( $_POST['admin_note'] ?? '' ) ), 0, 255 );
        $wpdb->update( self::ENTRIES_TABLE, [ 'admin_note' => $note ], [ 'id' => $entry_id ] );
        $this->back( [ 'dk_msg' => 'Note saved.' ] );
    }

    /**
     * Moves a legacy value onto a cardholder as a real credential and removes it from here.
     */
    public function handle_assign() {
        $this->verify( 'fsbhoa_dk_legacy_edit' );
        global $wpdb;

        $value_id = absint( $_POST['value_id'] ?? 0 );
        $choice   = sanitize_text_field( wp_unslash( $_POST['cardholder'] ?? '' ) );
        if ( ! preg_match( '/#(\d+)\s*$/', $choice, $m ) ) {
            $this->back( [ 'dk_error' => 'Choose a cardholder from the list (the entry ends with #id).' ] );
        }
        $cardholder = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, first_name, last_name, company, household_id, cardholder_type, cardholder_status FROM ac_cardholders WHERE id = %d",
            (int) $m[1]
        ) );
        if ( ! $cardholder || 'active' !== $cardholder->cardholder_status ) {
            $this->back( [ 'dk_error' => 'That cardholder was not found or is not current.' ] );
        }
        $v = $wpdb->get_row( $wpdb->prepare(
            "SELECT v.*, e.ram_name FROM " . self::VALUES_TABLE . " v JOIN " . self::ENTRIES_TABLE . " e ON e.id = v.entry_id WHERE v.id = %d",
            $value_id
        ) );
        if ( ! $v ) {
            $this->back( [ 'dk_error' => 'That legacy value no longer exists.' ] );
        }

        // What the value becomes: a device 0xxxx is gate code xxxx (the export's convention)
        if ( 'dir' === $v->value_kind ) {
            $type  = 'DK_DIR_CODE';
            $value = $v->value;
        } elseif ( 'ent' === $v->value_kind ) {
            $type  = 'DK_ENTRY_CODE';
            $value = $v->value;
        } elseif ( '0' === $v->value[0] ) {
            $type  = 'DK_ENTRY_CODE';
            $value = substr( $v->value, 1 );
        } else {
            $type  = 'DK_WINDSHIELD';
            $value = $v->value;
        }
        $label = self::cardholder_label( $cardholder );

        $db     = self::database_map();
        $holder = self::claimed_by( $db, $v );
        if ( $holder ) {
            $this->back( [ 'dk_error' => sprintf( '%s %s already belongs to %s. Remove the legacy value instead.', self::KIND_LABELS[ $v->value_kind ], $v->value, $holder ) ] );
        }

        // The cardholder screen holds one directory code and one gate code per person
        if ( 'DK_WINDSHIELD' !== $type ) {
            $has = $wpdb->get_var( $wpdb->prepare(
                "SELECT credential_value FROM ac_credentials WHERE cardholder_id = %d AND credential_type = %s AND credential_value != '' LIMIT 1",
                $cardholder->id, $type
            ) );
            if ( null !== $has ) {
                $this->back( [ 'dk_error' => sprintf( '%s already has %s %s. Change it on the cardholder screen, or assign this one to another household member.', $label, ( 'DK_DIR_CODE' === $type ) ? 'directory code' : 'gate code', $has ) ] );
            }
        }

        // A resident's windshield tag goes on a vehicle in their household, so the
        // cardholder screen shows it. A vendor's has none (vehicles need a household).
        $vehicle_id = null;
        if ( 'DK_WINDSHIELD' === $type && 'resident' === $cardholder->cardholder_type ) {
            $household_id = (int) $cardholder->household_id;
            if ( ! $household_id ) {
                $wpdb->insert( 'ac_households', [ 'household_name' => trim( (string) $cardholder->last_name ) . ' Household' ] );
                $household_id = (int) $wpdb->insert_id;
                if ( ! $household_id ) {
                    $this->back( [ 'dk_error' => 'Could not create a household for ' . $label . ': ' . $wpdb->last_error ] );
                }
                $wpdb->update( 'ac_cardholders', [ 'household_id' => $household_id ], [ 'id' => (int) $cardholder->id ] );
            }
            $wpdb->insert( 'ac_vehicles', [
                'household_id' => $household_id,
                'make'         => substr( '' !== $v->ram_note ? $v->ram_note : 'From RAM: ' . $v->ram_name, 0, 50 ),
            ] );
            $vehicle_id = (int) $wpdb->insert_id;
            if ( ! $vehicle_id ) {
                $this->back( [ 'dk_error' => 'Could not create the vehicle: ' . $wpdb->last_error ] );
            }
        }

        $ok = $wpdb->insert( 'ac_credentials', [
            'cardholder_id'    => (int) $cardholder->id,
            'vehicle_id'       => $vehicle_id,
            'credential_type'  => $type,
            'credential_value' => $value,
            'status'           => 'active',
            'issue_date'       => current_time( 'Y-m-d' ),
            'expiration_date'  => '2099-12-31',
            'notes'            => 'From RAM entry "' . $v->ram_name . '"' . ( '' !== $v->ram_note ? ': ' . $v->ram_note : '' ),
        ] );
        if ( ! $ok ) {
            $this->back( [ 'dk_error' => 'Could not save the credential: ' . $wpdb->last_error ] );
        }

        $wpdb->delete( self::VALUES_TABLE, [ 'id' => (int) $v->id ] );
        $this->drop_empty_entry( (int) $v->entry_id );
        error_log( sprintf( 'FSBHOA DoorKing legacy: %s assigned %s %s (RAM "%s") to cardholder %d.', wp_get_current_user()->user_login, $type, $value, $v->ram_name, $cardholder->id ) );
        $this->changed();

        $msg = sprintf( 'Assigned %s %s to %s.', ( 'DK_WINDSHIELD' === $type ) ? 'windshield tag' : ( ( 'DK_DIR_CODE' === $type ) ? 'directory code' : 'gate code' ), $value, $label );
        if ( $vehicle_id ) {
            $msg .= ' A vehicle was added to the household for it; fill in its details on the cardholder screen.';
        }
        $this->back( [ 'dk_msg' => $msg ] );
    }

    private function drop_empty_entry( $entry_id ) {
        global $wpdb;
        if ( $entry_id && ! $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM " . self::VALUES_TABLE . " WHERE entry_id = %d LIMIT 1", $entry_id ) ) ) {
            $wpdb->delete( self::ENTRIES_TABLE, [ 'id' => $entry_id ] );
        }
    }

    /**
     * Who now holds a legacy value as a valid credential of a current cardholder ('' if nobody).
     * The export then sends it for them, not from the legacy entry.
     */
    private static function claimed_by( $db, $v ) {
        foreach ( $db[ self::key( $v->value_kind, $v->value ) ] ?? [] as $h ) {
            if ( $h['current'] && $h['valid'] ) {
                return $h['name'] . ' #' . $h['id'];
            }
        }
        return '';
    }

    /**
     * The latest vehicle-log record of each legacy value, from core's vehicle log.
     */
    private static function last_used( array $entries ) {
        global $wpdb;
        $wanted = [];
        foreach ( $entries as $item ) {
            foreach ( $item['values'] as $v ) {
                if ( 'dir' === $v->value_kind ) {
                    $wanted[ 'DK_DIR_CODE|' . $v->value ] = true;
                } elseif ( 'ent' === $v->value_kind ) {
                    $wanted[ 'DK_ENTRY_CODE|' . $v->value ] = true;
                } else {
                    $wanted[ 'DK_WINDSHIELD|' . $v->value ] = true;
                    if ( '0' === $v->value[0] ) {
                        $wanted[ 'DK_ENTRY_CODE|' . substr( $v->value, 1 ) ] = true;
                    }
                }
            }
        }
        $out = [ 'since' => null, 'seen' => [] ];
        if ( empty( $wanted ) ) {
            return $out;
        }
        $ids = array_unique( array_map( function ( $k ) { return explode( '|', $k )[1]; }, array_keys( $wanted ) ) );
        $in  = implode( ',', array_map( function ( $id ) use ( $wpdb ) { return $wpdb->prepare( '%s', $id ); }, $ids ) );

        $suppress = $wpdb->suppress_errors( true );
        $rows = $wpdb->get_results( "
            SELECT auth_type, auth_id, event_timestamp, gate_identifier, lpr_plate_string
            FROM ac_vehicle_log
            WHERE auth_type IN ('DK_DIR_CODE', 'DK_ENTRY_CODE', 'DK_WINDSHIELD') AND auth_id IN ($in)
            ORDER BY event_timestamp DESC
        " );
        $out['since'] = $wpdb->get_var( "SELECT MIN(event_timestamp) FROM ac_vehicle_log" );
        $wpdb->suppress_errors( $suppress );

        foreach ( (array) $rows as $r ) {
            $k = $r->auth_type . '|' . $r->auth_id;
            if ( ! isset( $wanted[ $k ] ) ) {
                continue;
            }
            if ( ! isset( $out['seen'][ $k ] ) ) {
                $out['seen'][ $k ] = [ 'last' => $r->event_timestamp, 'gate' => $r->gate_identifier, 'plate' => $r->lpr_plate_string, 'count' => 0 ];
            }
            $out['seen'][ $k ]['count']++;
        }
        return $out;
    }

    private static function last_used_for( $used, $v ) {
        $keys = [];
        if ( 'dir' === $v->value_kind ) {
            $keys[] = 'DK_DIR_CODE|' . $v->value;
        } elseif ( 'ent' === $v->value_kind ) {
            $keys[] = 'DK_ENTRY_CODE|' . $v->value;
        } else {
            $keys[] = 'DK_WINDSHIELD|' . $v->value;
            if ( '0' === $v->value[0] ) {
                $keys[] = 'DK_ENTRY_CODE|' . substr( $v->value, 1 );
            }
        }
        $best = null;
        foreach ( $keys as $k ) {
            if ( isset( $used['seen'][ $k ] ) && ( ! $best || $used['seen'][ $k ]['last'] > $best['last'] ) ) {
                $best = $used['seen'][ $k ];
            }
        }
        return $best;
    }

    // ------------------------------------------------------------------
    // Page
    // ------------------------------------------------------------------

    public function add_submenu( $parent_slug ) {
        add_submenu_page( $parent_slug, 'DoorKing Legacy Entries', 'DoorKing Legacy', 'manage_options', self::PAGE_SLUG, [ $this, 'render_page' ], 18 );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>DoorKing Legacy Entries</h1>
            <p style="max-width: 900px;">Tags and codes RAM holds that the importers didn't place on a cardholder. Every export to RAM replaces all of RAM's data, so these are sent too, as RAM had them, after the cardholders, until you assign each one to its cardholder or remove it. Nothing here expires on its own.</p>

            <?php
            if ( isset( $_GET['dk_msg'] ) ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( wp_unslash( $_GET['dk_msg'] ) ) . '</p></div>';
            }
            if ( isset( $_GET['dk_error'] ) ) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( wp_unslash( $_GET['dk_error'] ) ) . '</p></div>';
            }

            $report = get_transient( 'fsbhoa_dk_legacy_report_' . get_current_user_id() );
            if ( is_array( $report ) ) {
                $this->render_report( $report );
            }

            $this->render_check_form();
            $this->render_entries();
            ?>
        </div>
        <?php
    }

    private function render_check_form() {
        ?>
        <h2>Check a RAM export</h2>
        <div style="max-width: 900px;">
            <p>Export RAM's data as CSV (in RAM: File → Export) and upload it here. The check lists every tag and code in that file that the next push would <strong>not</strong> send, and why. It reads the rows of the account <code><?php echo esc_html( get_option( 'fsbhoa_dk_account_name', '' ) ); ?></code> (DoorKing settings); other accounts in the file are only compared against it.</p>
            <ol>
                <li>Run the resident and vendor imports (DoorKing Backfill) with the same file.</li>
                <li><strong>Check only</strong>: review the list.</li>
                <li><strong>Keep</strong> the values you don't want lost. They become the entries below, and the page checks the file again: what's still listed is what you chose to let go.</li>
                <li>After the first push, export RAM again and check that file: nothing should be listed as not sent or as new. That confirms RAM holds exactly what the system sends.</li>
            </ol>
        </div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="padding: 16px 20px; background: #fff; border: 1px solid #ccc; max-width: 860px;">
            <input type="hidden" name="action" value="fsbhoa_dk_legacy_check">
            <?php wp_nonce_field( 'fsbhoa_dk_legacy_check' ); ?>
            <p><label><strong>RAM export (CSV)</strong><br><input type="file" name="ram_csv" accept=".csv" required></label></p>
            <p>
                <label style="margin-right: 18px;"><input type="radio" name="mode" value="compare" checked> <strong>Check only</strong> (changes nothing)</label>
                <label><input type="radio" name="mode" value="capture"> <strong>Keep</strong> the values below as legacy entries, then check again</label>
            </p>
            <fieldset style="margin-left: 20px;">
                <?php foreach ( [ 'missing', 'archived', 'inactive', 'superseded' ] as $reason ) : ?>
                    <label style="display: block; margin-bottom: 4px;">
                        <input type="checkbox" name="reasons[]" value="<?php echo esc_attr( $reason ); ?>" <?php checked( 'missing', $reason ); ?>>
                        <?php echo esc_html( self::REASONS[ $reason ] ); ?>
                    </label>
                <?php endforeach; ?>
                <p class="description">The database's own decisions (archived cardholders, disabled credentials) are left off by default: keeping them would undo those decisions at the gates.</p>
            </fieldset>
            <p class="submit" style="margin-bottom: 0;"><button type="submit" class="button button-primary">Check file</button></p>
        </form>
        <?php
    }

    private function render_report( array $r ) {
        $by_reason = [];
        foreach ( $r['lost'] as $v ) {
            $by_reason[ $v['reason'] ][] = $v;
        }
        $real_lost = count( $r['lost'] ) - count( $by_reason['test'] ?? [] );
        $ok        = 0 === $real_lost && empty( $r['added'] ) && empty( $r['other_only'] );
        ?>
        <div style="background: #fff; border: 1px solid #ccc; border-left: 4px solid <?php echo $ok ? '#00a32a' : ( $real_lost ? '#d63638' : '#dba617' ); ?>; padding: 12px 20px; margin: 16px 0; max-width: 1100px;">
            <h2 style="margin-top: 4px;">Check of <?php echo esc_html( $r['file'] ); ?> <span style="font-weight: normal; font-size: 13px;">(<?php echo esc_html( $r['checked_at'] ); ?>)</span></h2>
            <?php if ( null !== $r['kept'] ) : ?>
                <p><strong>Kept <?php echo (int) $r['kept']; ?> value(s) as legacy entries.</strong> The check below was run again afterwards.</p>
            <?php endif; ?>
            <p>
                RAM (account <code><?php echo esc_html( $r['account'] ); ?></code>): <?php echo (int) $r['ram_rows']; ?> rows, <?php echo (int) $r['ram_values']; ?> different tags and codes.<br>
                Next push: <?php echo (int) $r['our_rows']; ?> rows, <?php echo (int) $r['our_values']; ?> different tags and codes (<?php echo (int) $r['our_cards']; ?> of the controller's <?php echo (int) Fsbhoa_DoorKing_Export::MAX_CARDS; ?> card slots).
            </p>
            <?php if ( $ok ) : ?>
                <p style="color: #00a32a; font-weight: 600;">Every tag and code in this file would be sent, and nothing else.</p>
            <?php elseif ( 0 === $real_lost ) : ?>
                <p style="color: #00a32a; font-weight: 600;">Every tag and code in this file would be sent.</p>
            <?php else : ?>
                <p style="color: #d63638; font-weight: 600;"><?php echo (int) $real_lost; ?> tag(s) and code(s) in this file would stop working at the next push.</p>
            <?php endif; ?>

            <?php foreach ( self::REASONS as $reason => $label ) :
                if ( empty( $by_reason[ $reason ] ) ) {
                    continue;
                }
                ?>
                <details <?php echo ( 'test' === $reason ) ? '' : 'open'; ?> style="margin-bottom: 10px;">
                    <summary><strong>Not sent: <?php echo esc_html( $label ); ?> (<?php echo count( $by_reason[ $reason ] ); ?>)</strong></summary>
                    <table class="widefat striped" style="margin-top: 6px;">
                        <thead><tr><th>RAM name</th><th>Kind</th><th>Value</th><th>RAM note</th><th>In the database</th></tr></thead>
                        <tbody>
                        <?php foreach ( $by_reason[ $reason ] as $v ) : ?>
                            <tr>
                                <td><?php echo esc_html( $v['name'] ); ?></td>
                                <td><?php echo esc_html( self::KIND_LABELS[ $v['kind'] ] ); ?></td>
                                <td><code><?php echo esc_html( $v['value'] ); ?></code></td>
                                <td><?php echo esc_html( $v['note'] ); ?></td>
                                <td><?php echo esc_html( $v['holders'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endforeach; ?>

            <?php if ( ! empty( $r['added'] ) ) : ?>
                <details style="margin-bottom: 10px;">
                    <summary><strong>New: sent at the next push, not in this file (<?php echo count( $r['added'] ); ?>)</strong></summary>
                    <p class="description">Tags and codes entered in this system since the file was exported, or that RAM didn't have.</p>
                    <table class="widefat striped">
                        <thead><tr><th>Row in the export</th><th>Kind</th><th>Value</th></tr></thead>
                        <tbody>
                        <?php foreach ( $r['added'] as $v ) : ?>
                            <tr><td><?php echo esc_html( $v['name'] ); ?></td><td><?php echo esc_html( self::KIND_LABELS[ $v['kind'] ] ); ?></td><td><code><?php echo esc_html( $v['value'] ); ?></code></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>

            <?php if ( ! empty( $r['other_accounts'] ) ) : ?>
                <p>Other accounts in the file: <?php echo esc_html( implode( ', ', $r['other_accounts'] ) ); ?>.
                <?php if ( empty( $r['other_only'] ) ) : ?>
                    They hold nothing that <code><?php echo esc_html( $r['account'] ); ?></code> doesn't (shared accounts, as expected).</p>
                <?php else : ?>
                    </p>
                    <details open>
                        <summary><strong style="color: #d63638;">Only in other accounts (<?php echo count( $r['other_only'] ); ?>)</strong></summary>
                        <p class="description">The export sends one account's data to every account, so these would be lost. Shared accounts should have none: check RAM's account settings.</p>
                        <table class="widefat striped">
                            <thead><tr><th>Account</th><th>RAM name</th><th>Kind</th><th>Value</th><th>RAM note</th></tr></thead>
                            <tbody>
                            <?php foreach ( $r['other_only'] as $v ) : ?>
                                <tr><td><?php echo esc_html( $v['account'] ); ?></td><td><?php echo esc_html( $v['name'] ); ?></td><td><?php echo esc_html( self::KIND_LABELS[ $v['kind'] ] ); ?></td><td><code><?php echo esc_html( $v['value'] ); ?></code></td><td><?php echo esc_html( $v['note'] ); ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_entries() {
        global $wpdb;
        $entries = self::get_export_entries();
        $db      = self::database_map();
        $used    = self::last_used( $entries );

        $value_count   = 0;
        $claimed_count = 0;
        foreach ( $entries as $item ) {
            foreach ( $item['values'] as $v ) {
                $value_count++;
                if ( self::claimed_by( $db, $v ) ) {
                    $claimed_count++;
                }
            }
        }
        ?>
        <h2 style="margin-top: 30px;">Legacy entries (<?php echo count( $entries ); ?> entries, <?php echo (int) $value_count; ?> values)</h2>
        <?php if ( empty( $entries ) ) : ?>
            <p>None.</p>
            <?php
            return;
        endif;

        $cardholders = $wpdb->get_results( "
            SELECT id, first_name, last_name, company, cardholder_type
            FROM ac_cardholders
            WHERE cardholder_status = 'active'
            ORDER BY cardholder_type ASC, last_name ASC, first_name ASC
        " );
        ?>
        <datalist id="fsbhoa-dk-cardholders">
            <?php foreach ( (array) $cardholders as $c ) : ?>
                <option value="<?php echo esc_attr( self::cardholder_label( $c ) . ( 'vendor' === $c->cardholder_type ? ' [vendor]' : '' ) . ' #' . (int) $c->id ); ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <p style="max-width: 900px;">
            <strong>Assign</strong> moves a value onto a cardholder as their credential (a resident's windshield tag gets a new vehicle in their household).
            <strong>Remove</strong> stops it working at the gates at the next push. Either change shows on the "pending changes" banner.
            <?php if ( $used['since'] ) : ?>
                <em>Last used</em> is from the vehicle log, which starts <?php echo esc_html( substr( $used['since'], 0, 10 ) ); ?>.
            <?php endif; ?>
        </p>
        <?php if ( $claimed_count ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 12px;">
                <input type="hidden" name="action" value="fsbhoa_dk_legacy_remove_claimed">
                <?php wp_nonce_field( 'fsbhoa_dk_legacy_edit' ); ?>
                <button type="submit" class="button"><?php echo esc_html( sprintf( 'Remove the %d value(s) that now belong to cardholders', $claimed_count ) ); ?></button>
            </form>
        <?php endif; ?>

        <table class="widefat" style="max-width: 1400px;">
            <thead>
                <tr><th>RAM entry</th><th>Kind</th><th>Value</th><th>RAM note</th><th>Kept because</th><th>Last used</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ( $entries as $entry_id => $item ) :
                $e    = $item['entry'];
                $rows = max( 1, count( $item['values'] ) );
                foreach ( $item['values'] as $i => $v ) :
                    $holder = self::claimed_by( $db, $v );
                    $seen   = self::last_used_for( $used, $v );
                    ?>
                    <tr style="<?php echo 0 === $i ? 'border-top: 2px solid #c3c4c7;' : ''; ?>">
                        <?php if ( 0 === $i ) : ?>
                            <td rowspan="<?php echo (int) $rows; ?>" style="vertical-align: top; background: #f6f7f7; min-width: 220px;">
                                <strong><?php echo esc_html( '' !== $e->ram_name ? $e->ram_name : '(no name)' ); ?></strong>
                                <?php echo 'Y' === $e->is_vendor ? ' <em>vendor</em>' : ''; ?><br>
                                <?php if ( '' !== $e->phone ) : ?>
                                    <?php echo esc_html( ( '' !== $e->aac ? '(' . $e->aac . ') ' : '' ) . $e->phone ); ?><?php echo 'N' === $e->h_flag ? ' · listed in the directory' : ''; ?><br>
                                <?php endif; ?>
                                <span class="description">Kept <?php echo esc_html( substr( $e->captured_at, 0, 10 ) ); ?> from <?php echo esc_html( $e->source_file ); ?></span>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 6px;">
                                    <input type="hidden" name="action" value="fsbhoa_dk_legacy_note">
                                    <input type="hidden" name="entry_id" value="<?php echo (int) $entry_id; ?>">
                                    <?php wp_nonce_field( 'fsbhoa_dk_legacy_edit' ); ?>
                                    <input type="text" name="admin_note" value="<?php echo esc_attr( $e->admin_note ); ?>" placeholder="Your note" style="width: 150px;">
                                    <button type="submit" class="button button-small">Save</button>
                                </form>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 6px;" onsubmit="return confirm('Remove every value of this entry? They stop working at the gates at the next push.');">
                                    <input type="hidden" name="action" value="fsbhoa_dk_legacy_remove">
                                    <input type="hidden" name="entry_id" value="<?php echo (int) $entry_id; ?>">
                                    <?php wp_nonce_field( 'fsbhoa_dk_legacy_edit' ); ?>
                                    <button type="submit" class="button button-small button-link-delete">Remove entry</button>
                                </form>
                            </td>
                        <?php endif; ?>
                        <td><?php echo esc_html( self::KIND_LABELS[ $v->value_kind ] ?? $v->value_kind ); ?></td>
                        <td><code><?php echo esc_html( $v->value ); ?></code></td>
                        <td><?php echo esc_html( $v->ram_note ); ?></td>
                        <td><?php echo esc_html( self::REASONS[ $v->reason ] ?? $v->reason ); ?></td>
                        <td>
                            <?php if ( $seen ) : ?>
                                <?php echo esc_html( substr( $seen['last'], 0, 16 ) . ' · ' . $seen['gate'] ); ?>
                                <?php echo '' !== (string) $seen['plate'] ? '<br>plate <code>' . esc_html( $seen['plate'] ) . '</code>' : ''; ?>
                                <br><span class="description"><?php echo (int) $seen['count']; ?> time(s)</span>
                            <?php else : ?>
                                <span class="description">Not seen</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $holder ) : ?>
                                <span style="color: #2271b1;">Now belongs to <?php echo esc_html( $holder ); ?>; sent for them</span>
                            <?php else : ?>
                                <span style="color: #d63638;">Sent from here</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space: nowrap;">
                            <?php if ( ! $holder ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block;">
                                    <input type="hidden" name="action" value="fsbhoa_dk_legacy_assign">
                                    <input type="hidden" name="value_id" value="<?php echo (int) $v->id; ?>">
                                    <?php wp_nonce_field( 'fsbhoa_dk_legacy_edit' ); ?>
                                    <input type="text" name="cardholder" list="fsbhoa-dk-cardholders" placeholder="Cardholder…" style="width: 200px;" required>
                                    <button type="submit" class="button button-small">Assign</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block;" onsubmit="return confirm('Remove <?php echo esc_js( $v->value ); ?>? It stops working at the gates at the next push.');">
                                <input type="hidden" name="action" value="fsbhoa_dk_legacy_remove">
                                <input type="hidden" name="value_id" value="<?php echo (int) $v->id; ?>">
                                <?php wp_nonce_field( 'fsbhoa_dk_legacy_edit' ); ?>
                                <button type="submit" class="button button-small button-link-delete">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach;
            endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
