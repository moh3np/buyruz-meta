<?php
declare(strict_types=1);

/**
 * Build static HTML pages (index + brands/tags/categories/attributes)
 * Audience: LLMs/agents (ماشین)، نه انسان.
 * - Decodes URL-encoded Persian
 * - Parses JSON cells to readable Persian
 * - Unified UI with top navigation & sticky headers
 * Run: /refresh.php?secret=YOUR_SECRET
 * Optional: &only=brands | tags | categories | attributes
 */

//////////////////////
// CONFIG
//////////////////////
const SPREADSHEET_ID = '1LsM7opLNqYuirVM20rEBZwOjaPDNlaxC1bZpZtDbkck';
const ENDPOINTS = [
  'brands'     => 120861559,
  'tags'       => 1888622902,
  'categories' => 449911808,
  'attributes' => 1260611769,
  'products'   => 26471258,
];
const TAXONOMY_JSON = 'taxonomy.json';

// خروجی‌ها در همین دایرکتوری ساخته می‌شوند
const OUTPUT_DIR = __DIR__;

// سکرت (URL-safe) — جایگزین کن
const SECRET = 'kpWJf1vJmS3x9Q7rB2z8Nn4aDyUE0cL6hP_A-9m';

// تایم‌زون و تایم‌اوت
const TZ_TEHRAN = 'Asia/Tehran';
const TIMEOUT   = 12;
const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
const JSON_STALE_AFTER_SECONDS = 600; // اگر سن فایل از این مقدار بیشتر شد، هشدار بده
const FAVICON_DATA_URI = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA2NCA2NCI+CiAgPGRlZnM+CiAgICA8bGluZWFyR3JhZGllbnQgaWQ9ImciIHgxPSIwIiB5MT0iNjQiIHgyPSI2NCIgeTI9IjAiIGdyYWRpZW50VW5pdHM9InVzZXJTcGFjZU9uVXNlIj4KICAgICAgPHN0b3Agb2Zmc2V0PSIwIiBzdG9wLWNvbG9yPSIjMGVhNWU5Ii8+CiAgICAgIDxzdG9wIG9mZnNldD0iMSIgc3RvcC1jb2xvcj0iIzFmMjkzNyIvPgogICAgPC9saW5lYXJHcmFkaWVudD4KICA8L2RlZnM+CiAgPHJlY3Qgd2lkdGg9IjY0IiBoZWlnaHQ9IjY0IiByeD0iMTQiIHJ5PSIxNCIgZmlsbD0idXJsKCNnKSIvPgogIDxwYXRoIGZpbGw9IiNmZmZmZmYiIGQ9Ik0yMCAxNmgxNGM2IDAgMTAgNCAxMCA5YzAgNC0yLjIgNi44LTUgOGMzLjIgMSA1IDQgNSA3LjhjMCA2LTQgOS4yLTEwLjggOS4ySDIwVjE2em04IDEzLjhoNmMyIDAgNC0xLjYgNC00cy0yLTQtNC00aC02djh6bTAgMTguNGg3YzIuNiAwIDQuNS0xLjggNC41LTQuNXMtMS45LTQuNS00LjUtNC41aC03djl6Ii8+CiAgPHJlY3QgeD0iMTYuNSIgeT0iMTYuNSIgd2lkdGg9IjMxIiBoZWlnaHQ9IjMxIiByeD0iOCIgcnk9IjgiIGZpbGw9Im5vbmUiIHN0cm9rZT0icmdiYSgyNTUsMjU1LDI1NSwwLjE4KSIgc3Ryb2tlLXdpZHRoPSIyIi8+Cjwvc3ZnPgo=';

@date_default_timezone_set('UTC');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// احراز هویت ساده
$is_cli = (php_sapi_name() === 'cli'); // تشخیص اینکه آیا از طریق کران/ترمینال اجرا شده؟
$secret = $_GET['secret'] ?? '';

// اگر از طریق مرورگر بود (CLI نبود) و رمز غلط بود، خطا بده
if (!$is_cli && !hash_equals(SECRET, (string)$secret)) {
  http_response_code(403);
  echo json_encode(['error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
  exit;
}

$only = $_GET['only'] ?? null;
$targets = ENDPOINTS;
if ($only && isset($targets[$only])) $targets = [$only => ENDPOINTS[$only]];

$results = [];
$stats   = [];
$jsonPayloads = [];
$runTimestampIso = now_iso8601();

foreach ($targets as $name => $gid) {
  $csvUrl = "https://docs.google.com/spreadsheets/d/" . SPREADSHEET_ID . "/export?format=csv&gid={$gid}";
  $csv = http_get($csvUrl, TIMEOUT);
  if ($csv === null) { $results[$name] = 'fetch_failed'; continue; }

  $rows = csv_to_assoc($csv);

  // 🔧 نرمال‌سازی (URL-decode + JSON pretty)
  foreach ($rows as &$r) {
    foreach ($r as $k => $v) $r[$k] = prettify_cell($v);
  } unset($r);

  $titleMap = [
    'brands'     => 'لیست نام برندها',
    'tags'       => 'لیست تگ‌ها',
    'categories' => 'لیست دسته‌بندی‌ها',
    'attributes' => 'لیست ویژگی‌ها',
    'products'   => 'لیست محصولات',
  ];
  $title = $titleMap[$name] ?? ('Snapshot: '.$name);
  $updatedAtDisplay = now_tehran_string();

  $htmlOk = atomic_write(
    OUTPUT_DIR . "/{$name}.html",
    render_table_page($name, $title, $rows, $updatedAtDisplay, $runTimestampIso)
  );

  $jsonOk = true;
  $jsonPayload = build_json_payload($name, $rows, $runTimestampIso, $updatedAtDisplay);
  if ($jsonPayload !== null) {
    $jsonContent = json_encode($jsonPayload, JSON_FLAGS);
    if ($jsonContent === false) {
      $jsonOk = false;
    } else {
      $jsonOk = atomic_write(OUTPUT_DIR . "/{$name}.json", $jsonContent);
      if ($jsonOk) $jsonPayloads[$name] = $jsonPayload; // later used to build taxonomy.json exactly from per-file payloads
    }
  }

  if ($htmlOk && $jsonOk) {
    $results[$name] = 'ok';
  } elseif (!$htmlOk) {
    $results[$name] = 'write_failed';
  } else {
    $results[$name] = 'json_write_failed';
  }

  $stats[$name] = [
    'count'             => count($rows),
    'updated_at_display' => $updatedAtDisplay,
    'updated_at_iso'     => $runTimestampIso,
  ];
}

$indexDisplay = now_tehran_string();
$indexIso      = now_iso8601();

// all-in-one JSON (only when همهٔ دیتاست‌ها موجود باشند)
$taxonomyOk = false;
if (count($jsonPayloads) === count(ENDPOINTS)) {
  $unifiedPayload = build_unified_snapshot_from_payloads($jsonPayloads, $indexIso, $indexDisplay);
  if ($unifiedPayload !== null) {
    $unifiedJson = json_encode($unifiedPayload, JSON_FLAGS);
    if ($unifiedJson !== false) $taxonomyOk = atomic_write(OUTPUT_DIR . '/' . TAXONOMY_JSON, $unifiedJson);
  }
  $results['taxonomy'] = $taxonomyOk ? 'ok' : 'json_write_failed';
} else {
  $results['taxonomy'] = 'skipped_partial';
}

// index.html
atomic_write(OUTPUT_DIR . '/index.html', render_index_page($stats, $indexDisplay, $indexIso));

// --- تغییر برای دانلود خودکار ---

// اگر اجرا توسط مرورگر (انسان) بود
if (!$is_cli) {
    $filePath = OUTPUT_DIR . '/' . TAXONOMY_JSON;

    if (file_exists($filePath)) {
        // هدرهای لازم برای دانلود اجباری
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream'); // نوع فایل برای دانلود
        header('Content-Disposition: attachment; filename="taxonomy.json"'); // اسم فایل دانلودی
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        
        // پاک کردن بافر خروجی (برای جلوگیری از خرابی فایل دانلودی)
        if (ob_get_length()) ob_clean();
        flush();
        
        // خواندن و ارسال فایل به کاربر
        readfile($filePath);
        exit;
    } else {
        // اگر به هر دلیلی فایل ساخته نشد
        echo "خطا: فایل taxonomy.json ساخته نشد. لطفاً لاگ را بررسی کنید.";
        exit;
    }
}

// اگر اجرا توسط کران‌جاب (سرور) بود، همان خروجی JSON را بده
echo json_encode(['ok' => true, 'results' => $results, 'time' => gmdate('c')], JSON_FLAGS);
exit;

/* ================= helpers ================= */

function http_get(string $url, int $timeout): ?string {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => $timeout,
      CURLOPT_TIMEOUT        => $timeout,
      CURLOPT_USERAGENT      => 'Buyruz HTML Snapshot Builder',
      CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($res !== false && $code >= 200 && $code < 300) ? $res : null;
  }
  $ctx = stream_context_create([
    'http' => [
      'method'        => 'GET',
      'header'        => "User-Agent: Buyruz HTML Snapshot Builder\r\n",
      'timeout'       => $timeout,
      'ignore_errors' => true,
    ]
  ]);
  $res = @file_get_contents($url, false, $ctx);
  if ($res === false) return null;
  global $http_response_header;
  if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
    $code = (int)$m[1];
    if ($code >= 200 && $code < 300) return $res;
  }
  return null;
}

