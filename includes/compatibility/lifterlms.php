<?php
/**
 * Compatibility for the LifterLMS plugin.
 * @since 2.12
 * 
 * We add an option to "Streamline LifterLMS" to the
 * LifterLMS Setup Wizard and also the PMPro Advanced Settings Page.
 * When enabled, this will:
 * 1. Allow LifterLMS courses to be restricted by PMPro levels.
 * 2. Hide the LifterLMS Membership menu item from the admin dashboard.
 * 3. Hide the Restrictions tab of the edit course page.
 * 4. Hide the Access Plans section of the edit course page.
 * 5. Override the My Memberships and My Orders tabs of the student dashboard.
 */

/**
 * Add streamline setting to the PMPro Advanced Settings page.
 */
function pmpro_lifter_streamline_advanced_setting( $settings ) {
	$settings['lifter_streamline'] = [
		'field_name'  => 'lifter_streamline',
		'field_type'  => 'select',
		'options'	  => [
			'0' => __( 'No - All LifterLMS features are enabled.', 'paid-memberships-pro' ),
			'1' => __( 'Yes - Some LifterLMS features are disabled.', 'paid-memberships-pro' ),
		],
		'is_associative' => true,
		'label'       => __( 'Streamline LifterLMS', 'paid-memberships-pro' )		
	];
	return $settings;
}
add_filter( 'pmpro_custom_advanced_settings', 'pmpro_lifter_streamline_advanced_setting' );

/**
 * Add Require Membership box to LifterLMS courses.
 */
function pmpro_lifter_admin_menu() {
	// Bail if the streamline option is not enabled.
	if ( ! get_option( 'pmpro_lifter_streamline' ) ) {
		return;
	}
	
	if( function_exists( 'pmpro_page_meta' ) ){
		add_meta_box( 'pmpro_page_meta', esc_html__( 'Require Membership', 'pmpro-courses' ), 'pmpro_page_meta', 'course', 'side');
	}
}
add_action('admin_menu', 'pmpro_lifter_admin_menu', 20);

/**
 * Override PMPro's the_content filter.
 * We want to show course content even if it requires membership.
 * Still showing the non-member text at the bottom.
 */
function pmpro_lifter_membership_content_filter( $filtered_content, $original_content ) {	
	// Bail if the streamline option is not enabled.
	if ( ! get_option( 'pmpro_lifter_streamline' ) ) {
		return $filtered_content;
	}
	
	if ( is_singular( 'course' ) ) {
		// Show non-member text if needed.
		ob_start();
		// Get hasaccess ourselves so we get level ids and names.
		$hasaccess = pmpro_has_membership_access(NULL, NULL, true);
		if( is_array( $hasaccess ) ) {
			//returned an array to give us the membership level values
			$post_membership_levels_ids = $hasaccess[1];
			$post_membership_levels_names = $hasaccess[2];
			$hasaccess = $hasaccess[0];
			if ( ! $hasaccess ) {
				echo pmpro_get_no_access_message( '', $post_membership_levels_ids, $post_membership_levels_names );
			}
		}
		
		$after_the_content = ob_get_contents();
		ob_end_clean();			
		return $original_content . $after_the_content;		
	} else {
		return $filtered_content;	// Probably false.
	}
}
add_filter( 'pmpro_membership_content_filter', 'pmpro_lifter_membership_content_filter', 10, 2 );

/**
 * Get courses associated with a level.
 */
function pmpro_lifter_get_courses_for_levels( $level_ids ) {
	global $wpdb;
	
	// In case a level object was passed in.
	if ( is_object( $level_ids ) ) {
		$level_ids = $level_ids->ID;
	}
	
	// Make sure we have an array of ids.
	if ( ! is_array( $level_ids ) ) {
		$level_ids = array( $level_ids );
	}
	
	if ( empty( $level_ids ) ) {
		return array();
	}
	
	$course_ids = $wpdb->get_col(
		$wpdb->prepare(
			"
				SELECT mp.page_id 
				FROM $wpdb->pmpro_memberships_pages mp 
				LEFT JOIN $wpdb->posts p ON mp.page_id = p.ID 
				WHERE mp.membership_id IN(%s) 
				AND p.post_type = 'course' 
				AND p.post_status = 'publish' 
				GROUP BY mp.page_id
			",
			implode(',', $level_ids )
		)
	);
	
	return $course_ids;
}

