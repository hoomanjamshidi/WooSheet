<?php
/**
 * نگاشت محصول ووکامرس به ردیف شیت/اکسل و اعمال سخت‌گیرانهٔ تغییرات.
 *
 * نکتهٔ کلیدی: همهٔ نوشتن‌ها از طریق WooCommerce CRUD انجام می‌شود
 * (set_* + save) و هرگز مستقیم در postmeta نوشته نمی‌شود تا کش‌ها،
 * جدول‌های lookup و قیمت محصولات متغیر درست به‌روز شوند.
 *
 * @package WooSheet
 */

defined( 'ABSPATH' ) || exit;

/**
 * کلاس نگاشت و اعتبارسنجی محصول.
 */
class WooSheet_Product_Mapper {

	/**
	 * وضعیت‌های انتشار مجاز.
	 *
	 * @return string[]
	 */
	public static function allowed_statuses() {
		return array( 'publish', 'draft', 'pending', 'private' );
	}

	/**
	 * وضعیت‌های موجودی مجاز.
	 *
	 * @return string[]
	 */
	public static function allowed_stock_statuses() {
		return array( 'instock', 'outofstock', 'onbackorder' );
	}

	/**
	 * فیلدهای قابل ویرایش از طریق شیت.
	 *
	 * @return string[]
	 */
	public static function editable_fields() {
		return array(
			'name',
			'sku',
			'regular_price',
			'sale_price',
			'sale_from',
			'sale_to',
			'stock_quantity',
			'stock_status',
			'manage_stock',
			'status',
			'image_id',
		);
	}

	/**
	 * کش خلاصهٔ فایل‌های دانلودِ متغیرها، به‌ازای شناسهٔ محصول والد.
	 *
	 * ساختار هر عضو: array( 'downloadable' => bool, 'count' => int ).
	 *
	 * @var array<int,array>
	 */
	private static $variation_download_cache = array();