function csv_to_assoc(string $csv): array {
  if (substr($csv, 0, 3) === "\xEF\xBB\xBF") $csv = substr($csv, 3);
  $fh = fopen('php://memory', 'r+'); fwrite($fh, $csv); rewind($fh);
  $headers = null; $rows = [];
  while (($data = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
    $data = array_map(static fn($v) => ensure_utf8(trim((string)$v)), $data);
    if ($headers === null) { $headers = $data; continue; }
    $row = [];
    foreach ($headers as $i => $key) {
      $k = $key !== '' ? $key : "col{$i}";
      $row[$k] = $data[$i] ?? '';
    }
    if (implode('', $row) === '') continue;
    $rows[] = $row;
  }
  fclose($fh);
  return $rows;
}

function ensure_utf8(string $s): string {
  if ($s === '') return $s;
  if (!mb_detect_encoding($s, 'UTF-8', true)) $s = mb_convert_encoding($s, 'UTF-8', 'auto');
  return $s;
}

function prettify_cell(string $v): string {
  $v = trim($v);
  if ($v === '') return $v;

  // URL-encoded → Persian
  if (strpos($v, '%') !== false && preg_match('/%(?:[0-9A-Fa-f]{2})/', $v)) {
    $decoded = @rawurldecode($v);
    if ($decoded !== '' && preg_match('/[\x{0600}-\x{06FF}]/u', $decoded)) $v = $decoded;
  }

  // JSON → متن خوانا
  if (($v[0] === '[' && substr($v, -1) === ']') || ($v[0] === '{' && substr($v, -1) === '}')) {
    $json = json_decode($v, true);
    if (json_last_error() === JSON_ERROR_NONE) {
      if (is_array($json)) {
        if (array_keys($json) === range(0, count($json)-1)) {
          $parts = [];
          foreach ($json as $item) {
            if (is_array($item)) $item = implode(' / ', array_map('strval', $item));
            $parts[] = (string)$item;
          }
          $v = implode('، ', $parts);
        } else {
          $parts = [];
          foreach ($json as $k => $val) {
            if (is_array($val)) $val = implode(' / ', array_map('strval', $val));
            $parts[] = "{$k}: {$val}";
          }
          $v = implode('؛ ', $parts);
        }
      } else {
        $v = (string)$json;
      }
    }
  }

  return ensure_utf8($v);
}

function now_tehran_string(): string {
  $tz  = new DateTimeZone(TZ_TEHRAN);
  $dt  = new DateTime('now', $tz);
  $off = $tz->getOffset($dt);
  $sign = $off >= 0 ? '+' : '-';
  $abs  = abs($off);
  $hh   = str_pad((string) intdiv($abs, 3600), 2, '0', STR_PAD_LEFT);
  $mm   = str_pad((string) intdiv($abs % 3600, 60), 2, '0', STR_PAD_LEFT);
  return $dt->format('Y-m-d H:i:s') . " ({$sign}{$hh}:{$mm})";
}

function now_iso8601(): string {
  $tz = new DateTimeZone(TZ_TEHRAN);
  $dt = new DateTime('now', $tz);
  return $dt->format(DATE_ATOM);
}

function tehran_offset_seconds(): int {
  $tz  = new DateTimeZone(TZ_TEHRAN);
  $dt  = new DateTime('now', $tz);
  return $tz->getOffset($dt);
}

function iso_to_epoch(string $iso): ?int {
  try {
    $dt = new DateTime($iso);
    return $dt->getTimestamp();
  } catch (Exception $e) {
    return null;
  }
}

function relative_elapsed_label(string $iso): array {
  try {
    $tz = new DateTimeZone(TZ_TEHRAN);
    $target = new DateTime($iso, $tz);
    $now = new DateTime('now', $tz);
  } catch (Exception $e) {
    return ['seconds' => null, 'label' => ''];
  }
  $diff = $now->getTimestamp() - $target->getTimestamp();
  if ($diff < 0) $diff = 0;
  $units = [
    ['limit' => 60,        'div' => 1,       'label' => 'ثانیه'],
    ['limit' => 3600,      'div' => 60,      'label' => 'دقیقه'],
    ['limit' => 86400,     'div' => 3600,    'label' => 'ساعت'],
    ['limit' => 2592000,   'div' => 86400,   'label' => 'روز'],
    ['limit' => 31104000,  'div' => 2592000, 'label' => 'ماه'],
    ['limit' => PHP_INT_MAX, 'div' => 31104000, 'label' => 'سال'],
  ];
  $chosen = $units[count($units) - 1];
  foreach ($units as $unit) {
    if ($diff < $unit['limit']) { $chosen = $unit; break; }
  }
  $value = (int) round($diff / $chosen['div']);
  $human = $value . ' ' . $chosen['label'] . ' پیش';
  return ['seconds' => $diff, 'label' => $human];
}

function atomic_write(string $file, string $content): bool {
  $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
  $ok  = @file_put_contents($tmp, $content);
  if ($ok === false) { @unlink($tmp); return false; }
  if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
  @chmod($file, 0644);
  return true;
}

/* ================= JSON Builders ================= */

function build_structured_dataset(string $name, array $rows, string $timestampIso, string $timestampHumanTehran): ?array {
  $base = [
    'updated_at'        => $timestampIso,
    'updated_at_tehran' => $timestampHumanTehran,
  ];

  switch ($name) {
    case 'brands':
      $items = build_brand_items($rows);
      return array_merge($base, [
        'count' => count($items),
        'items' => $items,
      ]);
    case 'tags':
      $items = build_tag_items($rows);
      return array_merge($base, [
        'count' => count($items),
        'items' => $items,
      ]);
    case 'categories':
      [$items, $tree] = build_category_items_and_tree($rows);
      return array_merge($base, [
        'count' => count($items),
        'items' => $items,
        'tree'  => $tree,
      ]);
    case 'attributes':
      $items = build_attribute_items($rows);
      return array_merge($base, [
        'count' => count($items),
        'items' => $items,
      ]);
    default:
      return null;
  }
}

function build_unified_snapshot(array $datasets, string $timestampIso, string $timestampHumanTehran): ?array {
  if (empty($datasets)) return null;
  $meta = [
    'generated_at_iso8601'           => $timestampIso,
    'generated_at_epoch_seconds_utc' => iso_to_epoch($timestampIso),
    'generated_at_tehran_human'      => $timestampHumanTehran,
    'timezone'                       => TZ_TEHRAN,
    'timezone_offset_seconds'        => tehran_offset_seconds(),
    'stale_after_seconds'            => JSON_STALE_AFTER_SECONDS,
    'stale_after_minutes'            => (int) ceil(JSON_STALE_AFTER_SECONDS / 60),
    'note'                           => 'یک لینک واحد برای ایجنت‌ها (brands/tags/categories/attributes).',
  ];

  $summary = [];
  foreach ($datasets as $key => $payload) {
    if (isset($payload['count'])) $summary[$key] = $payload['count'];
  }

  return [
    'meta' => $meta,
    'usage_notes' => unified_usage_notes(),
    'source_files' => [
      'brands'     => 'brands.json',
      'tags'       => 'tags.json',
      'categories' => 'categories.json',
      'attributes' => 'attributes.json',
    ],
    'summary_counts' => $summary,
    'datasets' => $datasets,
  ];
}

/**
 * Build unified snapshot directly from per-file JSON payloads to ensure
 * taxonomy.json fields match the individual dataset JSONs exactly.
 */
function build_unified_snapshot_from_payloads(array $payloads, string $timestampIso, string $timestampHumanTehran): ?array {
  if (empty($payloads)) return null;

  $meta = [
    'generated_at_iso8601'           => $timestampIso,
    'generated_at_epoch_seconds_utc' => iso_to_epoch($timestampIso),
    'generated_at_tehran_human'      => $timestampHumanTehran,
    'timezone'                       => TZ_TEHRAN,
    'timezone_offset_seconds'        => tehran_offset_seconds(),
    'stale_after_seconds'            => JSON_STALE_AFTER_SECONDS,
    'stale_after_minutes'            => (int) ceil(JSON_STALE_AFTER_SECONDS / 60),
    'note'                           => 'یک لینک واحد برای ایجنت‌ها (brands/tags/categories/attributes).',
  ];

  $summary = [];
  foreach ($payloads as $key => $payload) {
    if (isset($payload['rows']) && is_array($payload['rows'])) {
      $summary[$key] = count($payload['rows']);
    }
  }

  return [
    'meta' => $meta,
    'usage_notes' => unified_usage_notes(),
    'source_files' => [
      'brands'     => 'brands.json',
      'tags'       => 'tags.json',
      'categories' => 'categories.json',
      'attributes' => 'attributes.json',
      'products'   => 'products.json',
    ],
    'summary_counts' => $summary,
    'datasets' => $payloads,
  ];
}

function unified_usage_notes(): array {
  return [
    'برند/تگ/دسته جدید نساز؛ از مقادیر موجود استفاده کن.',
    'ویژگی جدید فقط در صورت نیاز و پس از بررسی هم‌معنی‌ها اضافه شود.',
    'هدف: دادهٔ یکپارچه و بدون پراکندگی.',
  ];
}

function build_json_payload(string $name, array $rows, string $timestampIso, string $timestampHumanTehran): ?array {
  // دادهٔ داینامیک شیت + متادیتای زمان (برای تشخیص قدمت فایل توسط ایجنت‌ها)
  $epoch = iso_to_epoch($timestampIso);
  return [
    'updated_at_iso8601'           => $timestampIso,              // زمان ساخت (ISO/Tehran)
    'updated_at_epoch_seconds_utc' => $epoch,                     // یونیکس ثانیه (UTC) — برای محاسبهٔ سن در لحظهٔ خواندن
    'updated_at_tehran_human'      => $timestampHumanTehran,      // متن خوانا برای انسان (تهران)
    'timezone'                     => TZ_TEHRAN,                  // برای تفسیر دقیق‌تر
    'timezone_offset_seconds'      => tehran_offset_seconds(),    // افست تهران در لحظهٔ ساخت
    'stale_after_seconds'          => JSON_STALE_AFTER_SECONDS,   // اگر سن فایل از این مقدار گذشت، هشدار بده
    'stale_after_minutes'          => (int) ceil(JSON_STALE_AFTER_SECONDS / 60),
    'rows'                         => $rows,
  ];
}

function build_brand_items(array $rows): array {
  $items = [];
  foreach ($rows as $row) {
    $name = value_from_row($row, ['برندها', 'برند', 'نام برند', 'brand', 'Brand', 'name_fa', 'name']);
    $slug = value_from_row($row, ['slug', 'Slug', 'نامک']);
    if ($name === '' && $slug === '') continue;
    $items[] = [
      'name_fa' => $name,
      'slug'    => $slug,
    ];
  }
  return $items;
}

function build_tag_items(array $rows): array {
  $items = [];
  foreach ($rows as $row) {
    $id = to_int(value_from_row($row, ['ID', 'id']));
    if ($id === null) continue;
    $name = value_from_row($row, ['تگ', 'tag', 'Tag', 'نام', 'name_fa', 'name']);
    $slug = value_from_row($row, ['slug', 'Slug', 'نامک']);
    $items[] = [
      'id'      => $id,
      'name_fa' => $name,
      'slug'    => $slug,
    ];
  }
  return $items;
}

function build_attribute_items(array $rows): array {
  $items = [];
  foreach ($rows as $row) {
    $id = to_int(value_from_row($row, ['ID', 'id']));
    if ($id === null) continue;
    $name = value_from_row($row, ['نام', 'attribute', 'Attribute', 'attribute_name', 'name_fa', 'name']);
    $slug = value_from_row($row, ['نامک', 'attribute_slug', 'slug', 'Slug']);
    $optionsRaw = value_from_row($row, ['مشخصه', 'attribute_items', 'options', 'values']);
    $items[] = [
      'id'      => $id,
      'name_fa' => $name,
      'slug'    => $slug,
      'options' => parse_attribute_options($optionsRaw),
    ];
  }
  return $items;
}

function build_category_items_and_tree(array $rows): array {
  $categories = [];
  $order = [];
  foreach ($rows as $row) {
    $id = to_int(value_from_row($row, ['ID', 'id']));
    if ($id === null) continue;
    if (!array_key_exists($id, $categories)) $order[] = $id;

    $name = value_from_row($row, ['دسته‌بندی', 'نام', 'Category', 'category', 'name_fa', 'name']);
    $slug = value_from_row($row, ['slug', 'Slug', 'نامک']);
    $parentRaw = value_from_row($row, ['Parent ID', 'parent_id', 'parent']);
    $parentId = to_int($parentRaw);
    if ($parentRaw === '' || $parentId === null || $parentId === 0) $parentId = null;

    $filtersRaw = value_from_row($row, ['فیلترهای سایدبار صفحه دسته‌بندی', 'sidebar filters', 'filters', 'Required product attributes for this category']);

    $categories[$id] = [
      'id'              => $id,
      'name_fa'         => $name,
      'slug'            => $slug,
      'parent_id'       => $parentId,
      'sidebar_filters' => parse_sidebar_filters($filtersRaw),
      'path_ids'        => [],
      'path_slugs'      => [],
      'path_names'      => [],
      'level'           => 0,
    ];
  }

  foreach (array_keys($categories) as $catId) hydrate_category_path($catId, $categories);

  $items = [];
  foreach ($order as $id) if (isset($categories[$id])) $items[] = $categories[$id];

  $tree = build_category_tree($items);

  return [$items, $tree];
}

function hydrate_category_path(int $id, array &$categories, array $chain = []): void {
  if (!isset($categories[$id])) return;
  if (!empty($categories[$id]['path_ids'])) return;

  if (in_array($id, $chain, true)) {
    $categories[$id]['path_ids'] = [$categories[$id]['id']];
    $categories[$id]['path_slugs'] = [$categories[$id]['slug']];
    $categories[$id]['path_names'] = [$categories[$id]['name_fa']];
    $categories[$id]['level'] = 1;
    return;
  }

  $chain[] = $id;
  $parentId = $categories[$id]['parent_id'];
  $pathIds = [];
  $pathSlugs = [];
  $pathNames = [];
  if ($parentId !== null && isset($categories[$parentId])) {
    hydrate_category_path($parentId, $categories, $chain);
    $pathIds = $categories[$parentId]['path_ids'];
    $pathSlugs = $categories[$parentId]['path_slugs'];
    $pathNames = $categories[$parentId]['path_names'];
  }

  $pathIds[] = $categories[$id]['id'];
  $pathSlugs[] = $categories[$id]['slug'];
  $pathNames[] = $categories[$id]['name_fa'];

  $categories[$id]['path_ids'] = $pathIds;
  $categories[$id]['path_slugs'] = $pathSlugs;
  $categories[$id]['path_names'] = $pathNames;
  $categories[$id]['level'] = count($pathIds);
}

function build_category_tree(array $items): array {
  $nodes = [];
  foreach ($items as $item) {
    $item['children'] = [];
    $nodes[$item['id']] = $item;
  }

  foreach ($nodes as $id => &$node) {
    $parentId = $node['parent_id'];
    if ($parentId !== null && isset($nodes[$parentId])) {
      $nodes[$parentId]['children'][] = &$node;
    }
  }
  unset($node);

  $tree = [];
  foreach ($nodes as $id => &$node) {
    if ($node['parent_id'] === null || !isset($nodes[$node['parent_id']])) {
      $tree[] = $node;
    }
  }
  unset($node);

  return array_values($tree);
}

function parse_attribute_options(string $raw): array {
  $raw = trim($raw);
  if ($raw === '') return [];

  $chunks = [];
  if (preg_match_all('/\[(.*?)\]/u', $raw, $matches)) {
    $chunks = $matches[1];
  } else {
    $chunks = explode(',', $raw);
  }

  $options = [];
  foreach ($chunks as $chunk) {
    $chunk = trim($chunk, "[] \t\n\r\0\v");
    if ($chunk === '') continue;
    $parts = explode(':', $chunk, 4);
    if (count($parts) < 4) continue;

    [$idRaw, $label, $slug, $sortRaw] = $parts;
    $id = to_int($idRaw);
    if ($id === null) continue;
    $sort = to_int($sortRaw);

    $options[] = [
      'id'        => $id,
      'label_fa'  => trim($label),
      'slug'      => trim($slug),
      'sort'      => $sort,
    ];
  }

  return $options;
}

function parse_sidebar_filters(string $raw): array {
  $raw = trim($raw);
  if ($raw === '') return [];
  $parts = preg_split('/,/', $raw);
  $parts = is_array($parts) ? $parts : [$raw];
  $parts = array_map(static fn($v) => trim($v), $parts);
  $parts = array_values(array_filter($parts, static function($v) {
    if ($v === '') return false;
    $norm = normalize_key($v);
    if (in_array($norm, ['not required', 'not_required', 'notrequired'], true)) return false;
    if (in_array($v, ['-', '—'], true)) return false;
    return true;
  }));
  return $parts;
}

function to_int(?string $value): ?int {
  if ($value === null) return null;
  $value = trim($value);
  if ($value === '') return null;
  if (!preg_match('/^-?\d+$/', $value)) return null;
  return (int)$value;
}

function value_from_row(array $row, array $candidates): string {
  foreach ($candidates as $candidate) {
    if ($candidate === null) continue;
    if (array_key_exists($candidate, $row)) {
      return trim((string)$row[$candidate]);
    }
    $needle = normalize_key((string)$candidate);
    foreach ($row as $key => $value) {
      if (normalize_key((string)$key) === $needle) {
        return trim((string)$value);
      }
    }
  }
  return '';
}

function normalize_key(string $key): string {
  return function_exists('mb_strtolower') ? mb_strtolower($key, 'UTF-8') : strtolower($key);
}

/* ================= UI ================= */

function common_css(): string {
  return <<<CSS
:root, html.light {
  --bg:#f7f8fb;--card:#fff;--text:#101114;--muted:#6b7280;--border:#e6e8ee;
  --brand:#1f2937;--accent:#0ea5e9;--accent-weak:#e0f2fe;
}
html.dark {
  --bg:#090d16;--card:#111726;--text:#f3f4f6;--muted:#9ca3af;--border:#1f293d;
  --brand:#6366f1;--accent:#06b6d4;--accent-weak:rgba(6,182,212,0.08);
}
*{box-sizing:border-box}
html,body{
  margin:0;padding:0;
  background-color:var(--bg);
  color:var(--text);
  font-family:Vazirmatn,IRANSans,Segoe UI,Arial,sans-serif;
  transition:background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}
a{color:var(--brand);text-decoration:none}
.container{max-width:1120px;margin:24px auto;padding:0 16px}
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;box-shadow:0 2px 10px rgba(0,0,0,.04);overflow:hidden}
.header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between}
h1{font-size:1.1rem;margin:0}
.count,.small{color:var(--muted);font-size:.92rem}
.rel-time{margin-right:.4rem;color:var(--muted);font-size:.85em}
.badge{display:inline-block;padding:.15rem .55rem;border-radius:999px;border:1px solid var(--border);font-size:.8rem;background:#fff;margin-right:.4rem}

/* top nav */
.topnav{position:sticky;top:0;z-index:50;display:flex;gap:8px;align-items:center;background:rgba(255,255,255,.9);backdrop-filter:blur(6px);padding:10px 12px;border-bottom:1px solid var(--border)}
.topnav a{padding:8px 10px;border-radius:10px;border:1px solid transparent}
.topnav a:hover{background:#fafafa}
.topnav a.active{border-color:var(--accent);background:var(--accent-weak)}

/* table */
.tblwrap{overflow:auto;max-height:calc(100vh - 260px)}
table{width:100%;border-collapse:separate;border-spacing:0;table-layout:auto}
thead th{position:sticky;top:0;background:#fafafa;font-weight:700;border-bottom:1px solid var(--border);z-index:1}
th,td{padding:10px 12px;text-align:right;font-size:.95rem;word-break:break-word;white-space:pre-wrap;vertical-align:top}
tbody tr:nth-child(odd){background:#fff}
tbody tr:nth-child(even){background:#fcfcff}
tbody tr:hover{background:#f8fbff}

/* ✅ بهبود سه ستون اول */
th:nth-child(1), td:nth-child(1){
  width:96px; min-width:96px; max-width:120px;
  white-space:nowrap; text-align:center; direction:ltr; font-variant-numeric:tabular-nums;
}
th:nth-child(2), td:nth-child(2){
  min-width:180px; white-space:nowrap;
}
th:nth-child(3), td:nth-child(3){
  min-width:240px; white-space:nowrap;
}

/* sections */
.section{padding:16px 18px}
.guide{padding:16px 18px}
.guide h2,.guide h3{margin:.2rem 0 .6rem}
.guide ul{margin:.2rem 0 1rem 0;line-height:1.9}
.note{margin:.5rem 0;padding:.6rem .8rem;background:#fff;border:1px dashed var(--border);border-radius:12px}

/* responsive */
@media (max-width:640px){
  .tblwrap{max-height:none}
  th:nth-child(2), td:nth-child(2){min-width:160px}
  th:nth-child(3), td:nth-child(3){min-width:200px}
}

/* Custom styling override for products table */
.tblwrap-products th:nth-child(1), .tblwrap-products td:nth-child(1){
  width:auto; min-width:240px; max-width:none;
  white-space:normal; text-align:right; direction:rtl; font-variant-numeric:normal;
}
.tblwrap-products th:nth-child(2), .tblwrap-products td:nth-child(2){
  width:auto; min-width:180px; max-width:none;
  white-space:normal; text-align:right; direction:rtl;
}
.tblwrap-products th:nth-child(3), .tblwrap-products td:nth-child(3){
  width:auto; min-width:150px; max-width:none;
  white-space:normal; text-align:right; direction:rtl;
}
.tblwrap-products th:nth-child(4), .tblwrap-products td:nth-child(4){
  width:120px; min-width:120px;
  white-space:nowrap; text-align:center; direction:ltr; font-variant-numeric:tabular-nums;
}

/* =========================================
   Dashboard Specific Styles (Modern Minimal Dark Mode)
   ========================================= */
body.index-page {
  --bg: #090d16;
  --card: #111726;
  --text: #f3f4f6;
  --muted: #9ca3af;
  --border: #1f293d;
  --brand: #6366f1;
  --accent: #06b6d4;
  --accent-weak: rgba(6, 182, 212, 0.08);
  background-color: var(--bg);
  color: var(--text);
}
body.index-page .topnav {
  background: rgba(17, 23, 38, 0.85);
  border-bottom: 1px solid var(--border);
  backdrop-filter: blur(12px);
}
body.index-page .topnav a {
  color: var(--muted);
  font-size: 0.9rem;
  padding: 6px 12px;
  border-radius: 8px;
  transition: all 0.2s;
}
body.index-page .topnav a:hover {
  background: rgba(255, 255, 255, 0.03);
  color: #fff;
}
body.index-page .topnav a.active {
  color: #06b6d4;
  background: var(--accent-weak);
  border-color: #06b6d4;
}
body.index-page .dashboard-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 20px;
  margin-top: 24px;
}
body.index-page .card-stat {
  padding: 24px;
  border-radius: 16px;
  background: var(--card);
  border: 1px solid var(--border);
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-height: 200px;
}
body.index-page .card-stat:hover {
  transform: translateY(-4px);
  border-color: rgba(6, 182, 212, 0.4);
  box-shadow: 0 12px 30px rgba(0, 0, 0, 0.25);
}
body.index-page .btn-sync {
  background: #06b6d4;
  color: #090d16;
  border: none;
  padding: 12px 24px;
  border-radius: 10px;
  font-weight: 700;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: all 0.2s ease;
  font-family: inherit;
  font-size: 0.9rem;
}
body.index-page .btn-sync:hover {
  background: #0891b2;
  box-shadow: 0 0 15px rgba(6, 182, 212, 0.3);
}
body.index-page .btn-sync:active {
  transform: scale(0.98);
}
body.index-page .btn-secondary-outline {
  background: transparent;
  color: var(--text);
  border: 1px solid var(--border);
  padding: 8px 16px;
  border-radius: 8px;
  font-size: 0.82rem;
  flex: 1;
  text-align: center;
  transition: all 0.2s;
  text-decoration: none;
}
body.index-page .btn-secondary-outline:hover {
  background: rgba(255, 255, 255, 0.03);
  border-color: var(--muted);
}
body.index-page .modal {
  display: none;
  position: fixed;
  top: 0; left: 0; width: 100%; height: 100%;
  background: rgba(9, 13, 22, 0.85);
  backdrop-filter: blur(8px);
  z-index: 100;
  align-items: center;
  justify-content: center;
}
body.index-page .modal-content {
  background: #111726;
  border: 1px solid #1f293d;
  border-radius: 16px;
  padding: 24px;
  width: 90%;
  max-width: 440px;
  text-align: right;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
  animation: modalFadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalFadeIn {
  from { opacity: 0; transform: translateY(-20px) scale(0.95); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
body.index-page .modal-title {
  margin-top: 0;
  font-size: 1.25rem;
  color: #f3f4f6;
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
}
body.index-page .modal-input {
  width: 100%;
  padding: 12px;
  border-radius: 8px;
  border: 1px solid #1f293d;
  background: #090d16;
  color: #fff;
  font-family: inherit;
  margin: 16px 0;
  direction: ltr;
  outline: none;
  transition: border-color 0.2s;
}
body.index-page .modal-input:focus {
  border-color: #06b6d4;
}
body.index-page .modal-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  margin-top: 20px;
}
body.index-page .btn-primary {
  background: #6366f1;
  color: white;
  border: none;
  padding: 10px 20px;
  border-radius: 8px;
  cursor: pointer;
  font-weight: bold;
  font-family: inherit;
  transition: background 0.2s;
}
body.index-page .btn-primary:hover {
  background: #4f46e5;
}
body.index-page .btn-secondary {
  background: transparent;
  color: var(--muted);
  border: 1px solid var(--border);
  padding: 10px 20px;
  border-radius: 8px;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.2s;
}
body.index-page .btn-secondary:hover {
  background: rgba(255, 255, 255, 0.03);
  border-color: var(--muted);
}
body.index-page .status-box {
  margin-top: 16px;
  padding: 12px 16px;
  border-radius: 10px;
  background: rgba(6, 182, 212, 0.05);
  border: 1px solid rgba(6, 182, 212, 0.15);
  display: none;
  align-items: center;
  gap: 12px;
  font-size: 0.85rem;
  color: #06b6d4;
  animation: modalFadeIn 0.3s ease;
}
body.index-page .spinner {
  width: 18px;
  height: 18px;
  border: 2px solid rgba(6, 182, 212, 0.1);
  border-top-color: #06b6d4;
  border-radius: 50%;
  animation: spin 1s infinite linear;
  flex-shrink: 0;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}
@media (max-width: 768px) {
  body.index-page .top-grid {
    grid-template-columns: 1fr !important;
  }
}

/* Global Dark Theme Overrides */
html.dark .topnav {
  background: rgba(17, 23, 38, 0.85);
}
html.dark .topnav a:hover {
  background: rgba(255, 255, 255, 0.03);
  color: #fff;
}
html.dark thead th {
  background: #111726;
  color: #fff;
  border-bottom: 1px solid var(--border);
}
html.dark tbody tr:nth-child(odd){background:#111726}
html.dark tbody tr:nth-child(even){background:#161d2f}
html.dark tbody tr:hover{background:#1e293d}
html.dark .badge {background: #1e293d; color: #f3f4f6; border-color: var(--border);}
html.dark .note {background: #161d2f; border-color: var(--border);}
html.dark .card {background: var(--card); border-color: var(--border); box-shadow: 0 4px 20px rgba(0,0,0,0.4);}

/* Theme Toggle Button */
.theme-btn {
  margin-right: auto;
  background: transparent;
  border: 1px solid var(--border);
  border-radius: 10px;
  cursor: pointer;
  font-size: 1.1rem;
  padding: 6px 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
  color: var(--text);
  outline: none;
}
.theme-btn:hover {
  background: rgba(0, 0, 0, 0.05);
}
html.dark .theme-btn:hover {
  background: rgba(255, 255, 255, 0.05);
}

/* Categories Page Layout Overrides (Prevent Squishing) */
.tblwrap-categories table {
  min-width: 1500px;
}
.tblwrap-categories th, .tblwrap-categories td {
  white-space: nowrap;
}
.tblwrap-categories td:nth-child(5) {
  white-space: normal;
  min-width: 260px;
}
.tblwrap-categories td:nth-child(7) {
  white-space: normal;
  min-width: 280px;
}
.tblwrap-categories td:nth-child(8), .tblwrap-categories td:nth-child(9), .tblwrap-categories td:nth-child(10) {
  white-space: normal;
  min-width: 220px;
}
CSS;
}


function render_nav(string $active): string {
  $is = fn(string $k) => $k === $active ? 'class="active"' : '';
  return <<<HTML
<nav class="topnav" aria-label="primary">
  <a href="./index.html" {$is('index')}>داشبورد</a>
  <a href="./brands.html" {$is('brands')}>برندها</a>
  <a href="./tags.html" {$is('tags')}>تگ‌ها</a>
  <a href="./categories.html" {$is('categories')}>دسته‌بندی‌ها</a>
  <a href="./attributes.html" {$is('attributes')}>ویژگی‌ها</a>
  <a href="./products.html" {$is('products')}>محصولات</a>
  <button id="theme-toggle" class="theme-btn" aria-label="Toggle theme">🌙</button>
</nav>
HTML;
}

function relative_time_script(): string {
  return <<<HTML
<script>
(function(){
  const update = function(){
    const nodes = document.querySelectorAll('[data-relative-time]');
    if (!nodes.length) return;
    let rtf;
    try { rtf = new Intl.RelativeTimeFormat('fa', {numeric: 'auto'}); } catch (e) { rtf = null; }
    const unitMap = [
      {limit: 60, unit: 'second', div: 1, label: 'ثانیه'},
      {limit: 3600, unit: 'minute', div: 60, label: 'دقیقه'},
      {limit: 86400, unit: 'hour', div: 3600, label: 'ساعت'},
      {limit: 2592000, unit: 'day', div: 86400, label: 'روز'},
      {limit: 31104000, unit: 'month', div: 2592000, label: 'ماه'},
      {limit: Infinity, unit: 'year', div: 31104000, label: 'سال'}
    ];
    nodes.forEach(function(node){
      const iso = node.getAttribute('data-relative-time');
      if (!iso) return;
      const target = new Date(iso);
      if (isNaN(target)) return;
      const diffSeconds = Math.round((target.getTime() - Date.now()) / 1000);
      const absSeconds = Math.abs(diffSeconds);
      let unit = unitMap.find(u => absSeconds < u.limit) || unitMap[unitMap.length - 1];
      let value = Math.round(diffSeconds / unit.div);
      if (!isFinite(value)) value = 0;
      let text;
      if (rtf) {
        text = rtf.format(value, unit.unit);
      } else {
        const suffix = value <= 0 ? 'پیش' : 'بعد';
        text = Math.abs(value) + ' ' + unit.label + ' ' + suffix;
      }
      node.textContent = ' | ' + text;
    });
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', update);
  } else {
    update();
  }
  
  // Theme Toggle Handler
  const toggle = document.getElementById('theme-toggle');
  if (toggle) {
    const updateIcon = (theme) => {
      const isDark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
      toggle.innerHTML = isDark ? '☀️' : '🌙';
    };
    const currentTheme = localStorage.getItem('theme') || 'system';
    updateIcon(currentTheme);
    toggle.addEventListener('click', () => {
      const isDark = document.documentElement.classList.contains('dark');
      const newTheme = isDark ? 'light' : 'dark';
      document.documentElement.classList.toggle('dark', !isDark);
      document.documentElement.classList.toggle('light', isDark);
      localStorage.setItem('theme', newTheme);
      updateIcon(newTheme);
    });
  }
})();
</script>
HTML;
}

/* ---- Guides ---- */
function guidelines_full_html(): string {
  return <<<HTML
<section class="guide section">
  <h2>🧭 راهنمای کار با داده‌ها</h2>

  <h3>📦 محصولات</h3>
  <ul>
    <li>برای انطباق نام محصول، نام کوتاه، برند و WooCommerce Product ID.</li>
    <li>هرگز شناسه محصول یا نام کوتاه را بدون تایید تغییر نده.</li>
  </ul>
  <hr/>

  <h3>🏷 برندها</h3>
  <ul>
    <li>فقط از <strong>برندهای موجود</strong> استفاده کن.</li>
    <li>برند جدید فقط وقتی اضافه کن که مطمئن باشی مشابهش در لیست نیست.</li>
  </ul>
  <hr/>

  <h3>🔖 تگ‌ها</h3>
  <ul>
    <li>ساخت تگ جدید <strong>مجاز نیست</strong>؛ فقط از لیست موجود انتخاب کن.</li>
    <li>برای هر محصول، <strong>تگ‌های مرتبط</strong> را اختصاص بده.</li>
  </ul>
  <hr/>

  <h3>📂 دسته‌بندی‌ها</h3>
  <ul>
    <li><strong>دسته/دسته‌های مرتبط</strong> را برای محصول انتخاب کن.</li>
    <li>افزودن دسته‌بندی جدید <strong>مجاز نیست</strong>.</li>
  </ul>
  <hr/>

  <h3>⚙️ ویژگی‌ها و مشخصه‌ها</h3>
  <ul>
    <li>برای توصیف دقیق محصول (رنگ، جنس، گروه سنی، تعداد قطعات…)</li>
    <li>اگر ویژگی موجود است، <strong>همان را استفاده کن</strong>.</li>
    <li>در صورت نیاز می‌توانی ویژگی/مقدار جدید بسازی؛ اما از <strong>هم‌معنیِ تکراری</strong> بپرهیز.</li>
  </ul>
  <blockquote class="note">مثال: اگر «تعداد قطعات» موجود است، ویژگی تازه‌ای به نام «تعداد تکه‌ها» نساز.</blockquote>

  <hr/>
  <h3>💡 یادآوری کلی</h3>
  <ul>
    <li>از تکرار با واژه‌های هم‌معنی یا املای متفاوت خودداری کن.</li>
    <li>برندها و دسته‌بندی‌ها <strong>قابل افزودن نیستند</strong>.</li>
    <li>تگ‌ها فقط از لیست موجود انتخاب می‌شوند.</li>
    <li>ویژگی‌های جدید فقط در صورت نیاز و پس از بررسی شباهت با موجودها.</li>
    <li>هدف: <strong>یکپارچگی داده</strong> و جلوگیری از پراکندگی.</li>
  </ul>
</section>
HTML;
}

function guidelines_section_html(string $name): string {
  $map = [
    'brands' => <<<HTML
      <h3>🏷 راهنمای برندها</h3>
      <ul>
        <li>فقط از برندهای موجود استفاده کن.</li>
        <li>برند جدید فقط در صورت نبودِ مشابه.</li>
      </ul>
    HTML,
    'tags' => <<<HTML
      <h3>🔖 راهنمای تگ‌ها</h3>
      <ul>
        <li>ساخت تگ جدید مجاز نیست.</li>
        <li>فقط تگ‌های مرتبط را از لیست موجود انتخاب کن.</li>
      </ul>
    HTML,
    'categories' => <<<HTML
      <h3>📂 راهنمای دسته‌بندی‌ها</h3>
      <ul>
        <li>دسته‌های مرتبط را برای محصول انتخاب کن.</li>
        <li>افزودن دسته‌بندی جدید مجاز نیست.</li>
      </ul>
    HTML,
    'attributes' => <<<HTML
      <h3>⚙️ راهنمای ویژگی‌ها و مشخصه‌ها</h3>
      <ul>
        <li>از ویژگی‌های موجود برای توصیف دقیق استفاده کن.</li>
        <li>در صورت نیاز می‌توانی ویژگی/مقدار جدید بسازی؛ اما از ایجاد هم‌معنیِ تکراری بپرهیز.</li>
      </ul>
      <blockquote class="note">مثال: «تعداد قطعات» ≠ «تعداد تکه‌ها»</blockquote>
    HTML,
    'products' => <<<HTML
      <h3>📦 راهنمای محصولات</h3>
      <ul>
        <li>شناسه‌های ووکامرس (WooCommerce ID) نباید تغییر کنند.</li>
        <li>نام کوتاه هر محصول برای ارجاعات سریع استفاده می‌شود.</li>
      </ul>
    HTML,
  ];
  return '<section class="guide section">'.($map[$name] ?? '').'</section>';
}

/* ---- Pages ---- */
function favicon_link(): string {
  return '<link rel="icon" type="image/svg+xml" href="' . FAVICON_DATA_URI . '">';
}

function render_table_page(string $name, string $title, array $rows, string $updatedAtDisplay, string $updatedAtIso): string {
  $headers = $rows ? array_keys($rows[0]) : [];
  $thead = '';
  if ($headers) {
    $ths = '';
    foreach ($headers as $h) {
      $ths .= '<th>' . htmlspecialchars((string)$h, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>';
    }
    $thead = "<thead><tr>{$ths}</tr></thead>";
  }
  $tbody = '';
  foreach ($rows as $r) {
    $tds = '';
    foreach ($headers as $h) {
      $tds .= '<td>' . htmlspecialchars((string)($r[$h] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
    }
    $tbody .= "<tr>{$tds}</tr>";
  }
  if ($tbody === '') $tbody = '<tr><td>موردی یافت نشد</td></tr>';

  $count = count($rows);
  $isoAttr = htmlspecialchars($updatedAtIso, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

  $css = common_css();
  $nav = render_nav($name);
  $guide = guidelines_section_html($name);
  $script = relative_time_script();
  $updatedAtHtml = htmlspecialchars($updatedAtDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $favicon = favicon_link();

  return <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate" />
<meta http-equiv="Pragma" content="no-cache" />
<meta http-equiv="Expires" content="0" />
{$favicon}
<title>{$title}</title>
<style>{$css}</style>
<script>
(function() {
  const theme = localStorage.getItem('theme') || 'system';
  const isDark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.classList.toggle('dark', isDark);
  document.documentElement.classList.toggle('light', !isDark);
})();
</script>
</head>
<body>
{$nav}
<div class="container">
  <div class="card">
    <div class="header">
      <h1>{$title} <span class="badge">{$count} ردیف</span></h1>
      <div class="count">بروزرسانی: {$updatedAtHtml}<span class="rel-time" data-relative-time="{$isoAttr}"></span></div>
    </div>
    <div class="tblwrap tblwrap-{$name}" role="region" aria-label="snapshot">
      <table>
        {$thead}
        <tbody>
          {$tbody}
        </tbody>
      </table>
    </div>
  </div>
  {$guide}
</div>
{$script}
</body>
</html>
HTML;
}

function render_index_page(array $stats, string $buildDisplay, string $buildIso): string {
  $buildIsoAttr = htmlspecialchars($buildIso, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $buildDisplayHtml = htmlspecialchars($buildDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  
  $gridItems = '';
  $labelMap = [
    'products'   => '📦 لیست محصولات',
    'brands'     => '🏷 لیست برندها',
    'categories' => '📂 دسته‌بندی‌ها',
    'attributes' => '⚙️ ویژگی‌های محصول',
    'tags'       => '🔖 تگ‌های محصول',
  ];
  
  foreach ($labelMap as $k => $label) {
    if (!isset($stats[$k])) continue;
    $c = (int)$stats[$k]['count'];
    $display = htmlspecialchars($stats[$k]['updated_at_display'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $isoAttr = htmlspecialchars($stats[$k]['updated_at_iso'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    
    $gridItems .= <<<HTML
<div class="card-stat">
  <div>
    <h4 style="margin:0 0 8px 0;font-size:1.1rem;color:#f1f5f9;display:flex;align-items:center;gap:6px;">{$label}</h4>
    <div style="font-size:1.8rem;font-weight:bold;color:var(--accent);margin:12px 0 8px 0;">{$c} <span style="font-size:0.9rem;font-weight:normal;color:var(--muted);">ردیف</span></div>
  </div>
  <div>
    <div style="font-size:0.78rem;color:var(--muted);margin-bottom:12px;direction:rtl;" data-relative-time="{$isoAttr}">بروزرسانی: {$display}</div>
    <div style="display:flex;gap:8px;">
      <a href="./{$k}.html" style="padding:8px;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:8px;font-size:0.8rem;color:#f1f5f9;flex:1;text-align:center;transition:all 0.2s;" onmouseover="this.style.background='rgba(99,102,241,0.15)'" onmouseout="this.style.background='rgba(255,255,255,0.05)'">نمایش وب</a>
      <a href="./{$k}.json" style="padding:8px;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:8px;font-size:0.8rem;color:#f1f5f9;flex:1;text-align:center;transition:all 0.2s;" onmouseover="this.style.background='rgba(6,182,212,0.15)'" onmouseout="this.style.background='rgba(255,255,255,0.05)'" download>دانلود JSON</a>
    </div>
  </div>
</div>
HTML;
  }

  $css = common_css();
  $nav = render_nav('index');
  $guide = guidelines_full_html();
  $script = relative_time_script();
  $favicon = favicon_link();

  return <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate" />
<meta http-equiv="Pragma" content="no-cache" />
<meta http-equiv="Expires" content="0" />
{$favicon}
<title>پنل پایش داده و متادیتا</title>
<style>{$css}</style>
<script>
(function() {
  const theme = localStorage.getItem('theme') || 'system';
  const isDark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.classList.toggle('dark', isDark);
  document.documentElement.classList.toggle('light', !isDark);
})();
</script>
</head>
<body class="index-page">
{$nav}
<div class="container">
  
  <!-- Title Header -->
  <div style="margin-bottom: 24px; text-align: right;">
    <h1 style="font-size: 1.6rem; color: #f1f5f9; margin: 0 0 8px 0;">پنل پایش داده و متادیتا</h1>
    <div style="font-size: 0.85rem; color: var(--muted);">آخرین ساخت سراسری: {$buildDisplayHtml}<span class="rel-time" data-relative-time="{$buildIsoAttr}"></span></div>
  </div>

  <!-- Top Operation Panels -->
  <div class="top-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
    <!-- Sync Card -->
    <div class="card" style="padding:20px;display:flex;flex-direction:column;justify-content:space-between;min-height:180px;">
      <div>
        <h3 style="margin:0 0 10px 0;font-size:1.2rem;color:#f1f5f9;display:flex;align-items:center;gap:8px;">⚡ همگام‌سازی دستی داده‌ها</h3>
        <p style="margin:0;font-size:0.85rem;color:var(--muted);line-height:1.6;">اگر داده‌ای را در گوگل شیت تغییر داده‌اید و نمی‌خواهید منتظر اجرای خودکار بعدی بمانید، با دکمه زیر فرآیند بازسازی را در لحظه آغاز کنید.</p>
      </div>
      <div style="margin-top:15px;">
        <button class="btn-sync" onclick="triggerSync()">
          <span>🔄 به‌روزرسانی در لحظه</span>
        </button>
        <div id="sync-status" class="status-box"></div>
      </div>
    </div>

    <!-- Unified JSON Card -->
    <div class="card" style="padding:20px;display:flex;flex-direction:column;justify-content:space-between;min-height:180px;position:relative;overflow:hidden;border-color:var(--accent);">
      <div>
        <h3 style="margin:0 0 10px 0;font-size:1.2rem;color:#f1f5f9;display:flex;align-items:center;gap:8px;">🔗 فایل جامع و یکپارچه</h3>
        <p style="margin:0;font-size:0.85rem;color:var(--muted);line-height:1.6;">این فایل شامل تمامی داده‌های ۵ شیت به همراه متادیتای زمانی و راهنمای ساختار است که مرجع اصلی ایجنت‌ها محسوب می‌شود.</p>
      </div>
      <div style="margin-top:15px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <a href="./taxonomy.json" style="padding:10px 20px;background:linear-gradient(90deg, #6366f1, #06b6d4);color:white;border-radius:10px;font-weight:bold;font-size:0.9rem;box-shadow:0 4px 12px rgba(6,182,212,0.25);">دانلود taxonomy.json</a>
        <button onclick="copyToClipboard('https://meta.buyruz.com/taxonomy.json')" style="padding:10px 15px;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:10px;color:#f1f5f9;font-weight:bold;font-size:0.9rem;cursor:pointer;transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.background='rgba(255,255,255,0.05)'">کپی لینک فایل</button>
      </div>
    </div>
  </div>

  <!-- Dashboard Grid -->
  <h3 style="color:#f1f5f9;margin:32px 0 16px 0;">📊 وضعیت اسنپ‌شات‌ها</h3>
  <div class="dashboard-grid">
    {$gridItems}
  </div>

  <!-- Guides -->
  <div style="margin-top: 40px;">
    {$guide}
  </div>
</div>

<!-- Token Modal -->
<div id="token-modal" class="modal">
  <div class="modal-content">
    <h3 class="modal-title">🔐 اتصال به گیت‌هاب</h3>
    <p style="color:var(--muted);font-size:0.85rem;line-height:1.6;margin:10px 0;">جهت احراز هویت برای ارسال دستور به گیت‌هاب، لطفاً توکن شخصی خود (GitHub PAT) را وارد کنید. این توکن فقط در مرورگر شما ذخیره خواهد شد.</p>
    <div style="font-size:0.8rem;background:#0f172a;padding:12px;border-radius:8px;border:1px solid var(--border);margin-bottom:15px;color:var(--muted);line-height:1.5;">
      💡 توکن شما باید دسترسی‌های <strong>Actions: write</strong> و <strong>Contents: write</strong> برای این مخزن داشته باشد.
    </div>
    <label style="font-size:0.85rem;color:#f1f5f9;">توکن گیت‌هاب (GitHub PAT):</label>
    <input type="password" id="pat-token" class="modal-input" placeholder="ghp_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX">
    <div class="modal-actions">
      <button class="btn-secondary" onclick="closeTokenModal()">انصراف</button>
      <button class="btn-primary" onclick="saveTokenAndSync()">ذخیره و شروع</button>
    </div>
  </div>
</div>

<script>
const OWNER = 'moh3np';
const REPO = 'buyruz-meta';
const WORKFLOW_ID = 'cron-refresh.yml';
let pollInterval;

function copyToClipboard(text) {
  navigator.clipboard.writeText(text).then(() => {
    alert('لینک فایل جامع کپی شد.');
  }).catch(err => {
    console.error('Failed to copy: ', err);
  });
}

function showTokenModal() {
  document.getElementById('token-modal').style.display = 'flex';
}

function closeTokenModal() {
  document.getElementById('token-modal').style.display = 'none';
}

function saveTokenAndSync() {
  const token = document.getElementById('pat-token').value.trim();
  if (!token) {
    alert('لطفاً توکن را وارد کنید.');
    return;
  }
  localStorage.setItem('gh_pat', token);
  closeTokenModal();
  triggerSync();
}

async function triggerSync() {
  let token = localStorage.getItem('gh_pat');
  if (!token) {
    showTokenModal();
    return;
  }
  
  setSyncStatus('initiating', 'در حال ارسال درخواست به گیت‌هاب...');
  
  try {
    const res = await fetch(`https://api.github.com/repos/\${OWNER}/\${REPO}/actions/workflows/\${WORKFLOW_ID}/dispatches`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer \${token}`,
        'Accept': 'application/vnd.github+json',
        'X-GitHub-Api-Version': '2022-11-28',
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ ref: 'main' })
    });
    
    if (res.status === 204) {
      setSyncStatus('queued', 'درخواست ثبت شد. در حال راه‌اندازی سرور پردازش...');
      startPolling(token);
    } else {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.message || 'خطا در احراز هویت. احتمالاً توکن منقضی یا اشتباه است.');
    }
  } catch (error) {
    localStorage.removeItem('gh_pat');
    setSyncStatus('error', error.message);
  }
}

function setSyncStatus(type, message) {
  const box = document.getElementById('sync-status');
  box.style.display = 'flex';
  
  let content = '';
  if (type === 'initiating' || type === 'queued' || type === 'running') {
    content = `<div class="spinner"></div><span style="font-size:0.88rem;color:#f1f5f9;">\${message}</span>`;
    box.style.borderColor = 'var(--brand)';
    box.style.background = 'rgba(99, 102, 241, 0.1)';
  } else if (type === 'success') {
    content = `<span style="font-size:1.2rem;">✅</span><span style="font-size:0.88rem;color:#10b981;font-weight:bold;">\${message}</span>`;
    box.style.borderColor = '#10b981';
    box.style.background = 'rgba(16, 185, 129, 0.1)';
  } else if (type === 'error') {
    content = `<span style="font-size:1.2rem;">❌</span><span style="font-size:0.88rem;color:#ef4444;font-weight:bold;">\${message}</span>`;
    box.style.borderColor = '#ef4444';
    box.style.background = 'rgba(239, 68, 68, 0.1)';
  }
  
  box.innerHTML = content;
}

function startPolling(token) {
  if (pollInterval) clearInterval(pollInterval);
  
  pollInterval = setInterval(async () => {
    try {
      const res = await fetch(`https://api.github.com/repos/\${OWNER}/\${REPO}/actions/runs?workflow_id=\${WORKFLOW_ID}&limit=5`, {
        headers: {
          'Authorization': `Bearer \${token}`,
          'Accept': 'application/vnd.github+json',
          'X-GitHub-Api-Version': '2022-11-28'
        }
      });
      
      if (!res.ok) return;
      const data = await res.json();
      const runs = data.workflow_runs || [];
      const run = runs[0];
      if (!run) return;
      
      if (run.status === 'queued') {
        setSyncStatus('running', 'کران‌جاب در صف انتظار گیت‌هاب قرار گرفت...');
      } else if (run.status === 'in_progress') {
        setSyncStatus('running', 'در حال پردازش: داده‌ها از گوگل شیت بارگذاری و فایل‌ها بازنویسی می‌شوند...');
      } else if (run.status === 'completed') {
        clearInterval(pollInterval);
        if (run.conclusion === 'success') {
          setSyncStatus('success', 'به‌روزرسانی با موفقیت انجام شد! صفحه تا ۳ ثانیه دیگر رفرش می‌شود...');
          setTimeout(() => window.location.reload(), 3000);
        } else {
          setSyncStatus('error', 'خطایی در اجرای کران‌جاب گیت‌هاب رخ داد. لطفاً لاگ‌های گیت‌هاب را بررسی کنید.');
        }
      }
    } catch (e) {
      console.error(e);
    }
  }, 4000);
}
</script>
{$script}
</body>
</html>
HTML;
}
