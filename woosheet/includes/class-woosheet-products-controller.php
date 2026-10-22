<?php
/**
 * کنترلر محصولات برای REST.
 *
 * @package WooSheet
 */

defined( 'ABSPATH' ) || exit;

/**
 * کلاس کنترلر محصولات.
 */
class WooSheet_Products_Controller {

	/**
	 * بیشترین تعداد ردیف در یک درخواست دسته‌ای.
	 */
	const MAX_BATCH_ITEMS = 200;

	/**
	 * فهرست محصولات با صفحه‌بندی، جست‌وجو و مرتب‌سازی سمت سرور.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response
	 */
	public function list_products( WP_REST_Request $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 200, $per_page ) : 50;
		$search   = trim( (string) $request->get_param( 'search' ) );
		$orderby  = (string) $request->get_param( 'orderby' );
		$order    = strtoupper( (string) $request->get_param( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';
		$status   = (string) $request->get_param( 'status' );
		$type     = (string) $request->get_param( 'type' );
		$cat      = (int) $request->get_param( 'category' );

		$post_status = in_array( $status, WooSheet_Product_Mapper::allowed_statuses(), true )
			? array( $status )
			: array( 'publish', 'draft', 'pending', 'private' );

		$args = array(
			'post_type'      => 'product',
			'post_status'    => $post_status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'no_found_rows'  => false,
		);

		// مرتب‌سازی.
		switch ( $orderby ) {
			case 'name':
				$args['orderby'] = 'title';
				break;
			case 'modified':
				$args['orderby'] = 'modified';
				break;
			case 'price':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = '_price'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				break;
			case 'stock':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = '_stock'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				break;
			case 'sku':
				$args['orderby']  = 'meta_value';
				$args['meta_key'] = '_sku'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				break;
			case 'downloadable':
				// مرتب‌سازی واقعی در فیلتر posts_clauses پایین انجام می‌شود
				// چون محصول متغیر، دانلودی‌بودن را از متغیرهایش می‌گیرد.
				$args['orderby']               = 'ID';
				$args['woosheet_downloadable'] = true;
				break;
			case 'id':
			default:
				$args['orderby'] = 'ID';
				break;
		}
		$args['order'] = $order;

		// فیلتر نوع محصول.
		if ( in_array( $type, array( 'simple', 'variable', 'grouped', 'external' ), true ) ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => $type,
			);
		}

		// فیلتر دسته.
		if ( $cat > 0 ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $cat,
			);
		}

		// جست‌وجو (نام + SKU + شناسهٔ عددی).
		if ( '' !== $search ) {
			$ids = $this->search_ids( $search );
			if ( empty( $ids ) ) {
				$args['post__in'] = array( 0 );
			} else {
				$args['post__in'] = $ids;
			}
		}

		$clause_cb = null;
		if ( ! empty( $args['woosheet_downloadable'] ) ) {
			$clause_cb = $this->downloadable_orderby_filter( $order );
			add_filter( 'posts_clauses', $clause_cb, 10, 2 );
		}

		$query = new WP_Query( $args );

		if ( $clause_cb ) {
			remove_filter( 'posts_clauses', $clause_cb, 10 );
		}

		// یک کوئری برای وضعیت دانلودِ متغیرهای همهٔ محصولات متغیرِ این صفحه.
		WooSheet_Product_Mapper::prime_variation_downloads( wp_list_pluck( $query->posts, 'ID' ) );