/**
 * When users change levels, enroll/unenroll them from
 * any associated private courses.
 */
function pmpro_lifter_after_all_membership_level_changes( $pmpro_old_user_levels ) {		
	// Bail if the streamline option is not enabled.
	if ( ! get_option( 'pmpro_lifter_streamline' ) ) {
		return $pmpro_old_user_levels;
	}
	
	foreach ( $pmpro_old_user_levels as $user_id => $old_levels ) {
		// Get current courses.
		$current_levels = pmpro_getMembershipLevelsForUser( $user_id );
		if ( ! empty( $current_levels ) ) {
			$current_levels = wp_list_pluck( $current_levels, 'ID' );
		} else {
			$current_levels = array();
		}
		$current_courses = pmpro_lifter_get_courses_for_levels( $current_levels );
		
		// Get old courses.
		$old_levels = wp_list_pluck( $old_levels, 'ID' );
		$old_courses = pmpro_lifter_get_courses_for_levels( $old_levels );
		
		// Unenroll the user in any courses they used to have, but lost.
		$courses_to_unenroll = array_diff( $old_courses, $current_courses );
		foreach( $courses_to_unenroll as $course_id ) {
			if ( llms_is_user_enrolled( $user_id, $course_id ) ) {
				// Unenroll student
				llms_unenroll_student( $user_id, $course_id );					
			}
		}
		
		// Enroll the user in any courses for their current levels.
		$courses_to_enroll = array_diff( $current_courses, $old_courses );
		foreach( $courses_to_enroll as $course_id ) {
			if ( ! llms_is_user_enrolled( $user_id, $course_id ) ) {
				llms_enroll_student( $user_id, $course_id );
			}
		}
	}
}
add_action( 'pmpro_after_all_membership_level_changes', 'pmpro_lifter_after_all_membership_level_changes' );

/**
 * Hide the LifterLMS Membership menu item from the admin dashboard if streamline is enabled.
 */
function pmpro_lifter_hide_membership_menu() {
	// Bail if the streamline option is not enabled.
	if ( ! get_option( 'pmpro_lifter_streamline' ) ) {
		return;
	}
	
	// Remove the LifterLMS Membership menu item.
	remove_menu_page( 'edit.php?post_type=llms_membership' );
}
add_action( 'admin_menu', 'pmpro_lifter_hide_membership_menu', 99 );

/**
 * Hide the LifterLMS Membership and Checkout tabs from the admin settings.
 * @param array $tabs
 * @return array
 * @since 2.12
 */
function pmpro_lifter_hide_settings_tabs( $tabs ) {
	// Bail if the streamline option is not enabled.
	if ( ! get_option( 'pmpro_lifter_streamline' ) ) {
		return $tabs;
	}

	if ( isset( $tabs['memberships'] ) ) {
		unset( $tabs['memberships'] );
	}

	if ( isset( $tabs['checkout'] ) ) {
		unset( $tabs['checkout'] );
	}

	return $tabs;
}
add_filter( 'lifterlms_settings_tabs_array', 'pmpro_lifter_hide_settings_tabs', 30 );

/**
 * Hide the LifterLMS memberships and sales tabs from the reporting page.
 */
function pmpro_lifter_reporting_tabs( $tabs ) {
	// Bail if the streamline option is not enabled.
	if ( ! get_option( 'pmpro_lifter_streamline' ) ) {
		return $tabs;
	}

	if ( isset( $tabs['memberships'] ) ) {
		unset( $tabs['memberships'] );
	}

	if ( isset( $tabs['sales'] ) ) {
		unset( $tabs['sales'] );
	}
	
	return $tabs;
}
add_filter( 'lifterlms_reporting_tabs', 'pmpro_lifter_reporting_tabs' );

