<?php
/**
 * موتور خواندن/نوشتن فایل xlsx — کاملاً محلی و بدون هیچ وابستگی خارجی.
 *
 * فقط از ZipArchive و DOMDocument داخلی PHP استفاده می‌کند.
 * نوشتن با inline string + عدد خام؛ خواندن با پشتیبانی از sharedStrings،
 * inlineStr، formula string و عدد.
 *
 * @package WooSheet
 */

defined( 'ABSPATH' ) || exit;

/**
 * کلاس xlsx.
 */
class WooSheet_XLSX {

	/**
	 * بیشترین ایندکس ستون مجاز (ستون XFD در استاندارد xlsx).
	 */
	const MAX_COLUMN = 16383;

	/**
	 * بیشترین تعداد ردیفی که از یک فایل ورودی خوانده می‌شود.
	 */
	const MAX_ROWS = 20000;

	/**
	 * نوشتن یک شیت در فایل xlsx.
	 *
	 * @param string  $path  مسیر فایل خروجی.
	 * @param array[] $rows  آرایه‌ای از ردیف‌ها؛ هر سلول int/float (عدد) یا string (متن) یا null (خالی).
	 * @param string  $sheet نام شیت.
	 * @return true|WP_Error
	 */
	public static function write( $path, array $rows, $sheet = 'Products' ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'woosheet_no_zip', __( 'افزونهٔ ZipArchive در PHP فعال نیست.', 'woosheet' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'woosheet_zip_open', __( 'ایجاد فایل اکسل ناموفق بود.', 'woosheet' ) );
		}

		$zip->addFromString( '[Content_Types].xml', self::content_types_xml() );
		$zip->addFromString( '_rels/.rels', self::root_rels_xml() );
		$zip->addFromString( 'xl/workbook.xml', self::workbook_xml( $sheet ) );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', self::workbook_rels_xml() );
		$zip->addFromString( 'xl/styles.xml', self::styles_xml() );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', self::sheet_xml( $rows ) );

		$zip->close();

