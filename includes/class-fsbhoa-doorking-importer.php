<?php
/**
 * One-time parser to read the DoorKing export CSV and backfill RFIDs, Directory Codes, and Vehicle data.
 */
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . 'class-fsbhoa-doorking-vendor-importer.php';

class Fsbhoa_DoorKing_Importer {

	private $is_dry_run = false;
	private $log_only_errors = false;
	private $dry_run_log = [];

	// Curated explicit alias mapping for corner cases (DoorKing string => WP Target Name)
	private $explicit_aliases = [
		'ACOSTA T7K'      => [ 'first' => 'Kristi', 'last' => 'Acosta' ],
        'ALLEN, K&E'      => [ 'first' => 'Karen', 'last' => 'Allen' ],
        'ARRIBILAGA'      => [ 'first' => 'Terry', 'last' => 'Andrews' ],  // also Arribillaga
        'ANYON, SHARON'   => [ 'first' => 'Sharon', 'last' => 'Anyan' ],
		'BIEN & HEE YONG' => [ 'first' => 'Sharon Heeyong', 'last' => 'Bien' ],
		'BLACK, D & R'    => [ 'first' => 'Debbie', 'last' => 'Black' ],
		'BLACKWOOD'       => [ 'first' => 'Jeannette E', 'last' => 'Blackwood' ],
		'D+C HUSS'        => [ 'first' => 'Dale', 'last' => 'Huss' ],
		'DAILY-WEBB, R'   => [ 'first' => 'Roxann', 'last' => 'Dailey-Webb' ],
		'HUGHES, J & W'   => [ 'first' => 'Judy', 'last' => 'Pittario-Hughes' ],
		'KLINK, STEPHAN'  => [ 'first' => 'Stephen', 'last' => 'Klink' ],
		'KO, YOUNG AE'    => [ 'first' => 'Young Ae', 'last' => 'Ko' ],
		'KREBS-CODDINGTO' => [ 'first' => 'Ginny', 'last' => 'Krebs' ],
		'L DIAZ'          => [ 'first' => 'Laura Rocha', 'last' => 'Diaz' ],
		'MARTINEZ,PHYLIS' => [ 'first' => 'Phyllis', 'last' => 'Martinez' ],
		'OBRIEN, TERRY'   => [ 'first' => 'Terry', 'last' => "O'Brien" ],
		'RAMIREZ, G'      => [ 'first' => 'Guadalupe', 'last' => 'Ramirez' ],
		'RODACKER B.'     => [ 'first' => 'Elizabeth', 'last' => 'Rodacker' ],
		'ROMM, BETTY'     => [ 'first' => 'Betty', 'last' => 'Poe' ],
		'RUDE-ODELL'      => [ 'first' => 'James', 'last' => 'Rude' ],
		'STEVENSON, P&R'  => [ 'first' => 'Richard', 'last' => 'Stevenson' ],
		'STOCK, LOREN'    => [ 'first' => 'Loren W', 'last' => 'Stock' ],
        'THELEN, S'       => [ 'first' => 'Sara', 'last' => 'Allen' ],
        'THEIEN, SARA'    => [ 'first' => 'Sara', 'last' => 'Allen' ],
		'THOMAS, BILLY'   => [ 'first' => 'William', 'last' => 'Thomas' ],
		'WILLIAM,GREG'    => [ 'first' => 'Greg', 'last' => 'Willmon' ],
		'WILLIAMS,J + C'  => [ 'first' => 'Jeff', 'last' => 'Williams' ],
		'YATES, C & R'    => [ 'first' => 'Carole', 'last' => 'Yates' ],
		'YOUSEFZADEH, M'  => [ 'first' => 'Peylohi', 'last' => 'Yousefzadeh' ],

        // Typos and Discrepancies
        'ALVEREZ, L&K'    => [ 'first' => '%', 'last' => 'Alvarez' ],
        'BLAKENSHIP D'    => [ 'first' => '%', 'last' => 'Blankenship' ],
        'NUEBERT'         => [ 'first' => '%', 'last' => 'Neubert' ],
        'RIVERIA M & D'   => [ 'first' => '%', 'last' => 'Rivera' ],
        'VIGNORLI, E'     => [ 'first' => '%', 'last' => 'Vignolo' ],
        'WALZTMAN, R'     => [ 'first' => '%', 'last' => 'Waltzman' ],
        'ZAGUURSKI, H'    => [ 'first' => '%', 'last' => 'Zagurski' ],
        'ORTIZ.CYNTHIA'   => [ 'first' => 'Cynthia', 'last' => 'Ortiz' ],
        'JOSH MACIAS'     => [ 'first' => 'Josh', 'last' => 'Macias' ],
        'PIRRELO LAURA'   => [ 'first' => 'Laura', 'last' => 'Pirrello' ],
	];

