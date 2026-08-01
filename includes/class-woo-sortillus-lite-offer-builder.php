<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Woo_Sortillus_Lite_Offer_Builder {
	public function build( $product_id ) {
		$product_id = absint( $product_id );
		$product    = wc_get_product( $product_id );
		if ( ! $product || 'publish' !== get_post_status( $product_id ) || 'variation' === $product->get_type() ) {
			return null;
		}

		$stock_status = (string) $product->get_stock_status();
		$backorders   = 'no' !== (string) $product->get_backorders();
		$in_stock     = (bool) $product->is_in_stock();
		$availability = 'outofstock' === $stock_status ? 0 : ( 'onbackorder' === $stock_status ? 1 : 2 );
		$price        = (string) $product->get_price();
		if ( '' === $price && $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' ) ) {
			$price = (string) $product->get_variation_price( 'min', true );
		}

		$offer = array(
			'id'                  => $product_id,
			'external_offer_id'   => (string) $product_id,
			'external_product_id' => (string) $product_id,
			'product_id'          => (string) $product_id,
			'external_id_string'  => 'woo:product:' . $product_id,
			'title'               => (string) $product->get_name(),
			'name'                => (string) $product->get_name(),
			'product_name'        => (string) $product->get_name(),
			'description'         => $this->description( $product ),
			'url'                 => (string) get_permalink( $product_id ),
			'product_url'         => (string) get_permalink( $product_id ),
			'price'               => $price,
			'currency'            => (string) get_woocommerce_currency(),
			'available'           => $in_stock || $backorders,
			'availability'        => $availability,
			'availability_text'   => $stock_status,
			'available_items'     => $backorders ? null : ( $product->managing_stock() ? $product->get_stock_quantity() : null ),
			'in_stock'            => $in_stock,
			'backorders_allowed'  => $backorders,
			'virtual'             => (bool) $product->is_virtual(),
			'downloadable'        => (bool) $product->is_downloadable(),
			'categories'          => $this->terms( $product_id, 'product_cat', true ),
			'tags'                => $this->terms( $product_id, 'product_tag', false ),
			'specification'       => $this->specification( $product ),
			'last_modified_at'    => get_post_modified_time( 'c', true, $product_id ),
		);

		$image = wp_get_attachment_image_url( $product->get_image_id(), 'full' );
		if ( $image ) {
			$offer['image']            = $image;
			$offer['seller_image_url'] = $image;
		}
		$images = $this->images( $product );
		if ( $images ) {
			$offer['seller_images'] = $images;
		}
		$sku = (string) $product->get_sku();
		if ( '' !== $sku ) {
			$offer['sku'] = $sku;
		}
		$gtin = $this->gtin( $product );
		if ( '' !== $gtin ) {
			$offer['gtin'] = $gtin;
			$offer['ean']  = $gtin;
		}
		$brand = $this->brand( $product );
		if ( '' !== $brand ) {
			$offer['brand']                = $brand;
			$offer['product_manufacturer'] = $brand;
		}
		$regular_price = (string) $product->get_regular_price();
		if ( '' !== $regular_price && $regular_price !== $price ) {
			$offer['price_original'] = $regular_price;
		}

		return $offer;
	}

	private function description( $product ) {
		$parts = array_filter(
			array(
				wp_strip_all_tags( (string) $product->get_description() ),
				wp_strip_all_tags( (string) $product->get_short_description() ),
			)
		);
		return implode( "\n\n", array_unique( $parts ) );
	}

	private function gtin( $product ) {
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$value = (string) $product->get_global_unique_id();
			if ( '' !== $value ) {
				return $value;
			}
		}
		foreach ( array( '_global_unique_id', '_gtin', '_ean', '_upc', '_isbn' ) as $key ) {
			$value = get_post_meta( $product->get_id(), $key, true );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}
		return '';
	}

	private function brand( $product ) {
		foreach ( array( 'pa_brand', 'brand' ) as $attribute ) {
			$value = (string) $product->get_attribute( $attribute );
			if ( '' !== trim( $value ) ) {
				return trim( $value );
			}
		}
		$value = get_post_meta( $product->get_id(), '_brand', true );
		return is_string( $value ) ? trim( $value ) : '';
	}

	private function images( $product ) {
		$images = array();
		foreach ( (array) $product->get_gallery_image_ids() as $attachment_id ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'full' );
			if ( $url ) {
				$images[] = $url;
			}
		}
		return array_values( array_unique( $images ) );
	}

	private function terms( $product_id, $taxonomy, $with_parent ) {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$result = array();
		foreach ( $terms as $term ) {
			$item = array(
				'external_id' => (int) $term->term_id,
				'name'        => (string) $term->name,
			);
			if ( $with_parent ) {
				$item['parent_external_id'] = (int) $term->parent;
			}
			$result[] = $item;
		}
		return $result;
	}

	private function specification( $product ) {
		$attributes = array();
		foreach ( (array) $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) ) {
				continue;
			}
			$name   = $attribute->get_name();
			$values = $attribute->is_taxonomy()
				? (array) wc_get_product_terms( $product->get_id(), $name, array( 'fields' => 'names' ) )
				: (array) $attribute->get_options();
			$attributes[] = array(
				'name'   => (string) wc_attribute_label( $name ),
				'values' => array_map( 'strval', $values ),
			);
		}
		return array(
			'weight'     => array( 'value' => (string) $product->get_weight(), 'unit' => (string) get_option( 'woocommerce_weight_unit', '' ) ),
			'dimensions' => array(
				'length' => (string) $product->get_length(),
				'width'  => (string) $product->get_width(),
				'height' => (string) $product->get_height(),
				'unit'   => (string) get_option( 'woocommerce_dimension_unit', '' ),
			),
			'attributes' => $attributes,
			'virtual'    => (bool) $product->is_virtual(),
			'downloadable' => (bool) $product->is_downloadable(),
		);
	}
}