/**
 * Hide the Restrictions tab of the edit course page if streamline is enabled.
 */
function pmpro_lifter_hide_restrictions_tab( $fields ) {
	// Bail if the streamline option is not enabled.
	if ( ! get_option( 'pmpro_lifter_streamline' ) ) {
		return $fields;
	}
	
	$new_fields = array();
	foreach( $fields as $field ) {
		if ( $field['title'] != __( 'Restrictions', 'lifterlms' ) ) {
			$new_fields[] = $field;
		}
	}
	
	return $new_fields;
}
add_filter( 'llms_metabox_fields_lifterlms_course_options', 'pmpro_lifter_hide_restrictions_tab' );

/**
 * Hide the "No Gateways" notice.
 * @since 2.12
 * @param bool $has_gateways
 * @return bool
 */
function pmpro_lifter_hide_no_payment_gateways_notice( $has_gateways ) {	
	// Bail if the streamline option is not enabled.
	if ( ! get_option( 'pmpro_lifter_streamline' ) ) {
		return $has_gateways;
	}

	// The usage here is odd, but returning true means there are gateways, so don't show the notice.
	return true;
}
add_filter( 'llms_admin_notice_no_payment_gateways', 'pmpro_lifter_hide_no_payment_gateways_notice' );

/**
 * Hide the Access Plans section of the edit course page if streamline is enabled.
 */
function pmpro_lifter_hide_access_plans() {
	// Bail if the streamline option is not enabled.
	if ( ! get_option( 'pmpro_lifter_streamline' ) ) {
		return;
	}
	
	// Remove the Access Plans meta box.
	remove_meta_box( 'lifterlms-product', array( 'course', 'llms_membership' ), 'side' );
	remove_meta_box( 'lifterlms-product', array( 'course', 'llms_membership' ),  'normal' );
}
add_filter( 'add_meta_boxes', 'pmpro_lifter_hide_access_plans' );

/**
 * Override student dashboard links if streamline feature is enabled.
 */
function pmpro_lifter_override_dashboard_tabs( $tabs ) {	
	// Only override if streamlined is enabled.
	if ( ! get_option( 'pmpro_lifter_streamline' ) ) {
		return $tabs;
	}
	
	// Override the My Memberships tab.
	if ( isset( $tabs ) && isset( $tabs['view-memberships'] ) ) {
		$tabs['view-memberships']['url'] = pmpro_url( 'account' );
	}

	// Override the My Orders tab.
	if ( isset( $tabs ) && isset( $tabs['orders'] ) ) {
		$tabs['orders']['url'] = pmpro_url( 'invoice' );
	}

	return $tabs;
}
add_filter( 'llms_get_student_dashboard_tabs', 'pmpro_lifter_override_dashboard_tabs' );

/**
 * Insert our option into the intro step of the LifterLMS wizard.
 */
function pmpro_lifter_intro_html( $html, $wizard ) {
	// Get any previous streamline option. Default to true.
	$streamline = get_option( 'pmpro_lifter_streamline', null );	
	if ( $streamline === null ) {
		$streamline = 1;
	}
	
	// Save output buffer.
	ob_start();
	?>
	<hr />
	<p><?php esc_html_e( 'Since you already have Paid Memberships Pro installed, you can enable a "streamlined" version of LifterLMS that will let PMPro handle all checkouts, memberships, restrictions, and user fields.', 'paid-memberships-pro' ) ?></p>

	<label for="lifter-streamline">
		<input type="checkbox" name="lifter-streamline" id="lifter-streamline" <?php checked( (int)$streamline, 1 ); ?>>
		<?php esc_html_e( 'Enable streamlined version of LifterLMS', 'paid-memberships-pro' ) ?>
	</label>
	<script>
		jQuery(document).ready(function(){
			function pmpro_lifter_add_streamline_to_url() {
				let $checkbox = jQuery('#lifter-streamline');
				let $link = jQuery('.llms-setup-actions a.llms-button-primary');

				//If the checkbox is checked, add streamline to the url
				if ($checkbox.is(':checked')) {
					$link.attr('href', $link.attr('href') + '&pmpro_lifter_streamline=1');
				} else {
					$link.attr('href', $link.attr('href').replace('&pmpro_lifter_streamline=1', ''));
				}
			}
			
			// Update the URL when the checkbox is changed.
			jQuery('#lifter-streamline').on('change', function(){
				pmpro_lifter_add_streamline_to_url();
			});

			// Run on load too.
			pmpro_lifter_add_streamline_to_url();
		});
	</script>
	<?php
	// Add the content buffer to the $html string.
	$html .= ob_get_clean();
	
	return $html;
}
add_filter( 'llms_setup_wizard_intro_html', 'pmpro_lifter_intro_html', 10, 2 );