		$items = array();
		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post );
			if ( $product ) {
				$items[] = WooSheet_Product_Mapper::product_to_row( $product );
			}
		}

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			),
			200
		);
	}

	/**
	 * یافتن شناسه‌ها بر اساس عبارت جست‌وجو (نام/SKU/شناسه) با یک کوئری بهینه.
	 *
	 * @param string $search عبارت.
	 * @return int[]
	 */
	private function search_ids( $search ) {
		global $wpdb;

		$search = WooSheet_Jalali::normalize_digits( $search );
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$id     = ctype_digit( $search ) ? (int) $search : 0;

		$statuses  = array( 'publish', 'draft', 'pending', 'private' );
		$status_in = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";

		// یک کوئری: تطبیق با عنوان یا SKU یا شناسهٔ دقیق.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $status_in از مقادیر امن ساخته شده.
		$sql = $wpdb->prepare(
			"SELECT DISTINCT p.ID
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} sku ON ( p.ID = sku.post_id AND sku.meta_key = '_sku' )
			 WHERE p.post_type = 'product'
			   AND p.post_status IN ($status_in)
			   AND ( p.post_title LIKE %s OR sku.meta_value LIKE %s OR p.ID = %d )
			 ORDER BY p.ID DESC
			 LIMIT 500",
			$like,
			$like,
			$id
		);
		// phpcs:enable

		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', $ids );
	}

	/**
	 * ساخت فیلتر posts_clauses برای مرتب‌سازی بر اساس «دانلودی‌بودن».
	 *
	 * محصول ساده از متای _downloadable خودش و محصول متغیر از متغیرهایش
	 * دانلودی محسوب می‌شود؛ پس مرتب‌سازی با یک CASE ترکیبی انجام می‌شود.
	 *
	 * @param string $order ASC یا DESC (پیش‌تر اعتبارسنجی شده).
	 * @return callable
	 */
	private function downloadable_orderby_filter( $order ) {
		$dir = ( 'ASC' === $order ) ? 'ASC' : 'DESC';

		return static function ( $clauses, $query ) use ( $dir ) {
			if ( empty( $query->query_vars['woosheet_downloadable'] ) ) {
				return $clauses;
			}

			global $wpdb;

			$clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} ws_dl
				ON ( {$wpdb->posts}.ID = ws_dl.post_id AND ws_dl.meta_key = '_downloadable' )";

			$clauses['orderby'] = "( CASE
					WHEN ws_dl.meta_value = 'yes' THEN 1
					WHEN EXISTS (
						SELECT 1 FROM {$wpdb->posts} ws_v
						INNER JOIN {$wpdb->postmeta} ws_vm
							ON ( ws_v.ID = ws_vm.post_id AND ws_vm.meta_key = '_downloadable' )
						WHERE ws_v.post_parent = {$wpdb->posts}.ID
						  AND ws_v.post_type = 'product_variation'
						  AND ws_vm.meta_value = 'yes'
					) THEN 1
					ELSE 0
				END ) {$dir}, {$wpdb->posts}.ID DESC";

			return $clauses;
		};
	}

	/**
	 * فهرست فایل‌های دانلود یک محصول؛ برای محصول متغیر به تفکیک هر متغیر.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_downloads( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$product = wc_get_product( $id );

		if ( ! $product || $product instanceof WC_Product_Variation ) {
			return new WP_Error( 'woosheet_not_found', __( 'محصول پیدا نشد.', 'woosheet' ), array( 'status' => 404 ) );
		}

		$is_variable = $product->is_type( 'variable' );
		$groups      = array();

		if ( $is_variable ) {
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( ! $variation instanceof WC_Product_Variation || ! $variation->is_downloadable() ) {
					continue;
				}
				$group = $this->downloads_group( $variation, wc_get_formatted_variation( $variation, true, false, false ) );
				if ( $group ) {
					$groups[] = $group;
				}
			}
		} elseif ( $product->is_downloadable() ) {
			$group = $this->downloads_group( $product, $product->get_name() );
			if ( $group ) {
				$groups[] = $group;
			}
		}

		return new WP_REST_Response(
			array(
				'id'          => $product->get_id(),
				'name'        => $product->get_name(),
				'is_variable' => $is_variable,
				'groups'      => $groups,
			),
			200
		);
	}

	/**
	 * ساخت یک گروه دانلود (محصول ساده یا یک متغیر) برای پاسخ REST.
	 *
	 * @param WC_Product $product محصول یا متغیر.
	 * @param string     $label   عنوان گروه.
	 * @return array|null null اگر فایلی تنظیم نشده باشد.
	 */
	private function downloads_group( $product, $label ) {
		$downloads = array();

		foreach ( (array) $product->get_downloads() as $key => $download ) {
			$downloads[] = array(
				'id'   => (string) $key,
				'name' => $download->get_name(),
				'file' => $download->get_file(),
			);
		}

		if ( empty( $downloads ) ) {
			return null;
		}

		return array(
			'id'        => $product->get_id(),
			'name'      => wp_strip_all_tags( (string) $label ),
			'sku'       => $product->get_sku(),
			'limit'     => (int) $product->get_download_limit(),
			'expiry'    => (int) $product->get_download_expiry(),
			'downloads' => $downloads,
		);
	}

	/**
	 * گرفتن متغیرها و ویژگی‌های یک محصول متغیر.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_variations( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$product = wc_get_product( $id );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return new WP_Error( 'woosheet_not_variable', __( 'محصول متغیر پیدا نشد.', 'woosheet' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $this->variations_payload( $product ), 200 );
	}

	/**
	 * تغییر نوع محصول (ساده ⇄ متغیر).
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response|WP_Error
	 */
	public function change_type( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$type = (string) $request->get_param( 'type' );

		if ( ! in_array( $type, array( 'simple', 'variable' ), true ) ) {
			return new WP_Error( 'woosheet_bad_type', __( 'نوع محصول نامعتبر است.', 'woosheet' ), array( 'status' => 400 ) );
		}

		$product = wc_get_product( $id );
		if ( ! $product || $product instanceof WC_Product_Variation ) {
			return new WP_Error( 'woosheet_not_found', __( 'محصول پیدا نشد.', 'woosheet' ), array( 'status' => 404 ) );
		}

		wp_set_object_terms( $id, $type, 'product_type' );
		wc_delete_product_transients( $id );

		if ( 'variable' === $type ) {
			WC_Product_Variable::sync( $id );
		}

		$fresh = wc_get_product( $id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'row'     => WooSheet_Product_Mapper::current_row( $fresh ),
			),
			200
		);
	}

	/**
	 * ذخیرهٔ ویژگی‌های سفارشی محصول (برای ساخت متغیر).
	 *
	 * ویژگی‌های مبتنی بر تکسونومی حفظ می‌شوند؛ فقط ویژگی‌های سفارشی جایگزین می‌گردند.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_attributes( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$input   = $request->get_param( 'attributes' );
		$product = wc_get_product( $id );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return new WP_Error( 'woosheet_not_variable', __( 'محصول متغیر پیدا نشد.', 'woosheet' ), array( 'status' => 404 ) );
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		// حفظ ویژگی‌های تکسونومی موجود.
		$attributes = array();
		$position   = 0;
		foreach ( $product->get_attributes() as $existing ) {
			if ( $existing->is_taxonomy() ) {
				$existing->set_position( $position++ );
				$attributes[] = $existing;
			}
		}

		foreach ( $input as $a ) {
			$name = isset( $a['name'] ) ? wc_clean( wp_unslash( $a['name'] ) ) : '';
			if ( '' === trim( $name ) ) {
				continue;
			}
			$values = isset( $a['values'] ) && is_array( $a['values'] ) ? $a['values'] : array();
			$values = array_values( array_filter( array_map( function ( $v ) {
				return wc_clean( wp_unslash( $v ) );
			}, $values ), function ( $v ) {
				return '' !== trim( $v );
			} ) );

			if ( empty( $values ) ) {
				continue;
			}

			$attr = new WC_Product_Attribute();
			$attr->set_id( 0 ); // ویژگی سفارشی.
			$attr->set_name( $name );
			$attr->set_options( $values );
			$attr->set_position( $position++ );
			$attr->set_visible( true );
			$attr->set_variation( ! empty( $a['variation'] ) );
			$attributes[] = $attr;
		}

		$product->set_attributes( $attributes );
		$product->save();
		WC_Product_Variable::sync( $id );

		$fresh = wc_get_product( $id );
		return new WP_REST_Response( $this->variations_payload( $fresh ), 200 );
	}

	/**
	 * ساخت یک متغیر جدید.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_variation( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$product = wc_get_product( $id );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return new WP_Error( 'woosheet_not_variable', __( 'محصول متغیر پیدا نشد.', 'woosheet' ), array( 'status' => 404 ) );
		}

		$attrs_in = $request->get_param( 'attributes' );
		$attrs_in = is_array( $attrs_in ) ? $attrs_in : array();
		$fields   = $request->get_param( 'fields' );
		$fields   = is_array( $fields ) ? $this->filter_fields( $fields ) : array();

		$result = $this->create_one_variation( $id, $attrs_in, $fields );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		WC_Product_Variable::sync( $id );
		$fresh = wc_get_product( $id );
		return new WP_REST_Response( $this->variations_payload( $fresh ), 201 );
	}

	/**
	 * ساخت همهٔ متغیرها از ترکیب ویژگی‌های نشان‌دارشده برای متغیر.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_variations( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$product = wc_get_product( $id );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return new WP_Error( 'woosheet_not_variable', __( 'محصول متغیر پیدا نشد.', 'woosheet' ), array( 'status' => 404 ) );
		}

		// ویژگی‌های نشان‌دارشده برای متغیر و گزینه‌هایشان.
		$attr_options = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! $attr->get_variation() ) {
				continue;
			}
			$key     = sanitize_title( $attr->get_name() );
			$options = $attr->is_taxonomy()
				? wc_get_product_terms( $id, $attr->get_name(), array( 'fields' => 'slugs' ) )
				: $attr->get_options();
			if ( ! empty( $options ) ) {
				$attr_options[ $key ] = array_values( $options );
			}
		}

		if ( empty( $attr_options ) ) {
			return new WP_Error( 'woosheet_no_var_attrs', __( 'هیچ ویژگیِ نشان‌دارشده برای متغیر وجود ندارد.', 'woosheet' ), array( 'status' => 400 ) );
		}

		$combos = $this->cartesian( $attr_options );
		if ( count( $combos ) > 100 ) {
			return new WP_Error( 'woosheet_too_many', __( 'تعداد ترکیب‌های متغیر بیش از حد مجاز (۱۰۰) است.', 'woosheet' ), array( 'status' => 400 ) );
		}

		// امضای متغیرهای موجود برای جلوگیری از تکرار.
		$existing = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation instanceof WC_Product_Variation ) {
				$existing[ $this->attr_signature( $variation->get_attributes() ) ] = true;
			}
		}

		$created = 0;
		foreach ( $combos as $combo ) {
			if ( isset( $existing[ $this->attr_signature( $combo ) ] ) ) {
				continue;
			}
			$made = $this->create_one_variation( $id, $combo, array() );
			if ( is_wp_error( $made ) || ! $made ) {
				continue; // ساخت این ترکیب ناموفق بود؛ در شمارش نیاید.
			}
			$created++;
		}

		WC_Product_Variable::sync( $id );
		$fresh           = wc_get_product( $id );
		$payload         = $this->variations_payload( $fresh );
		$payload['created'] = $created;
		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * حذف یک متغیر.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_variation( WP_REST_Request $request ) {
		$id           = (int) $request->get_param( 'id' );
		$variation_id = (int) $request->get_param( 'variation_id' );
		$variation    = wc_get_product( $variation_id );

		if ( ! $variation instanceof WC_Product_Variation || $variation->get_parent_id() !== $id ) {
			return new WP_Error( 'woosheet_var_not_found', __( 'متغیر پیدا نشد.', 'woosheet' ), array( 'status' => 404 ) );
		}

		$variation->delete( true );
		WC_Product_Variable::sync( $id );

		$fresh = wc_get_product( $id );
		return new WP_REST_Response( $this->variations_payload( $fresh ), 200 );
	}

	/* ---------------------- کمکی‌های متغیر/ویژگی ---------------------- */

	/**
	 * ساخت بستهٔ پاسخ متغیرها + ویژگی‌ها.
	 *
	 * @param WC_Product $product محصول متغیر.
	 * @return array
	 */
	private function variations_payload( $product ) {
		$variations = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation instanceof WC_Product_Variation ) {
				$variations[] = WooSheet_Product_Mapper::variation_to_row( $variation );
			}
		}

		$attributes = array();
		foreach ( $product->get_attributes() as $attr ) {
			$attributes[] = array(
				'name'        => $attr->get_name(),
				'key'         => sanitize_title( $attr->get_name() ),
				'is_taxonomy' => $attr->is_taxonomy(),
				'variation'   => (bool) $attr->get_variation(),
				'values'      => $attr->is_taxonomy()
					? wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) )
					: $attr->get_options(),
			);
		}

		return array(
			'parent_id'  => $product->get_id(),
			'variations' => $variations,
			'attributes' => $attributes,
		);
	}

	/**
	 * ساخت یک متغیر و اعمال فیلدها.
	 *
	 * @param int   $parent_id شناسهٔ والد.
	 * @param array $attrs_map نگاشت کلید-ویژگی → مقدار.
	 * @param array $fields    فیلدهای قیمت/موجودی.
	 * @return int|WP_Error شناسهٔ متغیر یا خطا.
	 */
	private function create_one_variation( $parent_id, array $attrs_map, array $fields ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );

		$clean = array();
		foreach ( $attrs_map as $k => $v ) {
			$clean[ sanitize_title( $k ) ] = wc_clean( wp_unslash( (string) $v ) );
		}
		$variation->set_attributes( $clean );

		if ( ! empty( $fields ) ) {
			$applied = WooSheet_Product_Mapper::apply_updates( $variation, $fields );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			return $variation->get_id();
		}

		return $variation->save();
	}

	/**
	 * ضرب دکارتی گزینه‌های ویژگی‌ها.
	 *
	 * @param array $groups نگاشت کلید → آرایهٔ گزینه‌ها.
	 * @return array[] فهرست ترکیب‌ها (هر کدام نگاشت کلید → مقدار).
	 */
	private function cartesian( array $groups ) {
		$result = array( array() );
		foreach ( $groups as $key => $values ) {
			$append = array();
			foreach ( $result as $combo ) {
				foreach ( $values as $value ) {
					$new         = $combo;
					$new[ $key ] = $value;
					$append[]    = $new;
				}
			}
			$result = $append;
		}
		return $result;
	}

	/**
	 * امضای یکتای ترکیب ویژگی برای تشخیص تکرار.
	 *
	 * @param array $attrs نگاشت کلید → مقدار.
	 * @return string
	 */
	private function attr_signature( array $attrs ) {
		$norm = array();
		foreach ( $attrs as $k => $v ) {
			$norm[ sanitize_title( $k ) ] = sanitize_title( (string) $v );
		}
		ksort( $norm );
		return wp_json_encode( $norm );
	}

	/**
	 * بروزرسانی یک محصول/متغیر.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_product( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$fields = $request->get_param( 'fields' );

		if ( ! is_array( $fields ) ) {
			return new WP_Error( 'woosheet_no_fields', __( 'هیچ فیلدی برای بروزرسانی ارسال نشده است.', 'woosheet' ), array( 'status' => 400 ) );
		}

		$fields  = $this->filter_fields( $fields );
		$product = wc_get_product( $id );

		if ( ! $product ) {
			return new WP_Error( 'woosheet_not_found', __( 'محصول پیدا نشد.', 'woosheet' ), array( 'status' => 404 ) );
		}

		if ( empty( $fields ) ) {
			return new WP_Error( 'woosheet_no_valid_fields', __( 'فیلد معتبری برای بروزرسانی وجود ندارد.', 'woosheet' ), array( 'status' => 400 ) );
		}

		$result = WooSheet_Product_Mapper::apply_updates( $product, $fields );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// خواندن مجدد از پایگاه‌داده برای بازگرداندن مقدار «حقیقی».
		$fresh = wc_get_product( $id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'row'     => WooSheet_Product_Mapper::current_row( $fresh ),
			),
			200
		);
	}

	/**
	 * بروزرسانی دسته‌ای.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response
	 */
	public function batch_update( WP_REST_Request $request ) {
		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		// سقف، تا یک درخواست سرور را با هزاران ذخیرهٔ محصول قفل نکند.
		if ( count( $items ) > self::MAX_BATCH_ITEMS ) {
			return new WP_REST_Response(
				array(
					'results' => array(),
					'message' => __( 'تعداد ردیف‌های یک درخواست دسته‌ای بیش از حد مجاز است.', 'woosheet' ),
				),
				400
			);
		}

		$results = array();
		foreach ( $items as $item ) {
			$id     = isset( $item['id'] ) ? (int) $item['id'] : 0;
			$fields = isset( $item['fields'] ) && is_array( $item['fields'] ) ? $this->filter_fields( $item['fields'] ) : array();

			$product = $id ? wc_get_product( $id ) : false;
			if ( ! $product ) {
				$results[] = array(
					'id'      => $id,
					'success' => false,
					'message' => __( 'محصول پیدا نشد.', 'woosheet' ),
				);
				continue;
			}
			if ( empty( $fields ) ) {
				$results[] = array(
					'id'      => $id,
					'success' => false,
					'message' => __( 'فیلد معتبری وجود ندارد.', 'woosheet' ),
				);
				continue;
			}

			$result = WooSheet_Product_Mapper::apply_updates( $product, $fields );
			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'id'      => $id,
					'success' => false,
					'message' => $result->get_error_message(),
				);
				continue;
			}

			$results[] = array(
				'id'      => $id,
				'success' => true,
				'row'     => WooSheet_Product_Mapper::current_row( wc_get_product( $id ) ),
			);
		}

		return new WP_REST_Response( array( 'results' => $results ), 200 );
	}

	/**
	 * فقط فیلدهای قابل ویرایش مجاز را نگه می‌دارد.
	 *
	 * @param array $fields فیلدهای ورودی.
	 * @return array
	 */
	private function filter_fields( array $fields ) {
		$allowed = WooSheet_Product_Mapper::editable_fields();
		$out     = array();
		foreach ( $fields as $key => $value ) {
			if ( in_array( $key, $allowed, true ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}
}
