/**
 * تقویم و تبدیل تاریخ شمسی — کاملاً محلی و بدون وابستگی.
 *
 * window.WooSheetJalali در دسترس قرار می‌گیرد.
 */
( function () {
	'use strict';

	function div( gy, gm ) {
		return Math.floor( gy / gm );
	}

	/** میلادی → شمسی. */
	function gToJ( gy, gm, gd ) {
		var gDM = [ 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 ];
		var gy2 = gm > 2 ? gy + 1 : gy;
		var days = 355666 + 365 * gy + div( gy2 + 3, 4 ) - div( gy2 + 99, 100 ) + div( gy2 + 399, 400 ) + gd + gDM[ gm - 1 ];
		var jy = -1595 + 33 * div( days, 12053 );
		days %= 12053;
		jy += 4 * div( days, 1461 );
		days %= 1461;
		if ( days > 365 ) {
			jy += div( days - 1, 365 );
			days = ( days - 1 ) % 365;
		}
		var jm, jd;
		if ( days < 186 ) {
			jm = 1 + div( days, 31 );
			jd = 1 + ( days % 31 );
		} else {
			jm = 7 + div( days - 186, 30 );
			jd = 1 + ( ( days - 186 ) % 30 );
		}
		return [ jy, jm, jd ];
	}

	/** شمسی → میلادی. */
	function jToG( jy, jm, jd ) {
		jy += 1595;
		var days = -355668 + 365 * jy + div( jy, 33 ) * 8 + div( ( jy % 33 ) + 3, 4 ) + jd + ( jm < 7 ? ( jm - 1 ) * 31 : ( jm - 7 ) * 30 + 186 );
		var gy = 400 * div( days, 146097 );
		days %= 146097;
		if ( days > 36524 ) {
			gy += 100 * div( --days, 36524 );
			days %= 36524;
			if ( days >= 365 ) {
				days++;
			}
		}
		gy += 4 * div( days, 1461 );
		days %= 1461;
		if ( days > 365 ) {
			gy += div( days - 1, 365 );
			days = ( days - 1 ) % 365;
		}
		var gd = days + 1;
		var salA = [ 0, 31, ( ( gy % 4 === 0 && gy % 100 !== 0 ) || gy % 400 === 0 ) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];
		var gm = 0;
		while ( gm < 13 && gd > salA[ gm ] ) {
			gd -= salA[ gm ];
			gm++;
		}
		return [ gy, gm, gd ];
	}

	var MONTHS = [ 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' ];
	var WEEKDAYS = [ 'ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج' ]; // شنبه..جمعه

	/** تعداد روزهای یک ماه شمسی. */
	function daysInMonth( jy, jm ) {
		if ( jm <= 6 ) {
			return 31;
		}
		if ( jm <= 11 ) {
			return 30;
		}
		// اسفند: بررسی کبیسه با رفت‌وبرگشت تبدیل.
		var g = jToG( jy, 12, 30 );
		var back = gToJ( g[ 0 ], g[ 1 ], g[ 2 ] );
		return ( back[ 0 ] === jy && back[ 1 ] === 12 && back[ 2 ] === 30 ) ? 30 : 29;
	}

	/** روز هفتهٔ شمسی (0=شنبه..6=جمعه) برای اول ماه. */
	function weekdayOfJalali( jy, jm, jd ) {
		var g = jToG( jy, jm, jd );
		var d = new Date( g[ 0 ], g[ 1 ] - 1, g[ 2 ] );
		// getDay: 0=یکشنبه..6=شنبه → تبدیل به 0=شنبه.
		return ( d.getDay() + 1 ) % 7;
	}

	/** برچسب از WooSheetData.i18n با مقدار پیش‌فرض. */
	function label( key, fallback ) {
		var d = window.WooSheetData;
		return ( d && d.i18n && d.i18n[ key ] ) ? d.i18n[ key ] : fallback;
	}

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	/** تبدیل [jy,jm,jd] به رشتهٔ YYYY/MM/DD. */
	function format( j ) {
		return j[ 0 ] + '/' + pad( j[ 1 ] ) + '/' + pad( j[ 2 ] );
	}

	function normalizeDigits( str ) {
		var fa = '۰۱۲۳۴۵۶۷۸۹';
		var ar = '٠١٢٣٤٥٦٧٨٩';
		return String( str ).replace( /[۰-۹]/g, function ( c ) {
			return fa.indexOf( c );
		} ).replace( /[٠-٩]/g, function ( c ) {
			return ar.indexOf( c );
		} );
	}

	/** پارس رشتهٔ شمسی → [jy,jm,jd] یا null. */
	function parse( str ) {
		if ( ! str ) {
			return null;
		}
		var parts = normalizeDigits( str ).trim().split( /[\/\-.]/ );
		if ( parts.length < 3 ) {
			return null;
		}
		var jy = parseInt( parts[ 0 ], 10 );
		var jm = parseInt( parts[ 1 ], 10 );
		var jd = parseInt( parts[ 2 ], 10 );
		if ( ! jy || ! jm || ! jd || jm < 1 || jm > 12 || jd < 1 ) {
			return null;
		}
		// روز باید در همان ماه وجود داشته باشد؛ وگرنه تبدیل بی‌صدا به ماه بعد می‌رود.
		if ( jd > daysInMonth( jy, jm ) ) {
			return null;
		}
		return [ jy, jm, jd ];
	}

	function today() {
		var d = new Date();
		return gToJ( d.getFullYear(), d.getMonth() + 1, d.getDate() );
	}

	function el( tag, cls, text ) {
		var e = document.createElement( tag );
		if ( cls ) {
			e.className = cls;
		}
		if ( text != null ) {
			e.textContent = text;
		}
		return e;
	}

	/**
	 * ساخت یک تقویم شناور.
	 *
	 * opts: { value, anchorEl, onSelect(jStr), onClose() }
	 * خروجی: { destroy() }
	 */
	// فقط یک تقویم هم‌زمان باز می‌ماند؛ مرجعش نگه داشته می‌شود تا مودال‌ها
	// و کلید Escape بتوانند ببندندش (وگرنه بعد از بستن مودال، تقویم
	// یتیم روی صفحه باقی می‌ماند چون به body چسبیده نه به خود مودال).
	var openCalendar = null;

	function closeOpenCalendar() {
		if ( openCalendar ) {
			openCalendar.destroy();
			return true;
		}
		return false;
	}

	function createCalendar( opts ) {
		closeOpenCalendar();

		var sel = parse( opts.value );
		var view = sel ? { y: sel[ 0 ], m: sel[ 1 ] } : ( function () {
			var t = today();
			return { y: t[ 0 ], m: t[ 1 ] };
		} )();

		var wrap = el( 'div', 'woosheet-cal' );
		document.body.appendChild( wrap );

		function position() {
			var r = opts.anchorEl.getBoundingClientRect();
			wrap.style.position = 'absolute';
			wrap.style.zIndex = '999999';
			var top = window.scrollY + r.bottom + 2;
			wrap.style.top = top + 'px';
			var left = window.scrollX + r.left;
			wrap.style.left = left + 'px';
			// جلوگیری از بیرون‌زدگی افقی.
			var calW = 240;
			if ( left + calW > window.scrollX + window.innerWidth ) {
				wrap.style.left = ( window.scrollX + window.innerWidth - calW - 8 ) + 'px';
			}
		}

		function render() {
			wrap.innerHTML = '';

			var head = el( 'div', 'woosheet-cal-head' );
			var prev = el( 'button', 'woosheet-cal-nav', '‹' );
			var next = el( 'button', 'woosheet-cal-nav', '›' );
			prev.type = 'button';
			next.type = 'button';
			var title = el( 'span', 'woosheet-cal-title', MONTHS[ view.m - 1 ] + ' ' + view.y );
			// در RTL، prev (ماه قبل) سمت راست، next سمت چپ.
			next.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				ev.stopPropagation();
				view.m++;
				if ( view.m > 12 ) {
					view.m = 1;
					view.y++;
				}
				render();
			} );
			prev.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				ev.stopPropagation();
				view.m--;
				if ( view.m < 1 ) {
					view.m = 12;
					view.y--;
				}
				render();
			} );
			head.appendChild( next );
			head.appendChild( title );
			head.appendChild( prev );
			wrap.appendChild( head );

			var grid = el( 'div', 'woosheet-cal-grid' );
			WEEKDAYS.forEach( function ( w ) {
				grid.appendChild( el( 'span', 'woosheet-cal-wd', w ) );
			} );

			var firstWd = weekdayOfJalali( view.y, view.m, 1 );
			var dim = daysInMonth( view.y, view.m );
			var i;
			for ( i = 0; i < firstWd; i++ ) {
				grid.appendChild( el( 'span', 'woosheet-cal-empty' ) );
			}
			var t = today();
			for ( i = 1; i <= dim; i++ ) {
				( function ( day ) {
					var cell = el( 'button', 'woosheet-cal-day', String( day ) );
					cell.type = 'button';
					if ( sel && sel[ 0 ] === view.y && sel[ 1 ] === view.m && sel[ 2 ] === day ) {
						cell.classList.add( 'is-selected' );
					}
					if ( t[ 0 ] === view.y && t[ 1 ] === view.m && t[ 2 ] === day ) {
						cell.classList.add( 'is-today' );
					}
					cell.addEventListener( 'click', function ( ev ) {
						ev.preventDefault();
						ev.stopPropagation();
						opts.onSelect( format( [ view.y, view.m, day ] ) );
						destroy();
					} );
					grid.appendChild( cell );
				} )( i );
			}
			wrap.appendChild( grid );

			var foot = el( 'div', 'woosheet-cal-foot' );
			var todayBtn = el( 'button', 'woosheet-cal-foot-btn', label( 'calToday', 'امروز' ) );
			var clearBtn = el( 'button', 'woosheet-cal-foot-btn', label( 'calClear', 'پاک‌کردن' ) );
			todayBtn.type = 'button';
			clearBtn.type = 'button';
			todayBtn.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				ev.stopPropagation();
				opts.onSelect( format( today() ) );
				destroy();
			} );
			clearBtn.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				ev.stopPropagation();
				opts.onSelect( '' );
				destroy();
			} );
			foot.appendChild( todayBtn );
			foot.appendChild( clearBtn );
			wrap.appendChild( foot );

			position();
		}

		function onDocClick( ev ) {
			if ( ! wrap.contains( ev.target ) && ev.target !== opts.anchorEl ) {
				destroy();
			}
		}

		function onKeyDown( ev ) {
			if ( ev.key === 'Escape' ) {
				ev.stopPropagation();
				destroy();
			}
		}

		var destroyed = false;
		function destroy() {
			if ( destroyed ) {
				return;
			}
			destroyed = true;
			if ( openCalendar === handle ) {
				openCalendar = null;
			}
			document.removeEventListener( 'keydown', onKeyDown, true );
			document.removeEventListener( 'mousedown', onDocClick, true );
			window.removeEventListener( 'resize', position );
			window.removeEventListener( 'scroll', position, true );
			if ( wrap.parentNode ) {
				wrap.parentNode.removeChild( wrap );
			}
			if ( opts.onClose ) {
				opts.onClose();
			}
		}

		var handle = { destroy: destroy };
		openCalendar = handle;

		render();
		setTimeout( function () {
			document.addEventListener( 'mousedown', onDocClick, true );
		}, 0 );
		document.addEventListener( 'keydown', onKeyDown, true );
		window.addEventListener( 'resize', position );
		window.addEventListener( 'scroll', position, true );

		return handle;
	}

	window.WooSheetJalali = {
		gToJ: gToJ,
		jToG: jToG,
		format: format,
		parse: parse,
		today: today,
		daysInMonth: daysInMonth,
		normalizeDigits: normalizeDigits,
		createCalendar: createCalendar,
		closeOpenCalendar: closeOpenCalendar,
		MONTHS: MONTHS,
	};
} )();
