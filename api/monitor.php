<?php
/**
 * Единый скрипт: климатический монитор pogodaiklimat.ru
 * Для произвольного подмножества станций и выбранных месяца/года
 * собирает температуру, норму, отклонение и осадки, парся страницы
 * monitor.php?id=ID&month=M&year=Y (по одной на станцию).
 *
 * Режимы:
 *  - Web: открой в браузере — форма (месяц/год + чекбоксы станций) и таблица.
 *         JSON: ?format=json&month=9&year=2026&ids=27612,26063
 *  - CLI: php monitors.php [month] [year] [ids=27612,26063]
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

// ==========================================================================
//  НАСТРОЙКИ
// ==========================================================================

const STATION_BASE_URL = 'https://www.pogodaiklimat.ru/monitor.php';

// Полный каталог станций: WMO id => подпись.
// Через форму на странице можно выбирать произвольное подмножество.
const TARGET_STATIONS = [
    27612 => 'Москва',
    26063 => 'Санкт-Петербург',
    26850 => 'Минск',
    33345 => 'Киев',              // проверьте id
    26702 => 'Калининград',
    27459 => 'Нижний Новгород',   // проверьте id
    22113 => 'Мурманск',          // проверьте id
    22550 => 'Архангельск',       // проверьте id
    34731 => 'Ростов-на-Дону',    // проверьте id
    37099 => 'Сочи',              // проверьте id
    35121 => 'Оренбург',          // проверьте id
    28440 => 'Екатеринбург',      // проверьте id
    29638 => 'Новосибирск',
    23078 => 'Норильск',          // проверьте id
    30710 => 'Иркутск',
    24959 => 'Якутск',            // проверьте id
    20087 => 'Остров Визе',       // проверьте id
    31960 => 'Владивосток',       // проверьте id
];

const JSON_OUTPUT = __DIR__ . '/stations.json'; // используется только в CLI

// ==========================================================================
//  ЛОГИРОВАНИЕ
// ==========================================================================

function logLine(string $msg): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $msg);
    } else {
        error_log(rtrim($msg));
    }
}

function logError(string $msg): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg);
    } else {
        error_log(rtrim($msg));
    }
}

// ==========================================================================
//  0. МЕСЯЦ / ГОД / СПИСОК СТАНЦИЙ
// ==========================================================================

function resolvePeriod(): array
{
    $month = (int) date('n');
    $year  = (int) date('Y');

    if (PHP_SAPI === 'cli') {
        global $argv;
        if (isset($argv[1]) && ctype_digit($argv[1])) {
            $month = (int) $argv[1];
        }
        if (isset($argv[2]) && ctype_digit($argv[2])) {
            $year = (int) $argv[2];
        }
    } else {
        if (isset($_GET['month']) && ctype_digit((string) $_GET['month'])) {
            $month = (int) $_GET['month'];
        }
        if (isset($_GET['year']) && ctype_digit((string) $_GET['year'])) {
            $year = (int) $_GET['year'];
        }
    }

    $month = max(1, min(12, $month));
    $year  = max(1900, min(((int) date('Y')), $year));

    return [$month, $year];
}

/**
 * Возвращает массив [id => label] выбранных станций.
 * Источники (в порядке приоритета):
 *   - Web: GET-параметры ids (строка "27612,26063") или ids[]=... (массив).
 *   - CLI: argv-параметр вида ids=27612,26063.
 * Если ничего не задано — возвращает весь TARGET_STATIONS.
 * Если задано, но валидных id не осталось — тоже весь список
 * (защита от «пустой формы»).
 */
function resolveSelectedStations(): array
{
    $raw = [];

    if (PHP_SAPI === 'cli') {
        global $argv;
        foreach ($argv ?? [] as $arg) {
            if (strpos($arg, 'ids=') === 0) {
                $raw = explode(',', substr($arg, 4));
                break;
            }
        }
    } else {
        if (isset($_GET['ids'])) {
            if (is_array($_GET['ids'])) {
                $raw = $_GET['ids'];
            } else {
                $raw = explode(',', (string) $_GET['ids']);
            }
        }
    }

    $selected = [];
    foreach ($raw as $item) {
        $item = trim((string) $item);
        if ($item === '' || !ctype_digit($item)) {
            continue;
        }
        $id = (int) $item;
        if (isset(TARGET_STATIONS[$id])) {
            $selected[$id] = TARGET_STATIONS[$id];
        }
    }

    if (empty($selected)) {
        return TARGET_STATIONS;
    }

    return $selected;
}

