<?php
/**
 * ورود محصولات از فایل xlsx.
 *
 * اصول ضدباگ:
 *  - تطبیق فقط بر اساس «شناسه» (ID). هیچ محصولی ساخته نمی‌شود → داپلیکیت غیرممکن.
 *  - سلول خالی یعنی «بدون تغییر» (هیچ مقداری پاک نمی‌شود) تا از حذف ناخواستهٔ داده جلوگیری شود.
 *  - ابتدا اعتبارسنجی کامل (dry-run) و سپس اعمال نهایی پس از تأیید کاربر.
 *
 * @package WooSheet
 */

defined( 'ABSPATH' ) || exit;

/**
 * کلاس ورود اکسل.
 */
class WooSheet_Import {

	/**
	 * اعتبارسنجی فایل (بدون نوشتن).
	 *
	 * @param string $path مسیر فایل xlsx.
	 * @return array|WP_Error گزارش پیش‌نمایش.
	 */
	public static function validate( $path ) {
		return self::process( $path, false );
	}

	/**
	 * اعمال نهایی تغییرات.
	 *
	 * @param string $path مسیر فایل xlsx.
	 * @return array|WP_Error گزارش نتیجه.
	 */
	public static function commit( $path ) {
		return self::process( $path, true );
	}

	/**
	 * پردازش فایل (مشترک بین اعتبارسنجی و اعمال).
	 *
	 * @param string $path  مسیر فایل.
	 * @param bool   $apply اگر true باشد تغییرات ذخیره می‌شود.
	 * @return array|WP_Error
	 */
	private static function process( $path, $apply ) {
		$rows = WooSheet_XLSX::read( $path );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		if ( count( $rows ) < 2 ) {
			return new WP_Error( 'woosheet_empty_file', __( 'فایل اکسل خالی است یا فقط سرستون دارد.', 'woosheet' ), array( 'status' => 400 ) );
		}

		// کلید هر ردیف، شمارهٔ واقعی همان ردیف در اکسل است (نه ایندکس پیوسته).
		$row_numbers  = array_keys( $rows );
		$header_index = array_shift( $row_numbers );

		$header_map = self::build_header_map( $rows[ $header_index ] );
		if ( ! isset( $header_map['id'] ) ) {
			return new WP_Error( 'woosheet_no_id_column', __( 'ستون «شناسه» در فایل پیدا نشد. لطفاً از قالب خروجی همین افزونه استفاده کنید.', 'woosheet' ), array( 'status' => 400 ) );
		}

		$columns_by_key = array();
		foreach ( WooSheet_Export::columns() as $col ) {
			$columns_by_key[ $col['key'] ] = $col;
		}

		$results = array();
		$summary = array(
			'total'     => 0,
			'updated'   => 0,
			'no_change' => 0,
			'skipped'   => 0,
			'errors'    => 0,
		);

		foreach ( $row_numbers as $row_num ) {
			$row = $rows[ $row_num ];

			// ردیف کاملاً خالی را نادیده بگیر.
			if ( self::is_blank_row( $row ) ) {
				continue;
			}

			$summary['total']++;

			$id_raw = self::cell( $row, $header_map['id'] );
			$id_raw = WooSheet_Jalali::normalize_digits( trim( (string) $id_raw ) );

			if ( '' === $id_raw || ! ctype_digit( $id_raw ) ) {
				$results[]          = self::result( $row_num, 0, 'skipped', __( 'شناسهٔ معتبر ندارد؛ نادیده گرفته شد (محصول جدیدی ساخته نمی‌شود).', 'woosheet' ) );
				$summary['skipped']++;
				continue;
			}

			$id      = (int) $id_raw;
			$product = wc_get_product( $id );
			if ( ! $product ) {
				$results[]          = self::result( $row_num, $id, 'skipped', __( 'محصولی با این شناسه پیدا نشد؛ نادیده گرفته شد.', 'woosheet' ) );
				$summary['skipped']++;
				continue;
			}

			// مقادیر ورودیِ ستون‌های قابل ویرایش.
			$raw_values = array();
			foreach ( $columns_by_key as $key => $col ) {
				if ( ! $col['editable'] || ! isset( $header_map[ $key ] ) ) {
					continue;
				}
				$raw_values[ $key ] = trim( (string) self::cell( $row, $header_map[ $key ] ) );
			}

			$changes = self::compute_changes( $product, $raw_values );

			if ( empty( $changes ) ) {
				$results[]            = self::result( $row_num, $id, 'no_change', __( 'بدون تغییر.', 'woosheet' ) );
				$summary['no_change']++;
				continue;
			}

			$applied = WooSheet_Product_Mapper::apply_updates( $product, $changes, ! $apply );

			if ( is_wp_error( $applied ) ) {
				$results[]          = self::result( $row_num, $id, 'error', $applied->get_error_message(), array_keys( $changes ) );
				$summary['errors']++;
				continue;
			}

			$results[]            = self::result(
				$row_num,
				$id,
				'updated',
				$apply ? __( 'اعمال شد.', 'woosheet' ) : __( 'آمادهٔ اعمال.', 'woosheet' ),
				array_keys( $changes )
			);
			$summary['updated']++;
		}

		return array(
			'applied' => (bool) $apply,
			'summary' => $summary,
			'results' => $results,
		);
	}

