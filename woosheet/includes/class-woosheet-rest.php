<?php
/**
 * ثبت روت‌های REST افزونه.
 *
 * @package WooSheet
 */

defined( 'ABSPATH' ) || exit;

/**
 * کلاس REST.
 */
class WooSheet_REST {

	const NAMESPACE = 'woosheet/v1';

	/**
	 * بیشترین حجم مجاز فایل ورودی (۱۰ مگابایت).
	 */
	const MAX_UPLOAD_BYTES = 10485760;

	/**
	 * کنترلر محصولات.
	 *
	 * @var WooSheet_Products_Controller
	 */
	private $products;

	/**
	 * کنترلر کوپن.
	 *
	 * @var WooSheet_Coupons_Controller
	 */
	private $coupons;

	/**
	 * مقداردهی اولیه.
	 *
	 * @return void
	 */
	public function init() {
		$this->products = new WooSheet_Products_Controller();
		$this->coupons  = new WooSheet_Coupons_Controller();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * بررسی دسترسی.
	 *
	 * @return bool
	 */
	public function permission_check() {
		return current_user_can( WooSheet::capability() );
	}

	/**
	 * ثبت روت‌ها.
	 *
	 * @return void
	 */
	public function register_routes() {
		$perm = array( $this, 'permission_check' );

		register_rest_route(
			self::NAMESPACE,
			'/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this->products, 'list_products' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)/variations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this->products, 'get_variations' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this->products, 'create_variation' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)/variations/(?P<variation_id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this->products, 'delete_variation' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)/downloads',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this->products, 'get_downloads' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)/type',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->products, 'change_type' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)/attributes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->products, 'set_attributes' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)/generate-variations',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->products, 'generate_variations' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/batch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->products, 'batch_update' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->products, 'update_product' ),
				'permission_callback' => $perm,
				'args'                => array(
					'id' => array(
						'validate_callback' => static function ( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/coupons',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this->coupons, 'create_coupon' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/validate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_validate' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/commit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_commit' ),
				'permission_callback' => $perm,
			)
		);
	}

	/**
	 * اعتبارسنجی فایل ایمپورت.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_validate( WP_REST_Request $request ) {
		$path = $this->get_uploaded_xlsx( $request );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$report = WooSheet_Import::validate( $path );
		wp_delete_file( $path );

		if ( is_wp_error( $report ) ) {
			return $report;
		}
		return new WP_REST_Response( $report, 200 );
	}

	/**
	 * اعمال نهایی فایل ایمپورت.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_commit( WP_REST_Request $request ) {
		$path = $this->get_uploaded_xlsx( $request );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$report = WooSheet_Import::commit( $path );
		wp_delete_file( $path );

		if ( is_wp_error( $report ) ) {
			return $report;
		}
		return new WP_REST_Response( $report, 200 );
	}

	/**
	 * دریافت و اعتبارسنجی فایل آپلودشده، انتقال به مسیر موقت امن.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return string|WP_Error مسیر فایل موقت یا خطا.
	 */
	private function get_uploaded_xlsx( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! isset( $files['file']['tmp_name'] ) ) {
			return new WP_Error( 'woosheet_no_file', __( 'فایلی دریافت نشد.', 'woosheet' ), array( 'status' => 400 ) );
		}

		$file = $files['file'];

		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'woosheet_upload_error', __( 'بارگذاری فایل با خطا مواجه شد.', 'woosheet' ), array( 'status' => 400 ) );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'woosheet_not_uploaded', __( 'فایل نامعتبر است.', 'woosheet' ), array( 'status' => 400 ) );
		}

		// بررسی پسوند/نوع.
		$check = wp_check_filetype( $file['name'], array( 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ) );
		if ( 'xlsx' !== $check['ext'] ) {
			return new WP_Error( 'woosheet_bad_ext', __( 'فقط فایل با فرمت xlsx پذیرفته می‌شود.', 'woosheet' ), array( 'status' => 400 ) );
		}

		// حجم فایل.
		if ( isset( $file['size'] ) && (int) $file['size'] > self::MAX_UPLOAD_BYTES ) {
			return new WP_Error( 'woosheet_too_big', __( 'حجم فایل بیش از حد مجاز (۱۰ مگابایت) است.', 'woosheet' ), array( 'status' => 400 ) );
		}

		// مسیر موقت سیستم، نه wp-content/uploads که از وب قابل دسترسی است.
		$dest = wp_tempnam( 'woosheet-import.xlsx' );
		if ( ! $dest ) {
			return new WP_Error( 'woosheet_move_failed', __( 'ساخت فایل موقت ناموفق بود.', 'woosheet' ), array( 'status' => 500 ) );
		}

		if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_delete_file( $dest );
			return new WP_Error( 'woosheet_move_failed', __( 'انتقال فایل ناموفق بود.', 'woosheet' ), array( 'status' => 500 ) );
		}

		return $dest;
	}
}
