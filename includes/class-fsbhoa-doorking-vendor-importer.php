<?php
/**
 * Dedicated parser for DoorKing vendor records.
 */
if ( ! defined( 'ABSPATH' ) ) {
    die;
}

class Fsbhoa_DoorKing_Vendor_Importer {

    private $is_dry_run = false;
    private $dry_run_log = [];

    // Companies that are no longer active contractors
    private $inactive_vendors = [
        'BRIGHTVIEW',
    ];

    // Explicit manual mappings from DoorKing export strings to WordPress cardholder names
    private $vendor_aliases = [
        'S-EDDIE'         => [ 'first' => 'Edward',  'last' => 'Zepeda',     'company' => 'PMP' ],
        'B-FELIC'         => [ 'first' => 'Felicia', 'last' => 'Trone',      'company' => 'Beauty Bar' ],
        'B-FELICIA'       => [ 'first' => 'Felicia', 'last' => 'Trone',      'company' => 'Beauty Bar' ],
        'B- KRISTIN'      => [ 'first' => 'Kristin', 'last' => 'Hayes',      'company' => 'Beauty Bar', ],
        'B-KRIST'         => [ 'first' => 'Kristin', 'last' => 'Hayes',      'company' => 'Beauty Bar' ],
        'THOMPSON, AMY'   => [ 'first' => 'Amy',     'last' => 'Thompson',   'company' => 'PMP' ],
        'VILLANUEVA, RAU' => [ 'first' => 'Raul',    'last' => 'Villanueva', 'company' => 'PMP' ],
    ];

    
    public function process_file( $file_path, $is_dry_run = true ) {
        $this->is_dry_run  = $is_dry_run;
        $this->dry_run_log = [];

        if ( $this->is_dry_run ) {
            $this->dry_run_log[] = "=== STARTING VENDOR DRY RUN ===";
            $this->dry_run_log[] = "Database writes are DISABLED.";
            $this->dry_run_log[] = "0xxxx values = Gate PINs | 1xxxx values = Windshield RFIDs";
            $this->dry_run_log[] = "Directory codes (DK_DIR_CODE) are OMITTED for all vendors.";
            $this->dry_run_log[] = "--------------------------------------------------";
        }

        $init = $this->validate_and_open_csv( $file_path );
        if ( ! $init ) {
            return;
        }

        $handle         = $init['handle'];
        $col_map        = $init['col_map'];
        $name_key       = $init['name_key'];
        $target_account = $init['target_account'];

        $company_cache  = [];
        $imported_count = 0;

        // PHP 8.4 compatible CSV loop (avoids deprecated backslash escape parameter)
        while ( ( $data = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
            $processed = $this->process_vendor_row( $data, $col_map, $name_key, $target_account, $company_cache );
            if ( $processed ) {
                $imported_count++;
            }
        }

        fclose( $handle );

        if ( $this->is_dry_run ) {
            set_transient( 'fsbhoa_dk_vendor_dry_run_' . get_current_user_id(), $this->dry_run_log, 300 );
            wp_redirect( add_query_arg( 'dry_run_complete', '1', wp_get_referer() ) );
            exit;
        }

        wp_redirect( add_query_arg( 'imported', $imported_count, wp_get_referer() ) );
        exit;
    }

    /**
     * Validates headers, account configuration, and opens the file handle.
     */
    private function validate_and_open_csv( $file_path ) {
        if ( ( $handle = fopen( $file_path, 'r' ) ) === false ) {
            wp_redirect( add_query_arg( 'error', urlencode( 'Could not open CSV file.' ), wp_get_referer() ) );
            exit;
        }

        $target_account = trim( (string) get_option( 'fsbhoa_dk_account_name', '' ) );
        if ( empty( $target_account ) ) {
            fclose( $handle );
            wp_redirect( add_query_arg( 'error', urlencode( 'Configuration Error: DoorKing Account Name is not set in DoorKing Settings.' ), wp_get_referer() ) );
            exit;
        }

        $headers = fgetcsv( $handle, 0, ',', '"', '' );
        if ( empty( $headers ) ) {
            fclose( $handle );
            wp_redirect( add_query_arg( 'error', urlencode( 'Uploaded CSV file is empty.' ), wp_get_referer() ) );
            exit;
        }

        $col_map     = array_flip( $headers );
        $account_idx = false;

        foreach ( $headers as $idx => $header_name ) {
            $clean_h = strtoupper( trim( $header_name, " \t\n\r\0\x0B\"'/" ) );
            if ( 'ACCOUNT' === $clean_h ) {
                $account_idx = $idx;
                break;
            }
        }

        if ( false === $account_idx ) {
            fclose( $handle );
            wp_redirect( add_query_arg( 'error', urlencode( 'Invalid CSV format: Missing "ACCOUNT" column.' ), wp_get_referer() ) );
            exit;
        }

        $name_key = isset( $col_map['Resident'] ) ? 'Resident' : ( isset( $col_map['NAME'] ) ? 'NAME' : null );
        if ( ! $name_key ) {
            fclose( $handle );
            wp_redirect( add_query_arg( 'error', urlencode( 'Invalid CSV format. Missing "NAME" (or "Resident") column.' ), wp_get_referer() ) );
            exit;
        }

        return [
            'handle'         => $handle,
            'col_map'        => $col_map,
            'name_key'       => $name_key,
            'target_account' => $target_account,
        ];
    }

    /**
     * Parses and handles routing for a single vendor row.
     */
    private function process_vendor_row( array $data, array $col_map, $name_key, $target_account, array &$company_cache ) {
        $resident_name = trim( $data[ $col_map[ $name_key ] ] ?? '' );
        $account_name  = isset( $col_map['ACCOUNT'] ) ? trim( $data[ $col_map['ACCOUNT'] ] ?? '' ) : '';
        $is_vendor_col = isset( $col_map['VENDOR'] ) ? trim( $data[ $col_map['VENDOR'] ] ?? '' ) : '';

        // Filter account
        if ( 0 !== strcasecmp( $account_name, $target_account ) ) {
            return false;
        }

        // Special case: empty or "." maintenance slots
        if ( empty( $resident_name ) || '.' === $resident_name ) {
            $resident_name = 'Open & Shut';
            $is_vendor     = true;
        } else {
            $is_vendor = ( 'Y' === strtoupper( $is_vendor_col ) )
                      || ( 0 === stripos( $resident_name, 'V-' ) )
                      || ( 0 === stripos( $resident_name, 'V -' ) );
        }

        if ( ! $is_vendor ) {
            return false;
        }

        $clean_company  = strtoupper( $this->normalize_company_name( $resident_name ) );
        $initial_status = $this->determine_initial_status( $clean_company );

        $cardholder_id = $this->resolve_vendor_cardholder( $clean_company, $resident_name, $initial_status, $company_cache );
        if ( ! $cardholder_id ) {
            return false;
        }

        $this->import_row_credentials( $data, $col_map, $cardholder_id, $initial_status );

        if ( $this->is_dry_run ) {
            $this->dry_run_log[] = '';
        }

        return true;
    }

    /**
     * Checks inactive list to set active vs inactive. This is the credential status; a vendor on
     * the list is not current, so the cardholder is purged (vendors are never archived).
     */
    private function determine_initial_status( $clean_company ) {
        foreach ( $this->inactive_vendors as $inactive_name ) {
            if ( false !== stripos( $clean_company, $inactive_name ) ) {
                return 'inactive';
            }
        }
        return 'active';
    }

    /**
     * Resolves an existing cardholder via aliases, company name, or contact name.
     */
    private function resolve_vendor_cardholder( $clean_company, $resident_name, $initial_status, array &$company_cache ) {
        global $wpdb;

        if ( isset( $company_cache[ $clean_company ] ) ) {
            if ( $this->is_dry_run ) {
                $this->dry_run_log[] = "[APPEND TO VENDOR] Company: '{$clean_company}' (Merging multi-row devices)";
            }
            return $company_cache[ $clean_company ];
        }

        $existing = null;
        $category = $this->infer_category( $clean_company );
        $parts    = explode( ' ', $clean_company, 2 );
        $first    = $parts[0] ?? '';
        $last     = $parts[1] ?? 'Vendor';

        // 1. Alias Map lookup
        $lookup_key = strtoupper( trim( $clean_company ) );
        if ( isset( $this->vendor_aliases[ $lookup_key ] ) ) {
            $alias = $this->vendor_aliases[ $lookup_key ];
            if ( ! empty( $alias['company'] ) ) {
                $clean_company = $alias['company'];
            }

            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, cardholder_status, company
                 FROM ac_cardholders
                 WHERE UPPER(TRIM(first_name)) = %s
                   AND UPPER(TRIM(last_name)) = %s
                   AND cardholder_status != 'purged'
                 LIMIT 1",
                strtoupper( trim( $alias['first'] ) ),
                strtoupper( trim( $alias['last'] ) )
            ) );

            if ( $existing ) {
                if ( $this->is_dry_run ) {
                    $this->dry_run_log[] = "[ALIAS MATCH] DK '{$resident_name}' -> Cardholder: {$alias['first']} {$alias['last']} (ID: {$existing->id}) | Set Company: {$clean_company}";
                } elseif ( empty( $existing->company ) ) {
                    $wpdb->update(
                        'ac_cardholders',
                        [ 'company' => $clean_company ],
                        [ 'id' => absint( $existing->id ) ]
                    );
                }
            }
        }

