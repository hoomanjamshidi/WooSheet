<?php
/**
 * تبدیل تاریخ شمسی (جلالی) و میلادی — کاملاً محلی، بدون سرویس خارجی.
 *
 * الگوریتم تبدیل بر پایهٔ روش استاندارد jdf/Roozbeh است.
 *
 * @package WooSheet
 */

defined( 'ABSPATH' ) || exit;

/**
 * کلاس تبدیل تاریخ.
 */
class WooSheet_Jalali {

	/**
	 * تبدیل میلادی به شمسی.
	 *
	 * @param int $gy سال میلادی.
	 * @param int $gm ماه میلادی.
	 * @param int $gd روز میلادی.
	 * @return array{0:int,1:int,2:int} [سال, ماه, روز] شمسی.
	 */
	public static function gregorian_to_jalali( $gy, $gm, $gd ) {
		$gy = (int) $gy;
		$gm = (int) $gm;
		$gd = (int) $gd;

		$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );

		$gy2 = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
		$days = 355666 + ( 365 * $gy ) + ( (int) ( ( $gy2 + 3 ) / 4 ) ) - ( (int) ( ( $gy2 + 99 ) / 100 ) )
			+ ( (int) ( ( $gy2 + 399 ) / 400 ) ) + $gd + $g_d_m[ $gm - 1 ];

		$jy = -1595 + ( 33 * ( (int) ( $days / 12053 ) ) );
		$days %= 12053;

		$jy += 4 * ( (int) ( $days / 1461 ) );
		$days %= 1461;

		if ( $days > 365 ) {
			$jy += (int) ( ( $days - 1 ) / 365 );
			$days = ( $days - 1 ) % 365;
		}

		if ( $days < 186 ) {
			$jm = 1 + (int) ( $days / 31 );
			$jd = 1 + ( $days % 31 );
		} else {
			$jm = 7 + (int) ( ( $days - 186 ) / 30 );
			$jd = 1 + ( ( $days - 186 ) % 30 );
		}

