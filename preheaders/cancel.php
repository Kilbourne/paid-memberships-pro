<?php
	global $besecure;
	$besecure = false;

	global $current_user, $pmpro_msg, $pmpro_msgt, $pmpro_error;

	// Get the level IDs they are requesting to cancel using the old ?level param.
	if ( ! empty( $_REQUEST['level'] ) && empty( $_REQUEST['levelstocancel'] ) ) {
		// TODO: Maybe show a warning here that this is deprecated.
		$requested_ids = intval( $_REQUEST['level'] );
	}

	// Get the level IDs they are requesting to cancel from the ?levelstocancel param.
	if ( ! empty( $_REQUEST['levelstocancel'] ) && $_REQUEST['levelstocancel'] === 'all' ) {
		$requested_ids = 'all';
	} elseif ( ! empty( $_REQUEST['levelstocancel'] ) ) {		
		// A single ID could be passed, or a few like 1+2+3.
		$requested_ids = str_replace(array(' ', '%20'), '+', sanitize_text_field( $_REQUEST['levelstocancel'] ) );
		$requested_ids = preg_replace("/[^0-9\+]/", "", $requested_ids );
	}	

	// Redirection logic.
	if ( ! is_user_logged_in() ) {
		if ( ! empty( $requested_ids ) ) {
			$redirect = add_query_arg( 'levelstocancel', $requested_ids, pmpro_url( 'cancel' ) );
		} else {
			$redirect = pmpro_url( 'cancel' );
		}
		// Redirect non-user to the login page; pass the Cancel page with specific ?levelstocancel as the redirect_to query arg.
		wp_redirect( add_query_arg( 'redirect_to', urlencode( $redirect ), pmpro_login_url() ) );
		exit;
	}

		// If user has no membership level, redirect to levels page.
	$user_levels = pmpro_getMembershipLevelsForUser( $current_user->ID );
	if ( empty( $user_levels ) ) {
			wp_redirect( pmpro_url( 'levels' ) );
			exit;
		}

	//check if a level was passed in to cancel specifically
	if ( ! empty ( $requested_ids ) && $requested_ids != 'all' ) {		
		$old_level_ids = array_map( 'intval', explode( "+", $requested_ids ) );

		// Make sure the user has the level they are trying to cancel.
		if ( ! empty( array_diff( $old_level_ids, wp_list_pluck( $user_levels, 'ID' ) ) ) ) {
			// If they don't have the level, return to Membership Account.
			wp_redirect( pmpro_url( 'account' ) );
			exit;
		}
	} else {
		$old_level_ids = false;	//cancel all levels
	}

	// Are we confirming a cancellation?
	if ( ! empty( $_REQUEST['confirm'] ) ) {

		/**
		 * Check whether a cancellation should be able to process.
		 *
		 * @since 3.0
		 *
		 * @param bool $process_cancellation Whether the cancellation should be processed.
		 * @param WP_User $user The user cancelling their membership.
		 */
		$process_cancellation = apply_filters( 'pmpro_cancel_should_process', true, $current_user );
		if ( $process_cancellation ) {

			$worked = true;
			foreach($old_level_ids as $old_level_id) {

					// Send an email to the member.
					$myemail = new PMProEmail();
					$myemail->sendCancelOnNextPaymentDateEmail( $current_user, $old_level_id );

					// Send an email to the admin.
					$myemail = new PMProEmail();
					$myemail->sendCancelOnNextPaymentDateAdminEmail( $current_user, $old_level_id );
        
		if ( ! empty( $worked ) ) {
			if ( count( $old_level_ids ) > 1 ) {
				// If cancelling multiple levels, show a generic message.
				$pmpro_msg = __( 'Your memberships have been cancelled.', 'paid-memberships-pro' );
			} elseif ( ! empty( $old_level_ids[0] ) ) {
				// If cancelling a single level, show the level name.
				$cancelled_level = pmpro_getLevel( $old_level_ids[0] );
				if ( ! empty( $cancelled_level ) && ! empty( $cancelled_level->name ) ) {
					/* translators: %s: level name */
					$pmpro_msg = sprintf( __( 'Your %s membership has been cancelled.', 'paid-memberships-pro' ), esc_html( $cancelled_level->name ) );
				}

				// If the level has an enddate, show that.
				$expiring_level = pmpro_getSpecificMembershipLevelForUser( $current_user->ID, $old_level_ids[0] );
				if ( ! empty( $expiring_level ) && ! empty( $expiring_level->enddate ) ) {
					/* translators: %s: membership expiration date */
					$pmpro_msg = sprintf( __( 'Your recurring subscription has been cancelled. Your active membership will expire on %s.', 'paid-memberships-pro' ), date_i18n( get_option( 'date_format' ), $expiring_level->enddate ) );
				}
			} else {
				// Show a generic message about cancellation if we get here.
				$pmpro_msg = __( 'Your membership has been cancelled.', 'paid-memberships-pro' );
			}
			$pmpro_msgt = "pmpro_success";

			/**
			 * Fires after a membership level is cancelled.
			 *
			 * @since 3.0
			 *
			 * @param WP_User $user The user who cancelled their membership.
			 */
			do_action( 'pmpro_cancel_processed', $current_user );
		} else {
			if ( ! empty( $pmpro_error ) ) {
				pmpro_setMessage( $pmpro_error, 'pmpro_error' );
			}
			$_REQUEST['confirm'] = false; // Show the form again.
		}
	}

	wp_register_script(
		'pmpro_cancel',
		plugins_url( 'js/pmpro-cancel.js', PMPRO_BASE_FILE ),
		array( 'jquery' ),
		PMPRO_VERSION
	);
	wp_enqueue_script( 'pmpro_cancel' );