        // 2. Company Name lookup
        if ( ! $existing ) {
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, cardholder_status, company
                 FROM ac_cardholders
                 WHERE company = %s
                   AND cardholder_type = 'vendor'
                 LIMIT 1",
                $clean_company
            ) );
        }

        // 3. Contact Name fallback
        if ( ! $existing && ! empty( $first ) && ! empty( $last ) ) {
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, cardholder_status, company
                 FROM ac_cardholders
                 WHERE UPPER(TRIM(first_name)) = %s
                   AND UPPER(TRIM(last_name)) = %s
                 LIMIT 1",
                strtoupper( trim( $first ) ),
                strtoupper( trim( $last ) )
            ) );
        }

        if ( $this->is_dry_run ) {
            $status_label = $existing ? "[EXISTING VENDOR (ID: {$existing->id})]" : "[NEW VENDOR]";
            if ( 'inactive' === $initial_status ) {
                $status_label .= ' [MARKED PURGED]';
            }
            $this->dry_run_log[] = "{$status_label} Company: '{$clean_company}' | Category: {$category}";
            $cardholder_id = 'DRY_RUN_' . sanitize_title( $clean_company );
        } else {
            if ( $existing ) {
                $cardholder_id = absint( $existing->id );
                $wpdb->update(
                    'ac_cardholders',
                    [
                        'company'           => $clean_company,
                        'cardholder_type'   => 'vendor',
                        'resident_type'     => $category,
                        'cardholder_status' => ( 'inactive' === $initial_status ) ? 'purged' : ( ( 'purged' === $existing->cardholder_status ) ? 'active' : $existing->cardholder_status ),
                        'updated_at'        => current_time( 'mysql' ),
                    ],
                    [ 'id' => $cardholder_id ]
                );
            } else {
                $wpdb->insert( 'ac_cardholders', [
                    'first_name'        => $first,
                    'last_name'         => $last,
                    'company'           => $clean_company,
                    'cardholder_type'   => 'vendor',
                    'resident_type'     => $category,
                    'cardholder_status' => ( 'inactive' === $initial_status ) ? 'purged' : 'active',
                    'created_at'        => current_time( 'mysql' ),
                    'updated_at'        => current_time( 'mysql' ),
                ] );
                $cardholder_id = (int) $wpdb->insert_id;
            }
        }

        $company_cache[ $clean_company ] = $cardholder_id;
        return $cardholder_id;
    }

    /**
     * Extracts and upserts PINs and RFIDs from row columns.
     */
    private function import_row_credentials( array $data, array $col_map, $cardholder_id, $initial_status ) {
        // 1. Primary PIN (ENT column)
        $ent_code = isset( $col_map['ENT'] ) ? preg_replace( '/[^0-9]/', '', trim( $data[ $col_map['ENT'] ] ?? '' ) ) : '';
        if ( ! empty( $ent_code ) ) {
            $this->upsert_credential( $cardholder_id, null, 'DK_ENTRY_CODE', $ent_code, '', $initial_status );
        }

        // 2. Scan Devices (DEVICE# and DEVICE2..DEVICE26)
        $devices_to_check = [ [ 'dev' => 'DEVICE#', 'note' => 'NOTES' ] ];
        for ( $i = 2; $i <= 26; $i++ ) {
            $devices_to_check[] = [ 'dev' => 'DEVICE' . $i, 'note' => 'NOTES' . $i ];
        }

        foreach ( $devices_to_check as $fields ) {
            if ( ! isset( $col_map[ $fields['dev'] ] ) ) {
                continue;
            }

            $raw_device = trim( $data[ $col_map[ $fields['dev'] ] ] ?? '' );
            $device_num = preg_replace( '/[^0-9]/', '', $raw_device );
            $note       = trim( $data[ $col_map[ $fields['note'] ] ] ?? '' );

            if ( empty( $device_num ) ) {
                continue;
            }

            // 5-digit device starting with 0 is an entry PIN
            if ( 5 === strlen( $device_num ) && '0' === substr( $device_num, 0, 1 ) ) {
                $pin_val = substr( $device_num, 1 );
                $this->upsert_credential( $cardholder_id, null, 'DK_ENTRY_CODE', $pin_val, $note, $initial_status );
            }
            // 5-digit device starting with 1 is a windshield RFID
            elseif ( 5 === strlen( $device_num ) && '1' === substr( $device_num, 0, 1 ) ) {
                $vehicle_id = $this->resolve_vendor_vehicle( $cardholder_id, $note, $device_num );
                $this->upsert_credential( $cardholder_id, $vehicle_id, 'DK_WINDSHIELD', $device_num, $note, $initial_status );
            }
            // Other lengths are standard pedestrian fobs
            else {
                $this->upsert_credential( $cardholder_id, null, 'WIEGAND_26', $device_num, $note, $initial_status );
            }
        }
    }

    private function normalize_company_name( $raw_name ) {
        $clean = trim( preg_replace( '/^V\s*-\s*/i', '', $raw_name ) );
        $clean = preg_replace( '/\s*#?\d+$/i', '', $clean );
        return trim( $clean );
    }

    private function infer_category( $clean_name ) {
        $name_upper = strtoupper( $clean_name );

        $map = [
            'Staff'       => [ 'STAFF', '- STA', 'S-' ],
            'Salon'       => [ 'SALON' ],
            'Pool Care'   => [ 'POOL', 'ATLAS' ],
            'Landscaper'  => [ 'BRIGHTVIEW', 'LANDSCAPE', 'TREE', 'LAWN' ],
            'Emergency'   => [ 'FIRE', 'POLICE', 'SHERIFF', 'AMBULANCE', 'HALL' ],
            'Municipal'   => [ 'DISTRICT ATTY', 'CITY', 'COUNTY' ],
            'Delivery'    => [ 'FED EX', 'UPS', 'USPS', 'NEWSPAPER', 'COURIER', 'POSTAL' ],
            'Sanitation'  => [ 'WASTE', 'SANI', 'SUPERIOR SANI', 'DISPOSAL', 'TRASH' ],
            'Utilities'   => [ 'WATER', 'CAL WATER', 'EDISON', 'GAS', 'ELECTRIC', 'PG&E' ],
            'Gates'       => [ 'OPEN & SHUT', 'DOOR', 'FENCE', 'GATE', 'TEMP GATE' ],
        ];

        foreach ( $map as $category => $keywords ) {
            foreach ( $keywords as $kw ) {
                if ( false !== strpos( $name_upper, $kw ) ) {
                    return $category;
                }
            }
        }

        return 'General Contractor';
    }

    private function resolve_vendor_vehicle( $cardholder_id, $note, $rfid ) {
        if ( $this->is_dry_run ) {
            return 'DRY_RUN_VEH_ID';
        }

        global $wpdb;

        $existing_cred = $wpdb->get_row( $wpdb->prepare(
            "SELECT vehicle_id FROM ac_credentials WHERE credential_type = 'DK_WINDSHIELD' AND credential_value = %s LIMIT 1",
            $rfid
        ) );

        if ( $existing_cred && ! empty( $existing_cred->vehicle_id ) ) {
            return absint( $existing_cred->vehicle_id );
        }

        $plate = null;
        if ( preg_match( '/\b(?=.*[0-9])(?=.*[A-Z])[A-Z0-9]{5,8}\b/i', $note, $matches ) ) {
            $plate = strtoupper( $matches[0] );
        }

        $make_desc = trim( substr( $note, 0, 50 ) );
        if ( empty( $make_desc ) ) {
            $make_desc = 'Vendor Fleet Vehicle';
        }

        $wpdb->insert( 'ac_vehicles', [
            'household_id'  => null,
            'make'          => $make_desc,
            'license_plate' => $plate,
        ] );

        return (int) $wpdb->insert_id;
    }

    private function upsert_credential( $cardholder_id, $vehicle_id, $type, $value, $note = '', $status = 'active' ) {
        global $wpdb;

        if ( $this->is_dry_run ) {
            $note_str = $note ? " (Note: {$note})" : '';
            $veh_str  = $vehicle_id ? ' [Linked to Vehicle]' : '';
            $this->dry_run_log[] = "    -> [{$status}] Type: {$type} | Value: {$value}{$veh_str}{$note_str}";
            return;
        }

        $clean_veh_id = ( 'DRY_RUN_VEH_ID' === $vehicle_id || empty( $vehicle_id ) ) ? null : absint( $vehicle_id );

        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM ac_credentials WHERE cardholder_id = %d AND credential_type = %s AND credential_value = %s LIMIT 1",
            $cardholder_id,
            $type,
            $value
        ) );

        if ( ! $existing_id ) {
            $wpdb->insert( 'ac_credentials', [
                'cardholder_id'    => $cardholder_id,
                'vehicle_id'       => $clean_veh_id,
                'credential_type'  => $type,
                'credential_value' => $value,
                'status'           => $status,
                'issue_date'       => current_time( 'Y-m-d' ),
                'expiration_date'  => '2099-12-31',
                'created_at'       => current_time( 'mysql' ),
            ] );
        } else {
            $update_data = [
                'status'     => $status,
                'issue_date' => current_time( 'Y-m-d' ),
            ];
            if ( null !== $clean_veh_id ) {
                $update_data['vehicle_id'] = $clean_veh_id;
            }
            $wpdb->update( 'ac_credentials', $update_data, [ 'id' => $existing_id ] );
        }
    }
}

