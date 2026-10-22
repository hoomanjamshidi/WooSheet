<?php
/**
 * مدیریت بخش ادمین: منو، صفحه، بارگذاری اسکریپت‌ها و خروجی اکسل.
 *
 * @package WooSheet
 */

defined( 'ABSPATH' ) || exit;

/**
 * کلاس ادمین.
 */
class WooSheet_Admin {

	const PAGE_SLUG = 'woosheet';

	/**
	 * شناسهٔ صفحهٔ منو (برای hook بارگذاری).
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * مقداردهی اولیه.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_woosheet_export', array( $this, 'handle_export' ) );
		add_filter( 'plugin_action_links_' . WOOSHEET_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * ثبت منوی ادمین.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = add_menu_page(
			__( 'WooSheet — شیت محصولات', 'woosheet' ),
			__( 'WooSheet', 'woosheet' ),
			WooSheet::capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-grid-view',
			56
		);
	}

	/**
	 * افزودن لینک میان‌بر در فهرست افزونه‌ها.
	 *
	 * @param array $links لینک‌ها.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$url  = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'باز کردن شیت', 'woosheet' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * رندر صفحهٔ افزونه.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( WooSheet::capability() ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'woosheet' ) );
		}
		require WOOSHEET_PATH . 'templates/admin-page.php';
	}

	/**
	 * بارگذاری اسکریپت‌ها و استایل‌ها فقط در صفحهٔ افزونه.
	 *
	 * @param string $hook hook صفحهٔ جاری.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		// کتابخانهٔ رسانهٔ وردپرس (برای انتخاب تصویر شاخص).
		wp_enqueue_media();

		// استایل‌ها — نسخه بر اساس زمان تغییر فایل تا کش مرورگر هنگام بروزرسانی پاک شود.
		wp_enqueue_style( 'woosheet-vazir', WOOSHEET_URL . 'assets/css/vazir.css', array(), $this->asset_ver( 'assets/css/vazir.css' ) );
		wp_enqueue_style( 'woosheet-tabulator', WOOSHEET_URL . 'assets/css/tabulator.min.css', array(), $this->asset_ver( 'assets/css/tabulator.min.css' ) );
		wp_enqueue_style( 'woosheet', WOOSHEET_URL . 'assets/css/woosheet.css', array( 'woosheet-vazir', 'woosheet-tabulator' ), $this->asset_ver( 'assets/css/woosheet.css' ) );

		// اسکریپت‌ها.
		wp_enqueue_script( 'woosheet-tabulator', WOOSHEET_URL . 'assets/js/tabulator.min.js', array(), $this->asset_ver( 'assets/js/tabulator.min.js' ), true );
		wp_enqueue_script( 'woosheet-jalali', WOOSHEET_URL . 'assets/js/jalali-datepicker.js', array(), $this->asset_ver( 'assets/js/jalali-datepicker.js' ), true );
		wp_enqueue_script( 'woosheet', WOOSHEET_URL . 'assets/js/woosheet.js', array( 'woosheet-tabulator', 'woosheet-jalali' ), $this->asset_ver( 'assets/js/woosheet.js' ), true );

		wp_localize_script( 'woosheet', 'WooSheetData', $this->get_localized_data() );
	}

	/**
	 * نسخهٔ یک فایل دارایی بر اساس زمان تغییر (برای پاک‌کردن کش مرورگر).
	 *
	 * @param string $rel مسیر نسبی فایل از ریشهٔ افزونه.
	 * @return string
	 */
	private function asset_ver( $rel ) {
		$path  = WOOSHEET_PATH . $rel;
		$mtime = file_exists( $path ) ? filemtime( $path ) : false;
		return $mtime ? (string) $mtime : WOOSHEET_VERSION;
	}

