<?php
/**
 * قالب صفحهٔ ادمین WooSheet.
 *
 * @package WooSheet
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap woosheet-wrap" dir="rtl">
	<div id="woosheet-app">
		<header class="woosheet-toolbar">
			<h1 class="woosheet-title"><?php esc_html_e( 'شیت محصولات WooSheet', 'woosheet' ); ?></h1>

			<div class="woosheet-tools">
				<input type="search" id="woosheet-search" class="woosheet-input" placeholder="<?php esc_attr_e( 'جست‌وجوی نام، SKU یا شناسه…', 'woosheet' ); ?>" />

				<select id="woosheet-filter-cat" class="woosheet-select"></select>
				<select id="woosheet-filter-status" class="woosheet-select"></select>

				<button type="button" class="button" id="woosheet-reload"><?php esc_html_e( 'بارگذاری مجدد', 'woosheet' ); ?></button>
				<a class="button" id="woosheet-export" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=woosheet_export' ), 'woosheet_export' ) ); ?>"><?php esc_html_e( 'خروجی اکسل', 'woosheet' ); ?></a>
				<button type="button" class="button button-primary" id="woosheet-import-open"><?php esc_html_e( 'ورود از اکسل', 'woosheet' ); ?></button>
			</div>
		</header>

		<div id="woosheet-status-bar" class="woosheet-status-bar" aria-live="polite"></div>

		<div id="woosheet-grid" class="woosheet-grid"></div>

		<footer class="woosheet-footer">
			<div id="woosheet-pagination" class="woosheet-pagination"></div>
		</footer>
	</div>

	<!-- ظرف مودال‌ها (توسط JS پر می‌شود) -->
	<div id="woosheet-modal-root"></div>

	<!-- توست‌ها -->
	<div id="woosheet-toasts" class="woosheet-toasts" aria-live="assertive"></div>
</div>