	public function __construct() {
		add_action( 'fsbhoa_register_admin_submenus', [ $this, 'add_submenu' ] );
		add_action( 'admin_post_fsbhoa_doorking_import', [ $this, 'process_import' ] );
	}

	public function add_submenu( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			'DoorKing Backfill',
			'DoorKing Backfill',
			'manage_options',
			'fsbhoa_doorking_backfill',
			[ $this, 'render_page' ],
			17
		);
	}

    public function render_page() {
        ?>
        <div class="wrap">
            <h1>DoorKing Baseline Backfill</h1>
            <p>Upload your DoorKing <code>export.csv</code> file. Select whether you are matching and backfilling <strong>Residents</strong> or importing and organizing <strong>Vendors &amp; Contractors</strong>.</p>

            <?php
            // Check both resident and vendor dry-run transients
            $dry_run_results = get_transient( 'fsbhoa_dk_dry_run_' . get_current_user_id() );
            if ( false === $dry_run_results ) {
                $dry_run_results = get_transient( 'fsbhoa_dk_vendor_dry_run_' . get_current_user_id() );
            }

            if ( false !== $dry_run_results ) {
                echo '<div class="notice notice-warning is-dismissible"><p><strong>Dry Run Complete (No data was saved).</strong> Review the log below.</p>';
                echo '<textarea style="width: 100%; height: 450px; font-family: monospace; font-size: 12px; margin-bottom: 10px; padding: 10px; background: #f0f0f1; border: 1px solid #ccc; white-space: pre;" readonly>';
                echo esc_textarea( implode( "\n", $dry_run_results ) );
                echo '</textarea></div>';
                delete_transient( 'fsbhoa_dk_dry_run_' . get_current_user_id() );
                delete_transient( 'fsbhoa_dk_vendor_dry_run_' . get_current_user_id() );
            }
            ?>

            <?php if ( isset( $_GET['imported'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Successfully processed <strong><?php echo absint( $_GET['imported'] ); ?></strong> records.</p></div>
            <?php endif; ?>

            <?php if ( isset( $_GET['error'] ) ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( urldecode( $_GET['error'] ) ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccc; max-width: 650px;">
                <input type="hidden" name="action" value="fsbhoa_doorking_import">
                <?php wp_nonce_field( 'fsbhoa_doorking_import_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="dk_csv">DoorKing CSV File</label></th>
                        <td><input type="file" name="dk_csv" id="dk_csv" accept=".csv" required></td>
                    </tr>
                    <tr>
                        <th scope="row">Import Target</th>
                        <td>
                            <label style="margin-right: 18px;">
                                <input type="radio" name="import_target" value="residents" checked="checked">
                                <strong>Residents</strong> (Backfill RFIDs, Directory Codes &amp; Vehicles)
                            </label>
                            <br><br>
                            <label>
                                <input type="radio" name="import_target" value="vendors">
                                <strong>Vendors &amp; Contractors</strong> (Parse V- Accounts, PINs &amp; Fobs)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Execution Mode</th>
                        <td>
                            <label style="display:block; margin-bottom: 8px;">
                                <input type="checkbox" name="dry_run" value="1" checked="checked">
                                <strong>Dry Run</strong> (Simulate without saving to database)
                            </label>
                            <label style="display:block;">
                                <input type="checkbox" name="log_only_errors" value="1" checked="checked">
                                <strong>Exceptions Only Log</strong> (Resident mode: hide successful matches)
                            </label>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary">Execute Import</button>
                </p>
            </form>
        </div>
        <?php
    }


	private function fuzzy_name_match( $surname_tokens, $initials, $full_resident_string = '' ) {
		global $wpdb;
		$candidates = [];

		foreach ( $surname_tokens as $token ) {
			$token = trim( $token );
			if ( strlen( $token ) < 2 || strtoupper( $token ) === 'AND' ) {
				continue;
			}

            $query = "
                SELECT id, household_id, property_id, first_name, last_name, cardholder_status
                FROM ac_cardholders
                WHERE last_name LIKE %s
            ";
			$token_candidates = $wpdb->get_results( $wpdb->prepare( $query, $token . '%' ) );
			if ( ! empty( $token_candidates ) ) {
				$candidates = array_merge( $candidates, $token_candidates );
			}
		}

		$candidates = array_values( array_column( $candidates, null, 'id' ) );

		if ( empty( $candidates ) ) {
			return null;
		}

		if ( count( $candidates ) === 1 ) {
			return $candidates[0];
		}

		if ( count( $surname_tokens ) > 1 ) {
			$households = [];
			foreach ( $candidates as $candidate ) {
				$households[ $candidate->household_id ][] = $candidate;
			}

			foreach ( $households as $hh_id => $members ) {
				$matched_tokens_found = 0;
				foreach ( $surname_tokens as $token ) {
					$token = strtolower( trim( $token ) );
					foreach ( $members as $member ) {
						if ( strpos( strtolower( $member->last_name ), $token ) !== false ) {
							$matched_tokens_found++;
							break;
						}
					}
				}
				if ( $matched_tokens_found >= 2 || ( count( $surname_tokens ) === 2 && $matched_tokens_found === 2 ) ) {
					return $members[0];
				}
			}
		}

		$household_ids = array_unique( array_column( $candidates, 'household_id' ) );
		if ( count( $household_ids ) === 1 && ! empty( $household_ids[0] ) ) {
			return $candidates[0];
		}

		$all_tokens = preg_split( '/[\s,\/]+/', trim( $full_resident_string ) );
		foreach ( $candidates as $candidate ) {
			foreach ( $all_tokens as $token ) {
				if ( strcasecmp( trim( $token ), trim( $candidate->first_name ) ) === 0 ) {
					return $candidate;
				}
			}
		}

		if ( ! empty( $initials ) ) {
			$households = [];
			foreach ( $candidates as $candidate ) {
				$households[ $candidate->household_id ][] = $candidate;
			}

			foreach ( $households as $hh_id => $members ) {
				$member_initials = [];
				foreach ( $members as $member ) {
					$member_initials[] = strtoupper( substr( trim( $member->first_name ), 0, 1 ) );
					preg_match_all( '/\b([A-Z])\b/i', $member->first_name . ' ' . $member->last_name, $name_matches );
					if ( ! empty( $name_matches[1] ) ) {
						foreach ( $name_matches[1] as $nm ) {
							$member_initials[] = strtoupper( $nm );
						}
					}
				}
				$member_initials = array_unique( $member_initials );

				$all_found = true;
				foreach ( $initials as $dk_init ) {
					if ( ! in_array( $dk_init, $member_initials, true ) ) {
						$all_found = false;
						break;
					}
				}

				if ( $all_found ) {
					return $members[0];
				}
			}
		}

		return null;
	}

	public function process_import() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'fsbhoa_doorking_import_nonce' ) ) {
			wp_die( 'Unauthorized' );
		}

		if ( empty( $_FILES['dk_csv']['tmp_name'] ) ) {
			wp_redirect( add_query_arg( 'error', urlencode( 'No file uploaded.' ), wp_get_referer() ) );
			exit;
		}

        // Delegate to Vendor Importer if selected
        if ( isset( $_POST['import_target'] ) && 'vendors' === $_POST['import_target'] ) {
            $vendor_importer = new Fsbhoa_DoorKing_Vendor_Importer();
            $vendor_importer->process_file( $_FILES['dk_csv']['tmp_name'], isset( $_POST['dry_run'] ) );
            return;
        }

		$this->is_dry_run      = isset( $_POST['dry_run'] ) ? true : false;
		$this->log_only_errors = isset( $_POST['log_only_errors'] ) ? true : false;
		$this->dry_run_log     = [];

		if ( $this->is_dry_run ) {
			$this->dry_run_log[] = "=== STARTING DRY RUN " . ( $this->log_only_errors ? "(EXCEPTIONS ONLY)" : "(FULL LOG)" ) . " ===";
			$this->dry_run_log[] = "Database writes are DISABLED.";
			$this->dry_run_log[] = "--------------------------------------------------";
		}

		$file = $_FILES['dk_csv']['tmp_name'];
		if ( ( $handle = fopen( $file, 'r' ) ) === false ) {
			wp_redirect( add_query_arg( 'error', urlencode( 'Could not open file.' ), wp_get_referer() ) );
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

        // Locate ACCOUNT column index
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

        if ( ! $name_key || ! isset( $col_map['DEVICE#'] ) ) {
                wp_redirect( add_query_arg( 'error', urlencode( 'Invalid CSV format. Missing "NAME" (or "Resident") or "DEVICE#" columns.' ), wp_get_referer() ) );
                exit;
        }

		while ( ( $data = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
            $account_name = isset( $data[ $account_idx ] ) ? trim( $data[ $account_idx ], " \t\n\r\0\x0B\"'" ) : '';
			$resident_name = trim( $data[ $col_map[ $name_key ] ] ?? '' );
			$phone_raw     = trim( $data[ $col_map['PHONE'] ] ?? '' );
			$aac_raw       = trim( $data[ $col_map['AAC'] ] ?? '' );

            // Strictly skip non-matching accounts
            if ( strcasecmp( $account_name, $target_account ) !== 0 ) {
                continue;
            }

            // Discard empty lines.  They are contractor's test accounts.
            if ( empty( $resident_name ) || $resident_name === '.' ) {
                continue;
            }

			$is_vendor = isset( $col_map['VENDOR'] ) ? trim( $data[ $col_map['VENDOR'] ] ?? '' ) : 'N';
			if ( strtoupper( $is_vendor ) === 'Y' ) {
				continue;
			}

			$surname_tokens = [];
			$name_clean     = str_replace( '&', ' ', $resident_name );
			$raw_parts      = preg_split( '/[\s,\/]+/', $name_clean );

			$initials = [];
			foreach ( $raw_parts as $part ) {
				$part = trim( $part );
				if ( strlen( $part ) === 1 && ctype_alpha( $part ) ) {
					$initials[] = strtoupper( $part );
				} elseif ( strlen( $part ) > 1 ) {
					$surname_tokens[] = $part;
				}
			}

			$aac          = empty( $aac_raw ) ? '661' : preg_replace( '/[^0-9]/', '', $aac_raw );
			$phone_digits = preg_replace( '/[^0-9]/', '', $phone_raw );
			$full_phone   = ( strlen( $phone_digits ) === 7 ) ? $aac . $phone_digits : '';

			$cardholder   = null;
			$match_method = '';

            // 1. Explicit Alias Override  (manually match)
            if ( ! $cardholder && isset( $this->explicit_aliases[ strtoupper( $resident_name ) ] ) ) {
                $alias      = $this->explicit_aliases[ strtoupper( $resident_name ) ];
                $cardholder = $wpdb->get_row( $wpdb->prepare(
                     "SELECT id, household_id, property_id, first_name, last_name, cardholder_status
                      FROM ac_cardholders
                      WHERE first_name LIKE %s AND last_name LIKE %s
                      LIMIT 1",
                     '%' . $alias['first'] . '%',
                     '%' . $alias['last'] . '%'
                ) );
                if ( $cardholder ) {
                    $match_method = "Explicit Alias Map";
                }
            }
            // 2. Phone Match across any status
            if ( ! empty( $full_phone ) ) {
                $cardholder = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, household_id, property_id, first_name, last_name, cardholder_status
                     FROM ac_cardholders
                     WHERE phone = %s
                     LIMIT 1",
                    $full_phone
                ) );
                if ( $cardholder ) {
                    $match_method = "Phone Match ({$full_phone})";
                }
            }

			// 3. Fuzzy Name Match
			if ( ! $cardholder ) {
				$cardholder = $this->fuzzy_name_match( $surname_tokens, $initials, $resident_name );
				if ( $cardholder ) {
					$match_method = "Fuzzy Name Match";
				}
			}


			// 4. Archived/Survivor Redirect
			if ( ! $cardholder ) {
				$archived_match = null;
				if ( ! empty( $full_phone ) ) {
					$archived_match = $wpdb->get_row( $wpdb->prepare(
						"SELECT id, household_id, property_id, first_name, last_name FROM ac_cardholders WHERE phone = %s AND cardholder_status = 'archived' LIMIT 1",
						$full_phone
					) );
				}

				if ( ! $archived_match && ! empty( $surname_tokens ) ) {
					$archived_match = $wpdb->get_row( $wpdb->prepare(
						"SELECT id, household_id, property_id, first_name, last_name FROM ac_cardholders WHERE last_name LIKE %s AND cardholder_status = 'archived' LIMIT 1",
						$surname_tokens[0] . '%'
					) );
				}

				if ( $archived_match && ! empty( $archived_match->property_id ) ) {
					$current_resident = $wpdb->get_row( $wpdb->prepare(
						"SELECT id, household_id, property_id, first_name, last_name, cardholder_status
						 FROM ac_cardholders
						 WHERE property_id = %d
						   AND cardholder_status != 'archived'
						 LIMIT 1",
						$archived_match->property_id
					) );

					if ( $current_resident ) {
						$cardholder   = $current_resident;
						$match_method = "Property Survivor Redirect";
					}
				}
			}

			if ( ! $cardholder ) {
				if ( $this->is_dry_run ) {
					$this->dry_run_log[] = "[SKIP / NOT FOUND] Resident: '{$resident_name}' | Phone: '{$phone_raw}'";
				}
				continue;
			}

			$cardholder_id = absint( $cardholder->id );
			$household_id  = $cardholder->household_id ? absint( $cardholder->household_id ) : null;

			// Ensure matched cardholder has a household assigned
			if ( empty( $household_id ) ) {
				if ( $this->is_dry_run ) {
					if ( ! $this->log_only_errors ) {
						$this->dry_run_log[] = "[MATCH] DK Name: '{$resident_name}' -> DB: {$cardholder->first_name} {$cardholder->last_name} (ID: {$cardholder_id}) [via {$match_method}]";
						$this->dry_run_log[] = "   -> [CREATE HOUSEHOLD] Resident lacked household ID. Creating household.";
					}
					$household_id = 'DRY_RUN_HH_ID';
				} else {
					$wpdb->insert( 'ac_households', [ 'household_name' => trim( $cardholder->last_name ) . ' Household' ] );
					$household_id = $wpdb->insert_id;
					$wpdb->update( 'ac_cardholders', [ 'household_id' => $household_id ], [ 'id' => $cardholder_id ] );
				}
			} elseif ( $this->is_dry_run && ! $this->log_only_errors ) {
				$this->dry_run_log[] = "[MATCH] DK Name: '{$resident_name}' -> DB: {$cardholder->first_name} {$cardholder->last_name} (ID: {$cardholder_id}) [via {$match_method}]";
			}

			// 1. Personal Codes: Upsert DIR_CODE, DIR_OPT_IN, and ENTRY_CODE (Works with or without vehicle)
			$dir_code = trim( $data[ $col_map['DIR'] ] ?? '' );
			$ent_code = trim( $data[ $col_map['ENT'] ] ?? '' );

            // Get household members to distribute codes
            $household_members = [];
            if ( ! empty( $household_id ) && $household_id !== 'DRY_RUN_HH_ID' ) {
                $household_members = $wpdb->get_col( $wpdb->prepare(
                    "SELECT id FROM ac_cardholders WHERE household_id = %d AND cardholder_status = 'active'",
                    $household_id
                ) );
            }
            if ( empty( $household_members ) ) {
                $household_members = [ $cardholder_id ];
            }
            // Assign the exact same DIR and ENTRY codes to all members of the household
            foreach ( $household_members as $m_id ) {
                if ( ! empty( $dir_code ) ) {
                    $this->upsert_credential( $m_id, null, 'DK_DIR_CODE', $dir_code );
                    if ( ! empty( $full_phone ) ) {
                        $this->upsert_credential( $m_id, null, 'DK_DIR_OPT_IN', '1' );
                    }
                }
            
                if ( ! empty( $ent_code ) ) {
                    $clean_ent = preg_replace( '/[^0-9]/', '', $ent_code );
                    $this->upsert_credential( $m_id, null, 'DK_ENTRY_CODE', $clean_ent );
                }
            }


			// 2. Vehicles and Windshield RFIDs: Upsert based on physical tag identity
			$devices_to_check = [ [ 'dev' => 'DEVICE#', 'note' => 'NOTES' ] ];
			for ( $i = 2; $i <= 26; $i++ ) {
				$devices_to_check[] = [ 'dev' => 'DEVICE' . $i, 'note' => 'NOTES' . $i ];
			}

			foreach ( $devices_to_check as $fields ) {
				if ( ! isset( $col_map[ $fields['dev'] ] ) ) {
					continue;
				}

				$rfid = trim( $data[ $col_map[ $fields['dev'] ] ] ?? '' );
				$note = trim( $data[ $col_map[ $fields['note'] ] ] ?? '' );
				$rfid = preg_replace( '/[^0-9]/', '', $rfid );

				// Validate 5-digit gate tag format
				if ( ! empty( $rfid ) ) {
					if ( strlen( $rfid ) !== 5 || substr( $rfid, 0, 1 ) !== '1' ) {
						if ( $this->is_dry_run && ! $this->log_only_errors ) {
							$this->dry_run_log[] = "   -> [IGNORED] Tag '{$rfid}' is a 4-digit Pedestrian Tag. Skipping.";
						}
						$rfid = '';
					}
				}

				// Only create/link vehicle records if a valid windshield RFID exists
				if ( ! empty( $rfid ) && $household_id ) {
					$plate      = '';
					$make_model = $note;

					if ( ! empty( $note ) ) {
						if ( preg_match( '/#?([0-9][A-Z]{3}[0-9]{3})\b/i', $note, $matches ) ||
						     preg_match( '/\b(?=.*[0-9])(?=.*[A-Z])[A-Z0-9]{5,8}\b/i', $note, $matches ) ) {
							$plate      = strtoupper( isset( $matches[1] ) ? $matches[1] : $matches[0] );
							$make_model = str_ireplace( [ '#' . $plate, $plate ], '', $note );
						}

						$make_model = preg_replace( '/\b(AA|TB)\b/i', '', $make_model );
						$make_model = trim( trim( $make_model, '- ,#' ) );
					}

					$final_plate = empty( $plate ) ? null : substr( $plate, 0, 20 );
					$safe_make   = substr( $make_model, 0, 50 );

					// Anchor vehicle resolution directly to the physical RFID tag
					$existing_cred = $wpdb->get_row( $wpdb->prepare(
						"SELECT id, vehicle_id FROM ac_credentials WHERE credential_type = 'DK_WINDSHIELD' AND credential_value = %s LIMIT 1",
						$rfid
					) );

					$vehicle_id = null;

					if ( $this->is_dry_run ) {
						if ( ! $this->log_only_errors ) {
							$status_str          = $existing_cred ? "[EXISTING TAG {$rfid}]" : "[NEW TAG {$rfid}]";
							$this->dry_run_log[] = "   -> {$status_str} Make: '{$safe_make}' | Plate: " . ( $final_plate ?? 'NULL' );
						}
						$vehicle_id = 'DRY_RUN_VEH_ID';
					} else {
						if ( $existing_cred && ! empty( $existing_cred->vehicle_id ) ) {
							$vehicle_id = absint( $existing_cred->vehicle_id );
							$wpdb->update( 'ac_vehicles', [
								'household_id'  => $household_id,
								'make'          => $safe_make,
								'license_plate' => $final_plate
							], [ 'vehicle_id' => $vehicle_id ] );
						} else {
							$wpdb->insert( 'ac_vehicles', [
								'household_id'  => $household_id,
								'make'          => $safe_make,
								'license_plate' => $final_plate
							] );
							$vehicle_id = $wpdb->insert_id;
						}
					}

					$this->upsert_credential( $cardholder_id, $vehicle_id, 'DK_WINDSHIELD', $rfid );
				}
			}

			if ( $this->is_dry_run && ! $this->log_only_errors ) {
				$this->dry_run_log[] = "";
			}

			$imported_count++;
		}

		fclose( $handle );

		if ( $this->is_dry_run ) {
			set_transient( 'fsbhoa_dk_dry_run_' . get_current_user_id(), $this->dry_run_log, 300 );
			wp_redirect( add_query_arg( 'dry_run_complete', '1', wp_get_referer() ) );
			exit;
		}

		wp_redirect( add_query_arg( 'imported', $imported_count, wp_get_referer() ) );
		exit;
	}

	/**
	 * Strictly idempotent credential upsert.
	 * Personal codes are matched 1:1 on (cardholder_id, credential_type).
	 * Windshield tags are matched globally on (credential_type, credential_value).
	 */
	private function upsert_credential( $cardholder_id, $vehicle_id, $type, $value ) {
		global $wpdb;

		if ( $type === 'DK_WINDSHIELD' ) {
			$existing_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM ac_credentials WHERE credential_type = %s AND credential_value = %s LIMIT 1",
				$type, $value
			) );
		} else {
			$existing_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM ac_credentials WHERE cardholder_id = %d AND credential_type = %s LIMIT 1",
				$cardholder_id, $type
			) );
		}

		$clean_veh_id = ( $vehicle_id === 'DRY_RUN_VEH_ID' || empty( $vehicle_id ) ) ? null : absint( $vehicle_id );

		if ( ! $existing_id ) {
			if ( $this->is_dry_run && ! $this->log_only_errors ) {
				$this->dry_run_log[] = "   -> [ADD] Type: {$type} | Value: {$value}";
			} elseif ( ! $this->is_dry_run ) {
				$wpdb->insert( 'ac_credentials', [
					'cardholder_id'    => $cardholder_id,
					'vehicle_id'       => $clean_veh_id,
					'credential_type'  => $type,
					'credential_value' => $value,
					'status'           => 'active',
					'issue_date'       => current_time( 'Y-m-d' )
				] );
			}
		} else {
			if ( $this->is_dry_run && ! $this->log_only_errors ) {
				$this->dry_run_log[] = "   -> [UPDATE] Type: {$type} | Value: {$value} (ID: {$existing_id})";
			} elseif ( ! $this->is_dry_run ) {
				$data_to_update = [
					'cardholder_id'    => $cardholder_id,
					'credential_value' => $value,
					'status'           => 'active'
				];
				if ( $clean_veh_id !== null ) {
					$data_to_update['vehicle_id'] = $clean_veh_id;
				}
				$wpdb->update( 'ac_credentials', $data_to_update, [ 'id' => $existing_id ] );
			}
		}
	}
}

