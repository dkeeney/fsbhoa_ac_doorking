<?php
/**
 * Generates DoorKing RAM import CSV payloads and handles sync triggers.
 */
if ( ! defined( 'ABSPATH' ) ) {
    die;
}

class Fsbhoa_DoorKing_Export {

    public function __construct() {
        // Core triggers & manual AJAX
        add_action( 'fsbhoa_doorking_trigger_export', [ $this, 'generate_export' ] );
        add_action( 'wp_ajax_fsbhoa_dk_generate_csv', [ $this, 'ajax_manual_export' ] );

        // Trigger on Core configuration push
        add_action( 'fsbhoa_push_to_controllers', [ $this, 'generate_export' ] );

        // Scheduled midnight run via WP-Cron
        add_action( 'fsbhoa_dk_daily_midnight_export', [ $this, 'generate_export' ] );
        $this->ensure_cron_scheduled();
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

        $csv_path   = get_option( 'fsbhoa_dk_csv_path', '/mnt/shared/AccessControl/doorking_sync/import.csv' );
        $lock_path  = get_option( 'fsbhoa_dk_lock_path', '/mnt/shared/AccessControl/doorking_sync/import.lock' );
        $sec_level  = str_pad( get_option( 'fsbhoa_dk_security_level', '01' ), 2, '0', STR_PAD_LEFT );
        $account    = get_option( 'fsbhoa_dk_account_name', 'NORTH GATES' );

        $target_dir = dirname( $csv_path );
        if ( ! is_dir( $target_dir ) ) {
            if ( ! wp_mkdir_p( $target_dir ) ) {
                return new WP_Error( 'dir_error', 'Cannot create export directory: ' . $target_dir );
            }
        }

        $tmp_file = $csv_path . '.tmp';
        $handle   = fopen( $tmp_file, 'w' );
        if ( ! $handle ) {
            return new WP_Error( 'file_error', 'Cannot write to temp file: ' . $tmp_file );
        }

        // 1. Write DoorKing RAM 63-Column CSV Headers
        $headers = [ 'ACCOUNT', 'Resident', 'H', 'AAC', 'PHONE', 'DIR', 'ENT', 'SL', 'DEVICE#', 'FL', 'ER', 'NOTES', 'VENDOR' ];
        for ( $i = 2; $i <= 26; $i++ ) {
            $headers[] = 'DEVICE' . $i;
            $headers[] = 'NOTES' . $i;
        }
        $this->write_csv_row( $handle, $headers );

        // Global in-memory tracking across all rows to prevent RAM duplicate collisions
        $seen_dir_codes    = [];
        $seen_ent_codes    = [];
        $seen_device_codes = [];

        // 2. Export Residents
        $this->export_residents( $handle, $account, $sec_level, $seen_dir_codes, $seen_ent_codes, $seen_device_codes );

        // 3. Export Vendors
        $this->export_vendors( $handle, $account, $sec_level, $seen_ent_codes, $seen_device_codes );

        fclose( $handle );

        // Atomic file replacement
        if ( ! rename( $tmp_file, $csv_path ) ) {
            return new WP_Error( 'rename_error', 'Could not move temp export file into place.' );
        }

        // Touch lock file for Windows RAM scheduler wrapper
        file_put_contents( $lock_path, time() );

        return true;
    }