		return array( $jy, $jm, $jd );
	}

	/**
	 * تبدیل شمسی به میلادی.
	 *
	 * @param int $jy سال شمسی.
	 * @param int $jm ماه شمسی.
	 * @param int $jd روز شمسی.
	 * @return array{0:int,1:int,2:int} [سال, ماه, روز] میلادی.
	 */
	public static function jalali_to_gregorian( $jy, $jm, $jd ) {
		$jy = (int) $jy;
		$jm = (int) $jm;
		$jd = (int) $jd;

		$jy += 1595;
		$days = -355668 + ( 365 * $jy ) + ( ( (int) ( $jy / 33 ) ) * 8 ) + ( (int) ( ( ( $jy % 33 ) + 3 ) / 4 ) )
			+ $jd + ( ( $jm < 7 ) ? ( ( $jm - 1 ) * 31 ) : ( ( ( $jm - 7 ) * 30 ) + 186 ) );

		$gy = 400 * ( (int) ( $days / 146097 ) );
		$days %= 146097;

		if ( $days > 36524 ) {
			$gy += 100 * ( (int) ( --$days / 36524 ) );
			$days %= 36524;
			if ( $days >= 365 ) {
				++$days;
			}
		}

		$gy += 4 * ( (int) ( $days / 1461 ) );
		$days %= 1461;

		if ( $days > 365 ) {
			$gy += (int) ( ( $days - 1 ) / 365 );
			$days = ( $days - 1 ) % 365;
		}

		$gd = $days + 1;

		$sal_a = array( 0, 31, ( ( ( $gy % 4 === 0 && $gy % 100 !== 0 ) || ( $gy % 400 === 0 ) ) ? 29 : 28 ), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );

		$gm = 0;
		while ( $gm < 13 && $gd > $sal_a[ $gm ] ) {
			$gd -= $sal_a[ $gm ];
			++$gm;
		}

		return array( $gy, $gm, $gd );
	}

	/**
	 * تعداد روزهای یک ماه شمسی (با تشخیص کبیسه برای اسفند).
	 *
	 * ماه‌های ۱..۶ سی‌ویک روز، ۷..۱۱ سی روز و اسفند ۲۹ یا ۳۰ روز است.
	 * کبیسه‌بودن با رفت‌وبرگشت تبدیل تشخیص داده می‌شود (همان روش تقویم سمت کلاینت).
	 *
	 * @param int $jy سال شمسی.
	 * @param int $jm ماه شمسی.
	 * @return int تعداد روزها؛ ۰ اگر ماه نامعتبر باشد.
	 */
	public static function days_in_month( $jy, $jm ) {
		$jy = (int) $jy;
		$jm = (int) $jm;

		if ( $jm < 1 || $jm > 12 ) {
			return 0;
		}
		if ( $jm <= 6 ) {
			return 31;
		}
		if ( $jm <= 11 ) {
			return 30;
		}

		list( $gy, $gm, $gd ) = self::jalali_to_gregorian( $jy, 12, 30 );
		list( $by, $bm, $bd ) = self::gregorian_to_jalali( $gy, $gm, $gd );

		return ( $by === $jy && 12 === $bm && 30 === $bd ) ? 30 : 29;
	}

	/**
	 * تبدیل رشتهٔ تاریخ‌وزمان میلادی (Y-m-d یا Y-m-d H:i:s) به رشتهٔ شمسی.
	 *
	 * @param string $datetime رشتهٔ تاریخ میلادی.
	 * @param bool   $with_time افزودن بخش ساعت.
	 * @return string رشتهٔ شمسی به‌صورت Y/m/d یا Y/m/d H:i:s. در صورت خالی‌بودن، رشتهٔ خالی.
	 */
	public static function to_jalali_string( $datetime, $with_time = false ) {
		$datetime = is_string( $datetime ) ? trim( $datetime ) : '';
		if ( '' === $datetime ) {
			return '';
		}

		// اجزای تاریخ مستقیماً از خود رشته خوانده می‌شود.
		// (پیش‌تر رشته با strtotime به timestamp و بعد با gmdate به UTC برمی‌گشت؛
		// روی سرورهای با اختلاف زمانی مثبت — مثل Asia/Tehran — تاریخ یک روز عقب می‌افتاد.)
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{2}:\d{2}:\d{2}))?/', $datetime, $m ) ) {
			$gy   = (int) $m[1];
			$gm   = (int) $m[2];
			$gd   = (int) $m[3];
			$time = isset( $m[4] ) ? $m[4] : '00:00:00';
		} else {
			$ts = strtotime( $datetime );
			if ( false === $ts ) {
				return '';
			}
			// همان منطقهٔ زمانی محلی سایت، نه UTC.
			$gy   = (int) date( 'Y', $ts ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			$gm   = (int) date( 'n', $ts ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			$gd   = (int) date( 'j', $ts ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			$time = date( 'H:i:s', $ts );   // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		}

		list( $jy, $jm, $jd ) = self::gregorian_to_jalali( $gy, $gm, $gd );

		$out = sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );

		if ( $with_time ) {
			$out .= ' ' . $time;
		}

		return $out;
	}

	/**
	 * تبدیل رشتهٔ شمسی (Y/m/d یا Y-m-d) به رشتهٔ میلادی Y-m-d.
	 *
	 * @param string $jalali رشتهٔ شمسی.
	 * @return string|null رشتهٔ میلادی Y-m-d یا null در صورت نامعتبربودن.
	 */
	public static function to_gregorian_string( $jalali ) {
		$jalali = is_string( $jalali ) ? trim( $jalali ) : '';
		if ( '' === $jalali ) {
			return null;
		}

		// تبدیل ارقام فارسی/عربی به انگلیسی.
		$jalali = self::normalize_digits( $jalali );

		// جداکننده‌های مجاز: / یا - یا .
		$parts = preg_split( '#[/\-.]#', $jalali );
		if ( ! is_array( $parts ) || count( $parts ) < 3 ) {
			return null;
		}

		$jy = (int) $parts[0];
		$jm = (int) $parts[1];
		$jd = (int) $parts[2];

		if ( $jy < 1000 || $jy > 2000 || $jm < 1 || $jm > 12 || $jd < 1 ) {
			return null;
		}

		// روز باید در همان ماه واقعاً وجود داشته باشد؛ وگرنه تبدیل بی‌صدا
		// به ماه/سال بعد سُر می‌خورد (مثلاً ۱۴۰۳/۱۲/۳۱ → ۱۴۰۴/۰۱/۰۱).
		if ( $jd > self::days_in_month( $jy, $jm ) ) {
			return null;
		}

		list( $gy, $gm, $gd ) = self::jalali_to_gregorian( $jy, $jm, $jd );

		return sprintf( '%04d-%02d-%02d', $gy, $gm, $gd );
	}

	/**
	 * تبدیل ارقام فارسی و عربی به ارقام انگلیسی.
	 *
	 * @param string $str ورودی.
	 * @return string خروجی با ارقام انگلیسی.
	 */
	public static function normalize_digits( $str ) {
		$fa = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		$ar = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$en = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		$str = str_replace( $fa, $en, $str );
		$str = str_replace( $ar, $en, $str );
		return $str;
	}
}
