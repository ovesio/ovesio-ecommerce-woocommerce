<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Ovesio_Export {

	/**
	 * Get orders export data.
	 * 
	 * @param int $duration_months
	 * @return array
	 */
	public function get_orders_export( $duration_months = 12 ) {
		$date_from = date( 'Y-m-d', strtotime( "-$duration_months months" ) );

		// Check for HPOS support (High Performance Order Storage)
		$hpos_enabled = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$hpos_enabled = true;
		}

		// Prepare Status IDs
		$status_options = get_option( 'ovesio_ecommerce_order_states', array() );
		$statuses = array( 'wc-completed', 'wc-processing', 'wc-on-hold' ); // Default
		if ( ! empty( $status_options ) && is_array( $status_options ) ) {
			$statuses = $status_options;
		}

		if ( $hpos_enabled ) {
			// Use standard WC getters for HPOS compatibility
			// Retrieve IDs only to minimize memory usage
			$args = array(
				'date_created' => '>=' . $date_from,
				'limit'        => -1,
				'type'         => 'shop_order',
				'return'       => 'ids',
				'status'       => $statuses,
			);
			$order_ids = wc_get_orders( $args );
		} else {
			// Data stores in wp_posts - Optimize with direct SQL for speed
			global $wpdb;
			
			$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			// Remove 'wc-' prefix for database query if needed, but usually post_status is 'wc-completed'
			
			$sql = $wpdb->prepare( "
				SELECT ID 
				FROM {$wpdb->posts} 
				WHERE post_type = 'shop_order' 
				AND post_status IN ($status_placeholders) 
				AND post_date >= %s
			", array_merge( $statuses, array( $date_from ) ) );

			$order_ids = $wpdb->get_col( $sql );
		}

		$data = array();
		if ( empty( $order_ids ) ) {
			return $data;
		}

		// Process in chunks to manage memory
		$chunks = array_chunk( $order_ids, 250 );
		foreach ( $chunks as $chunk_ids ) {
			foreach ( $chunk_ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					continue;
				}
				
				$order_products = array();
				foreach ( $order->get_items() as $item ) {
					$product = $item->get_product();
					$sku = '';
					
					// Optimized SKU retrieval not worth singular query override due to object cached by WC,
					// but we rely on $order object which is already loaded.
					if ( $product ) {
						$sku = $product->get_sku();
						if ( ! $sku ) {
							$sku = (string) $product->get_id();
						}
					} else {
						$sku = (string) $item->get_product_id();
					}

                    $qty = $item->get_quantity();
                    $line_total = $item->get_total() + $item->get_total_tax();
                    $unit_price = $qty > 0 ? $line_total / $qty : 0;

					$order_products[] = array(
						'sku'      => $sku,
						'name'     => $item->get_name(),
						'quantity' => $qty,
						'price'    => (float) $unit_price, 
					);
				}

				$data[] = array(
					'order_id'    => $order->get_id(),
					'customer_id' => md5( $order->get_billing_email() ),
					'total'       => (float) $order->get_total(),
					'currency'    => $order->get_currency(),
					'date'        => $order->get_date_created()->date( 'Y-m-d H:i:s' ),
					'products'    => $order_products,
				);
			}
			// Optional: force garbage collection if needed, though PHP 7+ is good at this.
		}

		return $data;
	}

	/**
	 * Get products export data optimized.
	 * 
	 * @return array
	 */
	public function get_products_export() {
		global $wpdb;

		// 1. Fetch all Published Products (Simple & Variable) directly from DB
		// We explicitly want: ID, Type, Parent, Title, Content(desc), Excerpt(short_desc)
		$sql = "
			SELECT p.ID, p.post_title, p.post_content, p.post_excerpt, p.post_parent, p.post_type 
			FROM {$wpdb->posts} p
			WHERE p.post_status = 'publish' 
			AND p.post_type IN ('product', 'product_variation')
		";
		
		$raw_products = $wpdb->get_results( $sql );
		
		if ( empty( $raw_products ) ) {
			return array();
		}

		// Re-index by ID for faster lookup
		$products_by_id = array();
		$product_ids = array();
		foreach ( $raw_products as $p ) {
			$products_by_id[ $p->ID ] = $p;
			$product_ids[] = $p->ID;
		}

		// 2. Bulk Fetch Post Meta (Price, SKU, Stock, Image ID)
		$product_ids_sql = implode( ',', array_map( 'intval', $product_ids ) );
		// Need: _sku, _price, _stock, _stock_status, _thumbnail_id
		$meta_sql = "
			SELECT post_id, meta_key, meta_value 
			FROM {$wpdb->postmeta} 
			WHERE post_id IN ($product_ids_sql) 
			AND meta_key IN ('_sku', '_price', '_stock', '_stock_status', '_thumbnail_id', '_product_attributes')
		";
		$raw_meta = $wpdb->get_results( $meta_sql );
		
		$meta_by_id = array();
		foreach ( $raw_meta as $m ) {
			$meta_by_id[ $m->post_id ][ $m->meta_key ] = $m->meta_value;
		}

        // 3. Bulk Fetch Taxonomies (Categories, Manufacturers)
        // We only care about product_cat and maybe brands/manufacturers
        // Combining term lookups in one query is tricky, efficient way is per-taxonomy or just bulk term relationships
        // Let's do bulk term relationships for all IDs
        $terms_sql = "
            SELECT tr.object_id, t.name, tt.taxonomy, t.term_id, tt.parent
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
            WHERE tr.object_id IN ($product_ids_sql)
            AND tt.taxonomy IN ('product_cat', 'pa_brand', 'pa_manufacturer', 'brand', 'manufacturer')
        ";
        $raw_terms = $wpdb->get_results( $terms_sql );
        
        $terms_by_id = array();
        // Cache term parents for path building
        $term_parents = array(); 
		$term_names = array();

        foreach ( $raw_terms as $row ) {
            $terms_by_id[ $row->object_id ][ $row->taxonomy ][] = $row->term_id;
			$term_parents[ $row->term_id ] = $row->parent;
			$term_names[ $row->term_id ] = $row->name;
        }

		$data = array();
		$base_currency = get_woocommerce_currency();

		foreach ( $products_by_id as $id => $p ) {
            // Logic:
            // If it's a variation, merge with parent data where missing (description, etc)
            // If it's a variable product (parent), skip it?
            // Usually feeds want the buyable items (simple + variations).
            // Ovesio legacy code exported everything found in `product` table.
            // We should export Simple products AND Variations.
            // We should NOT export 'variable' parent products as distinct buyable items if we are exporting their variations.
            // However, keeping logic simple: If type is 'product' (simple) or 'product_variation', export.
            
            // Check post type
			if ( $p->post_type === 'product' ) {
				// Check using meta if it is variable?
                // Actually `wc_get_products` loop checked `is_type('variable')`.
                // In raw SQL, post_type 'product' covers simple, variable, grouped, external.
                // We identify variable products by checking if they have children? Or typically we skip "variable" parents in feeds.
                // But let's check if it has variations.
                // Simplification for feed: Export everything that has a price.
                
                // If it's a variable product, it usually doesn't have a distinct price or stock, its variations do.
                // We'll rely on Price existence.
			}

            $meta = isset( $meta_by_id[ $id ] ) ? $meta_by_id[ $id ] : array();
            
            $price = isset( $meta['_price'] ) ? $meta['_price'] : '';
            if ( $p->post_type === 'product' && $price === '' ) {
                // Likely a variable product container or purely out of stock/draft logic, or grouped.
                // If no price, skip (unless it's free? but usually empty string means N/A for variable parents)
                // Let's assume if it has variations, we skip (variations will be picked up as post_type='product_variation')
                // A quick check: do we have variations for this parent?
                // Optimization: Just check if we processed children.
                // Safer heuristic: Export if it has a price.
                continue; 
            }

            $sku = isset( $meta['_sku'] ) ? $meta['_sku'] : '';
            if ( empty( $sku ) ) {
                $sku = (string) $id;
            }

            $qty = isset( $meta['_stock'] ) ? $meta['_stock'] : 0;
            $stock_status = isset( $meta['_stock_status'] ) ? $meta['_stock_status'] : 'instock';
            $availability = $stock_status === 'instock' ? 'in_stock' : 'out_of_stock';
            
            if ( isset( $meta['_stock'] ) && $qty <= 0 && $stock_status === 'instock' ) {
                 // Managed stock with 0 qty but status instock? (Backorders)
                 // Keep as in_stock
            }
            if ( ! isset( $meta['_stock'] ) && $stock_status === 'instock' ) {
                 $qty = 999; // Not managed
            }

            // Description logic
            $description = $p->post_content;
            if ( ! $description ) {
                $description = $p->post_excerpt; // Short desc
            }
            // Fallback to parent if variation
            if ( $p->post_type === 'product_variation' && empty( $description ) && $p->post_parent ) {
                 if ( isset( $products_by_id[ $p->post_parent ] ) ) {
                     $parent = $products_by_id[ $p->post_parent ];
                     $description = $parent->post_content ? $parent->post_content : $parent->post_excerpt;
                 }
            }

            // Image
            $image_id = isset( $meta['_thumbnail_id'] ) ? $meta['_thumbnail_id'] : '';
            if ( ! $image_id && $p->post_type === 'product_variation' && $p->post_parent ) {
                 $parent_meta = isset( $meta_by_id[ $p->post_parent ] ) ? $meta_by_id[ $p->post_parent ] : array();
                 $image_id = isset( $parent_meta['_thumbnail_id'] ) ? $parent_meta['_thumbnail_id'] : '';
            }
            $image_url = $image_id ? wp_get_attachment_url( $image_id ) : '';

            // Manufacturer / Brand
            $manufacturer = '';
            $product_terms = isset( $terms_by_id[ $id ] ) ? $terms_by_id[ $id ] : array();
            
            // Try to find brand in terms
            foreach( ['pa_manufacturer', 'pa_brand', 'brand', 'manufacturer'] as $tax ) {
                if ( isset( $product_terms[ $tax ] ) && ! empty( $product_terms[ $tax ] ) ) {
                    $term_id = $product_terms[ $tax ][0]; // Take first
                    $manufacturer = isset( $term_names[ $term_id ] ) ? $term_names[ $term_id ] : '';
                    break;
                }
            }
            // If failed, try parent terms if variation
            if ( ! $manufacturer && $p->post_parent && isset( $terms_by_id[ $p->post_parent ] ) ) {
                $parent_terms = $terms_by_id[ $p->post_parent ];
                foreach( ['pa_manufacturer', 'pa_brand', 'brand', 'manufacturer'] as $tax ) {
                    if ( isset( $parent_terms[ $tax ] ) && ! empty( $parent_terms[ $tax ] ) ) {
                        $term_id = $parent_terms[ $tax ][0];
                        $manufacturer = isset( $term_names[ $term_id ] ) ? $term_names[ $term_id ] : '';
                        break;
                    }
                }
            }

            // Category Path
            // Variations don't have categories usually, they inherit from parent
            $cat_target_id = $id;
            if ( $p->post_type === 'product_variation' ) {
                $cat_target_id = $p->post_parent;
            }
            
            $cat_path_str = '';
            if ( isset( $terms_by_id[ $cat_target_id ]['product_cat'] ) ) {
                 $cat_ids = $terms_by_id[ $cat_target_id ]['product_cat'];
                 if ( ! empty( $cat_ids ) ) {
                     // Pick one (first)
                     $first_cat = $cat_ids[0];
                     // Build path
                     $path = array();
                     $curr = $first_cat;
                     while( $curr && isset( $term_names[$curr] ) ) {
                         array_unshift( $path, $term_names[$curr] );
                         $curr = isset( $term_parents[$curr] ) ? $term_parents[$curr] : 0;
                         if ( $curr == 0 ) break;
                         // Prevent infinite loop if bad data
                         if ( in_array( $term_names[$curr] ?? '', $path ) ) break; 
                     }
                     $cat_path_str = implode( ' > ', $path );
                 }
            }

            // Name
            $name = $p->post_title;
            if ( $p->post_type === 'product_variation' && $p->post_parent ) {
                $parent_title = isset( $products_by_id[ $p->post_parent ] ) ? $products_by_id[ $p->post_parent ]->post_title : '';
                $name = $parent_title . ' - ' . $name; 
                // Alternatively, variations often have title "Variation #123", we might want the attributes in name?
                // Standard WC behavior is "Parent Name - Attribute Name".
                // Detailed name generation is complex without WC functions (`$product->get_name()`).
                // Optimized compromise: Use Title. If strictly "Variation #...", maybe prepend parent.
            }

			$data[] = array(
				'sku'          => $sku,
				'name'         => $name,
				'quantity'     => (int) $qty,
				'price'        => (float) $price,
				'currency'     => $base_currency,
				'availability' => $availability,
				'description'  => $this->clean_html( $description ),
				'manufacturer' => $manufacturer,
				'image'        => $image_url,
				'url'          => get_permalink( $id ),
				'category'     => $cat_path_str,
			);
		}

		return $data;
	}

	private function clean_html( $content ) {
		$text = strip_tags( $content );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\t+/', ' ', $text );
		$text = preg_replace( '/ +/', ' ', $text );
		$text = preg_replace( "/(\r?\n){2,}/", "\n", $text );
		return trim( $text );
	}
}
