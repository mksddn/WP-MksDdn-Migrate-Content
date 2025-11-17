<?php
/**
 * Cron handler
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Cron operations class
 */
class MksDdn_MC_Cron {

	/**
	 * Cron option name
	 *
	 * @var string
	 */
	const CRON_OPTION = 'mksddn_mc_cron';

	/**
	 * Schedule a cron event
	 *
	 * @param string $hook Event hook
	 * @param string $recurrence Recurrence (hourly, daily, etc.)
	 * @param int $timestamp Timestamp
	 * @param array $args Arguments
	 * @return bool|WP_Error
	 */
	public static function add( $hook, $recurrence, $timestamp, $args = array() ) {
		$schedules = wp_get_schedules();

		if ( ! isset( $schedules[ $recurrence ] ) ) {
			return false;
		}

		$schedule = $schedules[ $recurrence ];
		$current_timestamp = time();

		if ( $timestamp <= $current_timestamp ) {
			while ( $timestamp <= $current_timestamp ) {
				$timestamp += $schedule['interval'];
			}
		}

		return wp_schedule_event( $timestamp, $recurrence, $hook, $args );
	}

	/**
	 * Clear all cron events for a hook
	 *
	 * @param string $hook Event hook
	 * @return bool
	 */
	public static function clear( $hook ) {
		$cron = get_option( self::CRON_OPTION, array() );
		if ( empty( $cron ) ) {
			return false;
		}

		$updated = false;
		foreach ( $cron as $timestamp => $hooks ) {
			if ( isset( $hooks[ $hook ] ) ) {
				unset( $cron[ $timestamp ][ $hook ] );
				$updated = true;

				if ( empty( $cron[ $timestamp ] ) ) {
					unset( $cron[ $timestamp ] );
				}
			}
		}

		if ( $updated ) {
			update_option( self::CRON_OPTION, $cron );
		}

		return $updated;
	}

	/**
	 * Check if cron event exists
	 *
	 * @param string $hook Event hook
	 * @param array $args Event arguments
	 * @return bool
	 */
	public static function exists( $hook, $args = array() ) {
		$cron = get_option( self::CRON_OPTION, array() );
		if ( empty( $cron ) ) {
			return false;
		}

		foreach ( $cron as $timestamp => $hooks ) {
			if ( empty( $args ) ) {
				if ( isset( $hooks[ $hook ] ) ) {
					return true;
				}
			} else {
				$key = md5( serialize( $args ) );
				if ( isset( $hooks[ $hook ][ $key ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Delete cron event
	 *
	 * @param string $hook Event hook
	 * @param array $args Event arguments
	 * @return bool
	 */
	public static function delete( $hook, $args = array() ) {
		$cron = get_option( self::CRON_OPTION, array() );
		if ( empty( $cron ) ) {
			return false;
		}

		$key = md5( serialize( $args ) );
		$updated = false;

		foreach ( $cron as $timestamp => $hooks ) {
			if ( isset( $cron[ $timestamp ][ $hook ][ $key ] ) ) {
				unset( $cron[ $timestamp ][ $hook ][ $key ] );
				$updated = true;
			}

			if ( isset( $cron[ $timestamp ][ $hook ] ) && empty( $cron[ $timestamp ][ $hook ] ) ) {
				unset( $cron[ $timestamp ][ $hook ] );
			}

			if ( empty( $cron[ $timestamp ] ) ) {
				unset( $cron[ $timestamp ] );
			}
		}

		if ( $updated ) {
			update_option( self::CRON_OPTION, $cron );
		}

		return $updated;
	}
}