function buildStationUrl(int $id, int $month, int $year): string
{
    return STATION_BASE_URL . '?id=' . $id . '&month=' . $month . '&year=' . $year;
}

// ==========================================================================
//  1. ПАРАЛЛЕЛЬНАЯ ЗАГРУЗКА СТРАНИЦ (curl_multi)
// ==========================================================================

function fetchManyPages(array $urlsByKey): array
{
    $results = [];

    if (!function_exists('curl_multi_init')) {
        foreach ($urlsByKey as $key => $url) {
            $context = stream_context_create([
                'http' => [
                    'method'  => 'GET',
                    'header'  => "User-Agent: Mozilla/5.0\r\nAccept-Language: ru-RU,ru;q=0.9\r\n",
                    'timeout' => 20,
                ],
            ]);
            $body = @file_get_contents($url, false, $context);
            $results[$key] = $body === false ? null : $body;
        }
        return $results;
    }

    $mh      = curl_multi_init();
    $handles = [];

    foreach ($urlsByKey as $key => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                    . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    foreach ($handles as $key => $ch) {
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body     = curl_multi_getcontent($ch);
        $results[$key] = ($body !== false && $body !== '' && $httpCode < 400) ? $body : null;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);

    return $results;
}

function toUtf8(string $html): string
{
    $charset = 'windows-1251';

    if (preg_match('/<meta[^>]+charset=["\']?\s*([a-zA-Z0-9\-]+)/i', $html, $m)) {
        $charset = $m[1];
    }

    if (stripos($charset, 'utf-8') !== false || stripos($charset, 'utf8') !== false) {
        return $html;
    }

    $converted = @iconv($charset, 'UTF-8//IGNORE', $html);
    if ($converted === false || $converted === '') {
        $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
    }

    return $converted !== false && $converted !== '' ? $converted : $html;
}

function htmlToPlainText(string $utf8Html): string
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8">' . $utf8Html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    foreach (['script', 'style'] as $tag) {
        $nodes = $dom->getElementsByTagName($tag);
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $node = $nodes->item($i);
            $node->parentNode?->removeChild($node);
        }
    }

    $text = $dom->textContent ?? '';
    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

    return $text;
}

// ==========================================================================
//  2. ПАРСИНГ ОДНОЙ СТРАНИЦЫ СТАНЦИИ
// ==========================================================================

function parseStationText(string $plainText): array
{
    $num = '([+\-−]?[0-9]+(?:[.,][0-9]+)?)';

    $data = [
        'temp_avg'       => null,
        'temp_norm'      => null,
        'temp_deviation' => null,
        'precip_fallen'  => null,
        'precip_norm'    => null,
        'precip_percent' => null,
        'name'           => null,
    ];

    // Единицы (° и мм) намеренно НЕ включаем в значение — они уже есть
    // в заголовках колонок, дублировать в ячейках не нужно.
    if (preg_match('/Норма\s+среднемесячной\s+температуры[^:]*:\s*' . $num . '\s*°/u', $plainText, $m)) {
        $data['temp_norm'] = normalizeNumber($m[1]);
    }
    if (preg_match('/Фактическая\s+температура\s+месяца[^:]*:\s*' . $num . '\s*°/u', $plainText, $m)) {
        $data['temp_avg'] = normalizeNumber($m[1]);
    }
    if (preg_match('/Отклонение\s+от\s+нормы\s*:\s*' . $num . '\s*°/u', $plainText, $m)) {
        $data['temp_deviation'] = normalizeSigned($m[1]);
    }
    if (preg_match('/Норма\s+суммы\s+осадков[^:]*:\s*([0-9]+(?:[.,][0-9]+)?)\s*мм/u', $plainText, $m)) {
        $data['precip_norm'] = normalizeNumber($m[1]);
    }
    if (preg_match('/Выпало\s+осадков\s*:\s*([0-9]+(?:[.,][0-9]+)?)\s*мм/u', $plainText, $m)) {
        $data['precip_fallen'] = normalizeNumber($m[1]);
    }
    if (preg_match('/составляет\s*([0-9]+(?:[.,][0-9]+)?)\s*%\s*от\s*нормы/u', $plainText, $m)) {
        $data['precip_percent'] = normalizeNumber($m[1]);
    }
    if (preg_match('/метеорологической\s+станции\s+([^(]+)\(/u', $plainText, $m)) {
        $data['name'] = trim($m[1]);
    }

    return $data;
}

