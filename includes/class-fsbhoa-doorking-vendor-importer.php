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

        if ( ( $handle = fopen( $file_path, 'r' ) ) === false ) {
            wp_redirect( add_query_arg( 'error', urlencode( 'Could not open CSV file.' ), wp_get_referer() ) );
            exit;
        }

        global $wpdb;
        $headers        = fgetcsv( $handle, 0, ',', '"', '\\' );
        $imported_count = 0;
        $col_map        = array_flip( $headers );

        $target_account = trim( (string) get_option( 'fsbhoa_dk_account_name', '' ) );

        if ( empty( $target_account ) ) {
            fclose( $handle );
            wp_redirect( add_query_arg( 'error', urlencode( 'Configuration Error: DoorKing Account Name is not set in DoorKing Settings.' ), wp_get_referer() ) );
            exit;
        }
        // Locate the ACCOUNT column index reliably (handling whitespace/quotes/BOM)
        $account_idx = false;
        foreach ( $headers as $idx => $header_name ) {
            $clean_h = strtoupper( trim( $header_name, " \t\n\r\0\x0B\"'/" ) );
            if ( $clean_h === 'ACCOUNT' ) {
                $account_idx = $idx;
                break;
            }
        }

        if ( false === $account_idx ) {
            fclose( $handle );
            wp_redirect( add_query_arg( 'error', urlencode( 'Invalid CSV format: Missing "ACCOUNT" column.' ), wp_get_referer() ) );
            exit;
        }

        // Determine resident/name header alias (6.5b vs 6.3h)
        $name_key = isset( $col_map['Resident'] ) ? 'Resident' : ( isset( $col_map['NAME'] ) ? 'NAME' : null );
        if ( ! $name_key ) {
            wp_redirect( add_query_arg( 'error', urlencode( 'Invalid CSV format. Missing "NAME" (or "Resident") column.' ), wp_get_referer() ) );
            exit;
        }

        $company_cache = [];

        while ( ( $data = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
            $resident_name = trim( $data[ $col_map[ $name_key ] ] ?? '' );
            $account_name  = isset( $col_map['ACCOUNT'] ) ? trim( $data[ $col_map['ACCOUNT'] ] ?? '' ) : '';
            $is_vendor_col = isset( $col_map['VENDOR'] ) ? trim( $data[ $col_map['VENDOR'] ] ?? '' ) : '';

            // STRICT FILTER: If the row account does not match the configured target account, skip it
            if ( strcasecmp( $account_name, $target_account ) !== 0 ) {
                continue;
            }

            // Route empty or "." maintenance slots directly to Open & Shut
            if ( empty( $resident_name ) || $resident_name === '.' ) {
                $resident_name = 'Open & Shut';
                $is_vendor     = true;
            } else {
                $is_vendor = ( 'Y' === strtoupper( $is_vendor_col ) )
                          || ( 0 === stripos( $resident_name, 'V-' ) )
                          || ( 0 === stripos( $resident_name, 'V -' ) );
            }

            if ( ! $is_vendor ) {
                continue;
            }

            $clean_company = strtoupper($this->normalize_company_name( $resident_name ));
            $category      = $this->infer_category( $clean_company );

            // Check if vendor is marked inactive
            $initial_status = 'active';
            foreach ( $this->inactive_vendors as $inactive_name ) {
                if ( false !== stripos( $clean_company, $inactive_name ) ) {
                    $initial_status = 'inactive';
                    break;
                }
            }

            $parts      = explode( ' ', $clean_company, 2 );
            $first_name = $parts[0] ?? '';
            $last_name  = $parts[1] ?? 'Vendor';

            $cardholder_id = null;

            if ( isset( $company_cache[ $clean_company ] ) ) {
                $cardholder_id = $company_cache[ $clean_company ];
                if ( $this->is_dry_run ) {
                    $this->dry_run_log[] = "[APPEND TO VENDOR] Company: '{$clean_company}' (Merging multi-row devices)";
                }
            } else {
                // 1. Match by Company Name first (active or purged)
                $existing = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, cardholder_status
                     FROM ac_cardholders
                     WHERE company = %s
                       AND cardholder_type = 'vendor'
                     LIMIT 1",
                    $clean_company
                ) );

                // 2. Fallback: Match by Person Name (handles staff & named contractors)
                if ( ! $existing && ! empty( $first_name ) && ! empty( $last_name ) ) {
                    $existing = $wpdb->get_row( $wpdb->prepare(
                        "SELECT id, cardholder_status
                         FROM ac_cardholders
                         WHERE UPPER(TRIM(first_name)) = %s
                           AND UPPER(TRIM(last_name)) = %s
                         LIMIT 1",
                        strtoupper( trim( $first_name ) ),
                        strtoupper( trim( $last_name ) )
                    ) );
                }

                if ( $this->is_dry_run ) {
                    $status_label = $existing ? "[EXISTING VENDOR (ID: {$existing->id})]" : "[NEW VENDOR]";
                    if ( 'inactive' === $initial_status ) {
                        $status_label .= " [MARKED INACTIVE]";
                    }
                    $this->dry_run_log[] = "{$status_label} Company: '{$clean_company}' | Category: {$category}";
                    $cardholder_id = 'DRY_RUN_' . sanitize_title( $clean_company );
                } else {
                    if ( $existing ) {
                        $cardholder_id = absint( $existing->id );

                        // Reactivate if previously purged and update classification
                        $wpdb->update(
                            'ac_cardholders',
                            [
                                'company'           => $clean_company,
                                'cardholder_type'   => 'vendor',
                                'resident_type'     => $category,
                                'cardholder_status' => ( $existing->cardholder_status === 'purged' ) ? 'active' : $existing->cardholder_status,
                                'updated_at'        => current_time( 'mysql' ),
                            ],
                            [ 'id' => $cardholder_id ]
                        );
                    } else {
                        // Create brand new vendor record
                        $wpdb->insert( 'ac_cardholders', [
                            'first_name'        => $first_name,
                            'last_name'         => $last_name,
                            'company'           => $clean_company,
                            'cardholder_type'   => 'vendor',
                            'resident_type'     => $category,
                            'cardholder_status' => $initial_status,
                            'created_at'        => current_time( 'mysql' ),
                            'updated_at'        => current_time( 'mysql' ),
                        ] );
                        $cardholder_id = $wpdb->insert_id;
                    }
                }
                $company_cache[ $clean_company ] = $cardholder_id;
            }

            // 1. PIN Code (ENT column)
            $ent_code = isset( $col_map['ENT'] ) ? preg_replace( '/[^0-9]/', '', trim( $data[ $col_map['ENT'] ] ?? '' ) ) : '';
            if ( ! empty( $ent_code ) ) {
                $this->upsert_credential( $cardholder_id, null, 'DK_ENTRY_CODE', $ent_code, '', $initial_status );
            }

            // (DK_DIR_CODE omitted intentionally for all vendors)

            // 2. Scan Devices (0xxxx = PIN, 1xxxx = Windshield)
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

                // 5-digit devices starting with 0 are gate PINs (strip leading 0)
                if ( 5 === strlen( $device_num ) && '0' === substr( $device_num, 0, 1 ) ) {
                    $pin_val = substr( $device_num, 1 );
                    $this->upsert_credential( $cardholder_id, null, 'DK_ENTRY_CODE', $pin_val, $note, $initial_status );
                }
                // 5-digit devices starting with 1 are windshield RFID tags
                elseif ( 5 === strlen( $device_num ) && '1' === substr( $device_num, 0, 1 ) ) {
                    $vehicle_id = $this->resolve_vendor_vehicle( $cardholder_id, $note, $device_num );
                    $this->upsert_credential( $cardholder_id, $vehicle_id, 'DK_WINDSHIELD', $device_num, $note, $initial_status );
                }
                // Fallback for standard fobs
                else {
                    $this->upsert_credential( $cardholder_id, null, 'WIEGAND_26', $device_num, $note, $initial_status );
                }
            }

            if ( $this->is_dry_run ) {
                $this->dry_run_log[] = "";
            }

            $imported_count++;
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

