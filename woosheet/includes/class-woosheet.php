<?php
/**
 * لودر اصلی افزونه (سینگلتون).
 *
 * @package WooSheet
 */

defined( 'ABSPATH' ) || exit;

/**
 * کلاس اصلی WooSheet.
 */
final class WooSheet {

	/**
	 * نمونهٔ یکتا.
	 *
	 * @var WooSheet|null
	 */
	private static $instance = null;

	/**
	 * شیٔ مدیریت ادمین.
	 *
	 * @var WooSheet_Admin
	 */
	public $admin;

	/**
	 * شیٔ مدیریت REST.
	 *
	 * @var WooSheet_REST
	 */
	public $rest;

	/**
	 * گرفتن نمونهٔ یکتا.
	 *
	 * @return WooSheet
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * سازنده.
	 */
	private function __construct() {
		$this->admin = new WooSheet_Admin();
		$this->rest  = new WooSheet_REST();

		$this->admin->init();
		$this->rest->init();
	}

	/**
	 * نام قابلیت لازم برای دسترسی به افزونه.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * فیلتر قابلیت دسترسی به WooSheet.
		 *
		 * @param string $capability قابلیت پیش‌فرض.
		 */
		return apply_filters( 'woosheet_capability', 'manage_woocommerce' );
	}
}
