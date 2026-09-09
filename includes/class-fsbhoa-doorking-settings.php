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
            'id' => 'fsbhoa_dk_controller_name', 'type' => 'text', 'default' => 'DoorKing Main Gate', 'desc' => 'Identifier injected into the ac_access_log table when a swipe is intercepted.'
        ]);
        register_setting($option_group, 'fsbhoa_dk_controller_name', 'sanitize_text_field');
    }

    public function render_field_callback( $args ) {
        $id      = $args['id'];
        $type    = $args['type'] ?? 'text';
        $default = $args['default'] ?? '';
        $desc    = $args['desc'] ?? '';
        $value   = get_option( $id, $default );

        echo "<input type='{$type}' name='{$id}' id='{$id}' value='" . esc_attr( $value ) . "' class='regular-text' />";
        if ( $desc ) {
            // Fixed the quotes around 'description'
            echo "<p class='description'>" . esc_html( $desc ) . "</p>";
        }
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

    public function ajax_save_settings() {
        check_ajax_referer( 'fsbhoa_dk_settings_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Permission denied.', 403 ); }

        $options = isset( $_POST['options'] ) ? $_POST['options'] : [];
        if ( ! empty( $options ) ) {
            foreach ( $options as $option ) {
                update_option( sanitize_key( $option['name'] ), sanitize_text_field( $option['value'] ) );
            }
        }

        $this->update_service_config();
        wp_send_json_success( 'DoorKing settings saved successfully.' );
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
        // Read Core Database connection details (if needed by Go Proxy)
        // Alternatively, the Go proxy can use the REST API just like Kiosk
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

