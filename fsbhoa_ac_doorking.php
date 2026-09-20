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
        //
        // Hook for merge, restore, and household transfer
        add_action( 'fsbhoa_core_cardholders_merged', [ $this, 'handle_cardholder_merge' ], 10, 4 );
        add_action( 'fsbhoa_core_cardholder_restored', [ $this, 'handle_cardholder_restore' ], 10, 1 );
        add_action( 'fsbhoa_core_cardholder_household_changed', [ $this, 'handle_household_changed' ], 10, 3 );
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
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-fsbhoa-doorking-rotation.php';
        new Fsbhoa_DoorKing_Rotation();
        
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-fsbhoa-doorking-importer.php';
        new Fsbhoa_DoorKing_Importer();

        // Load and instantiate the DoorKing RAM Export & Sync Module
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-fsbhoa-doorking-export.php';
        new Fsbhoa_DoorKing_Export();

        // Hook into the core plugin's UI for the cardholder edit page
        add_action( 'fsbhoa_render_credential_fields', [ $this, 'render_ui' ], 10, 2 );

        // Hook into the core plugin's validation routine (validates format only)
        add_filter( 'fsbhoa_validate_credentials', [ $this, 'validate_credentials' ], 10, 5 );

        // Hook into core broadcast events to persist credentials AFTER cardholder ID exists
        add_action( 'fsbhoa_core_cardholder_created', [ $this, 'save_cardholder_credentials' ], 10, 2 );
        add_action( 'fsbhoa_core_cardholder_updated', [ $this, 'save_cardholder_credentials' ], 10, 3 );

        // Hook into the core vehicle validation filters
        add_filter( 'fsbhoa_is_vehicle_row_empty', [ $this, 'dk_vehicle_row_empty_check' ], 10, 2 );
        add_filter( 'fsbhoa_validate_vehicle_row', [ $this, 'dk_validate_vehicle_row' ], 10, 2 );

        // Register the Go service with the Core System Status dashboard
        add_filter( 'fsbhoa_system_services', [ $this, 'register_system_service' ] );

        // Hook into Core's Cardholder List Status Column
        add_action( 'fsbhoa_cardholder_list_status_icons', [ $this, 'render_status_icon' ] );

        // Hook for merge and restore.
        add_action( 'fsbhoa_core_cardholders_merged', [ $this, 'handle_cardholder_merge' ], 10, 4 );
        add_action( 'fsbhoa_core_cardholder_restored', [ $this, 'handle_cardholder_restore' ], 10, 1 );
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
     * Render the UI Segment on the Cardholder or Vendor Edit Page
     */
    public function render_ui( $form_data, $is_edit_mode ) {
        global $wpdb;
        $cardholder_id   = isset( $form_data['id'] ) ? absint( $form_data['id'] ) : 0;
        $household_id    = isset( $form_data['household_id'] ) ? absint( $form_data['household_id'] ) : 0;
        $cardholder_type = isset( $form_data['cardholder_type'] ) ? $form_data['cardholder_type'] : 'resident';

        // Fetch all non-vehicle credentials for this cardholder
        $all_pins = [];
        $dir_code = '';
        $opt_in   = '0';

        if ( $cardholder_id > 0 ) {
            $query = $wpdb->prepare(
                "SELECT credential_type, credential_value, status
                 FROM ac_credentials
                 WHERE cardholder_id = %d
                   AND (vehicle_id IS NULL OR vehicle_id = 0)
                 ORDER BY id ASC",
                $cardholder_id
            );
            $results = $wpdb->get_results( $query );
            foreach ( $results as $row ) {
                if ( 'DK_ENTRY_CODE' === $row->credential_type ) {
                    $all_pins[] = [
                        'code'   => $row->credential_value,
                        'status' => $row->status,
                    ];
                } elseif ( 'DK_DIR_CODE' === $row->credential_type ) {
                    $dir_code = $row->credential_value;
                } elseif ( 'DK_DIR_OPT_IN' === $row->credential_type ) {
                    $opt_in = $row->credential_value;
                }
            }
        }

        // ==========================================
        // VENDOR DISPLAY (Read-Only PIN Badges / List)
        // ==========================================
        if ( 'vendor' === $cardholder_type ) {
            ?>
            <div class="fsbhoa-form-section">
                <div class="form-row">
                    <div class="form-field" style="width: 100%;">
                        <label style="font-weight: 600; margin-bottom: 6px; display: block;">
                            <?php esc_html_e( 'Gate Keypad Code(s)', 'fsbhoa-ac' ); ?>
                        </label>

                        <?php if ( empty( $all_pins ) ) : ?>
                            <p style="color: #666; font-style: italic; margin: 0; font-size: 13px;">
                                <?php esc_html_e( 'No dedicated keypad PIN assigned (Uses monthly rotating vendor code or RFID windshield tag).', 'fsbhoa-ac' ); ?>
                            </p>
                        <?php elseif ( count( $all_pins ) === 1 ) : ?>
                            <span class="fsbhoa-readonly-field" style="display: inline-block; font-size: 18px; font-weight: bold; letter-spacing: 2px; color: #1d2327; background: #f0f0f1; border: 1px solid #ccd0d4; padding: 4px 12px; border-radius: 4px;">
                                #<?php echo esc_html( $all_pins[0]['code'] ); ?>
                            </span>
                            <?php if ( 'active' !== $all_pins[0]['status'] ) : ?>
                                <span style="color: #d63638; font-weight: 600; font-size: 12px; margin-left: 8px;">(Inactive)</span>
                            <?php endif; ?>
                        <?php else : ?>
                            <!-- Multi-PIN Display (Hall Ambulance, BrightView, etc.) -->
                            <div style="background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px; padding: 10px; max-height: 180px; overflow-y: auto; max-width: 650px;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 6px; border-bottom: 1px solid #ddd; padding-bottom: 4px;">
                                    <strong style="font-size: 12px;"><?php printf( esc_html__( '%d Dedicated PINs Registered', 'fsbhoa-ac' ), count( $all_pins ) ); ?></strong>
                                    <span style="font-size: 11px; color: #666;">DoorKing Sync Protected</span>
                                </div>
                                <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                    <?php foreach ( $all_pins as $p ) : ?>
                                        <span style="font-family: monospace; font-size: 13px; font-weight: 600; padding: 2px 6px; background: #fff; border: 1px solid #ccd0d4; border-radius: 3px; <?php echo ( 'active' !== $p['status'] ) ? 'color: #999; text-decoration: line-through;' : 'color: #2271b1;'; ?>">
                                            #<?php echo esc_html( $p['code'] ); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        // ======================================================
        // RESIDENT DISPLAY (non-editable Directory & Access PIN)
        // ======================================================
        // If adding a member to an existing household, inherit the household's PIN and DIR code:
        //
        // Cardholder Add: If adding John Doe to the "Smith Household", the 
        // form automatically loads the Smith household's PIN and DIR code. 
        // If creating a new household, it generates unique 4-digit codes on the fly.
        //
        $household_pin = '';
        $household_dir = '';
        $household_opt = '0';

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

            $household_dir = $wpdb->get_var( $wpdb->prepare(
                "SELECT cr.credential_value
                 FROM ac_credentials cr
                 JOIN ac_cardholders c ON cr.cardholder_id = c.id
                 WHERE c.household_id = %d
                   AND cr.credential_type = 'DK_DIR_CODE'
                   AND cr.credential_value != ''
                 LIMIT 1",
                $household_id
            ) );

            $household_opt = $wpdb->get_var( $wpdb->prepare(
                "SELECT cr.credential_value
                 FROM ac_credentials cr
                 JOIN ac_cardholders c ON cr.cardholder_id = c.id
                 WHERE c.household_id = %d
                   AND cr.credential_type = 'DK_DIR_OPT_IN'
                 LIMIT 1",
                $household_id
            ) );
        }

        // Determine final values: direct cardholder -> household inherited -> newly generated
        $ent_code_val = ! empty( $all_pins[0]['code'] )
            ? $all_pins[0]['code']
            : ( ! empty( $household_pin ) ? $household_pin : $this->generate_unique_code( 'DK_ENTRY_CODE' ) );

        $dir_code_val = ! empty( $dir_code )
            ? $dir_code
            : ( ! empty( $household_dir ) ? $household_dir : $this->generate_unique_code( 'DK_DIR_CODE' ) );

        $opt_in_val = ( $opt_in !== '0' ) ? $opt_in : ( $household_opt ?: '0' );
        ?>
        <div class="postbox" style="margin-top: 15px;">
            <div class="inside" style="padding: 12px 16px;">
                <!-- Main Controls Line (Prevents wrapping) -->
                <div style="display: flex; align-items: center; gap: 20px; flex-wrap: nowrap;">
                    <span style="font-weight: 600; font-size: 13px; color: #1d2327; white-space: nowrap;">
                        DoorKing Gate Access:
                    </span>

                    <!-- Directory Code Badge -->
                    <div style="display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">
                        <label style="font-size: 12px; color: #50575e;">Directory Code:</label>
                        <input type="hidden" name="dk_creds[DK_DIR_CODE]" value="<?php echo esc_attr( $dir_code_val ); ?>" />
                        <span style="font-family: monospace; font-size: 13px; font-weight: bold; background: #f0f0f1; border: 1px solid #ccd0d4; padding: 2px 7px; border-radius: 3px; color: #1d2327;">
                            [ <?php echo esc_html( $dir_code_val ); ?> ]
                        </span>
                    </div>

                    <!-- Gate Code / Keypad PIN Badge -->
                    <div style="display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">
                        <label style="font-size: 12px; color: #50575e;">Gate Code:</label>
                        <input type="hidden" name="dk_creds[DK_ENTRY_CODE]" value="<?php echo esc_attr( $ent_code_val ); ?>" />
                        <span style="font-family: monospace; font-size: 13px; font-weight: bold; background: #f0f0f1; border: 1px solid #ccd0d4; padding: 2px 7px; border-radius: 3px; color: #1d2327;">
                            [ #<?php echo esc_html( $ent_code_val ); ?> ]
                        </span>
                    </div>

                    <!-- Shortened Opt-In Checkbox -->
                    <div style="display: inline-flex; align-items: center; white-space: nowrap;">
                        <label style="font-size: 12px; color: #1d2327; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                            <input type="checkbox"
                                   name="dk_creds[DK_DIR_OPT_IN]"
                                   value="1"
                                   style="margin: 0;"
                                   <?php checked( $opt_in_val, '1' ); ?> />
                            Opt-in Gate Directory
                        </label>
                    </div>
                </div>

                <!-- Helper Note Below -->
                <p class="description" style="margin: 6px 0 0 0; font-size: 11px; color: #646970; font-style: italic;">
                    Note: all household members share the same Gate Code and Directory Code.
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Validate DoorKing credentials during form submission.
     */
    public function validate_credentials( $result, $post_data, $existing_data, $cardholder_id, $is_update ) {
        if ( ! isset( $post_data['dk_creds'] ) || ! is_array( $post_data['dk_creds'] ) ) {
            return $result;
        }

        if ( ! empty( $post_data['dk_creds']['DK_ENTRY_CODE'] ) ) {
            $code = preg_replace( '/[^0-9]/', '', $post_data['dk_creds']['DK_ENTRY_CODE'] );
            if ( strlen( $code ) !== 4 ) {
                $result['errors']['dk_entry_code'] = __( 'DoorKing Keypad PIN must be exactly 4 digits.', 'fsbhoa-ac-doorking' );
            }
        }

        if ( ! empty( $post_data['dk_creds']['DK_DIR_CODE'] ) ) {
            $dir = preg_replace( '/[^0-9]/', '', $post_data['dk_creds']['DK_DIR_CODE'] );
            if ( strlen( $dir ) > 4 ) {
                $result['errors']['dk_dir_code'] = __( 'DoorKing Directory Code cannot exceed 4 digits.', 'fsbhoa-ac-doorking' );
            }
        }

        return $result;
    }

    /**
     * Save DoorKing credentials and propagate household opt-in.
     */
    public function save_cardholder_credentials( $cardholder_id, $data_saved, $existing_data = [] ) {
        if ( empty( $cardholder_id ) ) {
            return;
        }

        $cardholder_type = $data_saved['cardholder_type'] ?? 'resident';

        if ( 'vendor' === $cardholder_type ) {
            return;
        }

        if ( ! isset( $_POST['dk_creds'] ) || ! is_array( $_POST['dk_creds'] ) ) {
            return;
        }

        global $wpdb;
        $post_creds = $_POST['dk_creds'];

        // 1. Persist Directory Code & Keypad PIN for this individual
        $code_types = [ 'DK_DIR_CODE', 'DK_ENTRY_CODE' ];

        foreach ( $code_types as $type_code ) {
            if ( ! isset( $post_creds[ $type_code ] ) ) {
                continue;
            }

            $val = preg_replace( '/[^0-9]/', '', $post_creds[ $type_code ] );
            if ( '' === $val ) {
                continue;
            }

            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM ac_credentials
                 WHERE cardholder_id = %d
                   AND credential_type = %s
                   AND (vehicle_id IS NULL OR vehicle_id = 0)
                 LIMIT 1",
                $cardholder_id,
                $type_code
            ) );

            if ( $exists ) {
                $wpdb->update( 'ac_credentials', [
                    'credential_value' => $val,
                    'status'           => 'active',
                ], [ 'id' => $exists ] );
            } else {
                $wpdb->insert( 'ac_credentials', [
                    'cardholder_id'    => $cardholder_id,
                    'credential_type'  => $type_code,
                    'credential_value' => $val,
                    'status'           => 'active',
                    'issue_date'       => current_time( 'Y-m-d' ),
                ] );
            }
        }

        // 2. Persist Opt-In and synchronize across all household members
        $opt_in_submitted = ! empty( $post_creds['DK_DIR_OPT_IN'] ) ? '1' : '0';

        // Find all active members sharing this household
        $household_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT household_id FROM ac_cardholders WHERE id = %d",
            $cardholder_id
        ) );

        $target_ids = [ $cardholder_id ];
        if ( ! empty( $household_id ) ) {
            $members = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM ac_cardholders WHERE household_id = %d AND cardholder_status != 'archived'",
                $household_id
            ) );
            if ( ! empty( $members ) ) {
                $target_ids = array_map( 'absint', $members );
            }
        }

        foreach ( $target_ids as $member_id ) {
            $opt_in_exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM ac_credentials WHERE cardholder_id = %d AND credential_type = 'DK_DIR_OPT_IN'",
                $member_id
            ) );

            if ( $opt_in_exists ) {
                $wpdb->update(
                    'ac_credentials',
                    [ 'credential_value' => $opt_in_submitted, 'status' => 'active' ],
                    [ 'id' => $opt_in_exists ]
                );
            } else {
                $wpdb->insert( 'ac_credentials', [
                    'cardholder_id'    => $member_id,
                    'credential_type'  => 'DK_DIR_OPT_IN',
                    'credential_value' => $opt_in_submitted,
                    'status'           => 'active',
                    'issue_date'       => current_time( 'Y-m-d' ),
                ] );
            }
        }
    }

    /**
     * Renders a keypad icon in the cardholder list status column if a valid PIN exists
     */
    public function render_status_icon( $cardholder ) {
        global $wpdb;
        $cardholder_id = absint( $cardholder['id'] ?? 0 );
        $household_id  = absint( $cardholder['household_id'] ?? 0 );

        if ( ! $cardholder_id ) {
            echo '<span style="display: inline-block; width: 16px;"></span>';
            return;
        }

        // Check direct cardholder PIN first
        $has_pin = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM ac_credentials
             WHERE cardholder_id = %d
               AND credential_type = 'DK_ENTRY_CODE'
               AND credential_value != ''
               AND status = 'active'
             LIMIT 1",
            $cardholder_id
        ) );

        // Fallback to household-level PIN
        if ( ! $has_pin && $household_id > 0 ) {
            $has_pin = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM ac_credentials cr
                 JOIN ac_cardholders c ON cr.cardholder_id = c.id
                 WHERE c.household_id = %d
                   AND cr.credential_type = 'DK_ENTRY_CODE'
                   AND cr.credential_value != ''
                   AND cr.status = 'active'
                 LIMIT 1",
                $household_id
            ) );
        }

        if ( $has_pin ) {
            echo '<span class="dashicons dashicons-admin-network" title="' . esc_attr__( 'DoorKing Keypad PIN active', 'fsbhoa-ac-doorking' ) . '" style="font-size: 16px; width: 16px; height: 16px; color: #d63638;"></span>';
        } else {
            echo '<span style="display: inline-block; width: 16px;"></span>';
        }
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
                    [ 'credential_value' => $val, 'cardholder_id' => $cardholder_id, 'status' => 'active' ],
                    [ 'id' => $exists ]
                );
            } else {
                $wpdb->insert( 'ac_credentials', [
                    'cardholder_id'    => $cardholder_id,
                    'vehicle_id'       => $vehicle_id,
                    'credential_type'  => $type_code,
                    'credential_value' => $val,
                    'status'           => 'active',
                    'issue_date'       => current_time( 'Y-m-d' )
                ] );
            }
        } elseif ( $exists ) {
            $wpdb->delete( 'ac_credentials', [ 'id' => $exists ] );
        }
    }


    /**
     * Reconciles DoorKing credentials when two cardholders are merged.
     * Rule: Prefer destination household values. If destination has none, take source values.
     * Synchronizes all 3 fields (DIR, ENTRY, OPT_IN) across all destination household members.
     */
    public function handle_cardholder_merge( $destination_id, $source_id, $dest_record, $source_record ) {
        global $wpdb;

        $dest_hh_id = absint( $dest_record['household_id'] ?? 0 );
        $dk_fields  = [ 'DK_DIR_CODE', 'DK_ENTRY_CODE', 'DK_DIR_OPT_IN' ];

        foreach ( $dk_fields as $field_type ) {
            $chosen_value = null;

            // 1. Try finding an existing value on members of the destination household (excluding the absorbed source)
            if ( $dest_hh_id > 0 ) {
                $chosen_value = $wpdb->get_var( $wpdb->prepare(
                    "SELECT cr.credential_value
                     FROM ac_credentials cr
                     JOIN ac_cardholders c ON cr.cardholder_id = c.id
                     WHERE c.household_id = %d
                       AND c.id != %d
                       AND cr.credential_type = %s
                       AND cr.credential_value != ''
                       AND cr.status = 'active'
                     LIMIT 1",
                    $dest_hh_id,
                    $source_id,
                    $field_type
                ) );
            }

            // 2. If destination household has none, fall back to the source record's value
            if ( null === $chosen_value || '' === $chosen_value ) {
                $chosen_value = $wpdb->get_var( $wpdb->prepare(
                    "SELECT credential_value
                     FROM ac_credentials
                     WHERE cardholder_id = %d
                       AND credential_type = %s
                       AND credential_value != ''
                     ORDER BY id DESC LIMIT 1",
                    $source_id,
                    $field_type
                ) );
            }

            // 3. If neither had a value and it's DIR or ENTRY, generate a fresh one
            if ( empty( $chosen_value ) ) {
                if ( 'DK_DIR_OPT_IN' === $field_type ) {
                    $chosen_value = '0';
                } else {
                    $chosen_value = $this->generate_unique_code( $field_type );
                }
            }

            // 4. Remove any conflicting credentials belonging to the absorbed source record
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM ac_credentials WHERE cardholder_id = %d AND credential_type = %s",
                $source_id,
                $field_type
            ) );

            // 5. Propagate the chosen value to ALL active members of the destination household
            $target_cardholder_ids = [ $destination_id ];
            if ( $dest_hh_id > 0 ) {
                $members = $wpdb->get_col( $wpdb->prepare(
                    "SELECT id FROM ac_cardholders
                     WHERE household_id = %d
                       AND id != %d
                       AND cardholder_status NOT IN ('archived', 'purged')",
                    $dest_hh_id,
                    $source_id
                ) );
                if ( ! empty( $members ) ) {
                    $target_cardholder_ids = array_unique( array_merge( $target_cardholder_ids, array_map( 'absint', $members ) ) );
                }
            }

            foreach ( $target_cardholder_ids as $cid ) {
                $existing_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM ac_credentials
                     WHERE cardholder_id = %d AND credential_type = %s
                     LIMIT 1",
                    $cid,
                    $field_type
                ) );

                if ( $existing_id ) {
                    $wpdb->update( 'ac_credentials', [
                        'credential_value' => $chosen_value,
                        'status'           => 'active',
                    ], [ 'id' => $existing_id ] );
                } else {
                    $wpdb->insert( 'ac_credentials', [
                        'cardholder_id'    => $cid,
                        'credential_type'  => $field_type,
                        'credential_value' => $chosen_value,
                        'status'           => 'active',
                        'issue_date'       => current_time( 'Y-m-d' ),
                    ] );
                }
            }
        }
    }

    /**
     * Reconciles DoorKing credentials when an archived cardholder is restored.
     * Rule: If the household already has active members, adopt the household's current PIN, DIR, and OPT_IN.
     * If the resident is restoring into an empty household, re-activate their existing credentials.
     */
    public function handle_cardholder_restore( $cardholder_id ) {
        global $wpdb;
        $cardholder_id = absint( $cardholder_id );
        if ( ! $cardholder_id ) {
            return;
        }

        $household_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT household_id FROM ac_cardholders WHERE id = %d",
            $cardholder_id
        ) );

        $dk_fields = [ 'DK_DIR_CODE', 'DK_ENTRY_CODE', 'DK_DIR_OPT_IN' ];

        foreach ( $dk_fields as $field_type ) {
            $household_val = null;

            // Check if another active member in the same household has this credential
            if ( ! empty( $household_id ) ) {
                $household_val = $wpdb->get_var( $wpdb->prepare(
                    "SELECT cr.credential_value
                     FROM ac_credentials cr
                     JOIN ac_cardholders c ON cr.cardholder_id = c.id
                     WHERE c.household_id = %d
                       AND c.id != %d
                       AND c.cardholder_status = 'active'
                       AND cr.credential_type = %s
                       AND cr.credential_value != ''
                       AND cr.status = 'active'
                     LIMIT 1",
                    $household_id,
                    $cardholder_id,
                    $field_type
                ) );
            }

            $existing_cred_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM ac_credentials
                 WHERE cardholder_id = %d AND credential_type = %s
                 LIMIT 1",
                $cardholder_id,
                $field_type
            ) );

            if ( ! empty( $household_val ) ) {
                // Household already has an active code: snap the restored member to the household value
                if ( $existing_cred_id ) {
                    $wpdb->update( 'ac_credentials', [
                        'credential_value' => $household_val,
                        'status'           => 'active',
                    ], [ 'id' => $existing_cred_id ] );
                } else {
                    $wpdb->insert( 'ac_credentials', [
                        'cardholder_id'    => $cardholder_id,
                        'credential_type'  => $field_type,
                        'credential_value' => $household_val,
                        'status'           => 'active',
                        'issue_date'       => current_time( 'Y-m-d' ),
                    ] );
                }
            } elseif ( $existing_cred_id ) {
                // Restored member is the only one in the home; re-activate their existing record
                $wpdb->update( 'ac_credentials', [
                    'status' => 'active',
                ], [ 'id' => $existing_cred_id ] );
            }
        }
    }

