<?php
if ( ! defined( 'ABSPATH' ) ) { die; }

class Fsbhoa_DoorKing_Rotation {

    const VENDOR_CURRENT_NAME  = 'Vendor (Current)';
    const VENDOR_PREVIOUS_NAME = 'Vendor (Previous)';
    // Holds next month's code in the gates for the last "Days Before" days of the month
    const VENDOR_NEXT_NAME     = 'Vendor (Next)';

    // When a vendor code was changed on the settings page (a timestamp), until RAM has the
    // change and the website has been told
    const WEBSITE_PENDING_OPTION = 'fsbhoa_dk_website_pending';

    public function __construct() {
        add_action( 'fsbhoa_dk_daily_rotation_check', [ $this, 'run_daily_rotation_cycle' ] );

        // Daily at 00:01 local time, before the nightly RAM export at 00:05
        fsbhoa_dk_schedule_daily( 'fsbhoa_dk_daily_rotation_check', '00:01' );

        add_action( 'wp_ajax_fsbhoa_dk_force_sync', [ $this, 'ajax_force_sync' ] );
        add_action( 'fsbhoa_dk_import_confirmed', [ $this, 'notify_website_after_import' ] );
    }

    /**
     * The settings page changed a vendor code: the gates get it on the next push, and the
     * website once RAM confirms that push (notify_website_after_import).
     */
    public function website_update_pending() {
        update_option( self::WEBSITE_PENDING_OPTION, time(), false );
        do_action( 'fsbhoa_dk_credentials_changed' );
    }

    /**
     * fsbhoa_dk_import_confirmed: once the gates have a code changed on the settings page,
     * post it to the website, so the website never shows a code the gates don't accept.
     *
     * @param int $exported_at The confirmed export's trigger time.
     */
    public function notify_website_after_import( $exported_at ) {
        $pending = (int) get_option( self::WEBSITE_PENDING_OPTION, 0 );
        if ( ! $pending || (int) $exported_at < $pending ) {
            return; // nothing waiting, or that export was made before the change
        }
        delete_option( self::WEBSITE_PENDING_OPTION );

        // With auto-rotation on, the daily cycle posts the codes
        if ( get_option( 'fsbhoa_dk_enable_rotation', '0' ) === '1' ) {
            return;
        }
        error_log( 'FSBHOA DoorKing: RAM has the new vendor code; sending it to the website.' );
        $slot_ids = $this->get_vendor_record_ids();
        $this->notify_website_api( (string) $this->get_credential_value( $slot_ids['current'] ), '', '' );
    }

    /**
     * Gets or creates the three community vendor cardholder records.
     */
    public function get_vendor_record_ids() {
        global $wpdb;

        $slots = [
            'current'  => self::VENDOR_CURRENT_NAME,
            'previous' => self::VENDOR_PREVIOUS_NAME,
            'next'     => self::VENDOR_NEXT_NAME,
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
        $next_id       = $slot_ids['next'];

        $needs_ram_sync = false;

        // Read existing credential from DB first
        $curr_code = $this->get_credential_value( $curr_id );

        if ( empty( $curr_code ) ) {
            // First run or missing credential: seed Slot A
            $curr_code = $this->generate_unique_pin();
            $this->set_credential( $curr_id, $curr_code, 'active' );
            $needs_ram_sync = true;

        }

        $stored_month_key = get_option( 'fsbhoa_dk_current_month_key', '' );

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
        // 4. Mid-Month: Mint Next Month's Code (Day >= 15, or earlier if the lead time starts sooner)
        // -------------------------------------------------------------
        // The last $days_before days of the month (2 = the last two days)
        $in_lead_time = ( $days_in_month - $day ) < $days_before;

        $next_code = get_option( 'fsbhoa_dk_next_month_code', '' );
        if ( ( $day >= 15 || $in_lead_time ) && empty( $next_code ) ) {
            $next_code = $this->generate_unique_pin();
            update_option( 'fsbhoa_dk_next_month_code', $next_code );
        }

        // -------------------------------------------------------------
        // 5. Pre-Grace: Next month's code also opens the gates during the lead time
        // -------------------------------------------------------------
        // The Next slot is active only then. On the 1st its code moves to the Current slot
        // (step 2) and this turns the Next slot off, so the code is never in RAM twice.
        if ( $in_lead_time && ! empty( $next_code ) ) {
            $needs_ram_sync = $this->stage_credential( $next_id, $next_code, 'active' ) || $needs_ram_sync;
        } else {
            $needs_ram_sync = $this->deactivate_credential( $next_id ) || $needs_ram_sync;
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

        // Environment guard: the receiver must be able to tell testbed codes from production codes
        $env = Fsbhoa_DoorKing_Export::get_environment();
        if ( '' === $env ) {
            error_log( 'DoorKing Rotation Sync aborted: FSBHOA_AC_ENVIRONMENT is not defined in wp-config.php.' );
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
            'environment'          => $env,
            'source_host'          => wp_parse_url( home_url(), PHP_URL_HOST ),
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

    /**
     * Sets a slot's code and status only if they differ, so a daily run that changes nothing
     * leaves the record (and its issue date) alone. Returns true if it changed anything.
     */
    public function stage_credential( $cardholder_id, $code, $status ) {
        $code = (string) $code;
        if ( $this->get_credential_value( $cardholder_id ) === $code && $this->get_credential_status( $cardholder_id ) === $status ) {
            return false;
        }
        $this->set_credential( $cardholder_id, $code, $status );
        return true;
    }

    /**
     * Turns a slot's code off if it is active. Returns true if it changed anything.
     */
    public function deactivate_credential( $cardholder_id ) {
        if ( 'active' !== $this->get_credential_status( $cardholder_id ) ) {
            return false;
        }
        $this->set_credential_status( $cardholder_id, 'inactive' );
        return true;
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

