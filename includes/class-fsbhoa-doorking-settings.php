<?php
if ( ! defined( 'ABSPATH' ) ) { die; }

class Fsbhoa_DoorKing_Settings {

    // Where to write the config file for the Go Proxy service
    private $doorking_config_path = '/var/lib/fsbhoa/doorking_proxy.json';

    public function __construct() {
        // Hook into Core to register our submenu
        add_action( 'fsbhoa_register_admin_submenus', array( $this, 'add_submenu' ) );

        add_action( 'admin_init', array( $this, 'settings_api_init' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX handler for saving
        add_action( 'wp_ajax_fsbhoa_save_doorking_settings', array( $this, 'ajax_save_settings' ) );

        // Re-generate config if a user saves settings
        add_action( 'update_option', array( $this, 'trigger_config_update_on_save' ), 10, 3 );

        // THE CONNECTION HOOK: Listen to Core plugin's broadcast
        add_action( 'fsbhoa_update_service_configs', array( $this, 'update_service_config' ) );
    }

    public function add_submenu( $parent_slug ) {
        add_submenu_page(
            $parent_slug,
            'DoorKing Integration Settings',
            'DoorKing Service',
            'manage_options',
            'fsbhoa_doorking_settings',
            array( $this, 'render_settings_page' ),
            16 // Position right after Amenities
        );
    }

    public function settings_api_init() {
        $option_group = 'fsbhoa_doorking_options';
        $page_slug    = 'fsbhoa_doorking_settings';

        // --- Section: RAM Sync Settings ---
        add_settings_section('fsbhoa_dk_sync_section', 'RAM Software Sync Settings', null, $page_slug);

        // NEW FIELD: Account Name for DoorKing RAM (matches Column 1 of the CSV)
        add_settings_field('fsbhoa_dk_account_name_field', 'DoorKing Account Name', array($this, 'render_field_callback'), $page_slug, 'fsbhoa_dk_sync_section', [
            'id' => 'fsbhoa_dk_account_name', 'type' => 'text', 'default' => 'NORTH GATES', 'desc' => 'The exact Account Name configured in the DoorKing Remote Account Manager (e.g., NORTH GATES).'
        ]);
        register_setting($option_group, 'fsbhoa_dk_account_name', 'sanitize_text_field');

        add_settings_field('fsbhoa_dk_csv_path_field', 'CSV Export Path', array($this, 'render_field_callback'), $page_slug, 'fsbhoa_dk_sync_section', [
            'id' => 'fsbhoa_dk_csv_path', 'type' => 'text', 'default' => '/mnt/shared/AccessControl/doorking_sync/import.csv', 'desc' => 'Absolute file path where the plugin will write the CSV file for RAM.'
        ]);
        register_setting($option_group, 'fsbhoa_dk_csv_path', 'sanitize_text_field');

        add_settings_field('fsbhoa_dk_lock_path_field', 'Lock/Trigger File Path', array($this, 'render_field_callback'), $page_slug, 'fsbhoa_dk_sync_section', [
            'id' => 'fsbhoa_dk_lock_path', 'type' => 'text', 'default' => '/mnt/shared/AccessControl/doorking_sync/import.lock', 'desc' => 'Absolute file path for the trigger file that tells the Windows script to begin importing.'
        ]);
        register_setting($option_group, 'fsbhoa_dk_lock_path', 'sanitize_text_field');

        add_settings_field('fsbhoa_dk_security_level_field', 'Default Security Level', array($this, 'render_field_callback'), $page_slug, 'fsbhoa_dk_sync_section', [
            'id' => 'fsbhoa_dk_security_level', 'type' => 'text', 'default' => '01', 'desc' => 'The default RAM Security Level (Time Zone) assigned to all exported residents.'
        ]);
        register_setting($option_group, 'fsbhoa_dk_security_level', 'sanitize_text_field');

        // --- Section: Vendor Code Rotation Settings ---
        add_settings_section(
            'fsbhoa_dk_rotation_section',
            'Monthly Vendor PIN Rotation',
            null,
            $page_slug
        );

        add_settings_field(
            'fsbhoa_dk_enable_rotation_field',
            'Enable Auto-Rotation',
            [ $this, 'render_field_callback' ],
            $page_slug,
            'fsbhoa_dk_rotation_section',
            [
                'id'      => 'fsbhoa_dk_enable_rotation',
                'type'    => 'checkbox',
                'default' => '0',
                'desc'    => 'Automatically rotate the community vendor 4-digit PIN on the 1st of every month.',
            ]
        );
        register_setting( $option_group, 'fsbhoa_dk_enable_rotation', 'sanitize_text_field' );

        // Renders the editable manual input (visible when Auto is unchecked)
        add_settings_field(
            'fsbhoa_dk_manual_pin_field',
            'Active Vendor PIN',
            [ $this, 'render_manual_pin_field' ],
            $page_slug,
            'fsbhoa_dk_rotation_section'
        );

        // Renders read-only status of both slots (visible when Auto is checked)
        add_settings_field(
            'fsbhoa_dk_auto_status_field',
            'Current Rotation Status',
            [ $this, 'render_auto_status_field' ],
            $page_slug,
            'fsbhoa_dk_rotation_section'
        );

        add_settings_field(
            'fsbhoa_dk_grace_before_field',
            'Lead Time (Days Before 1st)',
            [ $this, 'render_field_callback' ],
            $page_slug,
            'fsbhoa_dk_rotation_section',
            [
                'id'      => 'fsbhoa_dk_grace_before',
                'type'    => 'number',
                'default' => '2',
                'desc'    => 'Days before month-end to generate the new code and publish it to the website (both codes valid).',
            ]
        );
        register_setting( $option_group, 'fsbhoa_dk_grace_before', 'absint' );

        add_settings_field(
            'fsbhoa_dk_grace_after_field',
            'Grace Period (Days After 1st)',
            [ $this, 'render_field_callback' ],
            $page_slug,
            'fsbhoa_dk_rotation_section',
            [
                'id'      => 'fsbhoa_dk_grace_after',
                'type'    => 'number',
                'default' => '3',
                'desc'    => 'Days after the 1st that the previous month code remains valid in the gate.',
            ]
        );
        register_setting( $option_group, 'fsbhoa_dk_grace_after', 'absint' );

        add_settings_field(
            'fsbhoa_dk_rotation_api_url_field', 
            'Website Sync API Endpoint', 
            array($this, 'render_field_callback'), 
            $page_slug, 
            'fsbhoa_dk_rotation_section', [
                'id' => 'fsbhoa_dk_rotation_api_url', 
                'type' => 'url', 
                'default' => '', 
                'desc' => 'External website webhook/API URL to push the updated vendor code to. https://access.fsbhoa.com/wp-json/fsbhoa/v1/ac-web-sync'
            ]
        );
        register_setting($option_group, 'fsbhoa_dk_rotation_api_url', 'esc_url_raw');

        add_settings_field(
            'fsbhoa_dk_rotation_api_token_field', 
            'API Bearer Token / Key', 
            array($this, 'render_field_callback'), 
            $page_slug, 'fsbhoa_dk_rotation_section', [
                'id' => 'fsbhoa_dk_rotation_api_token', 
                'type' => 'password', 
                'default' => '', 
                'desc' => 'Secret authorization token sent in the Authorization header. Must be the same as Vendor Gate code app on website. Use FSBHOA Sync Receiver API key on website.'
            ]
        );
        register_setting($option_group, 'fsbhoa_dk_rotation_api_token', 'sanitize_text_field');

        // --- Section: Go Proxy Settings ---
        add_settings_section('fsbhoa_dk_proxy_section', 'Real-Time Proxy Settings (Go Service)', null, $page_slug);

        add_settings_field('fsbhoa_dk_proxy_port_field', 'Proxy Listen Port', array($this, 'render_field_callback'), $page_slug, 'fsbhoa_dk_proxy_section', [
            'id' => 'fsbhoa_dk_proxy_port', 'type' => 'number', 'default' => 8084, 'desc' => 'The port the Go proxy listens on. Point your RAM network connection to this port.'
        ]);
        register_setting($option_group, 'fsbhoa_dk_proxy_port', 'absint');

        add_settings_field('fsbhoa_dk_gate_ip_field', 'Physical Gate IP & Port', array($this, 'render_field_callback'), $page_slug, 'fsbhoa_dk_proxy_section', [
            'id' => 'fsbhoa_dk_gate_ip', 'type' => 'text', 'default' => '192.168.1.50:10001', 'desc' => 'The actual IP address and port of the Lantronix adapter at the gate.'
        ]);
        register_setting($option_group, 'fsbhoa_dk_gate_ip', 'sanitize_text_field');

        add_settings_field('fsbhoa_dk_controller_name_field', 'Controller Name (Logs)', array($this, 'render_field_callback'), $page_slug, 'fsbhoa_dk_proxy_section', [
            'id' => 'fsbhoa_dk_controller_name', 'type' => 'text', 'default' => 'DoorKing Gate', 'desc' => 'Identifier injected into the ac_access_log table when a swipe is intercepted.'
        ]);
        register_setting($option_group, 'fsbhoa_dk_controller_name', 'sanitize_text_field');
    }

    public function render_field_callback( $args ) {
        $id      = $args['id'];
        $type    = $args['type'] ?? 'text';
        $default = $args['default'] ?? '';
        $desc    = $args['desc'] ?? '';
        $value   = get_option( $id, $default );

        if ( 'checkbox' === $type ) {
            $checked = checked( 1, $value, false );
            echo "<label><input type='checkbox' name='{$id}' id='{$id}' value='1' {$checked} /> " . esc_html( $desc ) . "</label>";
        } else {
            echo "<input type='{$type}' name='{$id}' id='{$id}' value='" . esc_attr( $value ) . "' class='regular-text' />";
            if ( $desc ) {
                echo "<p class='description'>" . esc_html( $desc ) . "</p>";
            }
        }
    }

    public function render_vendor_code_field( $args ) {
        $slot_key    = $args['slot'];     // 'current' or 'previous'
        $field_name  = $args['id'];
        $description = $args['desc'] ?? '';

        $rotation  = new Fsbhoa_DoorKing_Rotation();
        $slot_ids  = $rotation->get_vendor_record_ids();
        $card_id   = $slot_ids[ $slot_key ] ?? 0;
        $live_code = $card_id ? $rotation->get_credential_value( $card_id ) : '';

        ?>
        <input type="text"
               id="<?php echo esc_attr( $field_name ); ?>"
               name="<?php echo esc_attr( $field_name ); ?>"
               value="<?php echo esc_attr( $live_code ); ?>"
               maxlength="4"
               style="width: 100px; font-size: 16px; text-align: center; letter-spacing: 2px;" />
        <?php if ( $description ) : ?>
            <p class="description"><?php echo wp_kses_post( $description ); ?></p>
        <?php endif;
    }

    public function render_manual_pin_field() {
        $rotation  = new Fsbhoa_DoorKing_Rotation();
        $slot_ids  = $rotation->get_vendor_record_ids();
        $curr_code = $rotation->get_credential_value( $slot_ids['current'] );

        ?>
        <div id="fsbhoa-manual-pin-wrap">
            <input type="text"
                   id="fsbhoa_dk_manual_pin"
                   name="fsbhoa_dk_manual_pin"
                   value="<?php echo esc_attr( $curr_code ); ?>"
                   maxlength="4"
                   style="width: 100px; font-size: 18px; text-align: center; letter-spacing: 3px; font-weight: bold;" />
            <p class="description">Live code accepted at the gate and published on the website.</p>
        </div>
        <?php
    }

    public function render_auto_status_field() {
        $rotation  = new Fsbhoa_DoorKing_Rotation();
        $slot_ids  = $rotation->get_vendor_record_ids();

        $curr_code   = $rotation->get_credential_value( $slot_ids['current'] );
        $curr_status = $rotation->get_credential_status( $slot_ids['current'] );

        $prev_code   = $rotation->get_credential_value( $slot_ids['previous'] );
        $prev_status = $rotation->get_credential_status( $slot_ids['previous'] );

        $next_code   = get_option( 'fsbhoa_dk_next_month_code', '' );

        $current_month = current_time( 'F' );
        $prev_month    = date( 'F', strtotime( '-1 month', current_time( 'timestamp' ) ) );
        $next_month    = date( 'F', strtotime( '+1 month', current_time( 'timestamp' ) ) );

        ?>
        <div id="fsbhoa-auto-status-wrap" style="background: #f6f7f7; border: 1px solid #ccd0d4; padding: 12px 16px; border-radius: 4px; max-width: 520px;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr style="border-bottom: 1px solid #e2e4e7;">
                    <td style="padding: 6px 0;"><strong><?php echo esc_html( $current_month ); ?> (Active)</strong></td>
                    <td style="padding: 6px 0; font-family: monospace; font-size: 15px; font-weight: bold;"><?php echo esc_html( $curr_code ?: 'None' ); ?></td>
                    <td style="padding: 6px 0; color: green;">[<?php echo esc_html( strtoupper( $curr_status ?: 'inactive' ) ); ?>]</td>
                </tr>
                <tr style="border-bottom: 1px solid #e2e4e7;">
                    <td style="padding: 6px 0;"><strong><?php echo esc_html( $prev_month ); ?> (Grace)</strong></td>
                    <td style="padding: 6px 0; font-family: monospace; font-size: 15px;"><?php echo esc_html( $prev_code ?: 'None' ); ?></td>
                    <td style="padding: 6px 0; color: <?php echo ( 'active' === $prev_status ) ? 'green' : '#666'; ?>;">
                        [<?php echo esc_html( strtoupper( $prev_status ?: 'inactive' ) ); ?>]
                    </td>
                </tr>
                <tr>
                    <td style="padding: 6px 0;"><strong><?php echo esc_html( $next_month ); ?> (Upcoming)</strong></td>
                    <td style="padding: 6px 0; font-family: monospace; font-size: 15px;"><?php echo esc_html( $next_code ?: 'Staged on the 15th' ); ?></td>
                    <td style="padding: 6px 0; color: #888;">[PENDING]</td>
                </tr>
            </table>
            <p class="description" style="margin-top: 8px;">Codes rotate automatically on the 1st. DoorKing and website stay synchronized.</p>
        </div>
        <?php
    }

    public function ajax_save_settings() {
        check_ajax_referer( 'fsbhoa_dk_settings_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.', 403 );
        }

        $options = isset( $_POST['options'] ) ? $_POST['options'] : [];
        $saved_keys = [];

        if ( ! empty( $options ) ) {
            foreach ( $options as $option ) {
                $key = sanitize_key( $option['name'] );
                $val = sanitize_text_field( $option['value'] );
                update_option( $key, $val );
                $saved_keys[] = $key;
            }
        }

        // Handle checkboxes: if not in POST, set to '0'
        if ( ! in_array( 'fsbhoa_dk_enable_rotation', $saved_keys, true ) ) {
            update_option( 'fsbhoa_dk_enable_rotation', '0' );
        }

        $this->update_service_config();

        // -- HANDLE VENDOR ACCESS CODES --
        $rotation_enabled = ( get_option( 'fsbhoa_dk_enable_rotation', '0' ) === '1' );
        $rotation         = new Fsbhoa_DoorKing_Rotation();
        $slot_ids         = $rotation->get_vendor_record_ids();

        if ( $rotation_enabled ) {
            // Auto Mode: Run full rotation cycle (evaluates grace, generates next PIN, pushes payload)
            $rotation->run_daily_rotation_cycle();
        } else {
            // Manual Mode:
            // 1. Look for the manual PIN from the form
            $manual_pin = '';
            if ( isset( $_POST['options'] ) && is_array( $_POST['options'] ) ) {
                foreach ( $_POST['options'] as $opt ) {
                    if ( isset( $opt['name'] ) && 'fsbhoa_dk_manual_pin' === $opt['name'] ) {
                        $manual_pin = sanitize_text_field( $opt['value'] );
                        break;
                    }
                }
            }

            // 2. Write manual PIN into Slot A (active) and ensure Slot B is inactive
            if ( ! empty( $manual_pin ) ) {
                $rotation->set_credential( $slot_ids['current'], $manual_pin, 'active' );
            }
            $rotation->set_credential( $slot_ids['previous'], '', 'inactive' );
            delete_option( 'fsbhoa_dk_next_month_code' );

            // 3. Trigger DoorKing RAM export and push single-code payload to the website
            do_action( 'fsbhoa_doorking_trigger_export' );
            $rotation->notify_website_api( $manual_pin, '', '' );
        }

        wp_send_json_success( 'DoorKing settings saved successfully.' );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap" id="fsbhoa-doorking-settings-page">
            <h1>DoorKing Integration Settings</h1>
            <p>Configure the physical gateway parameters and the RAM software synchronization paths.</p>
            <hr>
            <?php do_settings_sections( 'fsbhoa_doorking_settings' ); ?>
            <p class="submit" style="display: flex; gap: 12px; align-items: center;">
                <button type="button" id="fsbhoa-save-doorking-settings-button" class="button button-primary">Save DoorKing Settings</button>
                <button type="button" id="fsbhoa-export-ram-csv-btn" class="button button-secondary">Generate RAM CSV Now</button>
                <span id="fsbhoa-save-feedback" style="display: none; vertical-align: middle;"></span>
                <span id="fsbhoa-export-feedback" style="display: none; vertical-align: middle; font-weight: 600;"></span>
            </p>
        </div>
        <?php
    }

    public function enqueue_assets( $hook ) {
        if ( $hook === 'fsbhoa-ac_page_fsbhoa_doorking_settings' ) {
            wp_enqueue_script( 'fsbhoa-doorking-settings-js', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/fsbhoa-doorking-settings.js', array( 'jquery' ), '1.0.0', true );

            wp_localize_script( 'fsbhoa-doorking-settings-js', 'fsbhoa_dk_vars', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'fsbhoa_dk_settings_nonce' )
            ) );
        }
    }

