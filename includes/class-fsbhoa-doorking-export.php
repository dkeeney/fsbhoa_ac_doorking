<?php
/**
 * Generates DoorKing RAM import CSV payloads and handles sync triggers.
 */
if ( ! defined( 'ABSPATH' ) ) {
    die;
}

class Fsbhoa_DoorKing_Export {
    // File names must match ram_automation/DK_Sync.ahk (CsvFile / FlagFile).
    // Each environment gets its own folder: To_RAM_Testbed, To_RAM_Production.
    const SYNC_BASE_DIR  = '/mnt/shared/Automation/DoorKing';
    const CSV_FILE_NAME  = 'updates.csv';
    const FLAG_FILE_NAME = 'import_now.flag';
    const ENVIRONMENTS   = [ 'testbed', 'production' ];

    // DoorKing 1838-010 Rev AB card memory limit
    const MAX_CARDS = 3000;

    private $global_devices = [];
    private $global_pins    = [];
    private $global_names   = [];

    public function __construct() {
        add_action( 'fsbhoa_doorking_trigger_export', [ $this, 'generate_export' ] );
        add_action( 'wp_ajax_fsbhoa_dk_generate_csv', [ $this, 'ajax_manual_export' ] );
        add_action( 'fsbhoa_push_to_controllers', [ $this, 'generate_export' ] );
        add_action( 'fsbhoa_dk_daily_midnight_export', [ $this, 'generate_export' ] );

        $this->ensure_cron_scheduled();
    }

    /**
     * Returns this server's environment from FSBHOA_AC_ENVIRONMENT in wp-config.php,
     * or '' if it is missing or not recognized. Deliberately not a WP option, so it
     * cannot travel with a copied database.
     */
    public static function get_environment() {
        $env = defined( 'FSBHOA_AC_ENVIRONMENT' ) ? strtolower( trim( (string) FSBHOA_AC_ENVIRONMENT ) ) : '';
        return in_array( $env, self::ENVIRONMENTS, true ) ? $env : '';
    }

    public static function default_sync_dir() {
        $env = self::get_environment();
        return self::SYNC_BASE_DIR . '/To_RAM_' . ( $env ? ucfirst( $env ) : 'Unconfigured' );
    }

    public static function default_csv_path() {
        return self::default_sync_dir() . '/' . self::CSV_FILE_NAME;
    }

    public static function default_flag_path() {
        return self::default_sync_dir() . '/' . self::FLAG_FILE_NAME;
    }

    public function ensure_cron_scheduled() {
        if ( ! wp_next_scheduled( 'fsbhoa_dk_daily_midnight_export' ) ) {
            $midnight = strtotime( 'tomorrow midnight' ) + ( 5 * MINUTE_IN_SECONDS );
            wp_schedule_event( $midnight, 'daily', 'fsbhoa_dk_daily_midnight_export' );
        }
    }

