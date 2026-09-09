<?php
/**
 * Plugin Name: FSBHOA Access Control - DoorKing
 * Description: Subordinate plugin to manage DoorKing 1838 access credentials, UI extensions, and CSV exports for RAM software synchronization.
 * Version: 1.0.0
 * Author: David Keeney
 * Text Domain: fsbhoa-ac-doorking
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSBHOA_AC_DoorKing {

	// Define the DoorKing credential configurations
	private $dk_types = [
		'DK_DIR_CODE'   => [ 'label' => 'Directory Code', 'max' => 4, 'desc' => 'DoorKing Directory Code (Primary Key)' ],
		'DK_ENTRY_CODE' => [ 'label' => 'Keypad Entry PIN', 'max' => 4, 'desc' => 'DoorKing Keypad Entry PIN' ],
		'DK_WINDSHIELD' => [ 'label' => 'Windshield RFID', 'max' => 5, 'desc' => 'DoorKing Primary Device (Windshield Tag)' ],
		'DK_DIR_OPT_IN' => [ 'label' => 'Directory Opt-In', 'max' => 1, 'desc' => 'Resident authorization to list phone in visitor directory' ]
	];

	public function __construct() {
		// Register activation hook for DB seeding
		register_activation_hook( __FILE__, [ $this, 'activate_plugin' ] );

		// Hook into WordPress init to setup the rest of the plugin
		add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
		add_action( 'fsbhoa_vehicle_table_header', [ $this, 'render_vehicle_header' ] );
		add_action( 'fsbhoa_vehicle_table_row_columns', [ $this, 'render_vehicle_row_column' ], 10, 2 );
		add_action( 'fsbhoa_core_vehicle_saved', [ $this, 'save_vehicle_credential' ], 10, 3 );
	}

	/**
	 * Runs on plugin activation to seed the database
	 */
	public function activate_plugin() {
		global $wpdb;

		// Seed the credential types required for DoorKing
		foreach ( $this->dk_types as $type_code => $config ) {
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO ac_credential_types (type_code, description) VALUES (%s, %s)",
				$type_code,
				$config['desc']
			) );
		}
	}

	/**
	 * Initialize plugin hooks if dependencies are met
	 */
	public function init_plugin() {
		// Check if the core plugin is active.
		if ( ! defined( 'FSBHOA_AC_VERSION' ) ) {
			add_action( 'admin_notices', [ $this, 'dependency_notice' ] );
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . 'includes/class-fsbhoa-doorking-settings.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-fsbhoa-doorking-importer.php';
		new Fsbhoa_DoorKing_Importer();

		// Hook into the core plugin's UI for the cardholder edit page
		add_action( 'fsbhoa_render_credential_fields', [ $this, 'render_ui' ], 10, 2 );

		// Hook into the core plugin's save routine
		add_action( 'fsbhoa_validate_credentials', [ $this, 'validate_and_save_credentials' ], 10, 5 );

		// Hook into the core vehicle validation filters
		add_filter( 'fsbhoa_is_vehicle_row_empty', [ $this, 'dk_vehicle_row_empty_check' ], 10, 2 );
		add_filter( 'fsbhoa_validate_vehicle_row', [ $this, 'dk_validate_vehicle_row' ], 10, 2 );

		// Endpoints to trigger the CSV download (for the Lodge PC automation)
		add_action( 'admin_post_nopriv_dk_csv_export', [ $this, 'generate_csv' ] );
		add_action( 'admin_post_dk_csv_export', [ $this, 'generate_csv' ] );

        // Endpoint to trigger the CSV generation.. sending config to RAM.
        add_action( 'wp_ajax_fsbhoa_dk_generate_csv', [ $this, 'generate_csv' ] );

		// Register the Go service with the Core System Status dashboard
		add_filter( 'fsbhoa_system_services', [ $this, 'register_system_service' ] );
	}

	/**
	 * Appends the DoorKing Go proxy to the System Status dashboard
	 */
	public function register_system_service( $services ) {
		$services['fsbhoa_doorking'] = 'DoorKing Service';
		return $services;
	}

	/**
	 * Admin notice if core plugin is missing
	 */
	public function dependency_notice() {
		echo '<div class="error"><p><strong>FSBHOA DoorKing Integration</strong> requires the <strong>FSBHOA AC Core</strong> plugin to be active.</p></div>';
	}

	/**
	 * Prevent Core from discarding a vehicle row if it contains a DoorKing RFID
	 */
	public function dk_vehicle_row_empty_check( $is_empty, $row ) {
		if ( ! empty( $row['dk_windshield'] ) ) {
			return false;
		}
		return $is_empty;
	}

	/**
	 * DoorKing-specific validation rules on a vehicle row
	 */
	public function dk_validate_vehicle_row( $errors, $row ) {
		if ( ! empty( $row['dk_windshield'] ) ) {
			$tag = preg_replace( '/[^0-9]/', '', $row['dk_windshield'] );
			if ( strlen( $tag ) > 5 ) {
				$errors[] = "DoorKing Windshield RFID cannot exceed 5 digits.";
			}
		}
		return $errors;
	}

	/**
	 * Generates a unique 4-digit DoorKing credential code
	 */
	private function generate_unique_code( $type_code ) {
		global $wpdb;
		$blacklist = [ '0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999', '1234', '4321', '2580' ];

		for ( $attempt = 0; $attempt < 100; $attempt++ ) {
			$candidate = sprintf( '%04d', wp_rand( 100, 9999 ) );
			if ( in_array( $candidate, $blacklist, true ) ) {
				continue;
			}

			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM ac_credentials WHERE credential_type = %s AND credential_value = %s LIMIT 1",
				$type_code,
				$candidate
			) );

			if ( ! $exists ) {
				return $candidate;
			}
		}

		return sprintf( '%04d', wp_rand( 1000, 9999 ) );
	}

	/**
	 * Render the UI Segment on the Cardholder Edit Page
	 */
	public function render_ui( $form_data, $is_edit_mode ) {
		global $wpdb;
		$cardholder_id = isset( $form_data['id'] ) ? absint( $form_data['id'] ) : 0;
		$household_id  = isset( $form_data['household_id'] ) ? absint( $form_data['household_id'] ) : 0;

		$creds = [];
		if ( $cardholder_id > 0 ) {
			$query = $wpdb->prepare(
				"SELECT credential_type, credential_value FROM ac_credentials WHERE cardholder_id = %d AND (vehicle_id IS NULL OR vehicle_id = 0)",
				$cardholder_id
			);
			$results = $wpdb->get_results( $query );
			foreach ( $results as $row ) {
				$creds[ $row->credential_type ] = $row->credential_value;
			}
		}

		// 1. Resolve Entry PIN: Existing -> Shared Household PIN -> Auto-generated Unique PIN
		if ( ! empty( $creds['DK_ENTRY_CODE'] ) ) {
			$ent_code_val = $creds['DK_ENTRY_CODE'];
		} else {
			$household_pin = '';
			if ( $household_id > 0 ) {
				$household_pin = $wpdb->get_var( $wpdb->prepare(
					"SELECT cr.credential_value 
					 FROM ac_credentials cr
					 JOIN ac_cardholders c ON cr.cardholder_id = c.id
					 WHERE c.household_id = %d 
					   AND cr.credential_type = 'DK_ENTRY_CODE' 
					   AND cr.credential_value != ''
					 LIMIT 1",
					$household_id
				) );
			}
			$ent_code_val = ! empty( $household_pin ) ? $household_pin : $this->generate_unique_code( 'DK_ENTRY_CODE' );
		}

		// 2. Resolve Directory Code: Existing -> Auto-generated Unique Code
		if ( ! empty( $creds['DK_DIR_CODE'] ) ) {
			$dir_code_val = $creds['DK_DIR_CODE'];
		} else {
			$dir_code_val = $this->generate_unique_code( 'DK_DIR_CODE' );
		}

		$opt_in_val = isset( $creds['DK_DIR_OPT_IN'] ) ? (string) $creds['DK_DIR_OPT_IN'] : '0';
		?>
		<div class="postbox" style="margin-top: 15px;">
			<div class="inside" style="padding: 10px 15px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
				<span style="font-weight: 600; font-size: 13px; color: #1d2327;">DoorKing Gate Access:</span>

				<div style="display: flex; align-items: center; gap: 8px;">
					<label style="font-size: 12px; color: #50575e;">Directory Code:</label>
					<input type="text"
					       name="dk_creds[DK_DIR_CODE]"
					       value="<?php echo esc_attr( $dir_code_val ); ?>"
					       maxlength="4"
					       pattern="\d*"
					       placeholder="4 digits"
					       style="width: 75px; padding: 3px; font-size: 12px;" />
				</div>

				<div style="display: flex; align-items: center; gap: 8px;">
					<label style="font-size: 12px; color: #50575e;">Keypad PIN:</label>
					<input type="text"
					       name="dk_creds[DK_ENTRY_CODE]"
					       value="<?php echo esc_attr( $ent_code_val ); ?>"
					       maxlength="4"
					       pattern="\d*"
					       placeholder="4 digits"
					       style="width: 75px; padding: 3px; font-size: 12px;" />
				</div>

				<div style="display: flex; align-items: center; gap: 6px;">
					<label style="font-size: 12px; color: #50575e; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
						<input type="checkbox"
						       name="dk_creds[DK_DIR_OPT_IN]"
						       value="1"
						       <?php checked( $opt_in_val, '1' ); ?> />
						Authorize Gate Directory Listing
					</label>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Validate and save DoorKing credentials during cardholder save routine
	 */
	public function validate_and_save_credentials( $result, $post_data, $existing_data, $cardholder_id, $is_update ) {
		if ( ! isset( $post_data['dk_creds'] ) || ! is_array( $post_data['dk_creds'] ) ) {
			return $result;
		}

		global $wpdb;

		// 1. Save / Update personal Directory Code and Entry PIN
		$code_types = [
			'DK_DIR_CODE'   => 4,
			'DK_ENTRY_CODE' => 4,
		];

		foreach ( $code_types as $type_code => $max_len ) {
			$val = isset( $post_data['dk_creds'][ $type_code ] ) ? preg_replace( '/[^0-9]/', '', $post_data['dk_creds'][ $type_code ] ) : '';

			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM ac_credentials WHERE cardholder_id = %d AND credential_type = %s AND (vehicle_id IS NULL OR vehicle_id = 0)",
				$cardholder_id,
				$type_code
			) );

			if ( $val !== '' ) {
				if ( $exists ) {
					$wpdb->update( 'ac_credentials', [ 'credential_value' => $val ], [ 'id' => $exists ] );
				} else {
					$wpdb->insert( 'ac_credentials', [
						'cardholder_id'    => $cardholder_id,
						'credential_type'  => $type_code,
						'credential_value' => $val,
						'status'           => 'valid',
						'issue_date'       => current_time( 'Y-m-d' )
					] );
				}
			} elseif ( $exists ) {
				$wpdb->delete( 'ac_credentials', [ 'id' => $exists ] );
			}
		}

		// 2. Save / Update Directory Opt-In Authorization
		$opt_in_submitted = ! empty( $post_data['dk_creds']['DK_DIR_OPT_IN'] ) ? '1' : '0';
		$opt_in_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM ac_credentials WHERE cardholder_id = %d AND credential_type = 'DK_DIR_OPT_IN'",
			$cardholder_id
		) );

		if ( $opt_in_exists ) {
			$wpdb->update( 'ac_credentials', [ 'credential_value' => $opt_in_submitted ], [ 'id' => $opt_in_exists ] );
		} else {
			$wpdb->insert( 'ac_credentials', [
				'cardholder_id'    => $cardholder_id,
				'credential_type'  => 'DK_DIR_OPT_IN',
				'credential_value' => $opt_in_submitted,
				'status'           => 'valid',
				'issue_date'       => current_time( 'Y-m-d' )
			] );
		}

		return $result;
	}

	/**
	 * Inject the Header Column for the Vehicle Table
	 */
	public function render_vehicle_header() {
		echo '<th style="padding: 6px; width: 20%;">Windshield RFID</th>';
	}

	/**
	 * Inject the Windshield RFID input for a specific vehicle row
	 */
	public function render_vehicle_row_column( $v_id, $index ) {
		global $wpdb;
		$rfid_val = '';

		if ( $v_id > 0 ) {
			$rfid_val = $wpdb->get_var( $wpdb->prepare(
				"SELECT credential_value FROM ac_credentials WHERE vehicle_id = %d AND credential_type = 'DK_WINDSHIELD'",
				$v_id
			) );
		}
		?>
		<td style="padding: 4px;">
			<input type="text"
			       name="vehicle_rows[<?php echo esc_attr( $index ); ?>][dk_windshield]"
			       value="<?php echo esc_attr( $rfid_val ); ?>"
			       maxlength="5"
			       pattern="\d*"
			       style="width: 100%; padding: 2px; font-size: 12px;" />
		</td>
		<?php
	}

	/**
	 * Catch vehicle row saves and update the DK_WINDSHIELD credential
	 */
	public function save_vehicle_credential( $vehicle_id, $raw_row, $cardholder_id ) {
		if ( ! isset( $raw_row['dk_windshield'] ) ) {
			return;
		}

		global $wpdb;

		$val       = preg_replace( '/[^0-9]/', '', $raw_row['dk_windshield'] );
		$type_code = 'DK_WINDSHIELD';

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM ac_credentials WHERE vehicle_id = %d AND credential_type = %s",
			$vehicle_id,
			$type_code
		) );

		if ( $val !== '' ) {
			if ( $exists ) {
				$wpdb->update( 'ac_credentials',
					[ 'credential_value' => $val, 'cardholder_id' => $cardholder_id ],
					[ 'id' => $exists ]
				);
			} else {
				$wpdb->insert( 'ac_credentials', [
					'cardholder_id'    => $cardholder_id,
					'vehicle_id'       => $vehicle_id,
					'credential_type'  => $type_code,
					'credential_value' => $val,
					'status'           => 'valid',
					'issue_date'       => current_time( 'Y-m-d' )
				] );
			}
		} elseif ( $exists ) {
			$wpdb->delete( 'ac_credentials', [ 'id' => $exists ] );
		}
	}

    /**
     * Generate the CSV for RAM Import and write to disk
     */
    public function generate_csv() {
        global $wpdb;
        if ( wp_doing_ajax() ) {
            check_ajax_referer( 'fsbhoa_dk_settings_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Permission denied.', 403 );
            }
        }

        $csv_path  = get_option( 'fsbhoa_dk_csv_path', '/mnt/nas/doorking_sync/import.csv' );
        $lock_path = get_option( 'fsbhoa_dk_lock_path', '/mnt/nas/doorking_sync/import.lock' );
        $sec_level = get_option( 'fsbhoa_dk_security_level', '01' );

        // Fetch active residents with DoorKing credentials
        $sql = "
            SELECT
                c.id,
                c.household_id,
                c.last_name,
                c.first_name,
                c.phone,
                MAX(CASE WHEN cr.credential_type = 'DK_DIR_CODE' THEN cr.credential_value END) AS dir_code,
                MAX(CASE WHEN cr.credential_type = 'DK_ENTRY_CODE' THEN cr.credential_value END) AS entry_code,
                MAX(CASE WHEN cr.credential_type = 'DK_DIR_OPT_IN' THEN cr.credential_value END) AS dir_opt_in
            FROM ac_cardholders c
            LEFT JOIN ac_credentials cr ON c.id = cr.cardholder_id
            WHERE c.cardholder_status = 'active'
            GROUP BY c.id
            HAVING (dir_code IS NOT NULL OR entry_code IS NOT NULL OR dir_opt_in IS NOT NULL)
            ORDER BY c.last_name ASC, c.first_name ASC
        ";

        $residents = $wpdb->get_results( $sql );

        $dir = dirname( $csv_path );
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0777, true );
        }

        $output = fopen( $csv_path, 'w' );
        if ( ! $output ) {
            wp_send_json_error( 'Error: Unable to open CSV path for writing: ' . esc_html( $csv_path ) );
        }

        // 1. Build and emit the standard DoorKing RAM header
        $headers = [ 'ACCOUNT', 'Resident', 'H', 'AAC', 'PHONE', 'DIR', 'ENT', 'SL', 'DEVICE#', 'FL', 'ER', 'NOTES', 'VENDOR' ];
        for ( $i = 2; $i <= 26; $i++ ) {
            $headers[] = 'DEVICE' . $i;
            $headers[] = 'NOTES' . $i;
        }
        fputcsv( $output, $headers );

        // 2. Emit data rows
        foreach ( $residents as $res ) {
            // Format combined name: "LAST, FIRST" capped to 15 chars
            $combined_name = trim( $res->last_name . ', ' . $res->first_name );
            $resident_name = substr( strtoupper( $combined_name ), 0, 15 );

            // Gate Directory Privacy check
            $is_authorized = ( $res->dir_opt_in === '1' && ! empty( $res->phone ) );
            $phone_clean   = $is_authorized ? preg_replace( '/[^0-9]/', '', $res->phone ) : '';

            $aac   = '';
            $phone = '';
            if ( ! empty( $phone_clean ) ) {
                if ( strlen( $phone_clean ) === 10 ) {
                    $aac   = substr( $phone_clean, 0, 3 );
                    $phone = substr( $phone_clean, 3, 7 );
                } elseif ( strlen( $phone_clean ) === 7 ) {
                    $aac   = '661';
                    $phone = $phone_clean;
                }
            }

            $dir_code   = $is_authorized ? ( $res->dir_code ?? '' ) : '';
            $entry_code = $res->entry_code ?? '';

            // Query all active windshield tags and vehicle info for this person and their household
            $tag_query = "
                SELECT cr.credential_value, v.make, v.model, v.license_plate
                FROM ac_credentials cr
                LEFT JOIN ac_vehicles v ON cr.vehicle_id = v.vehicle_id
                WHERE cr.credential_type = 'DK_WINDSHIELD'
                  AND cr.status = 'valid'
                  AND (cr.cardholder_id = %d OR (v.household_id IS NOT NULL AND v.household_id = %d))
                ORDER BY cr.id ASC
            ";
            $vehicle_rows = $wpdb->get_results( $wpdb->prepare( $tag_query, $res->id, (int) $res->household_id ) );

            // Primary Device (Slot 1)
            $dev1  = '';
            $note1 = '';
            if ( ! empty( $vehicle_rows[0] ) ) {
                $dev1  = $vehicle_rows[0]->credential_value;
                $desc  = trim( ( $vehicle_rows[0]->make ?? '' ) . ' ' . ( $vehicle_rows[0]->model ?? '' ) );
                $plate = $vehicle_rows[0]->license_plate ? '#' . $vehicle_rows[0]->license_plate : '';
                $note1 = substr( trim( $desc . ' ' . $plate ), 0, 20 );
            }

            // Assemble base row columns
            $row = [
                'NORTH GATES',   // ACCOUNT
                $resident_name,  // Resident
                '0',             // H
                $aac,            // AAC
                $phone,          // PHONE
                $dir_code,       // DIR
                $entry_code,     // ENT
                $sec_level,      // SL
                $dev1,           // DEVICE#
                '0',             // FL
                '0',             // ER
                $note1,          // NOTES
                'N'              // VENDOR
            ];

            // Fill DEVICE2/NOTES2 through DEVICE26/NOTES26
            for ( $i = 2; $i <= 26; $i++ ) {
                $index = $i - 1; // 0-based index into $vehicle_rows
                if ( ! empty( $vehicle_rows[ $index ] ) ) {
                    $tag_val   = $vehicle_rows[ $index ]->credential_value;
                    $veh_desc  = trim( ( $vehicle_rows[ $index ]->make ?? '' ) . ' ' . ( $vehicle_rows[ $index ]->model ?? '' ) );
                    $veh_plate = $vehicle_rows[ $index ]->license_plate ? '#' . $vehicle_rows[ $index ]->license_plate : '';
                    $row[]     = $tag_val;
                    $row[]     = substr( trim( $veh_desc . ' ' . $veh_plate ), 0, 20 );
                } else {
                    $row[] = '';
                    $row[] = '';
                }
            }

            fputcsv( $output, $row );
        }

        fclose( $output );

        file_put_contents( $lock_path, time() );

        wp_send_json_success( 'DoorKing sync files written successfully.' );
    }
}

// Initialize the plugin
new FSBHOA_AC_DoorKing();

