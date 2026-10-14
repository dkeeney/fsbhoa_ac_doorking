<?php
if ( ! defined( 'ABSPATH' ) ) { die; }

/**
 * DoorKing's part in core's "Push Changes Now" (core's Fsbhoa_Push).
 *
 * Whether RAM is up to date is decided by comparing exports: build_csv() depends only on the
 * database and the DoorKing settings (0.2 s for ~1,400 cards on the testbed), so after any
 * change that might matter, the export is rebuilt and its md5 compared with the last one RAM
 * confirmed. That catches every change, whichever screen or plugin made it, and ignores
 * saves that change nothing RAM holds.
 *
 * A push hands the export to RAM (updates.csv and import_now.flag, read by DK_Sync.ahk on
 * the RAM PC). The import finishes a minute or two later; DK_Sync.ahk then writes
 * import_result.txt beside the flag, echoing the flag's time= value, which is how a result
 * is matched to its export. That file name and its lines are a contract with DK_Sync.ahk
 * (CLAUDE.md, "Configuration sync").
 */
class Fsbhoa_DoorKing_Push {

    const OPTION         = 'fsbhoa_dk_push_state';
    const RESULT_FILE    = 'import_result.txt';
    const PICKUP_TIMEOUT = 600;  // seconds before an export nobody imported is an error
    const IMPORT_TIMEOUT = 900;  // seconds before an import with no result is an error

    /** @var Fsbhoa_DoorKing_Export */
    private $export;
    private $check_queued = false;

    public function __construct( Fsbhoa_DoorKing_Export $export ) {
        $this->export = $export;

        // Anything that may change what RAM holds: core's cardholder changes (status,
        // archive, purge, merge, any edit), DoorKing's own credential and vehicle saves,
        // and DoorKing's settings. The check runs once, at the end of the request, after
        // every plugin has saved.
        add_action( 'fsbhoa_pending_change_cardholder', [ $this, 'queue_check' ] );
        foreach ( [
            'fsbhoa_core_cardholder_created', 'fsbhoa_core_cardholder_updated', 'fsbhoa_core_cardholder_restored',
            'fsbhoa_core_cardholders_merged', 'fsbhoa_core_cardholder_household_changed',
            'fsbhoa_core_vehicle_saved', 'fsbhoa_core_vehicle_deleted',
        ] as $hook ) {
            add_action( $hook, [ $this, 'queue_check' ], 99 );
        }
        add_action( 'updated_option', [ $this, 'option_changed' ] );
        // The vendor code slots, changed on the settings page (Manual PIN)
        add_action( 'fsbhoa_dk_credentials_changed', [ $this, 'queue_check' ] );

        add_action( 'fsbhoa_push_to_controllers', [ $this, 'push' ] );
        add_action( 'fsbhoa_dk_exported', [ $this, 'exported' ], 10, 2 );
        add_filter( 'fsbhoa_push_targets', [ $this, 'push_target' ] );

        // "Resend Everything to DoorKing" on the settings page
        add_action( 'wp_ajax_fsbhoa_dk_resend_all', [ $this, 'ajax_resend_all' ] );
    }

    private function state() {
        return wp_parse_args( (array) get_option( self::OPTION, [] ), [
            'current_hash'   => '',  // the export as the database stands (at the last check)
            'confirmed_hash' => '',  // the export RAM last imported successfully
            'exported_hash'  => '',  // the export last handed to RAM
            'exported_at'    => 0,   // its trigger time (the flag's time=)
            'result'         => [],  // DK_Sync.ahk's result for it, once read
            'error'          => '',  // why the last check or export failed
        ] );
    }

    private function save( $state ) {
        update_option( self::OPTION, $state, false );
    }

    public function queue_check() {
        if ( ! $this->check_queued ) {
            $this->check_queued = true;
            add_action( 'shutdown', [ $this, 'check' ] );
        }
    }

    public function option_changed( $option ) {
        if ( 0 === strpos( (string) $option, 'fsbhoa_dk_' ) && self::OPTION !== $option ) {
            $this->queue_check();
        }
    }

    /**
     * Rebuilds the export and records its hash.
     */
    public function check() {
        $state = $this->state();
        $built = $this->export->build_csv();
        if ( is_wp_error( $built ) ) {
            $state['error'] = $built->get_error_message();
        } else {
            $state['current_hash'] = md5( $built['csv'] );
            $state['error']        = '';
        }
        $this->save( $state );
    }

    /**
     * After any export (a push, the nightly export, "Resend Everything to DoorKing", a
     * vendor-code rotation): wait for its result.
     */
    public function exported( $hash, $trigger ) {
        $state                  = $this->state();
        $state['exported_hash'] = $hash;
        $state['exported_at']   = (int) $trigger;
        $state['current_hash']  = $hash;
        $state['result']        = [];
        $state['error']         = '';
        $this->save( $state );
        error_log( 'FSBHOA DoorKing: export handed to RAM (trigger ' . (int) $trigger . ').' );
    }

