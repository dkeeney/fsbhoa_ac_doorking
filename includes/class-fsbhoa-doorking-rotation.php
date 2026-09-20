<?php
if ( ! defined( 'ABSPATH' ) ) { die; }

class Fsbhoa_DoorKing_Rotation {

    const VENDOR_CURRENT_NAME  = 'Vendor (Current)';
    const VENDOR_PREVIOUS_NAME = 'Vendor (Previous)';

    public function __construct() {
        add_action( 'fsbhoa_dk_daily_rotation_check', [ $this, 'run_daily_rotation_cycle' ] );

        if ( ! wp_next_scheduled( 'fsbhoa_dk_daily_rotation_check' ) ) {
            wp_schedule_event( time(), 'daily', 'fsbhoa_dk_daily_rotation_check' );
        }

        add_action( 'wp_ajax_fsbhoa_dk_force_sync', [ $this, 'ajax_force_sync' ] );
    }

    /**
     * Gets or creates the two community vendor cardholder records.
     */
    public function get_vendor_record_ids() {
        global $wpdb;

        $slots = [
            'current'  => self::VENDOR_CURRENT_NAME,
            'previous' => self::VENDOR_PREVIOUS_NAME,
        ];

        $ids = [];
        foreach ( $slots as $key => $last_name ) {
            $id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM ac_cardholders WHERE first_name = 'Community' AND last_name = %s AND cardholder_type = 'vendor' LIMIT 1",
                $last_name
            ) );

            if ( ! $id ) {
                $wpdb->insert( 'ac_cardholders', [
                    'first_name'        => 'Community',
                    'last_name'         => $last_name,
                    'company'           => 'FSB Community Vendor Access',
                    'cardholder_type'   => 'vendor',
                    'resident_type'     => 'Vendor',
                    'cardholder_status' => 'active',
                    'created_at'        => current_time( 'mysql' ),
                    'updated_at'        => current_time( 'mysql' ),
                ] );
                $id = $wpdb->insert_id;
            }