	/**
	 * دادهٔ منتقل‌شده به جاوااسکریپت.
	 *
	 * @return array
	 */
	private function get_localized_data() {
		// زمان محلی سایت، نه UTC: با gmdate، در تهران بعد از ۲۰:۳۰ «امروز» دیروز می‌شد.
		$today = WooSheet_Jalali::gregorian_to_jalali(
			(int) current_time( 'Y' ),
			(int) current_time( 'n' ),
			(int) current_time( 'j' )
		);

		return array(
			'version'   => $this->asset_ver( 'assets/js/woosheet.js' ),
			'restUrl'   => esc_url_raw( rest_url( WooSheet_REST::NAMESPACE . '/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'exportUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=woosheet_export' ), 'woosheet_export' ),
			'perPage'   => 50,
			'currency'  => array(
				'symbol'    => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'position'  => get_option( 'woocommerce_currency_pos', 'right_space' ),
				'decimals'  => wc_get_price_decimals(),
				'thousand'  => wc_get_price_thousand_separator(),
				'decimal'   => wc_get_price_decimal_separator(),
			),
			'today'     => array(
				'y' => $today[0],
				'm' => $today[1],
				'd' => $today[2],
			),
			'categories' => $this->get_categories(),
			'options'   => array(
				'stock_status' => array(
					'instock'     => __( 'موجود', 'woosheet' ),
					'outofstock'  => __( 'ناموجود', 'woosheet' ),
					'onbackorder' => __( 'پیش‌خرید', 'woosheet' ),
				),
				'status'       => array(
					'publish' => __( 'منتشرشده', 'woosheet' ),
					'draft'   => __( 'پیش‌نویس', 'woosheet' ),
					'pending' => __( 'در انتظار', 'woosheet' ),
					'private' => __( 'خصوصی', 'woosheet' ),
				),
			),
			'i18n'      => $this->get_i18n(),
		);
	}

	/**
	 * فهرست دسته‌های محصول برای فیلتر.
	 *
	 * @return array
	 */
	private function get_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		$out = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$out[] = array(
					'id'   => (int) $t->term_id,
					'name' => $t->name,
				);
			}
		}
		return $out;
	}

	/**
	 * رشته‌های ترجمه برای رابط کاربری.
	 *
	 * @return array
	 */
	private function get_i18n() {
		return array(
			'title'           => __( 'شیت محصولات WooSheet', 'woosheet' ),
			'search'          => __( 'جست‌وجوی نام، SKU یا شناسه…', 'woosheet' ),
			'export'          => __( 'خروجی اکسل', 'woosheet' ),
			'import'          => __( 'ورود از اکسل', 'woosheet' ),
			'reload'          => __( 'بارگذاری مجدد', 'woosheet' ),
			'undo'            => __( 'بازگردانی تغییر قبلی', 'woosheet' ),
			'reverted'        => __( 'به مقدار قبلی بازگردانده شد.', 'woosheet' ),
			'nothingUndo'     => __( 'تغییری برای بازگرداندن وجود ندارد.', 'woosheet' ),
			'columns'         => __( 'ستون‌ها', 'woosheet' ),
			'setImage'        => __( 'انتخاب تصویر شاخص', 'woosheet' ),
			'choose'          => __( 'انتخاب', 'woosheet' ),
			'removeImage'     => __( 'حذف تصویر', 'woosheet' ),
			'mediaUnavailable' => __( 'کتابخانهٔ رسانه در دسترس نیست.', 'woosheet' ),
			'allCategories'   => __( 'همهٔ دسته‌ها', 'woosheet' ),
			'allStatuses'     => __( 'همهٔ وضعیت‌ها', 'woosheet' ),
			'colId'           => __( 'شناسه', 'woosheet' ),
			'colImage'        => __( 'تصویر', 'woosheet' ),
			'colName'         => __( 'نام محصول', 'woosheet' ),
			'colSku'          => __( 'SKU', 'woosheet' ),
			'colType'         => __( 'نوع', 'woosheet' ),
			'colStock'        => __( 'موجودی', 'woosheet' ),
			'colStockStatus'  => __( 'وضعیت موجودی', 'woosheet' ),
			'colRegular'      => __( 'قیمت اصلی', 'woosheet' ),
			'colSale'         => __( 'قیمت تخفیفی', 'woosheet' ),
			'colSaleFrom'     => __( 'شروع تخفیف', 'woosheet' ),
			'colSaleTo'       => __( 'پایان تخفیف', 'woosheet' ),
			'colStatus'       => __( 'وضعیت انتشار', 'woosheet' ),
			'colDownloadable' => __( 'دانلودی', 'woosheet' ),
			'colDownloads'    => __( 'لینک‌های دانلود', 'woosheet' ),
			'isDownloadable'  => __( 'دانلودی', 'woosheet' ),
			'notDownloadable' => __( 'فیزیکی', 'woosheet' ),
			'viewDownloads'   => __( 'مشاهدهٔ لینک‌ها', 'woosheet' ),
			'downloadsTitle'  => __( 'لینک‌های دانلود', 'woosheet' ),
			'noDownloads'     => __( 'برای این محصول هیچ فایل دانلودی تنظیم نشده است.', 'woosheet' ),
			'downloadsCount'  => __( 'تعداد فایل:', 'woosheet' ),
			'downloadName'    => __( 'نام فایل', 'woosheet' ),
			'downloadLimit'   => __( 'محدودیت دانلود', 'woosheet' ),
			'downloadExpiry'  => __( 'انقضا (روز)', 'woosheet' ),
			'unlimited'       => __( 'نامحدود', 'woosheet' ),
			'noExpiry'        => __( 'بدون انقضا', 'woosheet' ),
			'copy'            => __( 'کپی', 'woosheet' ),
			'copied'          => __( 'کپی شد', 'woosheet' ),
			'copyFailed'      => __( 'کپی خودکار ممکن نشد؛ لینک را دستی انتخاب و کپی کنید.', 'woosheet' ),
			'copyAll'         => __( 'کپی همهٔ لینک‌ها', 'woosheet' ),
			'untitledFile'    => __( 'بدون نام', 'woosheet' ),
			'colCoupon'       => __( 'کد تخفیف', 'woosheet' ),
			'colView'         => __( 'نمایش', 'woosheet' ),
			'typeSimple'      => __( 'ساده', 'woosheet' ),
			'typeVariable'    => __( 'متغیر', 'woosheet' ),
			'editVariations'  => __( 'ویرایش متغیرها', 'woosheet' ),
			'view'            => __( 'نمایش', 'woosheet' ),
			'makeCoupon'      => __( 'ساخت کوپن', 'woosheet' ),
			'saved'           => __( 'ذخیره شد', 'woosheet' ),
			'saveError'       => __( 'خطا در ذخیره', 'woosheet' ),
			'saving'          => __( 'در حال ذخیره…', 'woosheet' ),
			'loading'         => __( 'در حال بارگذاری…', 'woosheet' ),
			'noData'          => __( 'محصولی یافت نشد.', 'woosheet' ),
			'close'           => __( 'بستن', 'woosheet' ),
			'cancel'          => __( 'انصراف', 'woosheet' ),
			'save'            => __( 'ذخیره', 'woosheet' ),
			'variationsOf'    => __( 'متغیرهای محصول', 'woosheet' ),
			'attributes'      => __( 'ویژگی‌ها', 'woosheet' ),
			'attrName'        => __( 'نام ویژگی', 'woosheet' ),
			'attrValues'      => __( 'مقادیر (با کاما جدا کنید)', 'woosheet' ),
			'attrForVariation' => __( 'برای ساخت متغیر', 'woosheet' ),
			'attrAdd'         => __( 'افزودن ویژگی', 'woosheet' ),
			'attrSave'        => __( 'ذخیرهٔ ویژگی‌ها', 'woosheet' ),
			'attrSaved'       => __( 'ویژگی‌ها ذخیره شد.', 'woosheet' ),
			'attrTaxonomy'    => __( '(ویژگی سراسری — فقط‌خواندنی)', 'woosheet' ),
			'genVariations'   => __( 'ساخت متغیرها از ویژگی‌ها', 'woosheet' ),
			'genDone'         => __( 'متغیر جدید ساخته شد:', 'woosheet' ),
			'noVariations'    => __( 'هنوز متغیری ندارد. ابتدا یک ویژگی «برای ساخت متغیر» تعریف و ذخیره کنید، سپس «ساخت متغیرها» را بزنید.', 'woosheet' ),
			'deleteVar'       => __( 'حذف', 'woosheet' ),
			'confirmDeleteVar' => __( 'این متغیر حذف شود؟', 'woosheet' ),
			'remove'          => __( 'حذف', 'woosheet' ),
			'typeChanged'     => __( 'نوع محصول تغییر کرد.', 'woosheet' ),
			'needVarAttr'     => __( 'حداقل یک ویژگی با گزینهٔ «برای ساخت متغیر» لازم است.', 'woosheet' ),
			'couponTitle'     => __( 'ساخت کد تخفیف', 'woosheet' ),
			'couponCode'      => __( 'کد تخفیف (خالی = تولید خودکار)', 'woosheet' ),
			'couponType'      => __( 'نوع تخفیف', 'woosheet' ),
			'couponPercent'   => __( 'درصدی', 'woosheet' ),
			'couponFixedCart' => __( 'مبلغ ثابت (سبد)', 'woosheet' ),
			'couponFixedProd' => __( 'مبلغ ثابت (محصول)', 'woosheet' ),
			'couponAmount'    => __( 'مقدار', 'woosheet' ),
			'couponExpiry'    => __( 'تاریخ انقضا (شمسی)', 'woosheet' ),
			'couponUsage'     => __( 'محدودیت تعداد استفاده', 'woosheet' ),
			'couponRestrict'  => __( 'محدود به این محصول', 'woosheet' ),
			'couponCreate'    => __( 'ساخت کوپن', 'woosheet' ),
			'couponCreated'   => __( 'کوپن ساخته شد:', 'woosheet' ),
			'couponView'      => __( 'مشاهده/ویرایش کوپن', 'woosheet' ),
			'importTitle'     => __( 'ورود از فایل اکسل', 'woosheet' ),
			'importPick'      => __( 'انتخاب فایل xlsx', 'woosheet' ),
			'importValidate'  => __( 'بررسی فایل', 'woosheet' ),
			'importApply'     => __( 'اعمال تغییرات', 'woosheet' ),
			'importHint'      => __( 'فقط محصولاتِ موجود بر اساس «شناسه» بروزرسانی می‌شوند. سلول خالی یعنی بدون تغییر و هیچ محصول جدیدی ساخته نمی‌شود.', 'woosheet' ),
			'importSummary'   => __( 'خلاصه', 'woosheet' ),
			'sumTotal'        => __( 'کل ردیف‌ها', 'woosheet' ),
			'sumUpdated'      => __( 'قابل/اعمال‌شده', 'woosheet' ),
			'sumNoChange'     => __( 'بدون تغییر', 'woosheet' ),
			'sumSkipped'      => __( 'نادیده‌گرفته‌شده', 'woosheet' ),
			'sumErrors'       => __( 'خطا', 'woosheet' ),
			'row'             => __( 'ردیف', 'woosheet' ),
			'status'          => __( 'وضعیت', 'woosheet' ),
			'message'         => __( 'پیام', 'woosheet' ),
			'page'            => __( 'صفحه', 'woosheet' ),
			'of'              => __( 'از', 'woosheet' ),
			'prev'            => __( 'قبلی', 'woosheet' ),
			'next'            => __( 'بعدی', 'woosheet' ),
			'priceRangeHint'  => __( 'قیمت متغیر — برای ویرایش کلیک کنید', 'woosheet' ),
			'confirmApply'    => __( 'آیا از اعمال تغییرات روی محصولات مطمئن هستید؟', 'woosheet' ),
			'requiredAmount'  => __( 'مقدار تخفیف را وارد کنید.', 'woosheet' ),
			'totalItems'      => __( 'تعداد کل:', 'woosheet' ),
			'addDate'         => __( '+ تاریخ', 'woosheet' ),
			'sortNewest'      => __( 'جدیدترین', 'woosheet' ),
			'loadError'       => __( 'خطا در بارگذاری فهرست محصولات.', 'woosheet' ),
			'gridFailed'      => __( 'جدول بارگذاری نشد؛ صفحه را دوباره باز کنید.', 'woosheet' ),
			'calToday'        => __( 'امروز', 'woosheet' ),
			'calClear'        => __( 'پاک‌کردن', 'woosheet' ),
		);
	}

	/**
	 * پردازش خروجی اکسل (admin-post).
	 *
	 * @return void
	 */
	public function handle_export() {
		if ( ! current_user_can( WooSheet::capability() ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'woosheet' ) );
		}
		check_admin_referer( 'woosheet_export' );

		$result = WooSheet_Export::stream_download();
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		exit;
	}
}