    public function ajax_manual_export() {
        check_ajax_referer( 'fsbhoa_dk_settings_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $result = $this->generate_export();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( 'DoorKing sync files written successfully.' );
    }

    public function generate_export() {
        global $wpdb;
        $this->global_devices = [];
        $this->global_pins    = [];
        $this->global_names   = [];

        $csv_path    = get_option( 'fsbhoa_dk_csv_path', self::default_csv_path() );
        $lock_path   = get_option( 'fsbhoa_dk_lock_path', self::default_flag_path() );

        // Environment guard: never write a RAM trigger unless this server knows what it is,
        // and only into a folder named for that environment.
        $env = self::get_environment();
        if ( '' === $env ) {
            $message = 'DoorKing export aborted: FSBHOA_AC_ENVIRONMENT is not defined in wp-config.php (expected one of: ' . implode( ', ', self::ENVIRONMENTS ) . ').';
            error_log( $message );
            return new WP_Error( 'environment_not_set', $message );
        }
        foreach ( [ $csv_path, $lock_path ] as $path ) {
            if ( false === stripos( basename( dirname( $path ) ), $env ) ) {
                $message = sprintf( 'DoorKing export aborted: %s is not in a folder named for the "%s" environment.', $path, $env );
                error_log( $message );
                return new WP_Error( 'environment_path_mismatch', $message );
            }
        }
        $raw_sec    = get_option( 'fsbhoa_dk_security_level', '01' );
        $sec_level  = str_pad( ! empty( $raw_sec ) ? $raw_sec : '01', 2, '0', STR_PAD_LEFT );
        $account     = get_option( 'fsbhoa_dk_account_name', 'SOUTH GATES' );
        $ram_version = get_option( 'fsbhoa_dk_ram_version', '65.b' ); // '63.h' or '65.b'

        $target_dir = dirname( $csv_path );
        if ( ! is_dir( $target_dir ) ) {
            if ( ! wp_mkdir_p( $target_dir ) ) {
                return new WP_Error( 'dir_error', 'Cannot create export directory: ' . $target_dir );
            }
        }

        $tmp_file = $csv_path . '.tmp';
        $handle   = fopen( $tmp_file, 'w' );
        if ( ! $handle ) {
            return new WP_Error( 'file_error', 'Cannot open temp file for writing: ' . $tmp_file );
        }

        // 1. Build Header: RAM 63.h expects 'NAME', 65.b expects 'Resident'
        $ram_version = get_option( 'fsbhoa_dk_ram_version', '65.b' );
        $name_col    = ( '63.h' === strtolower( trim( $ram_version ) ) ) ? 'NAME' : 'Resident';

        $headers = [ 'ACCOUNT', $name_col, 'H', 'AAC', 'PHONE', 'DIR', 'ENT', 'SL', 'DEVICE#', 'FL', 'ER', 'NOTES', 'VENDOR' ];

        for ( $i = 2; $i <= 26; $i++ ) {
            $headers[] = 'DEVICE' . $i;
            $headers[] = 'NOTES' . $i;
        }
        $this->write_csv_row( $handle, $headers );

        // 2. Export Households (Residents)
        $this->export_households( $handle, $account, $sec_level );

        // 3. Export Vendors
        $this->export_vendors( $handle, $account, $sec_level );

        fclose( $handle );

        // Refuse to publish an export the controllers cannot hold
        $card_count = count( $this->global_devices );
        if ( $card_count > self::MAX_CARDS ) {
            unlink( $tmp_file );
            $message = sprintf( 'DoorKing export aborted: %d cards exceeds the controller limit of %d.', $card_count, self::MAX_CARDS );
            error_log( $message );
            return new WP_Error( 'card_limit_exceeded', $message );
        }

        // Atomic swap
        if ( ! rename( $tmp_file, $csv_path ) ) {
            return new WP_Error( 'rename_error', 'Failed to replace ' . $csv_path );
        }

        // Drop the trigger file for AutoHotKey. DK_Sync.ahk rejects it unless env and host
        // match the RAM PC's local dk_sync.ini.
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        file_put_contents( $lock_path, "env={$env}\r\nhost={$host}\r\ntime=" . time() . "\r\n" );

        return true;
    }

    /**
     * Consolidates cardholders by household so each home has one DoorKing RAM record.
     */
    private function export_households( $handle, $account, $sec_level ) {
        global $wpdb;
        $local_area_code = get_option( 'fsbhoa_dk_local_area_code', '661' );

        $residents = $wpdb->get_results( "
            SELECT c.id, c.household_id, c.last_name, c.first_name, c.phone
            FROM ac_cardholders c
            WHERE c.cardholder_type = 'resident'
              AND c.cardholder_status = 'active'
            ORDER BY COALESCE(c.household_id, c.id), c.id ASC
        " );

        if ( empty( $residents ) ) return;

        $households = [];
        foreach ( $residents as $res ) {
            $key = ! empty( $res->household_id ) ? 'HH_' . $res->household_id : 'CH_' . $res->id;
            $households[ $key ][] = $res;
        }

        foreach ( $households as $group ) {
            $primary = $group[0];
            $ch_ids  = wp_list_pluck( $group, 'id' );
            $id_list = implode( ',', array_map( 'intval', $ch_ids ) );

            // -- NAME GENERATION (This was missing!) --
            $last_name     = trim( $primary->last_name ?? '' );
            $first_name    = trim( $primary->first_name ?? '' );
            if ( ! empty( $last_name ) || ! empty( $first_name ) ) {
                $combined_name = $last_name ? ( $last_name . ( $first_name ? ', ' . $first_name : '' ) ) : $first_name;
            } else {
                $combined_name = 'Unknown Resident';
            }
            $row_name = substr( strtoupper( $combined_name ), 0, 15 );

            $creds = $wpdb->get_results( "
                SELECT cr.credential_type, cr.credential_value
                FROM ac_credentials cr
                WHERE cr.cardholder_id IN ($id_list)
                  AND cr.status IN ('active', 'valid')
                ORDER BY cr.id ASC
            " );

            if ( ! empty( $primary->household_id ) ) {
                $veh_creds = $wpdb->get_results( $wpdb->prepare( "
                    SELECT cr.credential_type, cr.credential_value
                    FROM ac_credentials cr
                    JOIN ac_vehicles v ON cr.vehicle_id = v.vehicle_id
                    JOIN ac_cardholders c ON c.id = cr.cardholder_id AND c.cardholder_status = 'active'
                    WHERE v.household_id = %d
                      AND cr.status IN ('active', 'valid')
                    ORDER BY cr.id ASC
                ", (int) $primary->household_id ) );
                if ( ! empty( $veh_creds ) ) {
                    $creds = array_merge( $creds ?: [], $veh_creds );
                }
            }

            $dir_code    = '';
            $opt_in      = false;
            $gate_codes  = [];
            $device_list = [];

            if ( ! empty( $creds ) ) {
                foreach ( $creds as $cred ) {
                    $type = strtoupper( trim( $cred->credential_type ) );
                    $val  = trim( $cred->credential_value );
                    if ( '' === $val ) continue;

                    if ( 'DK_DIR_CODE' === $type ) {
                        if ( empty( $dir_code ) ) $dir_code = str_pad( $val, 4, '0', STR_PAD_LEFT );
                    } elseif ( 'DK_ENTRY_CODE' === $type ) {
                        $clean_gate = str_pad( $val, 4, '0', STR_PAD_LEFT );
                        if ( ! isset( $this->global_pins[ $clean_gate ] ) ) {
                            $this->global_pins[ $clean_gate ] = true;
                            $gate_codes[] = $clean_gate;
                        }
                    } elseif ( 'DK_DIR_OPT_IN' === $type ) {
                        if ( '1' === (string) $val || 'Y' === strtoupper( (string) $val ) ) $opt_in = true;
                    } elseif ( in_array( $type, [ 'DK_WINDSHIELD', 'RFID', 'WIEGAND_26' ], true ) ) {
                        $clean_dev = str_pad( substr( $val, -5 ), 5, '0', STR_PAD_LEFT );
                        if ( ! isset( $this->global_devices[ $clean_dev ] ) ) {
                            $this->global_devices[ $clean_dev ] = true;
                            $device_list[] = $clean_dev;
                        }
                    }
                }
            }

            $ent_val = '';
            if ( ! empty( $gate_codes ) ) {
                $ent_val = array_shift( $gate_codes );
                foreach ( $gate_codes as $extra_code ) {
                    $pad_code = '0' . $extra_code;
                    if ( ! isset( $this->global_devices[ $pad_code ] ) ) {
                        $this->global_devices[ $pad_code ] = true;
                        $device_list[] = $pad_code;
                    }
                }
            }

            $h_val = $opt_in ? 'N' : 'Y';
            $aac   = '';
            $phone = '';
            if ( ! empty( $primary->phone ) ) {
                $digits = preg_replace( '/[^0-9]/', '', $primary->phone );
                if ( 10 === strlen( $digits ) ) {
                    $area = substr( $digits, 0, 3 );
                    if ( $area !== $local_area_code ) $aac = $area;
                    $phone = substr( $digits, 3, 3 ) . '-' . substr( $digits, 6, 4 );
                } elseif ( 7 === strlen( $digits ) ) {
                    $phone = substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 4 );
                }
            }

            if ( 'Y' === $h_val && empty( $dir_code ) && empty( $ent_val ) && empty( $device_list ) ) {
                continue;
            }

            $chunks = ! empty( $device_list ) ? array_chunk( $device_list, 26 ) : [ [] ];
            foreach ( $chunks as $idx => $chunk ) {
                $chunk_name = $this->get_unique_ram_name( $row_name );
                $chunk_ent  = ( 0 === $idx ) ? $ent_val : '';
                $chunk_dir  = ( 0 === $idx ) ? $dir_code : '';

                $this->emit_ram_row(
                    $handle, $account, $chunk_name, $h_val, $aac, $phone,
                    $chunk_dir, $chunk_ent, $sec_level, $chunk, 'N'
                );
            }
        }
    }

    private function export_vendors( $handle, $account, $sec_level ) {
        global $wpdb;

        $vendors = $wpdb->get_results( "
            SELECT c.id, c.company, c.last_name, c.first_name
            FROM ac_cardholders c
            WHERE c.cardholder_type = 'vendor'
              AND c.cardholder_status = 'active'
            ORDER BY c.last_name ASC, c.first_name ASC, c.company ASC
        " );

        if ( empty( $vendors ) ) return;

        foreach ( $vendors as $ven ) {
            // -- NAME GENERATION (This was missing!) --
            $last_name  = trim( $ven->last_name ?? '' );
            $first_name = trim( $ven->first_name ?? '' );

            if ( ! empty( $last_name ) || ! empty( $first_name ) ) {
                $combined_name = $last_name ? ( $last_name . ( $first_name ? ', ' . $first_name : '' ) ) : $first_name;
            } else {
                $combined_name = trim( $ven->company ?? '' );
            }

            if ( empty( $combined_name ) ) continue;
            $clean_name = substr( strtoupper( $combined_name ), 0, 15 );

            $creds = $wpdb->get_results( $wpdb->prepare( "
                SELECT cr.credential_type, cr.credential_value
                FROM ac_credentials cr
                WHERE cr.cardholder_id = %d
                  AND cr.status IN ('active', 'valid')
                ORDER BY cr.id ASC
            ", $ven->id ) );

            $gate_codes  = [];
            $device_list = [];

            if ( ! empty( $creds ) ) {
                foreach ( $creds as $cred ) {
                    $type = strtoupper( trim( $cred->credential_type ) );
                    $val  = trim( $cred->credential_value );
                    if ( '' === $val ) continue;

                    if ( 'DK_ENTRY_CODE' === $type ) {
                        $clean_gate = str_pad( $val, 4, '0', STR_PAD_LEFT );
                        if ( ! isset( $this->global_pins[ $clean_gate ] ) ) {
                            $this->global_pins[ $clean_gate ] = true;
                            $gate_codes[] = $clean_gate;
                        }
                    } elseif ( in_array( $type, [ 'DK_WINDSHIELD', 'RFID', 'WIEGAND_26' ], true ) ) {
                        $clean_dev = str_pad( substr( $val, -5 ), 5, '0', STR_PAD_LEFT );
                        if ( ! isset( $this->global_devices[ $clean_dev ] ) ) {
                            $this->global_devices[ $clean_dev ] = true;
                            $device_list[] = $clean_dev;
                        }
                    }
                }
            }

            $ent_val = '';
            if ( ! empty( $gate_codes ) ) {
                $ent_val = array_shift( $gate_codes );
                foreach ( $gate_codes as $extra_code ) {
                    $pad_code = '0' . $extra_code;
                    if ( ! isset( $this->global_devices[ $pad_code ] ) ) {
                        $this->global_devices[ $pad_code ] = true;
                        $device_list[] = $pad_code;
                    }
                }
            }

            // GHOST GUARD: Safely drops vendors with no active gate PINs or Windshield tags
            if ( empty( $ent_val ) && empty( $device_list ) ) {
                continue;
            }

            $chunks = ! empty( $device_list ) ? array_chunk( $device_list, 26 ) : [ [] ];
            foreach ( $chunks as $idx => $chunk ) {
                $chunk_name = $this->get_unique_ram_name( $clean_name );
                $chunk_ent  = ( 0 === $idx ) ? $ent_val : '';

                $this->emit_ram_row(
                    $handle, $account, $chunk_name, 'Y', '', '',
                    '', $chunk_ent, $sec_level, $chunk, 'Y'
                );
            }
        }
    }

    private function get_unique_ram_name( $base_name ) {
        $name     = substr( strtoupper( trim($base_name ) ), 0, 15 );
        $original =$name;
        $count    = 1;

        while ( isset( $this->global_names[$name ] ) ) {
            $count++;
            $suffix = (string)$count;
            $name   = substr($original, 0, 15 - strlen( $suffix ) ) .$suffix;
        }

        $this->global_names[$name ] = true;
        return $name;
    }


    /**
     * Assembles and writes the full 63-column row to CSV.
     */
    private function emit_ram_row( $handle, $account, $name, $h, $aac, $phone, $dir, $ent, $sl, $devices, $is_vendor ) {
        $dev1 = $devices[0] ?? '';
        $er1  = ( '' !== $dev1 || '' !== $ent || '' !== $dir ) ? '1' : '';

        $row = [
            $account,
            $name,
            $h,
            $aac,
            $phone,
            $dir,
            $ent,
            $sl,
            $dev1,
            '',     // FL (Floor call - leave blank for 1838)
            $er1,    // ER (Relay 1 output)
            '',     // NOTES
            $is_vendor
        ];

        // Fill DEVICE2/NOTES2 through DEVICE26/NOTES26
        for ( $i = 2; $i <= 26; $i++ ) {
            $idx   = $i - 1;
            $row[] = $devices[ $idx ] ?? '';
            $row[] = ''; // NOTES blank
        }

        $this->write_csv_row( $handle, $row );
    }

    private function write_csv_row( $handle, array $fields ) {
        // Enforce CRLF Windows line endings required by DoorKing RAM
        fputs( $handle, implode( ',', array_map( [ $this, 'escape_csv_field' ], $fields ) ) . "\r\n" );
    }

    private function escape_csv_field( $field ) {
        $field = (string) $field;
        // Wrap in quotes if contains comma, quote, or leading/trailing whitespace
        if ( strpos( $field, ',' ) !== false || strpos( $field, '"' ) !== false || strpos( $field, "\n" ) !== false || strpos( $field, "\r" ) !== false ) {
            return '"' . str_replace( '"', '""', $field ) . '"';
        }
        return $field;
    }
}

new Fsbhoa_DoorKing_Export();

