<?php
/**
 * خروجی محصولات به فایل xlsx.
 *
 * @package WooSheet
 */

defined( 'ABSPATH' ) || exit;

/**
 * کلاس خروجی اکسل.
 */
class WooSheet_Export {

	/**
	 * تعریف ستون‌های مشترک خروجی/ورودی.
	 *
	 * هر ستون: key (کلید داخلی)، label (عنوان فارسی)، editable (آیا ورودی می‌تواند تغییرش دهد).
	 *
	 * @return array[]
	 */
	public static function columns() {
		return array(
			array(
				'key'      => 'id',
				'label'    => 'شناسه',
				'editable' => false,
			),
			array(
				'key'      => 'parent_id',
				'label'    => 'شناسهٔ والد',
				'editable' => false,
			),
			array(
				'key'      => 'type',
				'label'    => 'نوع',
				'editable' => false,
			),
			array(
				'key'      => 'name',
				'label'    => 'نام محصول',
				'editable' => true,
			),
			array(
				'key'      => 'sku',
				'label'    => 'SKU',
				'editable' => true,
			),
			array(
				'key'      => 'regular_price',
				'label'    => 'قیمت اصلی (تومان)',
				'editable' => true,
			),
			array(
				'key'      => 'sale_price',
				'label'    => 'قیمت تخفیفی (تومان)',
				'editable' => true,
			),
			array(
				'key'      => 'sale_from',
				'label'    => 'شروع تخفیف (شمسی)',
				'editable' => true,
			),
			array(
				'key'      => 'sale_to',
				'label'    => 'پایان تخفیف (شمسی)',
				'editable' => true,
			),
			array(
				'key'      => 'stock_quantity',
				'label'    => 'موجودی',
				'editable' => true,
			),
			array(
				'key'      => 'stock_status',
				'label'    => 'وضعیت موجودی',
				'editable' => true,
			),
			array(
				'key'      => 'status',
				'label'    => 'وضعیت انتشار',
				'editable' => true,
			),
			array(
				'key'      => 'categories',
				'label'    => 'دسته‌ها',
				'editable' => false,
			),
			array(
				'key'      => 'permalink',
				'label'    => 'لینک محصول',
				'editable' => false,
			),
		);
	}

	/**
	 * ساخت ردیف‌های خروجی (شامل سرستون + محصولات + متغیرها).
	 *
	 * @return array[]
	 */
	public static function build_rows() {
		// کاتالوگ بزرگ می‌تواند از حد زمان/حافظهٔ پیش‌فرض عبور کند.
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}
		wp_raise_memory_limit( 'admin' );

		$columns = self::columns();

		// سرستون.
		$header = array();
		foreach ( $columns as $col ) {
			$header[] = $col['label'];
		}
		$rows = array( $header );

		$paged    = 1;
		$per_page = 200;

		do {
			$ids = wc_get_products(
				array(
					'status'  => array( 'publish', 'draft', 'pending', 'private' ),
					'limit'   => $per_page,
					'page'    => $paged,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'ids',
				)
			);

			foreach ( $ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}

				$rows[] = self::row_from_data( WooSheet_Product_Mapper::product_to_row( $product ), $columns );

				if ( $product->is_type( 'variable' ) ) {
					foreach ( $product->get_children() as $child_id ) {
						$variation = wc_get_product( $child_id );
						if ( $variation instanceof WC_Product_Variation ) {
							$rows[] = self::row_from_data( WooSheet_Product_Mapper::variation_to_row( $variation ), $columns );
						}
					}
				}
			}

			$paged++;
			// آزادسازی کش برای حجم بالا.
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} while ( count( $ids ) === $per_page );

		return $rows;
	}

	/**
	 * تبدیل دادهٔ یک ردیف محصول به آرایهٔ مرتب بر اساس ستون‌ها با نوع داده درست.
	 *
	 * @param array   $data    دادهٔ ردیف از mapper.
	 * @param array[] $columns ستون‌ها.
	 * @return array
	 */
	private static function row_from_data( array $data, array $columns ) {
		$numeric_keys = array( 'id', 'parent_id', 'regular_price', 'sale_price', 'stock_quantity' );
		$row          = array();

		foreach ( $columns as $col ) {
			$key   = $col['key'];
			$value = isset( $data[ $key ] ) ? $data[ $key ] : '';

			if ( in_array( $key, $numeric_keys, true ) && '' !== $value && is_numeric( $value ) ) {
				// به‌صورت عدد ذخیره شود تا در اکسل قابل محاسبه باشد.
				$row[] = ( floor( (float) $value ) == (float) $value ) ? (int) $value : (float) $value;
			} else {
				$row[] = (string) $value;
			}
		}

		return $row;
	}

	/**
	 * تولید فایل xlsx و خروجی دانلود (و خروج).
	 *
	 * @return void|WP_Error
	 */
	public static function stream_download() {
		$rows = self::build_rows();

		// فایل موقت در مسیر temp سیستم ساخته می‌شود، نه داخل wp-content/uploads
		// که از وب قابل دسترسی است (اگر اسکریپت وسط کار قطع شود، فایلِ حاوی کل
		// کاتالوگ آنجا باقی می‌ماند).
		$tmp = wp_tempnam( 'woosheet-export.xlsx' );
		if ( ! $tmp ) {
			return new WP_Error( 'woosheet_tmp_failed', __( 'ساخت فایل موقت ناموفق بود.', 'woosheet' ) );
		}

		$result = WooSheet_XLSX::write( $tmp, $rows, 'Products' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$filename = 'woosheet-products-' . gmdate( 'Y-m-d' ) . '.xlsx';

		// هر خروجیِ زودهنگام (هشدار PHP، BOM، افزونهٔ دیگر) فایل xlsx را خراب می‌کند.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Content-Length: ' . filesize( $tmp ) );

		readfile( $tmp );
		wp_delete_file( $tmp );
		exit;
	}
}