    private function export_residents( $handle, $account, $sec_level, &$seen_dir_codes, &$seen_ent_codes, &$seen_device_codes ) {
        global $wpdb;

        $residents = $wpdb->get_results( "
            SELECT c.id, c.household_id, c.last_name, c.first_name, c.phone,
                   MAX(CASE WHEN cr.credential_type = 'DK_DIR_CODE' AND cr.status IN ('active', 'valid') THEN cr.credential_value END) AS personal_dir_code,
                   MAX(CASE WHEN cr.credential_type = 'DK_ENTRY_CODE' AND cr.status IN ('active', 'valid') THEN cr.credential_value END) AS personal_entry_code,
                   MAX(CASE WHEN cr.credential_type = 'DK_DIR_OPT_IN' AND cr.status IN ('active', 'valid') THEN cr.credential_value END) AS personal_dir_opt_in
            FROM ac_cardholders c
            LEFT JOIN ac_credentials cr ON c.id = cr.cardholder_id
            WHERE c.cardholder_type = 'resident'
              AND c.cardholder_status = 'active'
            GROUP BY c.id
            ORDER BY c.last_name ASC, c.first_name ASC
        " );

        foreach ( $residents as $res ) {
            $last_name     = trim( $res->last_name ?? '' );
            $first_name    = trim( $res->first_name ?? '' );
            $combined_name = $last_name ? ( $last_name . ( $first_name ? ', ' . $first_name : '' ) ) : $first_name;
            $resident_name = substr( strtoupper( $combined_name ), 0, 15 );

            // Resolve personal or household PIN & DIR
            $dir_code   = $res->personal_dir_code;
            $entry_code = $res->personal_entry_code;
            $dir_opt_in = $res->personal_dir_opt_in;

            if ( ( empty( $dir_code ) || empty( $entry_code ) ) && ! empty( $res->household_id ) ) {
                $hh_creds = $wpdb->get_results( $wpdb->prepare( "
                    SELECT cr.credential_type, cr.credential_value
                    FROM ac_credentials cr
                    JOIN ac_cardholders ch ON cr.cardholder_id = ch.id
                    WHERE ch.household_id = %d
                      AND cr.status IN ('active', 'valid')
                      AND cr.credential_type IN ('DK_DIR_CODE', 'DK_ENTRY_CODE', 'DK_DIR_OPT_IN')
                    ORDER BY cr.id ASC
                ", (int) $res->household_id ) );

                foreach ( $hh_creds as $hc ) {
                    if ( empty( $dir_code ) && 'DK_DIR_CODE' === $hc->credential_type ) {
                        $dir_code = $hc->credential_value;
                    }
                    if ( empty( $entry_code ) && 'DK_ENTRY_CODE' === $hc->credential_type ) {
                        $entry_code = $hc->credential_value;
                    }
                    if ( empty( $dir_opt_in ) && 'DK_DIR_OPT_IN' === $hc->credential_type ) {
                        $dir_opt_in = $hc->credential_value;
                    }
                }
            }

            // 1. Process DIR: Keep if unique across export, otherwise blank out
            $dir_val = '';
            if ( ! empty( $dir_code ) ) {
                $candidate_dir = str_pad( $dir_code, 4, '0', STR_PAD_LEFT );
                if ( ! in_array( $candidate_dir, $seen_dir_codes, true ) ) {
                    $dir_val          = $candidate_dir;
                    $seen_dir_codes[] = $candidate_dir;
                }
            }

            // 2. Process ENT: Keep if unique across export, otherwise blank out
            $ent_val = '';
            if ( ! empty( $entry_code ) ) {
                $candidate_ent = str_pad( $entry_code, 4, '0', STR_PAD_LEFT );
                if ( ! in_array( $candidate_ent, $seen_ent_codes, true ) ) {
                    $ent_val          = $candidate_ent;
                    $seen_ent_codes[] = $candidate_ent;
                }
            }

            // 3. Process Devices: Keep only unique tags across export
            $tags = $wpdb->get_results( $wpdb->prepare( "
                SELECT cr.credential_value, v.make, v.model, v.license_plate
                FROM ac_credentials cr
                LEFT JOIN ac_vehicles v ON cr.vehicle_id = v.vehicle_id
                WHERE cr.credential_type IN ('DK_WINDSHIELD', 'RFID')
                  AND cr.status IN ('active', 'valid')
                  AND (
                      cr.cardholder_id = %d 
                      OR (v.household_id IS NOT NULL AND v.household_id = %d)
                  )
                ORDER BY cr.id ASC
            ", $res->id, (int) $res->household_id ) );

            $devices = [];
            foreach ( $tags as $tag ) {
                $val = str_pad( $tag->credential_value, 5, '0', STR_PAD_LEFT );
                if ( in_array( $val, $seen_device_codes, true ) ) {
                    continue;
                }

                $seen_device_codes[] = $val;

                $desc  = trim( ( $tag->make ?? '' ) . ' ' . ( $tag->model ?? '' ) );
                $plate = ! empty( $tag->license_plate ) ? '#' . $tag->license_plate : '';
                $note  = substr( strtoupper( trim( $desc . ' ' . $plate ) ), 0, 20 );

                $devices[] = [ 'dev' => $val, 'note' => $note ];
            }

            // 4. Skip rule: If neither a unique DIR, ENT, nor DEVICE remains, drop the row
            if ( empty( $dir_val ) && empty( $ent_val ) && empty( $devices ) ) {
                continue;
            }

            // Format phone & directory listing
            $is_opted_in = ( '1' === (string) $dir_opt_in );
            $hide_dir    = $is_opted_in ? 'N' : 'Y';
            $aac         = '';
            $phone       = '';

            if ( $is_opted_in && ! empty( $res->phone ) ) {
                $digits = preg_replace( '/[^0-9]/', '', $res->phone );
                if ( 10 === strlen( $digits ) ) {
                    $area = substr( $digits, 0, 3 );
                    if ( '661' !== $area ) {
                        $aac = ' ' . $area;
                    }
                    $phone = substr( $digits, 3, 3 ) . '-' . substr( $digits, 6, 4 );
                } elseif ( 7 === strlen( $digits ) ) {
                    $phone = substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 4 );
                }
            }

            $this->emit_ram_row( $handle, $account, $resident_name, $hide_dir, $aac, $phone, $dir_val, $ent_val, $sec_level, $devices, 'N' );
        }
    }

    private function export_vendors( $handle, $account, $sec_level, &$seen_ent_codes, &$seen_device_codes ) {
        global $wpdb;

        $vendors = $wpdb->get_results( "
            SELECT c.id, c.company
            FROM ac_cardholders c
            WHERE c.cardholder_type = 'vendor'
              AND c.cardholder_status = 'active'
            ORDER BY c.company ASC
        " );

        foreach ( $vendors as $ven ) {
            $company_raw = trim( $ven->company ?? '' );
            if ( empty( $company_raw ) ) {
                continue;
            }

            $clean_name    = preg_replace( '/^V\s*-\s*/i', '', $company_raw );
            $company_clean = substr( strtoupper( 'V-' . $clean_name ), 0, 15 );

            $credentials = $wpdb->get_results( $wpdb->prepare( "
                SELECT cr.credential_type, cr.credential_value, v.make, v.license_plate
                FROM ac_credentials cr
                LEFT JOIN ac_vehicles v ON cr.vehicle_id = v.vehicle_id
                WHERE cr.cardholder_id = %d
                  AND cr.status IN ('active', 'valid')
                  AND cr.credential_type IN ('DK_ENTRY_CODE', 'DK_WINDSHIELD', 'RFID', 'WIEGAND_26')
                ORDER BY (cr.credential_type = 'DK_ENTRY_CODE') DESC, cr.id ASC
            ", $ven->id ) );

            if ( empty( $credentials ) ) {
                continue;
            }

            $primary_pin = '';
            $device_list = [];

            foreach ( $credentials as $cred ) {
                if ( 'DK_ENTRY_CODE' === $cred->credential_type && empty( $primary_pin ) ) {
                    $candidate_pin = str_pad( $cred->credential_value, 4, '0', STR_PAD_LEFT );
                    if ( ! in_array( $candidate_pin, $seen_ent_codes, true ) ) {
                        $primary_pin      = $candidate_pin;
                        $seen_ent_codes[] = $candidate_pin;
                    }
                } else {
                    if ( 'DK_ENTRY_CODE' === $cred->credential_type ) {
                        $val = '0' . str_pad( $cred->credential_value, 4, '0', STR_PAD_LEFT );
                    } else {
                        $val = str_pad( $cred->credential_value, 5, '0', STR_PAD_LEFT );
                    }

                    if ( in_array( $val, $seen_device_codes, true ) ) {
                        continue;
                    }
                    $seen_device_codes[] = $val;

                    $make  = trim( $cred->make ?? '' );
                    $plate = ! empty( $cred->license_plate ) ? '#' . $cred->license_plate : '';

                    $note = $make;
                    if ( $plate && false === stripos( $make, $plate ) ) {
                        $note = trim( $make . ' ' . $plate );
                    }

                    if ( 'VENDOR FLEET VEHICLE' === strtoupper( $note ) ) {
                        $note = '';
                    }

                    $device_list[] = [
                        'dev'  => $val,
                        'note' => substr( strtoupper( $note ), 0, 20 )
                    ];
                }
            }

            if ( empty( $primary_pin ) && empty( $device_list ) ) {
                continue;
            }

            $chunks = array_chunk( $device_list, 26 );
            if ( empty( $chunks ) ) {
                $chunks = [ [] ];
            }

            foreach ( $chunks as $chunk_idx => $chunk ) {
                $row_name = ( 0 === $chunk_idx )
                    ? $company_clean
                    : substr( $company_clean, 0, 14 ) . ( $chunk_idx + 1 );

                $ent = ( 0 === $chunk_idx ) ? $primary_pin : '';

                $this->emit_ram_row( $handle, $account, $row_name, 'N', '', '', '', $ent, $sec_level, $chunk, 'Y' );
            }
        }
    }

    private function emit_ram_row( $handle, $account, $resident, $h, $aac, $phone, $dir, $ent, $sl, $devices, $is_vendor ) {
        $dev1  = $devices[0]['dev'] ?? '';
        $note1 = $devices[0]['note'] ?? '';
        $er1   = ( '' !== $dev1 || '' !== $ent || '' !== $dir ) ? '1' : '';

        $row = [
            $account,
            $resident,
            $h,
            $aac,
            $phone,
            $dir,
            $ent,
            $sl,
            $dev1,
            '',
            $er1,
            $note1,
            $is_vendor
        ];

        for ( $i = 2; $i <= 26; $i++ ) {
            $idx   = $i - 1;
            $row[] = $devices[ $idx ]['dev'] ?? '';
            $row[] = $devices[ $idx ]['note'] ?? '';
        }

        $this->write_csv_row( $handle, $row );
    }

    private function write_csv_row( $handle, array $fields ) {
        fputcsv( $handle, $fields, ',', '"', "\\" );
    }
}

new Fsbhoa_DoorKing_Export();

