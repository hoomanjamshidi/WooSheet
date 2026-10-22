# WooSheet — راهنمای کار روی این مخزن

افزونهٔ وردپرس/ووکامرس که محصولات را در یک شیت شبیه اکسل مدیریت می‌کند.
رابط کاربری کاملاً **راست‌چین و فارسی** است و همهٔ منابع (فونت، Tabulator، تقویم) **محلی**‌اند.

## ساختار

کد افزونه داخل پوشهٔ `woosheet/` است (فایل‌های `woosheet*.zip` در ریشه فقط بسته‌های نسخه‌های قبلی‌اند؛ دست نزن).

```
woosheet/
├── woosheet.php                  بوت‌استرپ: ثابت‌ها، بررسی فعال‌بودن ووکامرس، require ترتیبی، WooSheet::instance()
├── includes/
│   ├── class-woosheet.php              سینگلتون + WooSheet::capability() (فیلتر woosheet_capability، پیش‌فرض manage_woocommerce)
│   ├── class-woosheet-admin.php        منو، enqueue، wp_localize_script('WooSheetData'), همهٔ رشته‌های i18n، خروجی اکسل
│   ├── class-woosheet-rest.php         ثبت روت‌های REST در namespace woosheet/v1
│   ├── class-woosheet-products-controller.php  لیست/مرتب‌سازی/متغیرها/ویژگی‌ها/دانلودها/batch
│   ├── class-woosheet-coupons-controller.php   ساخت کوپن
│   ├── class-woosheet-product-mapper.php       ⭐ نگاشت محصول ↔ ردیف + اعتبارسنجی و اعمال تغییرات
│   ├── class-woosheet-export.php       تعریف ستون‌های xlsx (منبع مشترک export/import) + استریم دانلود
│   ├── class-woosheet-import.php       اعتبارسنجی و اعمال فایل xlsx
│   ├── class-woosheet-xlsx.php         خواندن/نوشتن xlsx بدون کتابخانهٔ بیرونی
│   └── class-woosheet-jalali.php       تبدیل شمسی↔میلادی و نرمال‌سازی ارقام فارسی/عربی
├── templates/admin-page.php      اسکلت صفحه (toolbar، #woosheet-grid، #woosheet-modal-root، #woosheet-toasts)
└── assets/js/woosheet.js         ⭐ کل منطق UI در یک IIFE
```

## قواعدی که باید رعایت شوند

**نوشتن در محصول فقط از راه WooCommerce CRUD.** همیشه `set_*()` + `save()`؛ هرگز `update_post_meta` مستقیم.
بعد از تغییر قیمت/موجودیِ یک متغیر یا والد، `WC_Product_Variable::sync( $parent_id )` صدا زده شود
(الگو در انتهای `WooSheet_Product_Mapper::apply_updates()`).

**اعتبارسنجی سخت‌گیرانه است و باید بماند:** قیمت تخفیفی باید از قیمت اصلی کمتر باشد، تخفیف بدون قیمت اصلی
ممنوع، `sale_from <= sale_to`، قیمتِ محصول متغیر فقط از مسیر متغیرها. همهٔ خطاها `WP_Error` با
`array( 'status' => 4xx )` برمی‌گردانند و پیامشان مستقیم در toast نمایش داده می‌شود — پس فارسی و قابل‌فهم بنویس.

**زبان و سبک:** همهٔ کامنت‌ها و رشته‌های کاربر فارسی‌اند. PHP با استاندارد WordPress (تب، `array()`، Yoda،
docblock فارسی برای هر متد). JS به سبک ES5 (بدون `let`/arrow/template literal) چون همان سبک کل فایل است.

**ورودی عددی کاربر همیشه از `WooSheet_Jalali::normalize_digits()` رد شود** (ارقام فارسی/عربی + جداکنندهٔ هزارگان).

**افزودن فیلد جدید به ردیف:** هم در `product_to_row()` و هم در `variation_to_row()` اضافه‌اش کن.
ستون‌های اکسل مستقل‌اند و از `WooSheet_Export::columns()` می‌آیند، پس افزودن فیلد به ردیف، خروجی اکسل را
تغییر نمی‌دهد (اگر خواستی در اکسل هم بیاید، باید آنجا جدا اضافه شود و مراقب باش import با آن سازگار بماند).

