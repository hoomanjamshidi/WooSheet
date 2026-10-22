<?php
/**
 * کنترلر ساخت کوپن برای REST.
 *
 * @package WooSheet
 */

defined( 'ABSPATH' ) || exit;

/**
 * کلاس کنترلر کوپن.
 */
class WooSheet_Coupons_Controller {

	/**
	 * انواع تخفیف مجاز.
	 *
	 * @return string[]
	 */
	private function allowed_types() {
		return array( 'percent', 'fixed_cart', 'fixed_product' );
	}

	/**
	 * ساخت کوپن.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_coupon( WP_REST_Request $request ) {
		$code          = trim( (string) $request->get_param( 'code' ) );
		$discount_type = (string) $request->get_param( 'discount_type' );
		$amount_raw    = (string) $request->get_param( 'amount' );
		$restrict      = (bool) $request->get_param( 'restrict_to_product' );
		$product_id    = (int) $request->get_param( 'product_id' );
		$usage_limit   = (int) $request->get_param( 'usage_limit' );
		$expires_jal   = (string) $request->get_param( 'date_expires' );
		$description   = sanitize_text_field( (string) $request->get_param( 'description' ) );

		if ( ! in_array( $discount_type, $this->allowed_types(), true ) ) {
			return new WP_Error( 'woosheet_bad_type', __( 'نوع تخفیف نامعتبر است.', 'woosheet' ), array( 'status' => 400 ) );
		}

		// درصد نباید با تعداد اعشارِ «قیمت» گرد شود؛ در فروشگاه تومانی که
		// اعشار قیمت صفر است، ۱۲٫۵ درصد به ۱۳ درصد تبدیل می‌شد.
		if ( 'percent' === $discount_type ) {
			$amount = WooSheet_Jalali::normalize_digits( $amount_raw );
			$amount = str_replace( array( ',', '،', ' ', "\xc2\xa0", '٬' ), '', trim( $amount ) );
			$amount = is_numeric( $amount ) ? wc_format_decimal( $amount, 2 ) : false;
		} else {
			$amount = WooSheet_Product_Mapper::parse_price( $amount_raw );
		}

		if ( false === $amount || '' === $amount || (float) $amount <= 0 ) {
			return new WP_Error( 'woosheet_bad_amount', __( 'مقدار تخفیف باید عددی بزرگ‌تر از صفر باشد.', 'woosheet' ), array( 'status' => 400 ) );
		}
		if ( 'percent' === $discount_type && (float) $amount > 100 ) {
			return new WP_Error( 'woosheet_bad_percent', __( 'درصد تخفیف نمی‌تواند بیشتر از ۱۰۰ باشد.', 'woosheet' ), array( 'status' => 400 ) );
		}

		// محدودیت به محصول.
		if ( $restrict ) {
			$product = $product_id ? wc_get_product( $product_id ) : false;
			if ( ! $product ) {
				return new WP_Error( 'woosheet_bad_product', __( 'برای کوپن مخصوص محصول، محصول معتبر لازم است.', 'woosheet' ), array( 'status' => 400 ) );
			}
		}

		// کد: تولید خودکار در صورت خالی‌بودن.
		if ( '' === $code ) {
			$code = $this->generate_code( $restrict ? $product_id : 0 );
		}
		$code = wc_format_coupon_code( $code );

		if ( wc_get_coupon_id_by_code( $code ) ) {
			return new WP_Error( 'woosheet_dup_code', __( 'این کد تخفیف از قبل وجود دارد. کد دیگری انتخاب کنید.', 'woosheet' ), array( 'status' => 400 ) );
		}

		// تاریخ انقضا (شمسی → میلادی).
		$expires_ts = null;
		if ( '' !== trim( $expires_jal ) ) {
			$g = WooSheet_Jalali::to_gregorian_string( $expires_jal );
			if ( null === $g ) {
				return new WP_Error( 'woosheet_bad_expiry', __( 'تاریخ انقضا نامعتبر است.', 'woosheet' ), array( 'status' => 400 ) );
			}
			$expires_ts = strtotime( $g . ' 23:59:59' );
		}

		try {
			$coupon = new WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_discount_type( $discount_type );
			$coupon->set_amount( (float) $amount );
			if ( '' !== $description ) {
				$coupon->set_description( $description );
			}
			if ( $usage_limit > 0 ) {
				$coupon->set_usage_limit( $usage_limit );
			}
			if ( null !== $expires_ts ) {
				$coupon->set_date_expires( $expires_ts );
			}
			if ( $restrict && $product_id ) {
				$coupon->set_product_ids( array( $product_id ) );
			}
			$coupon_id = $coupon->save();
		} catch ( Exception $e ) {
			return new WP_Error( 'woosheet_coupon_failed', __( 'ساخت کوپن ناموفق بود: ', 'woosheet' ) . $e->getMessage(), array( 'status' => 400 ) );
		}

		if ( ! $coupon_id ) {
			return new WP_Error( 'woosheet_coupon_failed', __( 'ساخت کوپن ناموفق بود.', 'woosheet' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response(
			array(
				'success'   => true,
				'coupon_id' => $coupon_id,
				'code'      => $code,
				'edit_link' => get_edit_post_link( $coupon_id, 'raw' ),
			),
			201
		);
	}

	/**
	 * تولید کد تخفیف خودکار.
	 *
	 * @param int $product_id شناسهٔ محصول (اختیاری).
	 * @return string
	 */
	private function generate_code( $product_id ) {
		$prefix = 'OFF';
		if ( $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$sku = $product->get_sku();
				if ( $sku ) {
					$prefix = preg_replace( '/[^A-Za-z0-9]/', '', $sku );
				} else {
					$prefix = 'P' . $product_id;
				}
			}
		}
		return strtoupper( substr( $prefix, 0, 12 ) ) . '-' . strtoupper( wp_generate_password( 5, false ) );
	}
}
