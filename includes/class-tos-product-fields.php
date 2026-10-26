<?php
defined( 'ABSPATH' ) || exit;

/**
 * Zwei Felder je Produkt und Variante:
 *   · abweichende Tiger-One-Artikelnummer (wenn die SKU im Shop anders lautet)
 *   · „vom Bestandsabgleich ausnehmen"
 */
class TOS_Product_Fields {

	public static function init() {
		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ) );
		add_action( 'woocommerce_variation_options_inventory', array( __CLASS__, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
	}

	public static function product_fields() {
		global $post;
		woocommerce_wp_text_input(
			array(
				'id'          => TOS_META_CODE,
				'label'       => 'Tiger-One-Artikelnummer',
				'description' => 'Nur ausfüllen, wenn die Artikelnummer von Tiger One nicht der SKU entspricht.',
				'desc_tip'    => true,
				'value'       => get_post_meta( $post->ID, TOS_META_CODE, true ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => TOS_META_EXCLUDE,
				'label'       => 'Vom Tiger-One-Abgleich ausnehmen',
				'description' => 'Bestand und Status dieses Produkts werden nie automatisch geändert.',
				'value'       => get_post_meta( $post->ID, TOS_META_EXCLUDE, true ) === 'yes' ? 'yes' : 'no',
			)
		);
	}

	public static function save_product( $product ) {
		$code = isset( $_POST[ TOS_META_CODE ] ) ? sanitize_text_field( wp_unslash( $_POST[ TOS_META_CODE ] ) ) : '';
		$product->update_meta_data( TOS_META_CODE, $code );
		$product->update_meta_data( TOS_META_EXCLUDE, isset( $_POST[ TOS_META_EXCLUDE ] ) ? 'yes' : 'no' );
		TOS_Matcher::flush();
	}

	public static function variation_fields( $loop, $data, $variation ) {
		woocommerce_wp_text_input(
			array(
				'id'            => TOS_META_CODE . '[' . $loop . ']',
				'name'          => TOS_META_CODE . '[' . $loop . ']',
				'label'         => 'Tiger-One-Artikelnummer',
				'value'         => get_post_meta( $variation->ID, TOS_META_CODE, true ),
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'            => TOS_META_EXCLUDE . '[' . $loop . ']',
				'name'          => TOS_META_EXCLUDE . '[' . $loop . ']',
				'label'         => 'Vom Abgleich ausnehmen',
				'value'         => get_post_meta( $variation->ID, TOS_META_EXCLUDE, true ) === 'yes' ? 'yes' : 'no',
				'wrapper_class' => 'form-row form-row-last',
			)
		);
	}

	public static function save_variation( $variation_id, $loop ) {
		$codes = isset( $_POST[ TOS_META_CODE ] ) && is_array( $_POST[ TOS_META_CODE ] ) ? wp_unslash( $_POST[ TOS_META_CODE ] ) : array();
		$excl  = isset( $_POST[ TOS_META_EXCLUDE ] ) && is_array( $_POST[ TOS_META_EXCLUDE ] ) ? wp_unslash( $_POST[ TOS_META_EXCLUDE ] ) : array();
		update_post_meta( $variation_id, TOS_META_CODE, isset( $codes[ $loop ] ) ? sanitize_text_field( $codes[ $loop ] ) : '' );
		update_post_meta( $variation_id, TOS_META_EXCLUDE, isset( $excl[ $loop ] ) ? 'yes' : 'no' );
		TOS_Matcher::flush();
	}
}