**افزودن ستون جدید به شیت:** یک عضو در آرایهٔ `buildColumns()` در `woosheet.js` + یک کلید `col*` در
`WooSheet_Admin::get_i18n()`. ستون‌های اکشن با فیلد مجازی (`_coupon`، `_view`، `_downloads`) و
`headerSort: false` ساخته می‌شوند؛ همهٔ مرتب‌سازی **سمت سرور** است و از `select#woosheet-sort` در
`buildToolbarExtras()` کنترل می‌شود، نه از هدر جدول.

**افزودن روت REST:** متد را در کنترلر بنویس، بعد در `class-woosheet-rest.php` ثبت کن؛ `permission_callback`
همیشه `$perm` باشد. سمت JS با `api( 'path', { method, body } )` صدا زده می‌شود — این تابع خودش
`X-WP-Nonce` را می‌گذارد و خطا را به `Error` با پیام سرور تبدیل می‌کند.

**ذخیره‌سازی سلول:** از `enqueue( id, fn )` استفاده کن تا درخواست‌های همزمانِ یک محصول صف شوند،
و بعد از موفقیت `row.update( res.row )` + `flashCell()`.

## نکات دانلودی‌ها

- `downloadable` و `download_count` روی هر ردیف می‌نشینند. برای محصول **ساده** از خود محصول خوانده می‌شوند؛
  برای محصول **متغیر** از متغیرهایش (محصول متغیر در ووکامرس خودش `_downloadable` ندارد).
- برای جلوگیری از N+1، کنترلر قبل از ساخت ردیف‌ها `WooSheet_Product_Mapper::prime_variation_downloads()` را
  با شناسهٔ همهٔ پست‌های صفحه صدا می‌زند (یک کوئری برای کل صفحه). کش استاتیک و در حد یک ریکوئست است.
- مرتب‌سازی `orderby=downloadable` با متا ممکن نیست (چون والدِ متغیر مقدار درست ندارد)، پس با فیلتر
  `posts_clauses` و یک `CASE ... EXISTS` انجام می‌شود — `downloadable_orderby_filter()`. فیلتر با
  query var نشانه‌گذاری‌شدهٔ `woosheet_downloadable` فقط روی همان کوئری اثر می‌کند و بلافاصله حذف می‌شود.
- `GET woosheet/v1/products/{id}/downloads` لینک‌ها را برمی‌گرداند؛ برای محصول متغیر به تفکیک هر متغیر
  (`groups[]`). مودال سمت کلاینت برای بیش از یک گروه آکاردئون (`<details>`) می‌سازد.
- کپی در کلیپ‌بورد از `copyText()` استفاده می‌کند که در اتصال‌های `http` (بدون `isSecureContext`) به
  `execCommand('copy')` برمی‌گردد — این fallback را حذف نکن؛ خیلی از پنل‌های ادمین روی http هستند.

## تست و اجرا

اینجا محیط وردپرس نصب نیست و تست خودکاری وجود ندارد. حداقل بررسی‌های ممکن:

```bash
cd woosheet
for f in woosheet.php includes/*.php templates/*.php; do php -l "$f"; done
node --check assets/js/woosheet.js
```

برای تست واقعی باید پوشهٔ `woosheet/` در `wp-content/plugins/` یک نصب ووکامرس قرار بگیرد.
نسخهٔ فایل‌های استاتیک با `filemtime` کش‌شکنی می‌شود، پس بعد از ویرایش JS/CSS نیازی به تغییر
`WOOSHEET_VERSION` نیست.

## کارهایی که نباید بکنی

- فایل‌های `assets/js/tabulator.min.js` و `assets/css/tabulator.min.css` را ویرایش نکن (وندور).
- منبع بیرونی (CDN، فونت گوگل) اضافه نکن؛ همه‌چیز باید محلی بماند.
- زیپ‌های ریشهٔ مخزن را به‌عنوان کد مرجع نخوان؛ `woosheet/` منبع حقیقت است.