    /**
     * Reads DK_Sync.ahk's result for the last export, if it has arrived, and records it.
     */
    private function refresh( $state ) {
        if ( ! $state['exported_at'] || $state['result'] ) {
            return $state;
        }
        $path = dirname( get_option( 'fsbhoa_dk_lock_path', Fsbhoa_DoorKing_Export::default_flag_path() ) ) . '/' . self::RESULT_FILE;
        if ( ! is_readable( $path ) ) {
            return $state;
        }
        // The RAM PC replaces the file by a rename, so it can vanish between the check and the read
        $lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( false === $lines ) {
            return $state;
        }
        $result = [];
        foreach ( $lines as $line ) {
            $parts = explode( '=', trim( $line ), 2 );
            if ( 2 === count( $parts ) ) {
                $result[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
            }
        }
        if ( (int) ( $result['trigger'] ?? 0 ) !== (int) $state['exported_at'] ) {
            return $state; // an older import's result
        }
        $state['result'] = $result;
        if ( 'success' === ( $result['result'] ?? '' ) ) {
            $state['confirmed_hash'] = $state['exported_hash'];
        }
        error_log( 'FSBHOA DoorKing: RAM import ' . ( $result['result'] ?? '?' ) . ( ! empty( $result['message'] ) ? ': ' . $result['message'] : '' ) );
        $this->save( $state );

        if ( 'success' === ( $result['result'] ?? '' ) ) {
            /**
             * Fires once RAM confirms it imported an export and sent it to the gates.
             *
             * @param int $exported_at The export's trigger time (the flag's time=).
             */
            do_action( 'fsbhoa_dk_import_confirmed', (int) $state['exported_at'] );
        }
        return $state;
    }

    /**
     * Whether an export is out with RAM and has no result yet.
     */
    private function waiting( $state ) {
        return $state['exported_at'] && ! $state['result'];
    }

    /**
     * fsbhoa_push_to_controllers: export, if RAM is behind the database.
     */
    public function push() {
        $state = $this->refresh( $this->state() );
        if ( $this->waiting( $state ) && time() - $state['exported_at'] < self::IMPORT_TIMEOUT ) {
            error_log( 'FSBHOA DoorKing: push skipped; RAM is still importing the last export.' );
            return;
        }
        $this->check();
        $state = $this->state();
        if ( $state['error'] || $state['current_hash'] === $state['confirmed_hash'] ) {
            return; // nothing to send, or the export can't be built (the banner shows why)
        }
        $result = $this->export->generate_export();
        if ( is_wp_error( $result ) ) {
            $state          = $this->state();
            $state['error'] = $result->get_error_message();
            $this->save( $state );
        }
    }

    /**
     * "Resend Everything to DoorKing": export even if RAM already has it, e.g. after RAM's
     * database was restored. Like a push, it waits while RAM is importing the last export.
     */
    public function ajax_resend_all() {
        check_ajax_referer( 'fsbhoa_dk_settings_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $state = $this->refresh( $this->state() );
        if ( $this->waiting( $state ) && time() - $state['exported_at'] < self::IMPORT_TIMEOUT ) {
            wp_send_json_error( 'RAM is still importing the last export. Try again when the banner shows it has finished.' );
        }

        $result = $this->export->generate_export();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( 'Sent to RAM. The banner shows the progress.' );
    }

    /**
     * DoorKing's entry on core's banner.
     */
    public function push_target( $targets ) {
        $state = $this->refresh( $this->state() );
        $age   = time() - (int) $state['exported_at'];
        $flag  = get_option( 'fsbhoa_dk_lock_path', Fsbhoa_DoorKing_Export::default_flag_path() );

        if ( '' !== $state['error'] ) {
            $entry = [ 'state' => 'error', 'message' => $state['error'] ];
        } elseif ( $this->waiting( $state ) && file_exists( $flag ) ) {
            $entry = $age < self::PICKUP_TIMEOUT
                ? [ 'state' => 'in_progress', 'message' => 'Waiting for the RAM PC to pick up the export.' ]
                : [ 'state' => 'error', 'message' => sprintf( 'The RAM PC has not picked up the export for %d minutes. Is DK_Sync.ahk running?', intdiv( $age, 60 ) ) ];
        } elseif ( $this->waiting( $state ) ) {
            $entry = $age < self::IMPORT_TIMEOUT
                ? [ 'state' => 'in_progress', 'message' => 'RAM is importing and sending to the gates.' ]
                : [ 'state' => 'error', 'message' => sprintf( 'No import result from the RAM PC after %d minutes. Check automation_<environment>.log in the DoorKing Logs folder.', intdiv( $age, 60 ) ) ];
        } elseif ( $state['result'] && 'success' !== ( $state['result']['result'] ?? '' ) ) {
            $entry = [ 'state' => 'error', 'message' => 'RAM import ' . ( $state['result']['result'] ?? 'failed' ) . ': ' . ( $state['result']['message'] ?? '' ) ];
        } elseif ( $state['current_hash'] !== $state['confirmed_hash'] ) {
            $entry = [ 'state' => 'pending', 'message' => 'Changes waiting for RAM.' ];
        } else {
            $entry = [ 'state' => 'done', 'message' => $state['result'] ? 'RAM imported the changes.' : '' ];
        }
        $targets[] = array_merge( [ 'id' => 'doorking', 'label' => 'DoorKing (RAM)' ], $entry );
        return $targets;
    }
}
