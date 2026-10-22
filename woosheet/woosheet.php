<?php
/**
 * Plugin Name:       WooSheet — مدیریت اکسلی محصولات
 * Plugin URI:        https://hoomanjamshidi.ir
 * Description:        مدیریت محصولات ووکامرس در یک رابط شیت‌مانند شبیه اکسل/گوگل‌شیت؛ ویرایش قیمت و موجودی به‌صورت درجا، مدیریت تخفیف و کوپن، خروجی و ورودی اکسل (xlsx). تمام منابع محلی، فونت وزیر، تقویم شمسی.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            هومن جمشیدی
 * Text Domain:       woosheet
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   9.9
 *
 * @package WooSheet
 */

defined( 'ABSPATH' ) || exit;

define( 'WOOSHEET_VERSION', '1.0.0' );
define( 'WOOSHEET_FILE', __FILE__ );
define( 'WOOSHEET_PATH', plugin_dir_path( __FILE__ ) );
define( 'WOOSHEET_URL', plugin_dir_url( __FILE__ ) );
define( 'WOOSHEET_BASENAME', plugin_basename( __FILE__ ) );

/**
 * اعلام سازگاری با HPOS (ذخیره‌سازی سفارش‌های سفارشی ووکامرس).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WOOSHEET_FILE, true );
		}
	}
);

/**
 * بارگذاری افزونه پس از اطمینان از فعال‌بودن ووکامرس.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'افزونهٔ WooSheet برای کار کردن به ووکامرس فعال نیاز دارد.', 'woosheet' );
					echo '</p></div>';
				}
			);
			return;
		}

		// بارگذاری ترجمه‌ها (هدر افزونه Domain Path: /languages را اعلام می‌کند).
		load_plugin_textdomain( 'woosheet', false, dirname( WOOSHEET_BASENAME ) . '/languages' );

		require_once WOOSHEET_PATH . 'includes/class-woosheet-jalali.php';
		require_once WOOSHEET_PATH . 'includes/class-woosheet-xlsx.php';
		require_once WOOSHEET_PATH . 'includes/class-woosheet-product-mapper.php';
		require_once WOOSHEET_PATH . 'includes/class-woosheet-export.php';
		require_once WOOSHEET_PATH . 'includes/class-woosheet-import.php';
		require_once WOOSHEET_PATH . 'includes/class-woosheet-products-controller.php';
		require_once WOOSHEET_PATH . 'includes/class-woosheet-coupons-controller.php';
		require_once WOOSHEET_PATH . 'includes/class-woosheet-rest.php';
		require_once WOOSHEET_PATH . 'includes/class-woosheet-admin.php';
		require_once WOOSHEET_PATH . 'includes/class-woosheet.php';

		WooSheet::instance();
	}
);