	/**
	 * پیش‌بارگذاری وضعیت «دانلودی‌بودن» متغیرهای چند محصول متغیر با یک کوئری.
	 *
	 * بدون این کار، ساخت هر ردیفِ محصول متغیر یک کوئری جداگانه می‌زند.
	 *
	 * @param int[] $parent_ids شناسهٔ محصولات والد.
	 * @return void
	 */
	public static function prime_variation_downloads( array $parent_ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $parent_ids ) ) ) );
		$ids = array_values( array_diff( $ids, array_keys( self::$variation_download_cache ) ) );

		if ( empty( $ids ) ) {
			return;
		}

		// مقدار پیش‌فرض برای همهٔ والدها تا در نبودِ متغیر دوباره کوئری نخورد.
		foreach ( $ids as $pid ) {
			self::$variation_download_cache[ $pid ] = array(
				'downloadable' => false,
				'count'        => 0,
			);
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders فقط از %d ساخته شده.
		$sql = $wpdb->prepare(
			"SELECT v.post_parent AS parent_id, dl.meta_value AS is_downloadable, files.meta_value AS files
			 FROM {$wpdb->posts} v
			 INNER JOIN {$wpdb->postmeta} dl ON ( v.ID = dl.post_id AND dl.meta_key = '_downloadable' )
			 LEFT JOIN {$wpdb->postmeta} files ON ( v.ID = files.post_id AND files.meta_key = '_downloadable_files' )
			 WHERE v.post_type = 'product_variation'
			   AND v.post_parent IN ($placeholders)",
			$ids
		);
		// phpcs:enable

		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( (array) $rows as $r ) {
			if ( 'yes' !== $r->is_downloadable ) {
				continue;
			}

			// «دانلودی» بودن جدا از «فایل داشتن» است؛ دقیقاً مثل محصول ساده.
			$pid = (int) $r->parent_id;
			self::$variation_download_cache[ $pid ]['downloadable'] = true;

			$files = maybe_unserialize( $r->files );
			if ( is_array( $files ) ) {
				self::$variation_download_cache[ $pid ]['count'] += count( $files );
			}
		}
	}

	/**
	 * خلاصهٔ فایل‌های دانلودِ متغیرهای یک محصول متغیر.
	 *
	 * @param int $parent_id شناسهٔ محصول والد.
	 * @return array array( 'downloadable' => bool, 'count' => int )
	 */
	private static function variable_download_summary( $parent_id ) {
		$parent_id = (int) $parent_id;

		if ( ! isset( self::$variation_download_cache[ $parent_id ] ) ) {
			self::prime_variation_downloads( array( $parent_id ) );
		}

		return self::$variation_download_cache[ $parent_id ];
	}

	/**
	 * تبدیل یک محصول ساده/متغیر (والد) به ردیف شیت.
	 *
	 * @param WC_Product $product محصول.
	 * @return array
	 */
	public static function product_to_row( $product ) {
		$is_variable = $product->is_type( 'variable' );

		$row = array(
			'id'             => $product->get_id(),
			'parent_id'      => 0,
			'type'           => $product->get_type(),
			'is_variable'    => $is_variable,
			'editable_price' => ! $is_variable,
			'name'           => $product->get_name(),
			'sku'            => $product->get_sku(),
			'status'         => $product->get_status(),
			'manage_stock'   => (bool) $product->get_manage_stock(),
			'stock_quantity' => self::nullable_number( $product->get_stock_quantity() ),
			'stock_status'   => $product->get_stock_status(),
			'image_id'       => $product->get_image_id() ? (int) $product->get_image_id() : 0,
			'image'          => self::thumb_url( $product->get_image_id() ),
			'permalink'      => get_permalink( $product->get_id() ),
			'edit_link'      => get_edit_post_link( $product->get_id(), 'raw' ),
			'categories'     => self::get_categories_string( $product->get_id() ),
		);

		if ( $is_variable ) {
			$prices                = $product->get_variation_prices( true );
			$reg                   = is_array( $prices['regular_price'] ?? null ) ? $prices['regular_price'] : array();
			$active                = is_array( $prices['price'] ?? null ) ? $prices['price'] : array();
			$row['regular_price']  = '';
			$row['sale_price']     = '';
			$row['sale_from']      = '';
			$row['sale_to']        = '';
			$row['price_min']      = ! empty( $reg ) ? (float) min( $reg ) : '';
			$row['price_max']      = ! empty( $reg ) ? (float) max( $reg ) : '';
			$row['active_min']     = ! empty( $active ) ? (float) min( $active ) : '';
			$row['active_max']     = ! empty( $active ) ? (float) max( $active ) : '';
			$row['variation_count'] = count( $product->get_children() );

			$dl                     = self::variable_download_summary( $product->get_id() );
			$row['downloadable']    = (bool) $dl['downloadable'];
			$row['download_count']  = (int) $dl['count'];
		} else {
			$row['regular_price'] = self::nullable_number( $product->get_regular_price() );
			$row['sale_price']    = self::nullable_number( $product->get_sale_price() );
			$row['sale_from']     = self::date_to_jalali( $product->get_date_on_sale_from() );
			$row['sale_to']       = self::date_to_jalali( $product->get_date_on_sale_to() );
			$row['price_min']     = '';
			$row['price_max']     = '';
			$row['variation_count'] = 0;

			$row['downloadable']   = (bool) $product->is_downloadable();
			$row['download_count'] = count( (array) $product->get_downloads() );
		}

		return $row;
	}

	/**
	 * گرفتن ردیف فعلی متناسب با نوع محصول (ساده/متغیر/متغیر-فرزند).
	 *
	 * @param WC_Product $product محصول یا متغیر.
	 * @return array
	 */
	public static function current_row( $product ) {
		if ( $product instanceof WC_Product_Variation ) {
			return self::variation_to_row( $product );
		}
		return self::product_to_row( $product );
	}

	/**
	 * تبدیل یک متغیر (variation) به ردیف.
	 *
	 * @param WC_Product_Variation $variation متغیر.
	 * @return array
	 */
	public static function variation_to_row( $variation ) {
		return array(
			'id'             => $variation->get_id(),
			'parent_id'      => $variation->get_parent_id(),
			'type'           => 'variation',
			'is_variable'    => false,
			'editable_price' => true,
			'name'           => wc_get_formatted_variation( $variation, true, false, false ),
			'sku'            => $variation->get_sku(),
			'status'         => $variation->get_status(),
			'manage_stock'   => (bool) $variation->get_manage_stock(),
			'stock_quantity' => self::nullable_number( $variation->get_stock_quantity() ),
			'stock_status'   => $variation->get_stock_status(),
			'regular_price'  => self::nullable_number( $variation->get_regular_price() ),
			'sale_price'     => self::nullable_number( $variation->get_sale_price() ),
			'sale_from'      => self::date_to_jalali( $variation->get_date_on_sale_from() ),
			'sale_to'        => self::date_to_jalali( $variation->get_date_on_sale_to() ),
			'image_id'       => $variation->get_image_id() ? (int) $variation->get_image_id() : 0,
			'image'          => self::thumb_url( $variation->get_image_id() ),
			'permalink'      => get_permalink( $variation->get_parent_id() ),
			'downloadable'   => (bool) $variation->is_downloadable(),
			'download_count' => count( (array) $variation->get_downloads() ),
		);
	}

	/**
	 * اعمال سخت‌گیرانهٔ تغییرات روی یک محصول/متغیر و ذخیره.
	 *
	 * @param WC_Product $product محصول یا متغیر.
	 * @param array      $data    داده‌های ورودی (فیلدهای قابل ویرایش).
	 * @param bool       $dry_run اگر true باشد فقط اعتبارسنجی می‌شود و چیزی ذخیره نمی‌گردد.
	 * @return true|WP_Error true در موفقیت یا WP_Error در صورت خطای اعتبارسنجی.
	 */
	public static function apply_updates( $product, array $data, $dry_run = false ) {
		$is_variable_parent = $product->is_type( 'variable' );

		// ---- مقادیر مؤثر پس از ادغام ورودی با مقدار فعلی ----
		// قیمت اصلی.
		$has_regular = array_key_exists( 'regular_price', $data );
		$has_sale    = array_key_exists( 'sale_price', $data );

		if ( ( $has_regular || $has_sale ) && $is_variable_parent ) {
			return new WP_Error(
				'woosheet_variable_price',
				__( 'قیمت محصول متغیر باید از طریق ویرایش متغیرها تغییر کند، نه روی خود محصول.', 'woosheet' ),
				array( 'status' => 400 )
			);
		}

		// قیمت اصلی مؤثر.
		$effective_regular = $product->get_regular_price();
		if ( $has_regular ) {
			$parsed = self::parse_price( $data['regular_price'] );
			if ( false === $parsed ) {
				return new WP_Error( 'woosheet_invalid_regular', __( 'قیمت اصلی نامعتبر است.', 'woosheet' ), array( 'status' => 400 ) );
			}
			$effective_regular = $parsed; // '' یا رشتهٔ عددی.
		}

		// قیمت تخفیفی مؤثر.
		$effective_sale = $product->get_sale_price();
		if ( $has_sale ) {
			$parsed = self::parse_price( $data['sale_price'] );
			if ( false === $parsed ) {
				return new WP_Error( 'woosheet_invalid_sale', __( 'قیمت تخفیفی نامعتبر است.', 'woosheet' ), array( 'status' => 400 ) );
			}
			$effective_sale = $parsed;
		}

		// قانون سخت‌گیرانه: اگر قیمت تخفیفی وجود دارد باید کوچک‌تر از قیمت اصلی باشد.
		if ( '' !== $effective_sale ) {
			if ( '' === $effective_regular ) {
				return new WP_Error(
					'woosheet_sale_without_regular',
					__( 'برای ثبت قیمت تخفیفی، قیمت اصلی نباید خالی باشد.', 'woosheet' ),
					array( 'status' => 400 )
				);
			}
			if ( (float) $effective_sale >= (float) $effective_regular ) {
				return new WP_Error(
					'woosheet_sale_gte_regular',
					__( 'قیمت تخفیفی باید کوچک‌تر از قیمت اصلی باشد.', 'woosheet' ),
					array( 'status' => 400 )
				);
			}
			if ( (float) $effective_sale < 0 ) {
				return new WP_Error( 'woosheet_sale_negative', __( 'قیمت تخفیفی نمی‌تواند منفی باشد.', 'woosheet' ), array( 'status' => 400 ) );
			}
		}

		// تاریخ‌های تخفیف (شمسی → میلادی).
		$from_g = null; // null یعنی دست‌نخورده.
		$to_g   = null;
		if ( array_key_exists( 'sale_from', $data ) ) {
			$from_g = self::parse_jalali_date( $data['sale_from'] );
			if ( false === $from_g ) {
				return new WP_Error( 'woosheet_invalid_from', __( 'تاریخ شروع تخفیف نامعتبر است.', 'woosheet' ), array( 'status' => 400 ) );
			}
		}
		if ( array_key_exists( 'sale_to', $data ) ) {
			$to_g = self::parse_jalali_date( $data['sale_to'] );
			if ( false === $to_g ) {
				return new WP_Error( 'woosheet_invalid_to', __( 'تاریخ پایان تخفیف نامعتبر است.', 'woosheet' ), array( 'status' => 400 ) );
			}
		}

		// بررسی from <= to (با درنظرگرفتن مقادیر فعلی برای فیلدهای دست‌نخورده).
		$cmp_from = ( null !== $from_g ) ? $from_g : self::date_to_ymd( $product->get_date_on_sale_from() );
		$cmp_to   = ( null !== $to_g ) ? $to_g : self::date_to_ymd( $product->get_date_on_sale_to() );
		if ( '' !== (string) $cmp_from && '' !== (string) $cmp_to && strtotime( $cmp_from ) > strtotime( $cmp_to ) ) {
			return new WP_Error( 'woosheet_date_order', __( 'تاریخ شروع تخفیف نباید بعد از تاریخ پایان باشد.', 'woosheet' ), array( 'status' => 400 ) );
		}

		// ---- اعمال مقادیر (همه معتبر) ----
		if ( $has_regular ) {
			$product->set_regular_price( $effective_regular );
		}
		if ( $has_sale ) {
			$product->set_sale_price( $effective_sale );
			if ( '' === $effective_sale ) {
				// پاک‌کردن کامل تخفیف.
				$product->set_date_on_sale_from( '' );
				$product->set_date_on_sale_to( '' );
			}
		}
		if ( null !== $from_g ) {
			$product->set_date_on_sale_from( '' === $from_g ? '' : $from_g );
		}
		if ( null !== $to_g ) {
			// ووکامرس تاریخِ بدون ساعت را ۰۰:۰۰:۰۰ همان روز می‌گیرد، یعنی تخفیف
			// در ابتدای روزِ پایان تمام می‌شود و کل آن روز بی‌تخفیف می‌ماند.
			// هستهٔ ووکامرس هم در متاباکس محصول ' 23:59:59' اضافه می‌کند.
			$product->set_date_on_sale_to( '' === $to_g ? '' : $to_g . ' 23:59:59' );
		}

		if ( array_key_exists( 'name', $data ) ) {
			$name = sanitize_text_field( (string) $data['name'] );
			if ( '' === trim( $name ) ) {
				return new WP_Error( 'woosheet_empty_name', __( 'نام محصول نمی‌تواند خالی باشد.', 'woosheet' ), array( 'status' => 400 ) );
			}
			$product->set_name( $name );
		}

		if ( array_key_exists( 'sku', $data ) ) {
			try {
				$product->set_sku( sanitize_text_field( (string) $data['sku'] ) );
			} catch ( WC_Data_Exception $e ) {
				return new WP_Error( 'woosheet_invalid_sku', __( 'شناسهٔ انبار (SKU) تکراری یا نامعتبر است.', 'woosheet' ), array( 'status' => 400 ) );
			}
		}

		// نکته: «تعداد موجودی» و «مدیریت موجودی» روی هم اثر می‌گذارند، پس اول
		// تعداد اعمال می‌شود و در پایان، اگر کاربر صراحتاً manage_stock فرستاده
		// باشد، همان حرفِ آخر را می‌زند (وگرنه مقدارِ صریحِ کاربر بی‌صدا بازنویسی می‌شد).
		if ( array_key_exists( 'stock_quantity', $data ) ) {
			$qty_raw = WooSheet_Jalali::normalize_digits( (string) $data['stock_quantity'] );
			$qty_raw = trim( $qty_raw );
			if ( '' === $qty_raw ) {
				$product->set_manage_stock( false );
				$product->set_stock_quantity( null );
			} else {
				if ( ! is_numeric( $qty_raw ) ) {
					return new WP_Error( 'woosheet_invalid_qty', __( 'تعداد موجودی نامعتبر است.', 'woosheet' ), array( 'status' => 400 ) );
				}
				$product->set_manage_stock( true );
				$product->set_stock_quantity( wc_stock_amount( $qty_raw ) );
			}
		}

		if ( array_key_exists( 'manage_stock', $data ) ) {
			$manage = (bool) $data['manage_stock'];
			$product->set_manage_stock( $manage );
			if ( ! $manage ) {
				// بدون مدیریت موجودی، نگه‌داشتن تعداد باعث ناسازگاری ردیف با ووکامرس می‌شود.
				$product->set_stock_quantity( null );
			}
		}

		if ( array_key_exists( 'stock_status', $data ) ) {
			$ss = sanitize_text_field( (string) $data['stock_status'] );
			if ( ! in_array( $ss, self::allowed_stock_statuses(), true ) ) {
				return new WP_Error( 'woosheet_invalid_stock_status', __( 'وضعیت موجودی نامعتبر است.', 'woosheet' ), array( 'status' => 400 ) );
			}
			$product->set_stock_status( $ss );
		}

		if ( array_key_exists( 'status', $data ) ) {
			$st = sanitize_text_field( (string) $data['status'] );
			if ( ! in_array( $st, self::allowed_statuses(), true ) ) {
				return new WP_Error( 'woosheet_invalid_status', __( 'وضعیت انتشار نامعتبر است.', 'woosheet' ), array( 'status' => 400 ) );
			}
			$product->set_status( $st );
		}

		if ( array_key_exists( 'image_id', $data ) ) {
			$img = (int) $data['image_id'];
			if ( $img > 0 && 'attachment' !== get_post_type( $img ) ) {
				return new WP_Error( 'woosheet_invalid_image', __( 'تصویر انتخاب‌شده نامعتبر است.', 'woosheet' ), array( 'status' => 400 ) );
			}
			$product->set_image_id( $img > 0 ? $img : '' );
		}

		if ( $dry_run ) {
			return true;
		}

		$product->save();

		// همگام‌سازی کش قیمت محصول متغیر (بازهٔ قیمت والد) پس از تغییر.
		if ( $is_variable_parent ) {
			WC_Product_Variable::sync( $product->get_id() );
		} elseif ( $product instanceof WC_Product_Variation ) {
			// تغییر قیمت/موجودی یک متغیر باید بازهٔ قیمت والد را به‌روز کند.
			WC_Product_Variable::sync( $product->get_parent_id() );
		}

		return true;
	}

	/**
	 * پارس و نرمال‌سازی قیمت ورودی.
	 *
	 * @param mixed $raw ورودی خام.
	 * @return string|false رشتهٔ خالی برای پاک‌کردن، رشتهٔ عددی نرمال‌شده، یا false در صورت نامعتبربودن.
	 */
	public static function parse_price( $raw ) {
		if ( null === $raw ) {
			return '';
		}
		$val = WooSheet_Jalali::normalize_digits( (string) $raw );
		// حذف جداکنندهٔ هزارگان و فاصله.
		$val = str_replace( array( ',', '،', ' ', "\xc2\xa0", '٬' ), '', $val );
		$val = trim( $val );

		if ( '' === $val ) {
			return '';
		}
		if ( ! is_numeric( $val ) ) {
			return false;
		}
		if ( (float) $val < 0 ) {
			return false;
		}
		// نرمال‌سازی بر اساس تعداد اعشار ووکامرس (برای تومان معمولاً ۰).
		return wc_format_decimal( $val, wc_get_price_decimals() );
	}

	/**
	 * پارس تاریخ شمسی ورودی و تبدیل به میلادی Y-m-d.
	 *
	 * @param mixed $raw ورودی خام.
	 * @return string|false رشتهٔ خالی برای پاک‌کردن، Y-m-d میلادی، یا false در صورت نامعتبربودن.
	 */
	public static function parse_jalali_date( $raw ) {
		$val = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $val ) {
			return '';
		}
		$g = WooSheet_Jalali::to_gregorian_string( $val );
		return ( null === $g ) ? false : $g;
	}

	/**
	 * تبدیل WC_DateTime به رشتهٔ شمسی برای نمایش.
	 *
	 * @param WC_DateTime|null $dt تاریخ.
	 * @return string
	 */
	private static function date_to_jalali( $dt ) {
		if ( ! $dt instanceof WC_DateTime ) {
			return '';
		}
		return WooSheet_Jalali::to_jalali_string( $dt->date( 'Y-m-d' ) );
	}

	/**
	 * تبدیل WC_DateTime به رشتهٔ میلادی Y-m-d.
	 *
	 * @param WC_DateTime|null $dt تاریخ.
	 * @return string
	 */
	private static function date_to_ymd( $dt ) {
		if ( ! $dt instanceof WC_DateTime ) {
			return '';
		}
		return $dt->date( 'Y-m-d' );
	}

	/**
	 * عدد یا رشتهٔ خالی برای مقادیر nullable.
	 *
	 * @param mixed $value مقدار.
	 * @return string
	 */
	private static function nullable_number( $value ) {
		if ( null === $value || '' === $value ) {
			return '';
		}
		return (string) $value;
	}

	/**
	 * آدرس تصویر بندانگشتی برای یک شناسهٔ ضمیمه.
	 *
	 * @param int $image_id شناسهٔ تصویر.
	 * @return string
	 */
	private static function thumb_url( $image_id ) {
		if ( $image_id ) {
			$url = wp_get_attachment_image_url( $image_id, 'thumbnail' );
			if ( $url ) {
				return $url;
			}
		}
		return wc_placeholder_img_src( 'thumbnail' );
	}

	/**
	 * گرفتن نام دسته‌های محصول به‌صورت رشتهٔ جداشده با کاما.
	 *
	 * @param int $product_id شناسهٔ محصول.
	 * @return string
	 */
	private static function get_categories_string( $product_id ) {
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}
		$names = wp_list_pluck( $terms, 'name' );
		return implode( ', ', $names );
	}
}