    public function trigger_config_update_on_save( $option_name, $old_value, $new_value ) {
        $dk_options = [
            'fsbhoa_dk_proxy_port', 'fsbhoa_dk_gate_ip', 'fsbhoa_dk_controller_name'
        ];
        if ( in_array( $option_name, $dk_options ) ) {
            $this->update_service_config();
        }
    }

    /**
     * Generates doorking_proxy.json for the Go Service
     */
    public function update_service_config() {
        $wp_host = get_option( 'fsbhoa_ac_wp_host', 'access.fsbhoa.com' );

        $config = [
            'listen_port'     => ':' . absint( get_option( 'fsbhoa_dk_proxy_port', 8084 ) ),
            'gate_ip'         => sanitize_text_field( get_option( 'fsbhoa_dk_gate_ip', '192.168.1.50:10001' ) ),
            'controller_name' => sanitize_text_field( get_option( 'fsbhoa_dk_controller_name', 'DoorKing Main Gate' ) ),
            'wordpress_host'  => $wp_host
        ];

        $json_data = json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        if ( ! is_dir( dirname( $this->doorking_config_path ) ) ) {
            mkdir( dirname( $this->doorking_config_path ), 0755, true );
        }
        file_put_contents( $this->doorking_config_path, $json_data );
    }
}

// Initialize
new Fsbhoa_DoorKing_Settings();

