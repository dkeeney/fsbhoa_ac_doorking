<?php
if ( ! defined( 'ABSPATH' ) ) { die; }

/**
 * Schedules a daily WP-Cron event at a local time (the site's time zone, Settings > General).
 *
 * WordPress runs PHP in UTC, so plain strtotime( 'tomorrow midnight' ) means midnight UTC,
 * which is 17:00 in Los Angeles. This computes the time with wp_timezone() instead, and
 * reschedules an event that is more than 10 minutes off its local time, such as after a
 * daylight saving change, the same way fsbhoa_ac_uhppote schedules its nightly jobs.
 *
 * @param string $hook       The cron hook.
 * @param string $local_time The local time of day, 'HH:MM'.
 */
function fsbhoa_dk_schedule_daily( $hook, $local_time ) {
    $next = wp_next_scheduled( $hook );

    // An overdue event is waiting for wp-cron to run it. Leave it alone, or the reschedule
    // below would delete tonight's run before it happens.
    if ( $next && $next <= time() ) {
        return;
    }

    $timezone = wp_timezone();
    $ideal    = new DateTime( 'today ' . $local_time, $timezone );
    if ( $ideal->getTimestamp() <= time() ) {
        $ideal->modify( '+1 day' );
    }

    if ( ! $next || abs( $next - $ideal->getTimestamp() ) > 10 * MINUTE_IN_SECONDS ) {
        if ( $next ) {
            wp_clear_scheduled_hook( $hook );
        }
        wp_schedule_event( $ideal->getTimestamp(), 'daily', $hook );
        error_log( sprintf( 'FSBHOA DoorKing: (re)scheduled %s for %s', $hook, $ideal->format( 'Y-m-d H:i T' ) ) );
    }
}