/**
 * Hook into Page Setup step to save the streamline option.
 */
function pmpro_lifter_save_streamline_option() {
	// Bail if we're in the LifterLMS wizard.
	if ( empty( $_REQUEST['page'] ) || $_REQUEST['page'] !== 'llms-setup' ) {
		return;
	}

	// Bail if we're not on the pages step.
	if ( empty( $_REQUEST['step'] ) || $_REQUEST['step'] !== 'pages' ) {
		return;
	}

	// Bail if the current user doesn't have permission to manage LifterLMS.
	if ( ! current_user_can( 'manage_lifterlms' ) ) {
		return;
	}

	// Get the streamline value.
	if ( ! empty( $_REQUEST['pmpro_lifter_streamline'] ) ) {
		$streamline = 1;
	} else {
		$streamline = 0;
	}
	
	update_option( 'pmpro_lifter_streamline', $streamline );
}
add_action( 'admin_init', 'pmpro_lifter_save_streamline_option' );

/**
 * If the streamline option is enabled, don't create some pages.
 */
function pmpro_lifter_install_get_pages( $pages ) {
	// Bail if streamline is not enabled.
	if ( ! get_option( 'pmpro_lifter_streamline' ) ) {
		return $pages;
	}
	
	// Loop through and remove the membership catalog and checkout pages.
	$new_pages = array();
	foreach ( $pages as $page ) {
		if ( $page['slug'] == 'memberships' || $page['slug'] == 'purchase' ) {
			continue;
		}
		
		$new_pages[] = $page;
	}

	return $new_pages;
}
add_filter( 'llms_install_get_pages', 'pmpro_lifter_install_get_pages' );
add_filter( 'llms_install_create_pages', 'pmpro_lifter_install_get_pages' );	// Old filter.

/**
 * If (1) we are in streamlined mode and (2) PMPro Courses was
 * only being used for the LifterLMS module, deactivate it
 * and show a notice.
 */
function pmpro_lifter_maybe_deprecate_pmpro_courses( $deprecated_addons ) {
	// Bail if streamline is not enabled.
	if ( ! get_option( 'pmpro_lifter_streamline' ) ) {
		return $deprecated_addons;
	}

	// Bail if PMPro Courses is not active.
	if ( ! defined( 'PMPRO_COURSES_VERSION') ) {
		return $deprecated_addons;
	}

	// Bail if any module besides the LifterLMS module is active.
	$active_modules = array_diff( get_option( 'pmpro_courses_modules', array() ), array( 'lifterlms' ) );
	if ( ! empty( $active_modules ) ) {
		return $deprecated_addons;
	}

	// Okay, deprecate PMPro Courses
	$deprecated_addons['pmpro-courses'] = array(
		'file' => 'pmpro-courses.php',
		'label' => 'PMPro Courses',
		'message' => 'The Streamline LifterLMS option is enabled and includes all of the functionality previously found in the LifterLMS module of PMPro Courses.',
	);

	return $deprecated_addons;
}
add_filter( 'pmpro_deprecated_add_ons_list', 'pmpro_lifter_maybe_deprecate_pmpro_courses' );