function normalizeNumber(string $raw): string
{
    return str_replace(',', '.', trim($raw));
}

function normalizeSigned(string $raw): string
{
    $raw = str_replace('−', '-', trim($raw));
    $raw = str_replace(',', '.', $raw);
    if ($raw !== '' && $raw[0] !== '+' && $raw[0] !== '-') {
        $raw = '+' . $raw;
    }
    return $raw;
}

// ==========================================================================
//  3. СБОРКА ДАННЫХ ПО ВЫБРАННЫМ СТАНЦИЯМ
// ==========================================================================

function fetchAndParseAll(array $selectedStations, int $month, int $year): array
{
    $urls = [];
    foreach ($selectedStations as $id => $label) {
        $urls[$id] = buildStationUrl($id, $month, $year);
    }

    logLine('Загрузка ' . count($urls) . " страниц параллельно...\n");
    $bodies = fetchManyPages($urls);

    $stations = [];
    $missing  = [];

    foreach ($selectedStations as $id => $label) {
        $body = $bodies[$id] ?? null;

        if ($body === null) {
            $missing[] = "$label (id $id) — ошибка загрузки";
            continue;
        }

        $utf8Html  = toUtf8($body);
        $plainText = htmlToPlainText($utf8Html);
        $parsed    = parseStationText($plainText);

        $hasAnyData = $parsed['temp_avg'] !== null || $parsed['precip_fallen'] !== null;
        if (!$hasAnyData) {
            $missing[] = "$label (id $id) — не удалось распознать данные (проверьте id)";
            continue;
        }

        $stations[] = [
            'wmo_id'         => (string) $id,
            'name'           => $parsed['name'] ?? $label,
            'temp_avg'       => $parsed['temp_avg'],
            'temp_norm'      => $parsed['temp_norm'],
            'temp_deviation' => $parsed['temp_deviation'],
            'precip_fallen'  => $parsed['precip_fallen'],
            'precip_norm'    => $parsed['precip_norm'],
            'precip_percent' => $parsed['precip_percent'],
        ];
    }

    if (!empty($missing)) {
        logLine("Проблемные станции:\n - " . implode("\n - ", $missing) . "\n");
    }

    return [
        'month'         => $month,
        'year'          => $year,
        'period'        => monthName($month) . ' ' . $year,
        'generated_at'  => date('c'),
        'selected_ids'  => array_map('strval', array_keys($selectedStations)),
        'stations'      => $stations,
        'missing'       => $missing,
    ];
}

function monthName(int $month): string
{
    static $names = [
        1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
        5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
        9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
    ];
    return $names[$month] ?? (string) $month;
}

// ==========================================================================
//  4. РЕНДЕР
// ==========================================================================

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function renderDeviationCell(?string $raw): string
{
    $raw = $raw === null ? '' : trim($raw);
    if ($raw === '') {
        return '<span class="dev dev-na">н/д</span>';
    }
    if (str_starts_with($raw, '+')) {
        $cls = 'dev-pos';
    } elseif (str_starts_with($raw, '-')) {
        $cls = 'dev-neg';
    } else {
        $cls = 'dev-zero';
    }
    return '<span class="dev ' . $cls . '">' . e($raw) . '</span>';
}

function renderPlainCell(?string $raw): string
{
    $raw = $raw === null ? '' : trim($raw);
    if ($raw === '') {
        return '<span class="cell-na">н/д</span>';
    }
    return e($raw);
}

function buildTableRowsHtml(array $stations): string
{
    $out = '';
    foreach ($stations as $s) {
        $monitorUrl = 'https://www.pogodaiklimat.ru/monitor.php?id=' . rawurlencode($s['wmo_id']);

        $out .= "        <tr>\n";
        $out .= '          <td class="col-id">' . e($s['wmo_id']) . "</td>\n";
        $out .= '          <td class="col-name"><a href="' . e($monitorUrl) . '" target="_blank" rel="noopener noreferrer">'
            . e($s['name']) . "</a></td>\n";
        $out .= '          <td class="col-num">' . renderPlainCell($s['temp_avg']) . "</td>\n";
        $out .= '          <td class="col-num">' . renderPlainCell($s['temp_norm']) . "</td>\n";
        $out .= '          <td class="col-num">' . renderDeviationCell($s['temp_deviation']) . "</td>\n";
        $out .= '          <td class="col-num">' . renderPlainCell($s['precip_fallen']) . "</td>\n";
        $out .= '          <td class="col-num">' . renderPlainCell($s['precip_norm']) . "</td>\n";
        $out .= '          <td class="col-num">' . renderPlainCell($s['precip_percent']) . "</td>\n";
        $out .= "        </tr>\n";
    }
    return $out;
}