		return true;
	}

	/**
	 * خواندن اولین شیت یک فایل xlsx.
	 *
	 * @param string $path مسیر فایل ورودی.
	 * @return array[]|WP_Error آرایه‌ای از ردیف‌ها (هر ردیف آرایه‌ای از رشته‌ها) یا خطا.
	 */
	public static function read( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'woosheet_no_zip', __( 'افزونهٔ ZipArchive در PHP فعال نیست.', 'woosheet' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'woosheet_bad_xlsx', __( 'فایل اکسل قابل باز شدن نیست یا خراب است.', 'woosheet' ) );
		}

		// شناسهٔ شیت‌ها از sharedStrings.
		$shared       = array();
		$shared_xml   = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $shared_xml && '' !== $shared_xml ) {
			$shared = self::parse_shared_strings( $shared_xml );
		}

		// یافتن اولین فایل worksheet.
		$sheet_name = self::find_first_worksheet( $zip );
		if ( null === $sheet_name ) {
			$zip->close();
			return new WP_Error( 'woosheet_no_sheet', __( 'هیچ شیتی در فایل اکسل پیدا نشد.', 'woosheet' ) );
		}

		$sheet_xml = $zip->getFromName( $sheet_name );
		$zip->close();

		if ( false === $sheet_xml ) {
			return new WP_Error( 'woosheet_no_sheet_data', __( 'خواندن داده‌های شیت ناموفق بود.', 'woosheet' ) );
		}

		return self::parse_sheet( $sheet_xml, $shared );
	}

	/**
	 * یافتن نام اولین فایل worksheet داخل آرشیو.
	 *
	 * @param ZipArchive $zip آرشیو.
	 * @return string|null
	 */
	private static function find_first_worksheet( $zip ) {
		$candidates = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( is_string( $name ) && preg_match( '#^xl/worksheets/sheet\d+\.xml$#', $name ) ) {
				$candidates[] = $name;
			}
		}
		if ( empty( $candidates ) ) {
			return null;
		}
		sort( $candidates, SORT_NATURAL );
		return $candidates[0];
	}

	/**
	 * پارس sharedStrings.
	 *
	 * @param string $xml محتوای sharedStrings.xml.
	 * @return string[]
	 */
	private static function parse_shared_strings( $xml ) {
		$out = array();
		$dom = self::load_dom( $xml );
		if ( null === $dom ) {
			return $out;
		}
		$si_nodes = $dom->getElementsByTagNameNS( '*', 'si' );
		foreach ( $si_nodes as $si ) {
			$text  = '';
			$t_nodes = $si->getElementsByTagNameNS( '*', 't' );
			foreach ( $t_nodes as $t ) {
				$text .= $t->textContent;
			}
			$out[] = $text;
		}
		return $out;
	}

	/**
	 * پارس داده‌های شیت.
	 *
	 * @param string   $xml    محتوای sheet xml.
	 * @param string[] $shared sharedStrings.
	 * @return array[]
	 */
	private static function parse_sheet( $xml, array $shared ) {
		$rows = array();
		$dom  = self::load_dom( $xml );
		if ( null === $dom ) {
			return $rows;
		}

		$row_nodes = $dom->getElementsByTagNameNS( '*', 'row' );
		$fallback  = 0;

		foreach ( $row_nodes as $row ) {
			if ( count( $rows ) >= self::MAX_ROWS ) {
				break;
			}
			++$fallback;

			// شمارهٔ واقعی ردیف در اکسل؛ اگر ردیف‌های خالی حذف شده باشند
			// (کاری که خود اکسل می‌کند) شماره‌ها پیوسته نیستند و نباید با
			// ایندکس آرایه یکی گرفته شوند، وگرنه گزارش ایمپورت ردیف اشتباه می‌دهد.
			$row_num = (int) $row->getAttribute( 'r' );
			if ( $row_num < 1 || isset( $rows[ $row_num ] ) ) {
				$row_num = $fallback;
			}

			$cells   = array();
			$max_col = -1;
			$c_nodes = $row->getElementsByTagNameNS( '*', 'c' );
			foreach ( $c_nodes as $c ) {
				$ref = $c->getAttribute( 'r' );
				$col = self::col_index_from_ref( $ref );
				if ( $col < 0 ) {
					continue;
				}
				$type  = $c->getAttribute( 't' );
				$value = self::cell_value( $c, $type, $shared );

				$cells[ $col ] = $value;
				if ( $col > $max_col ) {
					$max_col = $col;
				}
			}

			// نرمال‌سازی به آرایهٔ پیوسته 0..max.
			$normalized = array();
			for ( $i = 0; $i <= $max_col; $i++ ) {
				$normalized[ $i ] = isset( $cells[ $i ] ) ? $cells[ $i ] : '';
			}
			$rows[ $row_num ] = $normalized;
		}

		return $rows;
	}

	/**
	 * استخراج مقدار یک سلول.
	 *
	 * @param DOMElement $c      گرهٔ سلول.
	 * @param string     $type   نوع سلول (t).
	 * @param string[]   $shared sharedStrings.
	 * @return string
	 */
	private static function cell_value( $c, $type, array $shared ) {
		if ( 'inlineStr' === $type ) {
			$text    = '';
			$t_nodes = $c->getElementsByTagNameNS( '*', 't' );
			foreach ( $t_nodes as $t ) {
				$text .= $t->textContent;
			}
			return $text;
		}

		$v_nodes = $c->getElementsByTagNameNS( '*', 'v' );
		if ( 0 === $v_nodes->length ) {
			return '';
		}
		$raw = $v_nodes->item( 0 )->textContent;

		if ( 's' === $type ) {
			$idx = (int) $raw;
			return isset( $shared[ $idx ] ) ? $shared[ $idx ] : '';
		}

		// str (نتیجهٔ فرمول)، b (بولین)، یا عدد.
		return (string) $raw;
	}

	/**
	 * تبدیل مرجع سلول (مثلاً "AB12") به شمارهٔ ستون صفر-مبنا.
	 *
	 * @param string $ref مرجع سلول.
	 * @return int شمارهٔ ستون یا -1.
	 */
	private static function col_index_from_ref( $ref ) {
		// بیشترین ستون مجاز در xlsx ستون XFD (ایندکس 16383) است.
		// مرجع دست‌کاری‌شده مثل "ZZZZZZ1" ایندکسی نجومی می‌سازد و پرکردن
		// آرایهٔ پیوسته تا آن ایندکس، حافظهٔ PHP را تمام می‌کند (کرش هنگام ایمپورت).
		if ( ! preg_match( '/^([A-Za-z]{1,3})\d/', $ref, $m ) ) {
			return -1;
		}
		$letters = strtoupper( $m[1] );
		$col     = 0;
		$len     = strlen( $letters );
		for ( $i = 0; $i < $len; $i++ ) {
			$col = $col * 26 + ( ord( $letters[ $i ] ) - 64 );
		}
		$col -= 1;

		return ( $col > self::MAX_COLUMN ) ? -1 : $col;
	}

	/**
	 * تبدیل شمارهٔ ستون صفر-مبنا به حروف (0 → A).
	 *
	 * @param int $index شمارهٔ ستون.
	 * @return string
	 */
	private static function col_letter( $index ) {
		$letter = '';
		$index += 1;
		while ( $index > 0 ) {
			$mod    = ( $index - 1 ) % 26;
			$letter = chr( 65 + $mod ) . $letter;
			$index  = (int) ( ( $index - $mod - 1 ) / 26 );
		}
		return $letter;
	}

	/**
	 * بارگذاری XML در DOMDocument با مدیریت خطا.
	 *
	 * @param string $xml محتوای XML.
	 * @return DOMDocument|null
	 */
	private static function load_dom( $xml ) {
		$dom = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $dom->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $ok ? $dom : null;
	}

	/**
	 * تولید XML شیت.
	 *
	 * @param array[] $rows ردیف‌ها.
	 * @return string
	 */
	private static function sheet_xml( array $rows ) {
		$out  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$out .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
		$out .= '<sheetData>';

		$r = 1;
		foreach ( $rows as $row ) {
			$out .= '<row r="' . $r . '">';
			$c = 0;
			foreach ( $row as $cell ) {
				$ref = self::col_letter( $c ) . $r;
				$out .= self::cell_xml( $ref, $cell );
				$c++;
			}
			$out .= '</row>';
			$r++;
		}

		$out .= '</sheetData></worksheet>';
		return $out;
	}

	/**
	 * تولید XML یک سلول.
	 *
	 * @param string $ref   مرجع سلول.
	 * @param mixed  $value مقدار.
	 * @return string
	 */
	private static function cell_xml( $ref, $value ) {
		if ( null === $value || '' === $value ) {
			return '<c r="' . $ref . '"/>';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return '<c r="' . $ref . '"><v>' . self::num( $value ) . '</v></c>';
		}
		$escaped = self::xml_escape( (string) $value );
		return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
	}

	/**
	 * نمایش عدد بدون نماد علمی.
	 *
	 * @param int|float $value عدد.
	 * @return string
	 */
	private static function num( $value ) {
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		return rtrim( rtrim( sprintf( '%.10F', $value ), '0' ), '.' );
	}

	/**
	 * فرار دادن کاراکترهای XML.
	 *
	 * @param string $str ورودی.
	 * @return string
	 */
	private static function xml_escape( $str ) {
		// حذف کاراکترهای کنترلی غیرمجاز در XML 1.0.
		$str = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $str );
		return htmlspecialchars( $str, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/* ---------- قالب‌های ثابت بسته ---------- */

	/**
	 * @return string
	 */
	private static function content_types_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '</Types>';
	}

	/**
	 * @return string
	 */
	private static function root_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	/**
	 * @param string $sheet نام شیت.
	 * @return string
	 */
	private static function workbook_xml( $sheet ) {
		$name = self::xml_escape( $sheet );
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';
	}

	/**
	 * @return string
	 */
	private static function workbook_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';
	}

	/**
	 * @return string
	 */
	private static function styles_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
			. '<borders count="1"><border/></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
			. '</styleSheet>';
	}
}
