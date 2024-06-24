<?php
/*
 * Plugin name: Simple WP Crossposting – Relationships Custom Fields
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Description: Allows to crosspost post IDs and term IDs in custom fields
 * Plugin URI: https://rudrastyh.com/support/crossposting-relationships-fields
 * Version: 1.0
 */

class Rudr_SWC_Relationships {

	function __construct() {

		add_filter( 'rudr_swc_pre_crosspost_meta', array( $this, 'process_meta' ), 10, 4 );
		add_filter( 'rudr_swc_pre_crosspost_termmeta', array( $this, 'process_meta' ), 10, 4 );

	}

	function process_meta( $meta_value, $meta_key, $object_id, $blog ) {

		if( ! class_exists( 'Rudr_Simple_WP_Crosspost' ) ) {
			return $meta_value;
		}

		// not an attachment custom field
		if( in_array( $meta_key, apply_filters( 'rudr_crosspost_post_relationship_meta_keys', array() ) ) ) {
			return $this->process_post_relationships( $meta_value, $blog );
		}

		if( in_array( $meta_key, apply_filters( 'rudr_crosspost_term_relationship_meta_keys', array() ) ) ) {
			return $this->process_term_relationships( $meta_value, $blog );
		}

		return $meta_value;

	}

	function process_post_relationships( $meta_value, $blog ) {

		$blog_id = Rudr_Simple_WP_Crosspost::get_blog_id( $blog );

		$meta_value = maybe_unserialize( $meta_value );
		$ids = is_array( $meta_value ) ? $meta_value : array( $meta_value );

		$crossposted_ids = array();
		foreach( $ids as $id ) {
			$post_type = get_post_type( $id );
			if( 'product' === $post_type && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $id );
				// no need to check connection type, this method does that
				if( $product && ( $new_id = Rudr_Simple_Woo_Crosspost::is_crossposted_product( $product, $blog ) ) ) {
					$crossposted_ids[] = $new_id;
				}
			} else {
				if( $new_id = Rudr_Simple_WP_Crosspost::is_crossposted( $id, $blog_id ) ) {
					$crossposted_ids[] = $new_id;
				}
			}
		}
		return is_array( $meta_value ) ? $crossposted_ids : ( $crossposted_ids ? reset( $crossposted_ids ) : 0 );

	}

	function process_term_relationships( $meta_value, $blog ) {

		// we don't know taxonomy yet
		$taxonomy = null;

		// get an array of term slugs
		$slugs = array();
		$term_ids = is_array( $meta_value ) ? $meta_value : array( $meta_value );

		foreach( $term_ids as $term_id ) {
			$term = get_term( $term_id );
			if( ! $term ) {
				continue;
			}
			if( ! $taxonomy ) {
				$taxonomy = $term->taxonomy;
			}
			$slugs[] = $term->slug;
		}

		if( ! $slugs ) {
			return 0;
		}

		// what we are going to return
		$crossposted_term_ids = array();

		// time to get taxonomy
		$taxonomy = get_taxonomy( $taxonomy );
		if( ! is_object( $taxonomy ) ) {
			return 0;
		}
		// rest base
		$rest_base = $taxonomy->rest_base ? $taxonomy->rest_base : $taxonomy->name;

		// sending request
		$request = wp_remote_get(
			add_query_arg(
				array(
					'slug' => join( ',', $slugs ),
					'hide_empty' => 0,
					'per_page' => 50,
				),
				"{$blog[ 'url' ]}/wp-json/wp/v2/{$rest_base}"
			)
		);
		// get results
		if( 'OK' === wp_remote_retrieve_response_message( $request ) ) {
			$terms = json_decode( wp_remote_retrieve_body( $request ), true );
			if( $terms && is_array( $terms ) ) {
				foreach( $terms as $term ) {
					if( empty( $term[ 'id' ] ) ) {
						continue;
					}
					$crossposted_term_ids[] = $term[ 'id' ];
				}
			}
		}
		return is_array( $meta_value ) ? $crossposted_term_ids : ( $crossposted_term_ids ? reset( $crossposted_term_ids ) : 0 );

	}


}

new Rudr_SWC_Relationships;