function buildMonthOptionsHtml(int $selectedMonth): string
{
    $out = '';
    for ($m = 1; $m <= 12; $m++) {
        $sel = $m === $selectedMonth ? ' selected' : '';
        $out .= '<option value="' . $m . '"' . $sel . '>' . e(monthName($m)) . "</option>\n";
    }
    return $out;
}

function buildStationCheckboxesHtml(array $selectedIds): string
{
    $selectedSet = array_flip(array_map('strval', $selectedIds));
    $out = '';
    foreach (TARGET_STATIONS as $id => $label) {
        $checked = isset($selectedSet[(string) $id]) ? ' checked' : '';
        $out .= '<label class="chk">'
              . '<input type="checkbox" name="ids[]" value="' . $id . '"' . $checked . '> '
              . e($label) . ' <span class="chk-id">' . $id . '</span>'
              . "</label>\n";
    }
    return $out;
}

function renderPage(array $data, int $month, int $year, array $selectedStations): string
{
    $rows          = buildTableRowsHtml($data['stations']);
    $monthOpts     = buildMonthOptionsHtml($month);
    $stationsChks  = buildStationCheckboxesHtml(array_keys($selectedStations));
    $total         = count($data['stations']);
    $selectedTotal = count($selectedStations);
    $currentYear   = (int) date('Y');

    $missingHtml = '';
    if (!empty($data['missing'])) {
        $items = array_map(static fn($m) => '<li>' . e($m) . '</li>', $data['missing']);
        $missingHtml = '<div class="missing-note"><b>Внимание:</b><ul>' . implode('', $items) . '</ul></div>';
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Температура воздуха и количество осадков</title>
<style>
  :root {
    --bg: #0f1115; --card: #171a21; --border: #2a2e38;
    --text: #e6e8ee; --text-muted: #9399a8;
    --pos: #ef5b5b; --neg: #4d9bf5; --zero: #9aa4b2;
  }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
  .page { max-width: 1100px; margin: 0 auto; padding: 24px 16px 48px; }
  h1 { font-size: 20px; margin: 0 0 16px; }

  form.controls { background:var(--card); border:1px solid var(--border); border-radius:10px; padding:14px 16px; margin-bottom:18px; }
  .row { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:10px; }
  .row:last-child { margin-bottom:0; }
  .row label.top { font-size:13px; color:var(--text-muted); }
  .row select, .row input[type="number"] {
    background:#11131a; color:var(--text); border:1px solid var(--border); border-radius:6px; padding:6px 10px; font-size:14px;
  }
  .row button {
    cursor:pointer; background:#2563eb; border:1px solid #2563eb; color:#fff; border-radius:6px; padding:7px 14px; font-size:14px;
  }
  .row button:hover { background:#1d4ed8; }
  .row button.secondary { background:transparent; border-color:var(--border); color:var(--text); }
  .row button.secondary:hover { background:#1e2129; }

  .stations-box { border:1px solid var(--border); border-radius:8px; padding:10px 12px; background:#11131a; }
  .stations-title { font-size:12px; color:var(--text-muted); margin-bottom:8px; text-transform:uppercase; letter-spacing:.05em; }
  .stations-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap:6px 14px; }
  label.chk { display:flex; align-items:center; gap:6px; font-size:13px; cursor:pointer; user-select:none; }
  label.chk input { accent-color:#2563eb; }
  .chk-id { color:var(--text-muted); font-size:11px; margin-left:auto; }

  .meta-strip { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:14px; color:var(--text-muted); font-size:13px; }

  .table-card { background:var(--card); border:1px solid var(--border); border-radius:10px; overflow:hidden; }
  .table-scroll { overflow-x:auto; }
  table { border-collapse:collapse; width:100%; font-size:13px; }
  th, td { padding:8px 10px; text-align:right; white-space:nowrap; border-bottom:1px solid var(--border); }
  th { text-align:right; color:var(--text-muted); font-weight:600; }
  .col-id, .col-name { text-align:left; }
  .col-name a { color:#7db4ff; text-decoration:none; }
  .col-name a:hover { text-decoration:underline; }
  .unit { display:block; font-size:10px; color:var(--text-muted); font-weight:400; }
  .cell-na { color:var(--text-muted); }
  .dev-pos { color:var(--pos); }
  .dev-neg { color:var(--neg); }
  .dev-zero, .dev-na { color:var(--zero); }

  .missing-note { margin-top:16px; padding:12px; border:1px solid #5a3a1a; background:#241a10; border-radius:8px; font-size:13px; color:#e2b98a; }
  .missing-note ul { margin:6px 0 0; padding-left:18px; }
  .page-footer { margin-top:18px; font-size:12px; color:var(--text-muted); }
</style>
</head>
<body>
<div class="page">

  <h1>Температура воздуха и количество осадков</h1>

  <form class="controls" method="get">
    <div class="row">
      <label class="top" for="month-select">Месяц</label>
      <select id="month-select" name="month">
        {$monthOpts}
      </select>

      <label class="top" for="year-input">Год</label>
      <input id="year-input" type="number" name="year" min="1900" max="{$currentYear}" value="{$year}">

      <button type="submit">Показать</button>
      <button type="button" class="secondary" onclick="toggleAll(true)">Выбрать все</button>
      <button type="button" class="secondary" onclick="toggleAll(false)">Снять все</button>
    </div>

    <div class="stations-box">
      <div class="stations-title">Станции ({$selectedTotal} выбрано)</div>
      <div class="stations-grid" id="stations-grid">
        {$stationsChks}
      </div>
    </div>
  </form>

  <div class="meta-strip">
    <div>Период: <b>{$data['period']}</b></div>
    <div>Показано станций: <b>{$total}</b></div>
    <div>Обновлено: {$data['generated_at']}</div>
  </div>

  <div class="table-card">
    <div class="table-scroll">
      <table>
        <thead>
          <tr>
            <th class="col-id">WMO ID</th>
            <th class="col-name">Станция</th>
            <th>Т средняя<span class="unit">°C</span></th>
            <th>Норма<span class="unit">°C</span></th>
            <th>Отклонение<span class="unit">°C</span></th>
            <th>Осадки<span class="unit">мм</span></th>
            <th>Норма<span class="unit">мм</span></th>
            <th>% нормы<span class="unit">осадков</span></th>
          </tr>
        </thead>
        <tbody>
{$rows}
        </tbody>
      </table>
    </div>
  </div>

  {$missingHtml}

  <div class="page-footer">
    Данные: pogodaiklimat.ru (страницы monitor.php по каждой станции)
  </div>

</div>

<script>
  function toggleAll(state) {
    document.querySelectorAll('#stations-grid input[type="checkbox"]').forEach(cb => cb.checked = state);
  }
</script>
</body>
</html>
HTML;
}

// ==========================================================================
//  5a. CLI-РЕЖИМ
// ==========================================================================

function runCli(): int
{
    try {
        [$month, $year] = resolvePeriod();
        $selected = resolveSelectedStations();
        $data = fetchAndParseAll($selected, $month, $year);

        file_put_contents(
            JSON_OUTPUT,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        logLine('Данные сохранены: ' . JSON_OUTPUT . "\n");
        logLine('Станций получено: ' . count($data['stations']) . ' из ' . count($selected) . "\n");

        return 0;
    } catch (Throwable $e) {
        logError('Ошибка: ' . $e->getMessage() . "\n");
        return 1;
    }
}

// ==========================================================================
//  5b. WEB-РЕЖИМ
// ==========================================================================

function runHttp(): void
{
    header('Cache-Control: public, s-maxage=1800, stale-while-revalidate=3600');

    $format = isset($_GET['format']) && $_GET['format'] === 'json' ? 'json' : 'html';
    [$month, $year] = resolvePeriod();
    $selected = resolveSelectedStations();

    try {
        $data = fetchAndParseAll($selected, $month, $year);
    } catch (Throwable $e) {
        logError('Ошибка: ' . $e->getMessage() . "\n");
        http_response_code(502);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><meta charset="utf-8">'
            . '<p style="font-family:sans-serif">Не удалось получить данные. Попробуйте позже.</p>';
        return;
    }

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo renderPage($data, $month, $year, $selected);
}

// ==========================================================================
//  MAIN
// ==========================================================================

if (PHP_SAPI === 'cli') {
    exit(runCli());
}

runHttp();