/**
     * Reconciles DoorKing credentials when a cardholder changes resident type / moves households.
     * Rule:
     * - If target household has an active member with DIR/PIN/OPT-IN, adopt those values.
     * - If target household is fresh (no members with codes), generate fresh unique codes.
     *
     * @param int $cardholder_id       The ID of the cardholder moved.
     * @param int $target_household_id The household ID they joined.
     * @param int $old_household_id    The household ID they left.
     */
    public function handle_household_changed( $cardholder_id, $target_household_id, $old_household_id ) {
        global $wpdb;

        $cardholder_id       = absint( $cardholder_id );
        $target_household_id = absint( $target_household_id );

        if ( ! $cardholder_id || ! $target_household_id ) {
            return;
        }

        $dk_fields = [ 'DK_DIR_CODE', 'DK_ENTRY_CODE', 'DK_DIR_OPT_IN' ];

        foreach ( $dk_fields as $field_type ) {
            // 1. Check if another active member in the target household already has this credential
            $household_val = $wpdb->get_var( $wpdb->prepare(
                "SELECT cr.credential_value
                 FROM ac_credentials cr
                 JOIN ac_cardholders c ON cr.cardholder_id = c.id
                 WHERE c.household_id = %d
                   AND c.id != %d
                   AND c.cardholder_status IN ('active', 'inactive')
                   AND cr.credential_type = %s
                   AND cr.credential_value != ''
                   AND cr.status = 'active'
                 LIMIT 1",
                $target_household_id,
                $cardholder_id,
                $field_type
            ) );

            // 2. Determine target value
            if ( ! empty( $household_val ) ) {
                $final_value = $household_val;
            } else {
                // Moving into a new household with no codes: generate fresh codes
                if ( 'DK_DIR_OPT_IN' === $field_type ) {
                    $final_value = '0';
                } else {
                    $final_value = $this->generate_unique_code( $field_type );
                }
            }

            // 3. Update or insert the credential for the moving cardholder
            $existing_cred_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM ac_credentials
                 WHERE cardholder_id = %d
                   AND credential_type = %s
                   AND (vehicle_id IS NULL OR vehicle_id = 0)
                 LIMIT 1",
                $cardholder_id,
                $field_type
            ) );

            if ( $existing_cred_id ) {
                $wpdb->update(
                    'ac_credentials',
                    [
                        'credential_value' => $final_value,
                        'status'           => 'active',
                    ],
                    [ 'id' => $existing_cred_id ]
                );
            } else {
                $wpdb->insert(
                    'ac_credentials',
                    [
                        'cardholder_id'    => $cardholder_id,
                        'credential_type'  => $field_type,
                        'credential_value' => $final_value,
                        'status'           => 'active',
                        'issue_date'       => current_time( 'Y-m-d' ),
                    ]
                );
            }
        }
    }
}

// Initialize the plugin
new FSBHOA_AC_DoorKing();

