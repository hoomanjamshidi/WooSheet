/**
 * WooSheet — منطق رابط کاربری شیت محصولات.
 *
 * وابسته به Tabulator و WooSheetJalali (هر دو محلی).
 */
( function () {
	'use strict';

	var cfg = window.WooSheetData || {};
	var i18n = cfg.i18n || {};
	var J = window.WooSheetJalali;

	var table = null;
	var state = {
		page: 1,
		perPage: cfg.perPage || 50,
		search: '',
		category: 0,
		status: '',
		orderby: 'id',
		order: 'desc',
		totalPages: 1,
		total: 0,
	};

	// صف ذخیره به‌ازای هر محصول برای جلوگیری از تداخل درخواست‌ها.
	var saveQueues = {};

	/* ---------------------- ابزارها ---------------------- */

	function api( path, opts ) {
		opts = opts || {};
		var headers = { 'X-WP-Nonce': cfg.nonce };
		var fetchOpts = {
			method: opts.method || 'GET',
			headers: headers,
			credentials: 'same-origin',
		};
		if ( opts.form ) {
			fetchOpts.body = opts.form;
		} else if ( opts.body ) {
			headers['Content-Type'] = 'application/json';
			fetchOpts.body = JSON.stringify( opts.body );
		}
		return fetch( cfg.restUrl + path, fetchOpts ).then( function ( r ) {
			return r.json().catch( function () {
				return null;
			} ).then( function ( data ) {
				if ( ! r.ok ) {
					var msg = data && data.message ? data.message : ( i18n.saveError || 'خطا' );
					var err = new Error( msg );
					throw err;
				}
				return data;
			} );
		} );
	}

	function enqueue( id, fn ) {
		var prev = saveQueues[ id ] || Promise.resolve();
		var next = prev.then( fn, fn );
		saveQueues[ id ] = next;
		return next;
	}

	function toast( msg, type ) {
		var root = document.getElementById( 'woosheet-toasts' );
		if ( ! root ) {
			return;
		}
		var t = document.createElement( 'div' );
		t.className = 'woosheet-toast ' + ( type || 'info' );
		t.textContent = msg;
		root.appendChild( t );
		setTimeout( function () {
			t.classList.add( 'show' );
		}, 10 );
		setTimeout( function () {
			t.classList.remove( 'show' );
			setTimeout( function () {
				if ( t.parentNode ) {
					t.parentNode.removeChild( t );
				}
			}, 300 );
		}, 4000 );
	}

	function digits( s ) {
		return J ? J.normalizeDigits( s ) : String( s );
	}

	function parseMoney( str ) {
		if ( str === null || str === undefined ) {
			return '';
		}
		var v = digits( String( str ) ).replace( /[,،\s ٬]/g, '' ).trim();
		if ( v === '' ) {
			return '';
		}
		if ( isNaN( Number( v ) ) ) {
			return null; // نامعتبر
		}
		return v;
	}

	function formatMoney( num ) {
		if ( num === '' || num === null || num === undefined ) {
			return '';
		}
		var n = Number( digits( String( num ) ).replace( /[,،\s ٬]/g, '' ) );
		if ( isNaN( n ) ) {
			return '';
		}
		var c = cfg.currency || { decimals: 0, thousand: ',', decimal: '.' };
		var neg = n < 0;
		n = Math.abs( n );
		var fixed = n.toFixed( c.decimals );
		var parts = fixed.split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, c.thousand );
		var out = parts.length > 1 ? parts.join( c.decimal ) : parts[ 0 ];
		return ( neg ? '-' : '' ) + out;
	}

	// فرار دادن برای درج در HTML. توجه: نقل‌قول‌ها هم فرار داده می‌شوند چون
	// خروجی این تابع گاهی داخل attribute می‌نشیند و textContent/innerHTML
	// به‌تنهایی " و ' را فرار نمی‌دهد.
	function esc( s ) {
		return ( s == null ? '' : String( s ) )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	// کپی در کلیپ‌بورد؛ با جایگزین برای اتصال‌های ناامن (http) که Clipboard API ندارند.
	function copyText( text ) {
		if ( window.navigator && navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'fixed';
			ta.style.top = '-1000px';
			ta.style.opacity = '0';
			document.body.appendChild( ta );
			ta.select();
			ta.setSelectionRange( 0, ta.value.length );
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			document.body.removeChild( ta );
			if ( ok ) {
				resolve();
			} else {
				reject( new Error( 'copy-failed' ) );
			}
		} );
	}

	/* ----- قالب‌بندی زندهٔ قیمت (جداکنندهٔ هزارگان حین تایپ) ----- */

	function liveFormat( value ) {
		var c = cfg.currency || { decimals: 0, thousand: ',', decimal: '.' };
		var s = digits( String( value ) );
		var neg = s.trim().charAt( 0 ) === '-';
		if ( ! ( c.decimals > 0 ) ) {
			var d = s.replace( /[^\d]/g, '' ).replace( /^0+(?=\d)/, '' );
			d = d.replace( /\B(?=(\d{3})+(?!\d))/g, c.thousand );
			return ( neg && d !== '' ? '-' : '' ) + d;
		}
		s = s.replace( /[^\d.,٫]/g, '' );
		var m = s.search( /[.,٫]/ );
		var intPart, fracPart;
		if ( m >= 0 ) {
			intPart = s.slice( 0, m ).replace( /\D/g, '' );
			fracPart = s.slice( m + 1 ).replace( /\D/g, '' ).slice( 0, c.decimals );
		} else {
			intPart = s.replace( /\D/g, '' );
			fracPart = null;
		}
		intPart = intPart.replace( /^0+(?=\d)/, '' ).replace( /\B(?=(\d{3})+(?!\d))/g, c.thousand );
		var out = intPart;
		if ( fracPart !== null ) {
			out += c.decimal + fracPart;
		}
		return ( neg && out !== '' ? '-' : '' ) + out;
	}

	function caretFromDigitCount( str, n ) {
		if ( n <= 0 ) {
			return 0;
		}
		var count = 0;
		for ( var i = 0; i < str.length; i++ ) {
			if ( /\d/.test( str[ i ] ) ) {
				count++;
				if ( count >= n ) {
					return i + 1;
				}
			}
		}
		return str.length;
	}

	function attachLiveGrouping( input ) {
		input.addEventListener( 'input', function () {
			var sel = input.selectionStart || 0;
			var digitsBefore = input.value.slice( 0, sel ).replace( /[^\d]/g, '' ).length;
			var formatted = liveFormat( input.value );
			input.value = formatted;
			var pos = caretFromDigitCount( formatted, digitsBefore );
			try {
				input.setSelectionRange( pos, pos );
			} catch ( e ) {}
		} );
	}

	// ویرایشگر سفارشی قیمت برای Tabulator با قالب‌بندی زنده.
	function moneyEditor( cell, onRendered, success, cancel ) {
		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'ws-cell-input';
		input.setAttribute( 'inputmode', 'numeric' );
		input.dir = 'ltr'; // جلوگیری از چپه‌نویسی اعداد فارسی هنگام ویرایش.
		var initial = cell.getValue();
		input.value = ( initial === '' || initial == null ) ? '' : formatMoney( initial );

		onRendered( function () {
			input.focus();
			input.select();
		} );

		attachLiveGrouping( input );

		// یک‌بار-مصرف: بعد از Enter/Escape، رویداد blur نباید دوباره مقدار را ثبت کند
		// (باعث می‌شد Escape عملاً لغو نکند و همان مقدار ذخیره شود).
		var done = false;
		function commit() {
			if ( done ) {
				return;
			}
			done = true;
			var raw = parseMoney( input.value );
			if ( raw === null ) {
				cancel();
				return;
			}
			success( raw );
		}
		function abort() {
			if ( done ) {
				return;
			}
			done = true;
			cancel();
		}
		input.addEventListener( 'blur', commit );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' || e.key === 'Tab' ) {
				commit();
			} else if ( e.key === 'Escape' ) {
				e.stopPropagation();
				abort();
			}
		} );
		return input;
	}

	function flashCell( row, field, cls ) {
		try {
			var cell = row.getCell( field );
			if ( ! cell ) {
				return;
			}
			var elm = cell.getElement();
			if ( ! elm ) {
				return;
			}
			elm.classList.remove( 'ws-ok', 'ws-err', 'ws-saving' );
			elm.classList.add( cls );
			if ( cls !== 'ws-saving' ) {
				setTimeout( function () {
					elm.classList.remove( cls );
				}, 1200 );
			}
		} catch ( e ) {}
	}

	/* ---------------------- ذخیرهٔ فیلد ---------------------- */

	function saveField( row, field, newVal, oldVal ) {
		var data = row.getData();
		var id = data.id;

		flashCell( row, field, 'ws-saving' );

		var fields = {};
		fields[ field ] = newVal;

		enqueue( id, function () {
			return api( 'products/' + id, { method: 'POST', body: { fields: fields } } )
				.then( function ( res ) {
					if ( res && res.row ) {
						row.update( res.row );
					}
					flashCell( row, field, 'ws-ok' );
					pushUndo( id, field, oldVal );
					toast( i18n.saved || 'ذخیره شد', 'success' );
				} )
				.catch( function ( err ) {
					var revert = {};
					revert[ field ] = oldVal;
					row.update( revert );
					flashCell( row, field, 'ws-err' );
					toast( err.message || i18n.saveError, 'error' );
				} );
		} );
	}

	/* ---------------------- بازگردانی تغییرات (Undo) ---------------------- */

	var undoStack = [];

	function pushUndo( id, field, value ) {
		undoStack.push( { id: id, field: field, value: value } );
		if ( undoStack.length > 100 ) {
			undoStack.shift();
		}
		updateUndoButton();
	}

	function updateUndoButton() {
		var b = document.getElementById( 'woosheet-undo' );
		if ( b ) {
			b.disabled = undoStack.length === 0;
		}
	}

	function applyUndo() {
		if ( ! undoStack.length ) {
			toast( i18n.nothingUndo || 'تغییری برای بازگرداندن نیست', 'info' );
			return;
		}
		var e = undoStack.pop();
		updateUndoButton();

		var row = table.getRow( e.id );
		var fields = {};
		fields[ e.field ] = e.value;

		// بازگردانی بدون افزودن دوباره به پشتهٔ undo (تا حلقه ایجاد نشود).
		enqueue( e.id, function () {
			return api( 'products/' + e.id, { method: 'POST', body: { fields: fields } } )
				.then( function ( res ) {
					if ( row && res && res.row ) {
						row.update( res.row );
						flashCell( row, e.field, 'ws-ok' );
					}
					toast( i18n.reverted || 'به مقدار قبلی بازگردانده شد', 'success' );
				} )
				.catch( function ( err ) {
					toast( err.message || i18n.saveError, 'error' );
				} );
		} );
	}

	function handleTypeChange( row, newVal, oldVal ) {
		var id = row.getData().id;
		flashCell( row, 'type', 'ws-saving' );
		enqueue( id, function () {
			return api( 'products/' + id + '/type', { method: 'POST', body: { type: newVal } } )
				.then( function ( res ) {
					if ( res && res.row ) {
						row.update( res.row );
					}
					flashCell( row, 'type', 'ws-ok' );
					toast( i18n.typeChanged || i18n.saved, 'success' );
					if ( newVal === 'variable' ) {
						// راهنمایی: برای ثبت قیمت، متغیرها را تعریف کنید.
						openVariationsModal( row.getData() );
					}
				} )
				.catch( function ( err ) {
					row.update( { type: oldVal } );
					flashCell( row, 'type', 'ws-err' );
					toast( err.message || i18n.saveError, 'error' );
				} );
		} );
	}

	function openImagePicker( row ) {
		if ( ! ( window.wp && window.wp.media ) ) {
			toast( i18n.mediaUnavailable || 'کتابخانهٔ رسانه در دسترس نیست', 'error' );
			return;
		}
		var pid = row.getData().id;
		var frame = window.wp.media( {
			title: i18n.setImage || 'انتخاب تصویر شاخص',
			button: { text: i18n.choose || 'انتخاب' },
			library: { type: 'image' },
			multiple: false,
		} );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			var attId = att && att.id ? att.id : 0;
			if ( ! attId ) {
				return;
			}
			flashCell( row, 'image', 'ws-saving' );
			enqueue( pid, function () {
				return api( 'products/' + pid, { method: 'POST', body: { fields: { image_id: attId } } } )
					.then( function ( res ) {
						if ( res && res.row ) {
							row.update( res.row );
						}
						flashCell( row, 'image', 'ws-ok' );
						toast( i18n.saved || 'ذخیره شد', 'success' );
					} )
					.catch( function ( err ) {
						flashCell( row, 'image', 'ws-err' );
						toast( err.message || i18n.saveError, 'error' );
					} );
			} );
		} );
		frame.open();
	}

	/* ---------------------- ستون‌ها ---------------------- */

	function moneyFormatter( cell ) {
		return formatMoney( cell.getValue() );
	}

	function regularPriceFormatter( cell ) {
		var d = cell.getData();
		if ( ! d.is_variable ) {
			return formatMoney( cell.getValue() );
		}

		var min = d.price_min;
		var max = d.price_max;

		var wrap = document.createElement( 'div' );
		wrap.className = 'ws-var-cell';

		var range = document.createElement( 'span' );
		if ( min === '' || min === null || min === undefined ) {
			range.className = 'ws-muted';
			range.textContent = i18n.priceRangeHint || '';
		} else {
			range.className = 'ws-range';
			range.textContent = ( Number( min ) === Number( max ) )
				? formatMoney( min )
				: ( formatMoney( min ) + ' – ' + formatMoney( max ) );
		}

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'ws-mini-btn ws-edit-var';
		btn.textContent = i18n.editVariations || 'ویرایش متغیرها';

		wrap.appendChild( range );
		wrap.appendChild( btn );

		// شنوندهٔ مستقیم روی عناصر داخل سلول؛ پیش از آنکه ماژول ویرایش Tabulator
		// جلوی انتشار رویداد را بگیرد اجرا می‌شود — برخلاف cellClick/واگذاری سراسری.
		var open = function ( ev ) {
			ev.preventDefault();
			ev.stopPropagation();
			openVariationsModal( cell.getData() );
		};
		wrap.addEventListener( 'click', open );
		btn.addEventListener( 'click', open );

		return wrap;
	}

	function buildColumns() {
		var opt = cfg.options || {};
		var stockOpts = opt.stock_status || {};
		var statusOpts = opt.status || {};
		var cur = ( cfg.currency && cfg.currency.symbol ) ? ' (' + cfg.currency.symbol + ')' : '';

		return [
			{
				title: i18n.colImage || 'تصویر',
				field: 'image',
				width: 64,
				headerSort: false,
				frozen: true,
				editable: false,
				hozAlign: 'center',
				formatter: function ( cell ) {
					var url = cell.getValue();
					if ( ! url ) {
						return '<span class="ws-thumb ws-thumb-empty" title="' + esc( i18n.setImage || '' ) + '"></span>';
					}
					return '<span class="ws-thumb-wrap"><img class="ws-thumb" src="' + esc( url ) + '" alt="" loading="lazy"></span>';
				},
				cellClick: function ( e, cell ) {
					openImagePicker( cell.getRow() );
				},
			},
			{
				title: i18n.colId || 'شناسه',
				field: 'id',
				width: 80,
				headerSort: false,
				frozen: true,
				editable: false,
				cssClass: 'ws-col-id',
			},
			{
				title: i18n.colName || 'نام محصول',
				field: 'name',
				minWidth: 220,
				headerSort: false,
				frozen: true,
				editor: 'input',
				formatter: function ( cell ) {
					return esc( cell.getValue() );
				},
			},
			{
				title: i18n.colType || 'نوع',
				field: 'type',
				width: 110,
				headerSort: false,
				editor: 'list',
				editorParams: {
					values: {
						simple: i18n.typeSimple || 'ساده',
						variable: i18n.typeVariable || 'متغیر',
					},
				},
				editable: function ( cell ) {
					var t = cell.getData().type;
					return t === 'simple' || t === 'variable';
				},
				formatter: function ( cell ) {
					var d = cell.getData();
					var label = d.is_variable ? ( i18n.typeVariable || 'متغیر' ) : ( i18n.typeSimple || 'ساده' );
					if ( d.type && d.type !== 'simple' && d.type !== 'variable' ) {
						label = d.type;
					}
					var c = d.is_variable ? 'ws-badge ws-badge-var' : 'ws-badge ws-badge-simple';
					return '<span class="' + c + '">' + esc( label ) + '</span>';
				},
			},
			{
				title: i18n.colSku || 'SKU',
				field: 'sku',
				width: 130,
				headerSort: false,
				editor: 'input',
				formatter: function ( cell ) {
					return esc( cell.getValue() );
				},
			},
			{
				title: ( i18n.colRegular || 'قیمت اصلی' ) + cur,
				field: 'regular_price',
				width: 180,
				headerSort: false,
				hozAlign: 'left',
				editor: moneyEditor,
				editable: function ( cell ) {
					return ! cell.getData().is_variable;
				},
				formatter: regularPriceFormatter,
				cssClass: 'ws-col-price',
			},
			{
				title: ( i18n.colSale || 'قیمت تخفیفی' ) + cur,
				field: 'sale_price',
				width: 160,
				headerSort: false,
				hozAlign: 'left',
				editor: moneyEditor,
				editable: function ( cell ) {
					return ! cell.getData().is_variable;
				},
				formatter: function ( cell ) {
					if ( cell.getData().is_variable ) {
						return '<span class="ws-muted">—</span>';
					}
					var v = formatMoney( cell.getValue() );
					return v === '' ? '<span class="ws-muted">—</span>' : v;
				},
				cssClass: 'ws-col-price',
			},
			{
				title: i18n.colSaleFrom || 'شروع تخفیف',
				field: 'sale_from',
				width: 130,
				headerSort: false,
				editable: false,
				formatter: dateFormatter,
				cellClick: onDateCellClick,
				cssClass: 'ws-col-date',
			},
			{
				title: i18n.colSaleTo || 'پایان تخفیف',
				field: 'sale_to',
				width: 130,
				headerSort: false,
				editable: false,
				formatter: dateFormatter,
				cellClick: onDateCellClick,
				cssClass: 'ws-col-date',
			},
			{
				title: i18n.colStock || 'موجودی',
				field: 'stock_quantity',
				width: 100,
				headerSort: false,
				hozAlign: 'center',
				editor: 'input',
				editable: function ( cell ) {
					return ! cell.getData().is_variable;
				},
				formatter: function ( cell ) {
					if ( cell.getData().is_variable ) {
						return '<span class="ws-muted">—</span>';
					}
					var v = cell.getValue();
					return ( v === '' || v === null ) ? '<span class="ws-muted">∞</span>' : esc( v );
				},
			},
			{
				title: i18n.colStockStatus || 'وضعیت موجودی',
				field: 'stock_status',
				width: 130,
				headerSort: false,
				editor: 'list',
				editorParams: { values: stockOpts },
				formatter: function ( cell ) {
					var v = cell.getValue();
					return esc( stockOpts[ v ] || v );
				},
			},
			{
				title: i18n.colStatus || 'وضعیت انتشار',
				field: 'status',
				width: 120,
				headerSort: false,
				editor: 'list',
				editorParams: { values: statusOpts },
				formatter: function ( cell ) {
					var v = cell.getValue();
					return '<span class="ws-status ws-status-' + esc( v ) + '">' + esc( statusOpts[ v ] || v ) + '</span>';
				},
			},
			{
				title: i18n.colDownloadable || 'دانلودی',
				field: 'downloadable',
				width: 110,
				headerSort: false,
				editable: false,
				hozAlign: 'center',
				formatter: function ( cell ) {
					var d = cell.getData();
					var isDl = !! d.downloadable;
					var label = isDl ? ( i18n.isDownloadable || 'دانلودی' ) : ( i18n.notDownloadable || 'فیزیکی' );
					var cls = isDl ? 'ws-badge ws-badge-dl' : 'ws-badge ws-badge-phys';
					var count = ( isDl && d.download_count )
						? ' <span class="ws-dl-count">' + esc( d.download_count ) + '</span>'
						: '';
					return '<span class="' + cls + '">' + esc( label ) + count + '</span>';
				},
			},
			{
				title: i18n.colDownloads || 'لینک‌های دانلود',
				field: '_downloads',
				width: 150,
				headerSort: false,
				editable: false,
				hozAlign: 'center',
				formatter: function ( cell ) {
					if ( ! hasDownloads( cell.getData() ) ) {
						return '<span class="ws-muted">—</span>';
					}
					return '<button type="button" class="ws-mini-btn ws-dl-btn">⬇ ' +
						esc( i18n.viewDownloads || 'مشاهدهٔ لینک‌ها' ) + '</button>';
				},
				cellClick: function ( e, cell ) {
					if ( ! hasDownloads( cell.getData() ) ) {
						return;
					}
					openDownloadsModal( cell.getData() );
				},
			},
			{
				title: i18n.colCoupon || 'کد تخفیف',
				field: '_coupon',
				width: 110,
				headerSort: false,
				editable: false,
				formatter: function () {
					return '<button type="button" class="ws-mini-btn ws-coupon-btn">' + esc( i18n.makeCoupon || 'ساخت کوپن' ) + '</button>';
				},
				cellClick: function ( e, cell ) {
					openCouponModal( cell.getData() );
				},
			},
			{
				title: i18n.colView || 'نمایش',
				field: '_view',
				width: 90,
				headerSort: false,
				editable: false,
				formatter: function ( cell ) {
					var d = cell.getData();
					if ( ! d.permalink ) {
						return '';
					}
					return '<a class="ws-mini-btn ws-view-btn" href="' + esc( d.permalink ) + '" target="_blank" rel="noopener">' + esc( i18n.view || 'نمایش' ) + '</a>';
				},
			},
		];
	}

	function dateFormatter( cell ) {
		if ( cell.getData().is_variable ) {
			return '<span class="ws-muted">—</span>';
		}
		var v = cell.getValue();
		return v ? '<span class="ws-date">' + esc( v ) + '</span>' : '<span class="ws-muted ws-date-empty">' + esc( i18n.addDate || '+ تاریخ' ) + '</span>';
	}

	function onDateCellClick( e, cell ) {
		var d = cell.getData();
		if ( d.is_variable ) {
			return;
		}
		var field = cell.getColumn().getField();
		var oldVal = cell.getValue();
		J.createCalendar( {
			value: oldVal,
			anchorEl: cell.getElement(),
			onSelect: function ( val ) {
				if ( val === oldVal ) {
					return;
				}
				saveField( cell.getRow(), field, val, oldVal );
			},
		} );
	}

	/* ---------------------- ویرایش سلول ---------------------- */

	function onCellEdited( cell ) {
		var field = cell.getColumn().getField();
		var newVal = cell.getValue();
		var oldVal = cell.getOldValue();

		// تغییر نوع محصول از مسیر اختصاصی انجام می‌شود.
		if ( field === 'type' ) {
			if ( String( newVal ) !== String( oldVal == null ? '' : oldVal ) ) {
				handleTypeChange( cell.getRow(), newVal, oldVal );
			}
			return;
		}

		// نرمال‌سازی قیمت‌ها پیش از مقایسه/ارسال.
		if ( field === 'regular_price' || field === 'sale_price' ) {
			var parsed = parseMoney( newVal );
			if ( parsed === null ) {
				var rv = {};
				rv[ field ] = oldVal;
				cell.getRow().update( rv );
				flashCell( cell.getRow(), field, 'ws-err' );
				toast( i18n.saveError || 'مقدار نامعتبر', 'error' );
				return;
			}
			newVal = parsed;
		}

		if ( String( newVal ) === String( oldVal == null ? '' : oldVal ) ) {
			return;
		}
		saveField( cell.getRow(), field, newVal, oldVal );
	}

	/* ---------------------- بارگذاری داده ---------------------- */

	var fetchSeq = 0;

	function fetchPage( page ) {
		state.page = page;
		var seq = ++fetchSeq;
		setStatus( i18n.loading || 'در حال بارگذاری…' );
		var params = new URLSearchParams( {
			page: page,
			per_page: state.perPage,
			search: state.search,
			orderby: state.orderby,
			order: state.order,
		} );
		if ( state.category ) {
			params.set( 'category', state.category );
		}
		if ( state.status ) {
			params.set( 'status', state.status );
		}
		return api( 'products?' + params.toString() )
			.then( function ( res ) {
				// نتیجهٔ کهنه را نادیده بگیر (جلوگیری از تداخل هنگام تایپ سریع).
				if ( seq !== fetchSeq ) {
					return;
				}
				state.totalPages = res.total_pages || 1;
				state.total = res.total || 0;
				table.setData( res.items || [] );
				renderPagination();
				setStatus( ( i18n.totalItems || 'تعداد کل:' ) + ' ' + state.total );
			} )
			.catch( function ( err ) {
				if ( seq !== fetchSeq ) {
					return;
				}
				toast( err.message || i18n.loadError || 'خطا در بارگذاری', 'error' );
				setStatus( '' );
			} );
	}

	function setStatus( msg ) {
		var bar = document.getElementById( 'woosheet-status-bar' );
		if ( bar ) {
			bar.textContent = msg;
		}
	}

	function renderPagination() {
		var root = document.getElementById( 'woosheet-pagination' );
		if ( ! root ) {
			return;
		}
		root.innerHTML = '';

		var prev = document.createElement( 'button' );
		prev.className = 'button';
		prev.textContent = i18n.prev || 'قبلی';
		prev.disabled = state.page <= 1;
		prev.addEventListener( 'click', function () {
			if ( state.page > 1 ) {
				fetchPage( state.page - 1 );
			}
		} );

		var next = document.createElement( 'button' );
		next.className = 'button';
		next.textContent = i18n.next || 'بعدی';
		next.disabled = state.page >= state.totalPages;
		next.addEventListener( 'click', function () {
			if ( state.page < state.totalPages ) {
				fetchPage( state.page + 1 );
			}
		} );

		var info = document.createElement( 'span' );
		info.className = 'woosheet-page-info';
		info.textContent = ( i18n.page || 'صفحه' ) + ' ' + state.page + ' ' + ( i18n.of || 'از' ) + ' ' + state.totalPages;

		// در RTL: بعدی سمت چپ، قبلی سمت راست (ترتیب طبیعی DOM).
		root.appendChild( prev );
		root.appendChild( info );
		root.appendChild( next );
	}

	/* ---------------------- مودال عمومی ---------------------- */

	// پشتهٔ مودال‌های باز؛ Escape فقط باید مودالِ رویی را ببندد نه همه را.
	var modalStack = [];

	function openModal( title, contentEl, opts ) {
		opts = opts || {};
		var root = document.getElementById( 'woosheet-modal-root' );
		var overlay = document.createElement( 'div' );
		overlay.className = 'woosheet-overlay';

		var box = document.createElement( 'div' );
		box.className = 'woosheet-modal' + ( opts.wide ? ' woosheet-modal-wide' : '' );

		var head = document.createElement( 'div' );
		head.className = 'woosheet-modal-head';
		var h = document.createElement( 'h2' );
		h.textContent = title;
		var close = document.createElement( 'button' );
		close.className = 'woosheet-modal-close';
		close.type = 'button';
		close.setAttribute( 'aria-label', i18n.close || 'بستن' );
		close.innerHTML = '&times;';
		head.appendChild( h );
		head.appendChild( close );

		var body = document.createElement( 'div' );
		body.className = 'woosheet-modal-body';
		body.appendChild( contentEl );

		box.appendChild( head );
		box.appendChild( body );
		overlay.appendChild( box );
		root.appendChild( overlay );

		var closed = false;
		function destroy() {
			if ( closed ) {
				return;
			}
			closed = true;
			var at = modalStack.indexOf( handle );
			if ( at >= 0 ) {
				modalStack.splice( at, 1 );
			}
			if ( overlay.parentNode ) {
				overlay.parentNode.removeChild( overlay );
			}
			document.removeEventListener( 'keydown', onKey );
			if ( opts.onClose ) {
				opts.onClose();
			}
		}
		function onKey( e ) {
			if ( e.key !== 'Escape' ) {
				return;
			}
			// تقویم شناور بیرون از overlay ساخته می‌شود؛ اول آن بسته شود.
			if ( J && J.closeOpenCalendar && J.closeOpenCalendar() ) {
				return;
			}
			// فقط مودالِ رویی به Escape پاسخ می‌دهد.
			if ( modalStack[ modalStack.length - 1 ] !== handle ) {
				return;
			}
			destroy();
		}
		var handle = { destroy: destroy, body: body };
		modalStack.push( handle );

		close.addEventListener( 'click', destroy );
		overlay.addEventListener( 'mousedown', function ( e ) {
			if ( e.target === overlay ) {
				destroy();
			}
		} );
		document.addEventListener( 'keydown', onKey );

		return handle;
	}

	function field( labelText, inputEl ) {
		var wrap = document.createElement( 'label' );
		wrap.className = 'woosheet-field';
		var span = document.createElement( 'span' );
		span.className = 'woosheet-field-label';
		span.textContent = labelText;
		wrap.appendChild( span );
		wrap.appendChild( inputEl );
		return wrap;
	}

	/* ---------------------- مودال متغیرها ---------------------- */

	function openVariationsModal( parentData ) {
		var container = document.createElement( 'div' );
		container.className = 'ws-var-container';
		container.innerHTML = '<div class="ws-loading">' + esc( i18n.loading || '…' ) + '</div>';

		var changed = { v: false };
		openModal(
			( i18n.variationsOf || 'متغیرهای محصول' ) + ' — ' + ( parentData.name || '' ),
			container,
			{
				wide: true,
				onClose: function () {
					if ( changed.v ) {
						fetchPage( state.page );
					}
				},
			}
		);

		function reload() {
			api( 'products/' + parentData.id + '/variations' )
				.then( function ( payload ) {
					renderVariationManager( container, payload, parentData, changed, reload );
				} )
				.catch( function ( err ) {
					container.innerHTML = '<div class="ws-error">' + esc( err.message || 'خطا' ) + '</div>';
				} );
		}
		reload();
	}

	function renderVariationManager( container, payload, parentData, changed, reload ) {
		container.innerHTML = '';
		container.appendChild( buildAttributesSection( container, payload, parentData, changed, reload ) );
		container.appendChild( buildVariationsSection( container, payload, parentData, changed, reload ) );
	}

	function buildAttributesSection( container, payload, parentData, changed, reload ) {
		var sec = document.createElement( 'div' );
		sec.className = 'ws-attr-section';

		var h = document.createElement( 'h3' );
		h.textContent = i18n.attributes || 'ویژگی‌ها';
		sec.appendChild( h );

		var list = document.createElement( 'div' );
		list.className = 'ws-attr-list';
		sec.appendChild( list );

		var rows = [];
		( payload.attributes || [] ).forEach( function ( a ) {
			rows.push( addAttrRow( list, a ) );
		} );

		var addBtn = document.createElement( 'button' );
		addBtn.type = 'button';
		addBtn.className = 'ws-mini-btn ws-attr-add';
		addBtn.textContent = '+ ' + ( i18n.attrAdd || 'افزودن ویژگی' );
		addBtn.addEventListener( 'click', function () {
			rows.push( addAttrRow( list, { name: '', values: [], variation: true, is_taxonomy: false } ) );
		} );
		sec.appendChild( addBtn );

		var actions = document.createElement( 'div' );
		actions.className = 'ws-attr-actions';

		var saveBtn = document.createElement( 'button' );
		saveBtn.type = 'button';
		saveBtn.className = 'button button-primary';
		saveBtn.textContent = i18n.attrSave || 'ذخیرهٔ ویژگی‌ها';
		saveBtn.addEventListener( 'click', function () {
			var custom = [];
			rows.forEach( function ( r ) {
				var data = r.collect();
				if ( data ) {
					custom.push( data );
				}
			} );
			saveBtn.disabled = true;
			api( 'products/' + parentData.id + '/attributes', { method: 'POST', body: { attributes: custom } } )
				.then( function ( payload2 ) {
					changed.v = true;
					toast( i18n.attrSaved || 'ذخیره شد', 'success' );
					renderVariationManager( container, payload2, parentData, changed, reload );
				} )
				.catch( function ( err ) {
					saveBtn.disabled = false;
					toast( err.message || i18n.saveError, 'error' );
				} );
		} );
		actions.appendChild( saveBtn );

		var genBtn = document.createElement( 'button' );
		genBtn.type = 'button';
		genBtn.className = 'button';
		genBtn.textContent = i18n.genVariations || 'ساخت متغیرها';
		genBtn.addEventListener( 'click', function () {
			genBtn.disabled = true;
			api( 'products/' + parentData.id + '/generate-variations', { method: 'POST', body: {} } )
				.then( function ( payload2 ) {
					changed.v = true;
					toast( ( i18n.genDone || 'ساخته شد:' ) + ' ' + ( payload2.created || 0 ), 'success' );
					renderVariationManager( container, payload2, parentData, changed, reload );
				} )
				.catch( function ( err ) {
					genBtn.disabled = false;
					toast( err.message || i18n.saveError, 'error' );
				} );
		} );
		actions.appendChild( genBtn );

		sec.appendChild( actions );
		return sec;
	}

	function addAttrRow( list, a ) {
		var isTax = !! a.is_taxonomy;
		var row = document.createElement( 'div' );
		row.className = 'ws-attr-row';

		var nameInput = document.createElement( 'input' );
		nameInput.type = 'text';
		nameInput.className = 'ws-var-input ws-attr-name';
		nameInput.placeholder = i18n.attrName || 'نام ویژگی';
		nameInput.value = a.name || '';

		var valuesInput = document.createElement( 'input' );
		valuesInput.type = 'text';
		valuesInput.className = 'ws-var-input ws-attr-values';
		valuesInput.placeholder = i18n.attrValues || 'مقادیر (با کاما)';
		valuesInput.value = ( a.values || [] ).join( '، ' );

		var varLabel = document.createElement( 'label' );
		varLabel.className = 'ws-attr-check';
		var varChk = document.createElement( 'input' );
		varChk.type = 'checkbox';
		varChk.checked = !! a.variation;
		var varText = document.createElement( 'span' );
		varText.textContent = i18n.attrForVariation || 'برای متغیر';
		varLabel.appendChild( varChk );
		varLabel.appendChild( varText );

		var rmBtn = document.createElement( 'button' );
		rmBtn.type = 'button';
		rmBtn.className = 'ws-mini-btn';
		rmBtn.textContent = i18n.remove || 'حذف';
		rmBtn.addEventListener( 'click', function () {
			row._removed = true;
			if ( row.parentNode ) {
				row.parentNode.removeChild( row );
			}
		} );

		if ( isTax ) {
			nameInput.readOnly = true;
			valuesInput.readOnly = true;
			varChk.disabled = true;
			rmBtn.disabled = true;
			var tax = document.createElement( 'span' );
			tax.className = 'ws-muted ws-attr-tax';
			tax.textContent = i18n.attrTaxonomy || '';
			row.appendChild( tax );
		}

		row.appendChild( nameInput );
		row.appendChild( valuesInput );
		row.appendChild( varLabel );
		row.appendChild( rmBtn );
		list.appendChild( row );

		return {
			collect: function () {
				if ( isTax || row._removed ) {
					return null;
				}
				var name = nameInput.value.trim();
				if ( ! name ) {
					return null;
				}
				var values = valuesInput.value.split( /[،,]/ ).map( function ( s ) {
					return s.trim();
				} ).filter( function ( s ) {
					return s !== '';
				} );
				if ( ! values.length ) {
					return null;
				}
				return { name: name, values: values, variation: varChk.checked };
			},
		};
	}

	function buildVariationsSection( container, payload, parentData, changed, reload ) {
		var sec = document.createElement( 'div' );
		sec.className = 'ws-var-section';

		var h = document.createElement( 'h3' );
		h.textContent = i18n.variationsOf || 'متغیرها';
		sec.appendChild( h );

		var variations = payload.variations || [];
		if ( ! variations.length ) {
			var empty = document.createElement( 'div' );
			empty.className = 'ws-empty';
			empty.textContent = i18n.noVariations || 'متغیری نیست.';
			sec.appendChild( empty );
			return sec;
		}

		var opt = cfg.options || {};
		var stockOpts = opt.stock_status || {};

		var tbl = document.createElement( 'table' );
		tbl.className = 'ws-var-table';
		var thead = document.createElement( 'thead' );
		thead.innerHTML = '<tr>' +
			'<th>' + esc( i18n.colName || 'متغیر' ) + '</th>' +
			'<th>' + esc( i18n.colRegular || 'قیمت اصلی' ) + '</th>' +
			'<th>' + esc( i18n.colSale || 'قیمت تخفیفی' ) + '</th>' +
			'<th>' + esc( i18n.colSaleFrom || 'شروع تخفیف' ) + '</th>' +
			'<th>' + esc( i18n.colSaleTo || 'پایان تخفیف' ) + '</th>' +
			'<th>' + esc( i18n.colStock || 'موجودی' ) + '</th>' +
			'<th>' + esc( i18n.colStockStatus || 'وضعیت' ) + '</th>' +
			'<th></th>' +
			'</tr>';
		tbl.appendChild( thead );

		var tbody = document.createElement( 'tbody' );
		variations.forEach( function ( v ) {
			var tr = document.createElement( 'tr' );
			tr.appendChild( tdText( v.name ) );
			tr.appendChild( tdMoneyInput( v, 'regular_price', changed ) );
			tr.appendChild( tdMoneyInput( v, 'sale_price', changed ) );
			tr.appendChild( tdDateInput( v, 'sale_from', changed ) );
			tr.appendChild( tdDateInput( v, 'sale_to', changed ) );
			tr.appendChild( tdNumberInput( v, 'stock_quantity', changed ) );
			tr.appendChild( tdSelectInput( v, 'stock_status', stockOpts, changed ) );
			tr.appendChild( tdDelete( container, v, parentData, changed, reload ) );
			tbody.appendChild( tr );
		} );

		tbl.appendChild( tbody );
		sec.appendChild( tbl );
		return sec;
	}

	function tdDelete( container, v, parentData, changed, reload ) {
		var td = document.createElement( 'td' );
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'ws-mini-btn ws-del-btn';
		btn.textContent = i18n.deleteVar || 'حذف';
		btn.addEventListener( 'click', function () {
			if ( ! window.confirm( i18n.confirmDeleteVar || 'حذف شود؟' ) ) {
				return;
			}
			btn.disabled = true;
			api( 'products/' + parentData.id + '/variations/' + v.id, { method: 'DELETE' } )
				.then( function ( payload2 ) {
					changed.v = true;
					renderVariationManager( container, payload2, parentData, changed, reload );
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					toast( err.message || i18n.saveError, 'error' );
				} );
		} );
		td.appendChild( btn );
		return td;
	}

	function tdText( text ) {
		var td = document.createElement( 'td' );
		td.textContent = text;
		td.className = 'ws-var-name';
		return td;
	}

	function saveVariationField( id, field, newVal, inputEl, revertVal, changed, previousCurrent ) {
		inputEl.classList.add( 'ws-saving' );
		// مقدار خوش‌بینانه تا وقتی سرور تأیید کند.
		inputEl.dataset.current = newVal == null ? '' : newVal;
		var fields = {};
		fields[ field ] = newVal;
		enqueue( id, function () {
			return api( 'products/' + id, { method: 'POST', body: { fields: fields } } )
				.then( function ( res ) {
					inputEl.classList.remove( 'ws-saving' );
					inputEl.classList.add( 'ws-ok' );
					setTimeout( function () {
						inputEl.classList.remove( 'ws-ok' );
					}, 1000 );
					changed.v = true;
					if ( res && res.row ) {
						inputEl.dataset.current = res.row[ field ] != null ? res.row[ field ] : '';
					}
				} )
				.catch( function ( err ) {
					inputEl.classList.remove( 'ws-saving' );
					inputEl.classList.add( 'ws-err' );
					setTimeout( function () {
						inputEl.classList.remove( 'ws-err' );
					}, 1500 );
					inputEl.value = revertVal == null ? '' : revertVal;
					if ( previousCurrent !== undefined ) {
						inputEl.dataset.current = previousCurrent == null ? '' : previousCurrent;
					}
					toast( err.message || i18n.saveError, 'error' );
				} );
		} );
	}

	function tdMoneyInput( v, field, changed ) {
		var td = document.createElement( 'td' );
		var inp = document.createElement( 'input' );
		inp.type = 'text';
		inp.className = 'ws-var-input';
		inp.setAttribute( 'inputmode', 'numeric' );
		inp.dir = 'ltr';
		inp.style.textAlign = 'right';
		var raw = v[ field ] == null ? '' : v[ field ];
		inp.value = raw === '' ? '' : formatMoney( raw );
		inp.dataset.current = raw;
		attachLiveGrouping( inp );
		inp.addEventListener( 'change', function () {
			var parsed = parseMoney( inp.value );
			if ( parsed === null ) {
				inp.classList.add( 'ws-err' );
				toast( i18n.saveError || 'نامعتبر', 'error' );
				inp.value = inp.dataset.current === '' ? '' : formatMoney( inp.dataset.current );
				return;
			}
			if ( String( parsed ) === String( inp.dataset.current ) ) {
				return;
			}
			var revert = inp.dataset.current === '' ? '' : formatMoney( inp.dataset.current );
			var previous = inp.dataset.current;
			inp.value = parsed === '' ? '' : formatMoney( parsed );
			// dataset.current فقط پس از تأیید سرور جلو می‌رود؛ در غیر این صورت
			// بعد از یک ذخیرهٔ ناموفق، برگرداندن دستیِ مقدار «بدون تغییر» تلقی
			// می‌شد و دیگر ارسال نمی‌شد.
			saveVariationField( v.id, field, parsed, inp, revert, changed, previous );
		} );
		td.appendChild( inp );
		return td;
	}

	function tdNumberInput( v, field, changed ) {
		var td = document.createElement( 'td' );
		var inp = document.createElement( 'input' );
		inp.type = 'text';
		inp.className = 'ws-var-input ws-var-num';
		inp.dir = 'ltr';
		inp.style.textAlign = 'center';
		inp.value = v[ field ] == null ? '' : v[ field ];
		inp.dataset.current = inp.value;
		inp.addEventListener( 'change', function () {
			if ( String( inp.value ) === String( inp.dataset.current ) ) {
				return;
			}
			saveVariationField( v.id, field, inp.value, inp, inp.dataset.current, changed );
		} );
		td.appendChild( inp );
		return td;
	}

	function tdSelectInput( v, field, options, changed ) {
		var td = document.createElement( 'td' );
		var sel = document.createElement( 'select' );
		sel.className = 'ws-var-input';
		Object.keys( options ).forEach( function ( k ) {
			var o = document.createElement( 'option' );
			o.value = k;
			o.textContent = options[ k ];
			if ( k === v[ field ] ) {
				o.selected = true;
			}
			sel.appendChild( o );
		} );
		sel.dataset.current = v[ field ] || '';
		sel.addEventListener( 'change', function () {
			if ( sel.value === sel.dataset.current ) {
				return;
			}
			saveVariationField( v.id, field, sel.value, sel, sel.dataset.current, changed );
		} );
		td.appendChild( sel );
		return td;
	}

	function tdDateInput( v, field, changed ) {
		var td = document.createElement( 'td' );
		var inp = document.createElement( 'input' );
		inp.type = 'text';
		inp.readOnly = true;
		inp.className = 'ws-var-input ws-var-date';
		inp.placeholder = '—';
		inp.value = v[ field ] == null ? '' : v[ field ];
		inp.dataset.current = inp.value;
		inp.addEventListener( 'click', function () {
			J.createCalendar( {
				value: inp.value,
				anchorEl: inp,
				onSelect: function ( val ) {
					if ( val === inp.dataset.current ) {
						return;
					}
					var previous = inp.dataset.current;
					inp.value = val;
					saveVariationField( v.id, field, val, inp, previous, changed, previous );
				},
			} );
		} );
		td.appendChild( inp );
		return td;
	}

	/* ---------------------- مودال لینک‌های دانلود ---------------------- */

	// فقط محصولی که هم دانلودی است و هم واقعاً فایلی برایش تنظیم شده.
	function hasDownloads( d ) {
		return !! d.downloadable && Number( d.download_count ) > 0;
	}

	function openDownloadsModal( productData ) {
		var container = document.createElement( 'div' );
		container.className = 'ws-dl-container';
		container.innerHTML = '<div class="ws-loading">' + esc( i18n.loading || '…' ) + '</div>';

		openModal(
			( i18n.downloadsTitle || 'لینک‌های دانلود' ) + ' — ' + ( productData.name || '' ),
			container,
			{ wide: true }
		);

		api( 'products/' + productData.id + '/downloads' )
			.then( function ( payload ) {
				renderDownloads( container, payload );
			} )
			.catch( function ( err ) {
				container.innerHTML = '<div class="ws-error">' + esc( err.message || 'خطا' ) + '</div>';
			} );
	}

	function renderDownloads( container, payload ) {
		container.innerHTML = '';

		var groups = payload.groups || [];
		if ( ! groups.length ) {
			container.innerHTML = '<div class="ws-dl-empty">' + esc( i18n.noDownloads || 'فایلی تنظیم نشده است.' ) + '</div>';
			return;
		}

		// یک گروه (محصول ساده) مسطح نمایش داده می‌شود؛ چند متغیر به‌صورت آکاردئون.
		var accordion = payload.is_variable || groups.length > 1;

		groups.forEach( function ( group, index ) {
			container.appendChild(
				accordion
					? buildDownloadGroupAccordion( group, index === 0 )
					: buildDownloadList( group )
			);
		} );
	}

	function buildDownloadGroupAccordion( group, open ) {
		var details = document.createElement( 'details' );
		details.className = 'ws-dl-group';
		details.open = !! open;

		var summary = document.createElement( 'summary' );
		summary.className = 'ws-dl-summary';

		var name = document.createElement( 'span' );
		name.className = 'ws-dl-group-name';
		name.textContent = group.name || ( '#' + group.id );
		summary.appendChild( name );

		if ( group.sku ) {
			var sku = document.createElement( 'span' );
			sku.className = 'ws-dl-group-sku';
			sku.textContent = group.sku;
			summary.appendChild( sku );
		}

		var count = document.createElement( 'span' );
		count.className = 'ws-dl-count';
		count.textContent = ( group.downloads || [] ).length;
		summary.appendChild( count );

		details.appendChild( summary );
		details.appendChild( buildDownloadList( group ) );
		return details;
	}

	function buildDownloadList( group ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'ws-dl-body';

		var meta = document.createElement( 'div' );
		meta.className = 'ws-dl-meta';
		meta.appendChild( metaChip(
			i18n.downloadLimit || 'محدودیت دانلود',
			group.limit < 0 ? ( i18n.unlimited || 'نامحدود' ) : String( group.limit )
		) );
		meta.appendChild( metaChip(
			i18n.downloadExpiry || 'انقضا (روز)',
			group.expiry < 0 ? ( i18n.noExpiry || 'بدون انقضا' ) : String( group.expiry )
		) );
		wrap.appendChild( meta );

		var list = document.createElement( 'div' );
		list.className = 'ws-dl-list';
		( group.downloads || [] ).forEach( function ( dl ) {
			list.appendChild( buildDownloadItem( dl ) );
		} );
		wrap.appendChild( list );

		if ( ( group.downloads || [] ).length > 1 ) {
			var all = document.createElement( 'button' );
			all.type = 'button';
			all.className = 'ws-mini-btn ws-dl-copy-all';
			all.textContent = '⧉ ' + ( i18n.copyAll || 'کپی همهٔ لینک‌ها' );
			all.addEventListener( 'click', function () {
				var text = group.downloads.map( function ( dl ) {
					return dl.file;
				} ).join( '\n' );
				runCopy( all, text, '⧉ ' + ( i18n.copyAll || 'کپی همهٔ لینک‌ها' ) );
			} );
			wrap.appendChild( all );
		}

		return wrap;
	}

	function buildDownloadItem( dl ) {
		var item = document.createElement( 'div' );
		item.className = 'ws-dl-item';

		var name = document.createElement( 'div' );
		name.className = 'ws-dl-name';
		name.textContent = dl.name || ( i18n.untitledFile || 'بدون نام' );

		var row = document.createElement( 'div' );
		row.className = 'ws-dl-row';

		var url = document.createElement( 'code' );
		url.className = 'ws-dl-url';
		url.dir = 'ltr';
		url.textContent = dl.file;
		// کلیک روی خود لینک، کل متن را انتخاب می‌کند تا کپی دستی هم ساده باشد.
		url.addEventListener( 'click', function () {
			var range = document.createRange();
			range.selectNodeContents( url );
			var sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange( range );
		} );

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'ws-mini-btn ws-dl-copy';
		btn.textContent = '⧉ ' + ( i18n.copy || 'کپی' );
		btn.addEventListener( 'click', function () {
			runCopy( btn, dl.file, '⧉ ' + ( i18n.copy || 'کپی' ) );
		} );

		row.appendChild( url );
		row.appendChild( btn );

		item.appendChild( name );
		item.appendChild( row );
		return item;
	}

	// کپی + بازخورد موقت روی خود دکمه.
	function runCopy( btn, text, resetLabel ) {
		copyText( text )
			.then( function () {
				btn.classList.add( 'is-copied' );
				btn.textContent = '✓ ' + ( i18n.copied || 'کپی شد' );
				setTimeout( function () {
					btn.classList.remove( 'is-copied' );
					btn.textContent = resetLabel;
				}, 1600 );
			} )
			.catch( function () {
				toast( i18n.copyFailed || 'کپی نشد', 'error' );
			} );
	}

	function metaChip( label, value ) {
		var chip = document.createElement( 'span' );
		chip.className = 'ws-dl-chip';
		chip.textContent = label + ': ' + value;
		return chip;
	}

	/* ---------------------- مودال کوپن ---------------------- */

	function openCouponModal( productData ) {
		var form = document.createElement( 'div' );
		form.className = 'ws-coupon-form';

		var codeInput = document.createElement( 'input' );
		codeInput.type = 'text';
		codeInput.className = 'woosheet-input';

		var typeSel = document.createElement( 'select' );
		typeSel.className = 'woosheet-input';
		[
			[ 'percent', i18n.couponPercent || 'درصدی' ],
			[ 'fixed_cart', i18n.couponFixedCart || 'مبلغ ثابت (سبد)' ],
			[ 'fixed_product', i18n.couponFixedProd || 'مبلغ ثابت (محصول)' ],
		].forEach( function ( pair ) {
			var o = document.createElement( 'option' );
			o.value = pair[ 0 ];
			o.textContent = pair[ 1 ];
			typeSel.appendChild( o );
		} );

		var amountInput = document.createElement( 'input' );
		amountInput.type = 'text';
		amountInput.className = 'woosheet-input';
		amountInput.setAttribute( 'inputmode', 'numeric' );
		amountInput.dir = 'ltr';

		var expiryInput = document.createElement( 'input' );
		expiryInput.type = 'text';
		expiryInput.readOnly = true;
		expiryInput.className = 'woosheet-input';
		expiryInput.placeholder = '—';
		expiryInput.addEventListener( 'click', function () {
			J.createCalendar( {
				value: expiryInput.value,
				anchorEl: expiryInput,
				onSelect: function ( val ) {
					expiryInput.value = val;
				},
			} );
		} );

		var usageInput = document.createElement( 'input' );
		usageInput.type = 'number';
		usageInput.min = '0';
		usageInput.className = 'woosheet-input';

		var restrictWrap = document.createElement( 'label' );
		restrictWrap.className = 'woosheet-check';
		var restrictChk = document.createElement( 'input' );
		restrictChk.type = 'checkbox';
		restrictChk.checked = true;
		var restrictText = document.createElement( 'span' );
		restrictText.textContent = ( i18n.couponRestrict || 'محدود به این محصول' ) + ' (' + ( productData.name || '' ) + ')';
		restrictWrap.appendChild( restrictChk );
		restrictWrap.appendChild( restrictText );

		form.appendChild( field( i18n.couponCode || 'کد تخفیف (خالی = تولید خودکار)', codeInput ) );
		form.appendChild( field( i18n.couponType || 'نوع تخفیف', typeSel ) );
		form.appendChild( field( i18n.couponAmount || 'مقدار', amountInput ) );
		form.appendChild( field( i18n.couponExpiry || 'تاریخ انقضا (شمسی)', expiryInput ) );
		form.appendChild( field( i18n.couponUsage || 'محدودیت تعداد استفاده', usageInput ) );
		form.appendChild( restrictWrap );

		var result = document.createElement( 'div' );
		result.className = 'ws-coupon-result';
		form.appendChild( result );

		var actions = document.createElement( 'div' );
		actions.className = 'woosheet-modal-actions';
		var submit = document.createElement( 'button' );
		submit.type = 'button';
		submit.className = 'button button-primary';
		submit.textContent = i18n.couponCreate || 'ساخت کوپن';
		actions.appendChild( submit );
		form.appendChild( actions );

		var modal = openModal( ( i18n.couponTitle || 'ساخت کد تخفیف' ), form );

		submit.addEventListener( 'click', function () {
			var amount = parseMoney( amountInput.value );
			if ( amount === '' || amount === null || Number( amount ) <= 0 ) {
				toast( i18n.requiredAmount || 'مقدار تخفیف را وارد کنید.', 'error' );
				return;
			}
			submit.disabled = true;
			api( 'coupons', {
				method: 'POST',
				body: {
					code: codeInput.value,
					discount_type: typeSel.value,
					amount: amount,
					date_expires: expiryInput.value,
					usage_limit: usageInput.value ? parseInt( usageInput.value, 10 ) : 0,
					restrict_to_product: restrictChk.checked,
					product_id: productData.id,
				},
			} )
				.then( function ( res ) {
					result.innerHTML = '<div class="ws-success">' + esc( i18n.couponCreated || 'کوپن ساخته شد:' ) + ' <strong>' + esc( res.code ) + '</strong>' +
						( res.edit_link ? ' — <a href="' + esc( res.edit_link ) + '" target="_blank" rel="noopener">' + esc( i18n.couponView || 'مشاهده' ) + '</a>' : '' ) + '</div>';
					toast( i18n.couponCreated + ' ' + res.code, 'success' );
					submit.disabled = false;
				} )
				.catch( function ( err ) {
					toast( err.message || i18n.saveError, 'error' );
					submit.disabled = false;
				} );
		} );
	}

	/* ---------------------- مودال ایمپورت ---------------------- */

	function openImportModal() {
		var wrap = document.createElement( 'div' );
		wrap.className = 'ws-import';

		var hint = document.createElement( 'p' );
		hint.className = 'ws-import-hint';
		hint.textContent = i18n.importHint || '';

		var fileInput = document.createElement( 'input' );
		fileInput.type = 'file';
		fileInput.accept = '.xlsx';
		fileInput.className = 'woosheet-input';

		var actions = document.createElement( 'div' );
		actions.className = 'woosheet-modal-actions';
		var validateBtn = document.createElement( 'button' );
		validateBtn.type = 'button';
		validateBtn.className = 'button';
		validateBtn.textContent = i18n.importValidate || 'بررسی فایل';
		var applyBtn = document.createElement( 'button' );
		applyBtn.type = 'button';
		applyBtn.className = 'button button-primary';
		applyBtn.textContent = i18n.importApply || 'اعمال تغییرات';
		applyBtn.disabled = true;
		actions.appendChild( validateBtn );
		actions.appendChild( applyBtn );

		var output = document.createElement( 'div' );
		output.className = 'ws-import-output';

		wrap.appendChild( hint );
		wrap.appendChild( fileInput );
		wrap.appendChild( actions );
		wrap.appendChild( output );

		openModal( i18n.importTitle || 'ورود از فایل اکسل', wrap, { wide: true } );

		function send( endpoint ) {
			if ( ! fileInput.files || ! fileInput.files[ 0 ] ) {
				toast( i18n.importPick || 'فایل را انتخاب کنید', 'error' );
				return Promise.reject();
			}
			var fd = new FormData();
			fd.append( 'file', fileInput.files[ 0 ] );
			return api( 'import/' + endpoint, { method: 'POST', form: fd } );
		}

		validateBtn.addEventListener( 'click', function () {
			validateBtn.disabled = true;
			output.innerHTML = '<div class="ws-loading">' + esc( i18n.loading || '…' ) + '</div>';
			send( 'validate' )
				.then( function ( report ) {
					renderImportReport( output, report );
					applyBtn.disabled = ( report.summary.updated === 0 );
					validateBtn.disabled = false;
				} )
				.catch( function ( err ) {
					validateBtn.disabled = false;
					if ( err && err.message ) {
						output.innerHTML = '<div class="ws-error">' + esc( err.message ) + '</div>';
					}
				} );
		} );

		applyBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( i18n.confirmApply || 'اعمال شود؟' ) ) {
				return;
			}
			applyBtn.disabled = true;
			output.innerHTML = '<div class="ws-loading">' + esc( i18n.loading || '…' ) + '</div>';
			send( 'commit' )
				.then( function ( report ) {
					renderImportReport( output, report );
					toast( ( i18n.sumUpdated || 'اعمال‌شده' ) + ': ' + report.summary.updated, 'success' );
					fetchPage( state.page );
				} )
				.catch( function ( err ) {
					applyBtn.disabled = false;
					if ( err && err.message ) {
						output.innerHTML = '<div class="ws-error">' + esc( err.message ) + '</div>';
					}
				} );
		} );

		fileInput.addEventListener( 'change', function () {
			applyBtn.disabled = true;
			output.innerHTML = '';
		} );
	}

	function renderImportReport( output, report ) {
		var s = report.summary;
		var html = '<div class="ws-summary">' +
			'<span class="ws-chip">' + esc( i18n.sumTotal || 'کل' ) + ': ' + s.total + '</span>' +
			'<span class="ws-chip ws-chip-ok">' + esc( i18n.sumUpdated || 'اعمال' ) + ': ' + s.updated + '</span>' +
			'<span class="ws-chip">' + esc( i18n.sumNoChange || 'بدون تغییر' ) + ': ' + s.no_change + '</span>' +
			'<span class="ws-chip ws-chip-warn">' + esc( i18n.sumSkipped || 'نادیده' ) + ': ' + s.skipped + '</span>' +
			'<span class="ws-chip ws-chip-err">' + esc( i18n.sumErrors || 'خطا' ) + ': ' + s.errors + '</span>' +
			'</div>';

		html += '<table class="ws-import-table"><thead><tr>' +
			'<th>' + esc( i18n.row || 'ردیف' ) + '</th>' +
			'<th>' + esc( i18n.colId || 'شناسه' ) + '</th>' +
			'<th>' + esc( i18n.status || 'وضعیت' ) + '</th>' +
			'<th>' + esc( i18n.message || 'پیام' ) + '</th>' +
			'</tr></thead><tbody>';

		report.results.forEach( function ( r ) {
			var cls = 'ws-row-' + r.status;
			var fieldsTxt = r.fields && r.fields.length ? ' (' + r.fields.map( esc ).join( '، ' ) + ')' : '';
			html += '<tr class="' + cls + '">' +
				'<td>' + esc( r.row ) + '</td>' +
				'<td>' + esc( r.id || '—' ) + '</td>' +
				'<td>' + esc( statusLabel( r.status ) ) + '</td>' +
				'<td>' + esc( r.message ) + fieldsTxt + '</td>' +
				'</tr>';
		} );
		html += '</tbody></table>';
		output.innerHTML = html;
	}

	function statusLabel( status ) {
		var map = {
			updated: i18n.sumUpdated || 'اعمال',
			no_change: i18n.sumNoChange || 'بدون تغییر',
			skipped: i18n.sumSkipped || 'نادیده',
			error: i18n.sumErrors || 'خطا',
		};
		return map[ status ] || status;
	}

	/* ---------------------- راه‌اندازی ---------------------- */

	function buildToolbarExtras() {
		var tools = document.querySelector( '.woosheet-tools' );
		if ( ! tools ) {
			return;
		}

		// مرتب‌سازی.
		var sortSel = document.createElement( 'select' );
		sortSel.className = 'woosheet-select';
		sortSel.id = 'woosheet-sort';
		[
			[ 'id', i18n.colId || 'شناسه' ],
			[ 'name', i18n.colName || 'نام' ],
			[ 'price', i18n.colRegular || 'قیمت' ],
			[ 'stock', i18n.colStock || 'موجودی' ],
			[ 'downloadable', i18n.colDownloadable || 'دانلودی' ],
			[ 'modified', i18n.sortNewest || 'جدیدترین' ],
		].forEach( function ( p ) {
			var o = document.createElement( 'option' );
			o.value = p[ 0 ];
			o.textContent = '↕ ' + p[ 1 ];
			sortSel.appendChild( o );
		} );
		sortSel.value = state.orderby;
		sortSel.addEventListener( 'change', function () {
			state.orderby = sortSel.value;
			fetchPage( 1 );
		} );

		var orderBtn = document.createElement( 'button' );
		orderBtn.type = 'button';
		orderBtn.className = 'button woosheet-order-btn';
		orderBtn.textContent = state.order === 'asc' ? '▲' : '▼';
		orderBtn.addEventListener( 'click', function () {
			state.order = state.order === 'asc' ? 'desc' : 'asc';
			orderBtn.textContent = state.order === 'asc' ? '▲' : '▼';
			fetchPage( 1 );
		} );

		// دکمهٔ بازگردانی تغییر قبلی.
		var undoBtn = document.createElement( 'button' );
		undoBtn.type = 'button';
		undoBtn.id = 'woosheet-undo';
		undoBtn.className = 'button';
		undoBtn.textContent = '↶ ' + ( i18n.undo || 'بازگردانی تغییر قبلی' );
		undoBtn.disabled = true;
		undoBtn.addEventListener( 'click', applyUndo );

		// درج قبل از دکمهٔ بارگذاری مجدد.
		var reload = document.getElementById( 'woosheet-reload' );
		tools.insertBefore( sortSel, reload );
		tools.insertBefore( orderBtn, reload );
		tools.insertBefore( undoBtn, reload );
	}

	function setupFilters() {
		var catSel = document.getElementById( 'woosheet-filter-cat' );
		var statusSel = document.getElementById( 'woosheet-filter-status' );

		if ( catSel ) {
			var optAll = document.createElement( 'option' );
			optAll.value = '';
			optAll.textContent = i18n.allCategories || 'همهٔ دسته‌ها';
			catSel.appendChild( optAll );
			( cfg.categories || [] ).forEach( function ( c ) {
				var o = document.createElement( 'option' );
				o.value = c.id;
				o.textContent = c.name;
				catSel.appendChild( o );
			} );
			catSel.addEventListener( 'change', function () {
				state.category = parseInt( catSel.value, 10 ) || 0;
				fetchPage( 1 );
			} );
		}

		if ( statusSel ) {
			var sAll = document.createElement( 'option' );
			sAll.value = '';
			sAll.textContent = i18n.allStatuses || 'همهٔ وضعیت‌ها';
			statusSel.appendChild( sAll );
			var statusOpts = ( cfg.options && cfg.options.status ) || {};
			Object.keys( statusOpts ).forEach( function ( k ) {
				var o = document.createElement( 'option' );
				o.value = k;
				o.textContent = statusOpts[ k ];
				statusSel.appendChild( o );
			} );
			statusSel.addEventListener( 'change', function () {
				state.status = statusSel.value;
				fetchPage( 1 );
			} );
		}
	}

	function setupSearch() {
		var input = document.getElementById( 'woosheet-search' );
		if ( ! input ) {
			return;
		}
		var timer = null;
		input.addEventListener( 'input', function () {
			clearTimeout( timer );
			timer = setTimeout( function () {
				state.search = input.value.trim();
				fetchPage( 1 );
			}, 250 );
		} );
		// جست‌وجوی فوری با Enter.
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				clearTimeout( timer );
				state.search = input.value.trim();
				fetchPage( 1 );
			}
		} );
	}

	/* ---------------------- مدیریت نمایش ستون‌ها ---------------------- */

	var STORAGE_COLS = 'woosheet_hidden_cols_v1';

	function getHiddenCols() {
		try {
			var v = JSON.parse( window.localStorage.getItem( STORAGE_COLS ) );
			return Array.isArray( v ) ? v : [];
		} catch ( e ) {
			return [];
		}
	}

	function setHiddenCols( arr ) {
		try {
			window.localStorage.setItem( STORAGE_COLS, JSON.stringify( arr ) );
		} catch ( e ) {}
	}

	// ستون‌هایی که همیشه باید بمانند.
	function isLockedCol( field ) {
		return field === 'id';
	}

	function setupColumnToggle() {
		var tools = document.querySelector( '.woosheet-tools' );
		if ( ! tools || ! table ) {
			return;
		}

		var wrap = document.createElement( 'div' );
		wrap.className = 'ws-colmenu';

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'button';
		btn.textContent = '▦ ' + ( i18n.columns || 'ستون‌ها' );

		var panel = document.createElement( 'div' );
		panel.className = 'ws-col-panel';
		panel.hidden = true;

		var hidden = getHiddenCols();

		table.getColumns().forEach( function ( col ) {
			var f = col.getField();
			if ( ! f ) {
				return;
			}
			var def = col.getDefinition() || {};
			var title = def.title || f;

			// اعمال وضعیت ذخیره‌شده هنگام ساخت.
			if ( ! isLockedCol( f ) && hidden.indexOf( f ) >= 0 ) {
				col.hide();
			}

			var lbl = document.createElement( 'label' );
			lbl.className = 'ws-col-toggle';
			var cb = document.createElement( 'input' );
			cb.type = 'checkbox';
			cb.checked = isLockedCol( f ) ? true : hidden.indexOf( f ) < 0;
			cb.disabled = isLockedCol( f );
			cb.addEventListener( 'change', function () {
				var h = getHiddenCols();
				if ( cb.checked ) {
					col.show();
					h = h.filter( function ( x ) {
						return x !== f;
					} );
				} else {
					col.hide();
					if ( h.indexOf( f ) < 0 ) {
						h.push( f );
					}
				}
				setHiddenCols( h );
			} );
			lbl.appendChild( cb );
			lbl.appendChild( document.createTextNode( ' ' + ( title || f ) ) );
			panel.appendChild( lbl );
		} );

		btn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			panel.hidden = ! panel.hidden;
		} );
		document.addEventListener( 'click', function ( e ) {
			if ( ! wrap.contains( e.target ) ) {
				panel.hidden = true;
			}
		} );

		wrap.appendChild( btn );
		wrap.appendChild( panel );

		var reload = document.getElementById( 'woosheet-reload' );
		tools.insertBefore( wrap, reload );
	}

	function setupButtons() {
		var reload = document.getElementById( 'woosheet-reload' );
		if ( reload ) {
			reload.addEventListener( 'click', function () {
				fetchPage( state.page );
			} );
		}
		var importBtn = document.getElementById( 'woosheet-import-open' );
		if ( importBtn ) {
			importBtn.addEventListener( 'click', openImportModal );
		}
	}

	function initGrid() {
		table = new Tabulator( '#woosheet-grid', {
			data: [],
			index: 'id',
			layout: 'fitDataStretch',
			textDirection: 'rtl',
			height: 'calc(100vh - 240px)',
			placeholder: i18n.noData || 'موردی نیست',
			reactiveData: false,
			columns: buildColumns(),
		} );

		table.on( 'cellEdited', onCellEdited );

		table.on( 'tableBuilt', function () {
			setupColumnToggle();
			fetchPage( 1 );
		} );
	}

	function init() {
		if ( typeof Tabulator === 'undefined' ) {
			setStatus( i18n.gridFailed || 'جدول بارگذاری نشد.' );
			return;
		}
		setupSearch();
		setupFilters();
		buildToolbarExtras();
		setupButtons();
		initGrid();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