	/**
	 * محاسبهٔ فیلدهایی که واقعاً تغییر می‌کنند (در مقایسه با مقدار فعلی).
	 *
	 * فقط سلول‌های غیرخالی بررسی می‌شوند؛ سلول خالی = بدون تغییر.
	 *
	 * @param WC_Product $product    محصول.
	 * @param array      $raw_values مقادیر خام ستون‌ها.
	 * @return array کلید→مقدار تغییرات.
	 */
	private static function compute_changes( $product, array $raw_values ) {
		$current = WooSheet_Product_Mapper::current_row( $product );

		$changes = array();

		foreach ( $raw_values as $key => $new_raw ) {
			if ( '' === $new_raw ) {
				continue; // سلول خالی = بدون تغییر.
			}

			$cur = isset( $current[ $key ] ) ? (string) $current[ $key ] : '';

			$changed = false;
			switch ( $key ) {
				case 'regular_price':
				case 'sale_price':
				case 'stock_quantity':
					$new_norm = self::to_float_or_null( $new_raw );
					$cur_norm = self::to_float_or_null( $cur );
					if ( null === $new_norm ) {
						$changed = true; // نامعتبر؛ بگذار اعتبارسنجی خطا بدهد.
					} else {
						$changed = ( $new_norm !== $cur_norm );
					}
					break;

				case 'sale_from':
				case 'sale_to':
					$changed = ( WooSheet_Jalali::normalize_digits( $new_raw ) !== WooSheet_Jalali::normalize_digits( $cur ) );
					break;

				default:
					$changed = ( $new_raw !== $cur );
					break;
			}

			if ( $changed ) {
				$changes[ $key ] = $new_raw;
			}
		}

		return $changes;
	}

	/**
	 * تبدیل مقدار قیمتی/عددی به float یا null.
	 *
	 * @param string $val ورودی.
	 * @return float|null
	 */
	private static function to_float_or_null( $val ) {
		$val = WooSheet_Jalali::normalize_digits( (string) $val );
		$val = str_replace( array( ',', '،', ' ', "\xc2\xa0", '٬' ), '', trim( $val ) );
		if ( '' === $val || ! is_numeric( $val ) ) {
			return null;
		}
		return (float) $val;
	}

	/**
	 * ساخت نگاشت سرستون (عنوان ستون → کلید داخلی).
	 *
	 * @param array $header_row ردیف سرستون.
	 * @return array کلید→ایندکس ستون.
	 */
	private static function build_header_map( array $header_row ) {
		// نام‌های پذیرفته‌شده برای هر کلید (عنوان فارسی، کلید انگلیسی و نام‌های مستعار).
		$aliases = array();
		foreach ( WooSheet_Export::columns() as $col ) {
			$aliases[ self::norm_header( $col['label'] ) ] = $col['key'];
			$aliases[ self::norm_header( $col['key'] ) ]   = $col['key'];
		}
		// مستعارهای اضافی.
		$aliases[ self::norm_header( 'id' ) ]    = 'id';
		$aliases[ self::norm_header( 'آیدی' ) ]  = 'id';

		$map = array();
		foreach ( $header_row as $index => $label ) {
			$norm = self::norm_header( (string) $label );
			if ( '' === $norm ) {
				continue;
			}
			if ( isset( $aliases[ $norm ] ) && ! isset( $map[ $aliases[ $norm ] ] ) ) {
				$map[ $aliases[ $norm ] ] = (int) $index;
			}
		}

		return $map;
	}

	/**
	 * نرمال‌سازی عنوان سرستون برای تطبیق.
	 *
	 * @param string $label عنوان.
	 * @return string
	 */
	private static function norm_header( $label ) {
		$label = WooSheet_Jalali::normalize_digits( $label );
		$label = trim( $label );
		// حذف فاصله‌های اضافی و یکسان‌سازی نیم‌فاصله.
		$label = str_replace( array( "\xe2\x80\x8c", "\xe2\x80\x8f", "\xe2\x80\x8e" ), '', $label );
		$label = preg_replace( '/\s+/u', ' ', $label );
		$label = function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );
		return $label;
	}

	/**
	 * خواندن یک سلول از ردیف با مدیریت نبودِ ستون.
	 *
	 * @param array $row   ردیف.
	 * @param int   $index ایندکس ستون.
	 * @return string
	 */
	private static function cell( array $row, $index ) {
		return isset( $row[ $index ] ) ? $row[ $index ] : '';
	}

	/**
	 * بررسی خالی‌بودن کامل یک ردیف.
	 *
	 * @param array $row ردیف.
	 * @return bool
	 */
	private static function is_blank_row( array $row ) {
		foreach ( $row as $cell ) {
			if ( '' !== trim( (string) $cell ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * ساخت یک رکورد نتیجه.
	 *
	 * @param int    $row_num شمارهٔ ردیف.
	 * @param int    $id      شناسهٔ محصول.
	 * @param string $status  وضعیت.
	 * @param string $message پیام.
	 * @param array  $fields  فیلدهای تغییریافته.
	 * @return array
	 */
	private static function result( $row_num, $id, $status, $message, $fields = array() ) {
		$labels = array();
		if ( ! empty( $fields ) ) {
			$by_key = array();
			foreach ( WooSheet_Export::columns() as $col ) {
				$by_key[ $col['key'] ] = $col['label'];
			}
			foreach ( $fields as $f ) {
				$labels[] = isset( $by_key[ $f ] ) ? $by_key[ $f ] : $f;
			}
		}

		return array(
			'row'     => $row_num,
			'id'      => $id,
			'status'  => $status,
			'message' => $message,
			'fields'  => $labels,
		);
	}
}