            $ids[ $key ] = (int) $id;
        }

        return $ids;
    }

    /**
     * Generates a unique 4-digit PIN not in active use.
     */
    public function generate_unique_pin() {
        global $wpdb;
        $active_pins = $wpdb->get_col( "SELECT credential_value FROM ac_credentials WHERE credential_type = 'DK_ENTRY_CODE' AND status = 'active'" );

        $attempts = 0;
        do {
            $pin = str_pad( wp_rand( 1000, 9999 ), 4, '0', STR_PAD_LEFT );
            $attempts++;
        } while ( in_array( $pin, $active_pins, true ) && $attempts < 100 );

        return $pin;
    }

    /**
     * Main daily scheduler. Runs daily via wp-cron.
     */
    public function run_daily_rotation_cycle() {
        if ( get_option( 'fsbhoa_dk_enable_rotation', '0' ) !== '1' ) {
            return;
        }

        global $wpdb;

        $now           = current_time( 'timestamp' );
        $year_month    = date( 'Y-m', $now );
        $day           = (int) date( 'j', $now );
        $days_in_month = (int) date( 't', $now );

        $days_before   = absint( get_option( 'fsbhoa_dk_grace_before', 2 ) );
        $days_after    = absint( get_option( 'fsbhoa_dk_grace_after', 3 ) );

        $slot_ids      = $this->get_vendor_record_ids();
        $curr_id       = $slot_ids['current'];
        $prev_id       = $slot_ids['previous'];

        // Read existing credential from DB first
        $curr_code = $this->get_credential_value( $curr_id );

        if ( empty( $curr_code ) ) {
            // First run or missing credential: seed Slot A
            $curr_code = $this->generate_unique_pin();
            $this->set_credential( $curr_id, $curr_code, 'active' );
            $needs_ram_sync = true;

        }

        $stored_month_key = get_option( 'fsbhoa_dk_current_month_key', '' );
        $needs_ram_sync   = false;

        // -------------------------------------------------------------
        // 1. First-time initialization
        // -------------------------------------------------------------
        if ( empty( $stored_month_key ) ) {
            update_option( 'fsbhoa_dk_current_month_key', $year_month );
            $stored_month_key = $year_month;
        }

        // -------------------------------------------------------------
        // 2. Month Rollover Detection (1st of Month)
        // -------------------------------------------------------------
        if ( $year_month !== $stored_month_key ) {
            $next_code = get_option( 'fsbhoa_dk_next_month_code', '' );
            $curr_code = $this->get_credential_value( $curr_id );

            if ( ! empty( $next_code ) ) {
                // Promote previous current into Slot B (active grace)
                if ( ! empty( $curr_code ) ) {
                    $this->set_credential( $prev_id, $curr_code, 'active' );
                }
                // Promote next code into Slot A (active current)
                $this->set_credential( $curr_id, $next_code, 'active' );
                delete_option( 'fsbhoa_dk_next_month_code' );
            }

            update_option( 'fsbhoa_dk_current_month_key', $year_month );
            $needs_ram_sync = true;
        }

        // -------------------------------------------------------------
        // 3. Post-Grace Expiration (Day > days_after)
        // -------------------------------------------------------------
        if ( $day > $days_after ) {
            $prev_status = $this->get_credential_status( $prev_id );
            if ( 'active' === $prev_status ) {
                $this->set_credential_status( $prev_id, 'inactive' );
                $needs_ram_sync = true;
            }
        }

        // -------------------------------------------------------------
        // 4. Mid-Month: Mint Next Month's Code (Day >= 15)
        // -------------------------------------------------------------
        $next_code = get_option( 'fsbhoa_dk_next_month_code', '' );
        if ( $day >= 15 && empty( $next_code ) ) {
            $next_code = $this->generate_unique_pin();
            update_option( 'fsbhoa_dk_next_month_code', $next_code );
        }

        // -------------------------------------------------------------
        // 5. Pre-Grace: Activate Next Code in DoorKing (Days before end)
        // -------------------------------------------------------------
        if ( ( $days_in_month - $day ) < $days_before && ! empty( $next_code ) ) {
            // Check if next code is physically staged in DoorKing
            // (If using a 2-slot table, Slot B can temporarily hold the upcoming code before the 1st)
        }

        // -------------------------------------------------------------
        // 6. Push Synchronized Payload to Public Website
        // -------------------------------------------------------------
        $current_code  = $this->get_credential_value( $curr_id );
        $previous_code = $this->get_credential_value( $prev_id );

        $this->notify_website_api( $current_code, $previous_code, $next_code );

        // -------------------------------------------------------------
        // 7. Trigger RAM export if credentials changed
        // -------------------------------------------------------------
        if ( $needs_ram_sync ) {
            do_action( 'fsbhoa_doorking_trigger_export' );
        }
    }

    /**
     * Sends the 3-code payload to the website REST API.
     */
    public function notify_website_api( $current_code, $previous_code = '', $next_code = '' ) {
        $api_url = get_option( 'fsbhoa_dk_rotation_api_url', '' );
        if ( empty( $api_url ) ) {
            return;
        }

        $token       = get_option( 'fsbhoa_dk_rotation_api_token', '' );
        $days_before = absint( get_option( 'fsbhoa_dk_grace_before', 2 ) );
        $days_after  = absint( get_option( 'fsbhoa_dk_grace_after', 3 ) );

        $payload = [
            'current_month_key'    => current_time( 'Y-m' ),
            'current_month_code'   => (string) $current_code,
            'previous_month_code'  => (string) $previous_code,
            'next_month_code'      => (string) $next_code,
            'days_before'          => $days_before,
            'days_after'           => $days_after,
        ];

        $headers = [ 'Content-Type' => 'application/json' ];
        if ( ! empty( $token ) ) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_post( esc_url_raw( $api_url ), [
            'method'  => 'POST',
            'headers' => $headers,
            'body'    => wp_json_encode( $payload ),
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'DoorKing Rotation Sync Error: ' . $response->get_error_message() );
        }
    }

    /* ------------------------------------------------------------------------
     * Database Helpers for ac_credentials
     * --------------------------------------------------------------------- */

    public function get_credential_value( $cardholder_id ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT credential_value FROM ac_credentials WHERE cardholder_id = %d AND credential_type = 'DK_ENTRY_CODE' LIMIT 1",
            $cardholder_id
        ) );
    }

    public function get_credential_status( $cardholder_id ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM ac_credentials WHERE cardholder_id = %d AND credential_type = 'DK_ENTRY_CODE' LIMIT 1",
            $cardholder_id
        ) );
    }

    public function set_credential( $cardholder_id, $code, $status = 'active' ) {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM ac_credentials WHERE cardholder_id = %d AND credential_type = 'DK_ENTRY_CODE'",
            $cardholder_id
        ) );

        if ( $id ) {
            $wpdb->update(
                'ac_credentials',
                [
                    'credential_value' => $code,
                    'status'           => $status,
                    'issue_date'       => current_time( 'Y-m-d' ),
                ],
                [ 'id' => $id ]
            );
        } else {
            $wpdb->insert( 'ac_credentials', [
                'cardholder_id'    => $cardholder_id,
                'credential_type'  => 'DK_ENTRY_CODE',
                'credential_value' => $code,
                'status'           => $status,
                'issue_date'       => current_time( 'Y-m-d' ),
                'expiration_date'  => '2099-12-31',
                'created_at'       => current_time( 'mysql' ),
            ] );
        }
    }

    public function set_credential_status( $cardholder_id, $status ) {
        global $wpdb;
        $wpdb->update(
            'ac_credentials',
            [ 'status' => $status ],
            [ 'cardholder_id' => $cardholder_id, 'credential_type' => 'DK_ENTRY_CODE' ]
        );
    }

    public function ajax_force_sync() {
        check_ajax_referer( 'fsbhoa_dk_settings_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $this->run_daily_rotation_cycle();
        wp_send_json_success( 'Rotation cycle executed and payload synced.' );
    }
}

