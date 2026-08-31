<?php
/**
 * CAT — Equipment Unit Auto-Title
 *
 * Equipment Units (individual physical printers, cutters, scanners,
 * tablets, light pads) never get a manually-typed title. Instead this
 * generates one automatically from Equipment Model + Workstation +
 * Location every time the record is saved, so what shows up everywhere
 * (Dashboard, Records, post_object dropdowns in other forms) is something
 * a lab admin actually recognizes - "Epson SureColor P800 — Station 2 —
 * Room 312" - instead of an internal ID or the manufacturer serial
 * number, which only matters for school-wide asset tracking, not for
 * day-to-day lab use.
 *
 * Pairs with cat-universal-acf-form-block.php, which hides the manual
 * WordPress title field specifically for the "equipment" post type so
 * nobody is ever asked to type a title for a printer/unit by hand.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'acf/save_post', 'cat_set_equipment_auto_title', 20 );

function cat_set_equipment_auto_title( $post_id ) {
	if ( ! is_int( $post_id ) && ! ctype_digit( (string) $post_id ) ) {
		return; // acf/save_post also fires for option pages (non-numeric $post_id) - not our concern here.
	}

	$post_id = (int) $post_id;

	if ( 'equipment' !== get_post_type( $post_id ) ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	$title = cat_build_equipment_title( $post_id );

	if ( '' === $title ) {
		return; // Nothing to build from yet (no model/workstation/location set).
	}

	$current = get_post_field( 'post_title', $post_id );
	if ( $current === $title ) {
		return; // Already correct - skip the extra wp_update_post() write.
	}

	// Avoid re-entering this same hook via the wp_update_post() call below.
	remove_action( 'acf/save_post', 'cat_set_equipment_auto_title', 20 );

	wp_update_post(
		array(
			'ID'         => $post_id,
			'post_title' => $title,
			'post_name'  => sanitize_title( $title . '-' . $post_id ), // suffix keeps the slug unique even if two units end up with the same title.
		)
	);

	add_action( 'acf/save_post', 'cat_set_equipment_auto_title', 20 );
}

/**
 * Builds "Model — Workstation — Location", skipping any part that isn't
 * set yet (a unit can be saved before its workstation/location is known).
 */
function cat_build_equipment_title( $post_id ) {
	$parts = array();

	$model_id = get_field( 'equipment_model', $post_id );
	if ( $model_id ) {
		$parts[] = cat_equipment_model_label( (int) $model_id );
	}

	$workstation_id = get_field( 'current_workstation', $post_id );
	if ( $workstation_id ) {
		$parts[] = get_the_title( (int) $workstation_id );
	}

	$location_id = get_field( 'current_location', $post_id );
	if ( $location_id ) {
		$parts[] = get_the_title( (int) $location_id );
	}

	$parts = array_filter( array_map( 'trim', $parts ) );

	return implode( ' — ', $parts );
}

/**
 * "Manufacturer Model Name" (e.g. "Epson SureColor P800") when both are
 * filled in on the Equipment Model, falling back to that model's own
 * post title otherwise.
 */
function cat_equipment_model_label( $model_id ) {
	$manufacturer = trim( (string) get_field( 'manufacturer', $model_id ) );
	$model_name   = trim( (string) get_field( 'model_name', $model_id ) );
	$label        = trim( $manufacturer . ' ' . $model_name );

	return '' !== $label ? $label : get_the_title( $model_id );
}
