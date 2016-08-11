<?php
/**
 * WP Rename Images to Match Image Title
 *
 * Force the filename of images to match the image title
 *
 * @package   WP_Rename_Images_to_Match_Image_Title
 * @author    Juke Labs, Inc. <hello@jukelabs.com>
 * @license   GPL-2.0+
 * @copyright 2016 Juke Labs, Inc.
 *
 * @wordpress-plugin
 * Plugin Name:       WP Rename Images to Match Image Title
 * Plugin URI:
 * Description:       Force the filename of images to match the image title
 * Version:           1.0.0
 * Author:            Juke Labs, Inc.
 * Author URI:        http://jukelabs.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * GitHub Plugin URI:
 */
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Emergency Off Switch
// return;

/* Load on init */
add_action( 'init', 'init_rename_images' );

function init_rename_images() {
	if( !is_admin() )
		return;

	$query_images_args = array(
	    'post_type'      => 'attachment',
	    'post_mime_type' => 'image',
	    'post_status'    => 'inherit',
	    'posts_per_page' => - 1,
	);

	$query_images = new WP_Query( $query_images_args );

	$images = array();
	foreach ( $query_images->posts as $image ) {
		if( !empty($image = rename_images_update( $image ))  ) {
			$images[] = $image;
		}
	}

	// Debug
	// if( !empty($images) ) {
	// 	echo '<pre>';
	// 	var_dump( $images );
	// 	exit;
	// }

}

function rename_images_update( $image ) {
	require_once ( ABSPATH . 'wp-admin/includes/image.php' );

	/* Get original filename */
	$orig_file = get_attached_file( $image->ID );
	$orig_filename = basename( $orig_file );
	$orig_fileext = wp_check_filetype($orig_filename)['ext'];

	/* Get original path of file */
	$orig_dir_path = substr( $orig_file, 0, ( strrpos( $orig_file, "/" ) ) );

	/* Get image sizes */
	$image_sizes = array_merge( get_intermediate_image_sizes(), array( 'full' ) );

	/* If image, get URLs to original sizes */
	if ( wp_attachment_is_image( $image->ID ) ) {
		$orig_image_urls = array();

		foreach ( $image_sizes as $image_size ) {
			$orig_image_data = wp_get_attachment_image_src( $image->ID, $image_size );
			$orig_image_urls[$image_size] = $orig_image_data[0];
		}
	/* Otherwise, get URL to original file */
	} else {
		$orig_attachment_url = wp_get_attachment_url( $image->ID );
	}

	$image_title = $image->post_title;
	$image_name = $image_title . '.' . $orig_fileext;

	if( $image_name == $orig_filename ) {
		return;
	}

	/* Make new filename and path */
	$new_filename = wp_unique_filename( $orig_dir_path, $image_name );
	$new_file = $orig_dir_path . "/" . $new_filename;

	/* Make new file with desired name */
	$copy = copy( $orig_file, $new_file );
	$delete = unlink( $orig_file );

	if (!$copy || !$delete) {
		die('Permissions denied for file copy');
	}

	/* Delete Sizes */
	if ( wp_attachment_is_image( $image->ID ) ) {
		foreach ( $image_sizes as $image_size ) {
			$orig_image_data = image_get_intermediate_size( $image->ID, $image_size );
			if( $orig_image_data['file'] ) {
				unlink( $orig_dir_path . "/" . $orig_image_data['file'] );
			}
		}
	}

	/* Update file location in database */
	update_attached_file( $image->ID, $new_file );

	/* Update guid for attachment */
	$post_for_guid = get_post( $image->ID );
	$guid = str_replace( $orig_filename, $new_filename, $post_for_guid->guid );

	wp_update_post( array(
		'ID' => $image->ID,
		'guid' => $guid
	) );

	/* Update attachment's metadata */
	wp_update_attachment_metadata( $image->ID, wp_generate_attachment_metadata( $image->ID, $new_file) );

	/* Load global so that we can save to the database */
	global $wpdb;

	/* If image, get URLs to new sizes and update posts with old URLs */
	if ( wp_attachment_is_image( $image->ID ) ) {
		foreach ( $image_sizes as $image_size ) {
			$orig_image_url = $orig_image_urls[$image_size];
			$new_image_data = wp_get_attachment_image_src( $image->ID, $image_size );
			$new_image_url = $new_image_data[0];

			$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->posts SET post_content = REPLACE(post_content, %s, %s);", $orig_image_url, $new_image_url ) );
		}
	/* Otherwise, get URL to new file and update posts with old URL */
	} else {
		$new_attachment_url = wp_get_attachment_url( $image->ID );

		$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->posts SET post_content = REPLACE(post_content, %s, %s);" ), $orig_attachment_url, $new_attachment_url );
	}

	return get_post($image->ID);
}

?>