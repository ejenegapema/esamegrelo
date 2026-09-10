<?php
/**
 * Единый скрипт: климатический монитор pogodaiklimat.ru
 * Для списка станций (WMO id) и выбранных месяца/года собирает
 * фактическую температуру, норму, отклонение и осадки, парся
 * страницы monitor.php?id=ID&month=M&year=Y (по одной на станцию —
 * именно там реально работают параметры month/year).
 *
 * Режимы:
 *  - Web: открой скрипт в браузере — увидишь форму (месяц/год) и таблицу.
 *         Можно получить чистый JSON: ?format=json&month=9&year=2026
 *  - CLI: php monitors.php [month] [year] — сохранит stations.json рядом.
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

// ==========================================================================
//  НАСТРОЙКИ
// ==========================================================================

const STATION_BASE_URL = 'https://www.pogodaiklimat.ru/monitor.php';

// Список станций произвольной длины: WMO id => подпись для чтения кода.
// Проверено поиском: 27612, 26063, 26850, 29638, 26702, 30710.
// Остальные id стоит перепроверить на странице
// https://www.pogodaiklimat.ru/monitor.php?id=XXXXX (в подписи страницы
// будет видно название станции).
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
//  0. МЕСЯЦ / ГОД
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

function buildStationUrl(int $id, int $month, int $year): string
{
    return STATION_BASE_URL . '?id=' . $id . '&month=' . $month . '&year=' . $year;
}

// ==========================================================================
//  1. ПАРАЛЛЕЛЬНАЯ ЗАГРУЗКА СТРАНИЦ (curl_multi)
// ==========================================================================

/**
 * Скачивает сразу несколько URL параллельно.
 * @param array<int|string,string> $urlsByKey ключ => URL
 * @return array<int|string,?string> ключ => тело ответа или null при ошибке
 */
function fetchManyPages(array $urlsByKey): array
{
    $results = [];

    if (!function_exists('curl_multi_init')) {
        // Fallback без cURL — последовательно через file_get_contents.
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

/** Превращает HTML-страницу в чистый текст (без тегов/скриптов) для регулярок. */
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

    if (preg_match('/Норма\s+среднемесячной\s+температуры[^:]*:\s*' . $num . '\s*°/u', $plainText, $m)) {
        $data['temp_norm'] = normalizeNumber($m[1]) . '°';
    }
    if (preg_match('/Фактическая\s+температура\s+месяца[^:]*:\s*' . $num . '\s*°/u', $plainText, $m)) {
        $data['temp_avg'] = normalizeNumber($m[1]) . '°';
    }
    if (preg_match('/Отклонение\s+от\s+нормы\s*:\s*' . $num . '\s*°/u', $plainText, $m)) {
        $data['temp_deviation'] = normalizeSigned($m[1]) . '°';
    }
    if (preg_match('/Норма\s+суммы\s+осадков[^:]*:\s*([0-9]+(?:[.,][0-9]+)?)\s*мм/u', $plainText, $m)) {
        $data['precip_norm'] = normalizeNumber($m[1]) . ' мм';
    }
    if (preg_match('/Выпало\s+осадков\s*:\s*([0-9]+(?:[.,][0-9]+)?)\s*мм/u', $plainText, $m)) {
        $data['precip_fallen'] = normalizeNumber($m[1]) . ' мм';
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
        $raw = '+' . $raw; // на сайте отклонение всегда со знаком, подстрахуемся
    }
    return $raw;
}

// ==========================================================================
//  3. СБОРКА ДАННЫХ ПО ВСЕМ СТАНЦИЯМ
// ==========================================================================

function fetchAndParseAll(int $month, int $year): array
{
    $urls = [];
    foreach (TARGET_STATIONS as $id => $label) {
        $urls[$id] = buildStationUrl($id, $month, $year);
    }

    logLine('Загрузка ' . count($urls) . " страниц параллельно...\n");
    $bodies = fetchManyPages($urls);

    $stations = [];
    $missing  = [];

    foreach (TARGET_STATIONS as $id => $label) {
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
        'month'        => $month,
        'year'         => $year,
        'period'       => monthName($month) . ' ' . $year,
        'generated_at' => date('c'),
        'stations'     => $stations,
        'missing'      => $missing,
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
        $percent = $s['precip_percent'] !== null ? $s['precip_percent'] . '%' : null;

        $out .= "        <tr>\n";
        $out .= '          <td class="col-id">' . e($s['wmo_id']) . "</td>\n";
        $out .= '          <td class="col-name"><a href="' . e($monitorUrl) . '" target="_blank" rel="noopener noreferrer">'
            . e($s['name']) . "</a></td>\n";
        $out .= '          <td class="col-num">' . renderPlainCell($s['temp_avg']) . "</td>\n";
        $out .= '          <td class="col-num">' . renderPlainCell($s['temp_norm']) . "</td>\n";
        $out .= '          <td class="col-num">' . renderDeviationCell($s['temp_deviation']) . "</td>\n";
        $out .= '          <td class="col-num">' . renderPlainCell($s['precip_fallen']) . "</td>\n";
        $out .= '          <td class="col-num">' . renderPlainCell($s['precip_norm']) . "</td>\n";
        $out .= '          <td class="col-num">' . renderPlainCell($percent) . "</td>\n";
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

function renderPage(array $data, int $month, int $year): string
{
    $rows        = buildTableRowsHtml($data['stations']);
    $monthOpts   = buildMonthOptionsHtml($month);
    $total       = count($data['stations']);
    $currentYear = (int) date('Y');

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
  .controls { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:18px; background:var(--card); border:1px solid var(--border); border-radius:10px; padding:12px 16px; }
  .controls label { font-size:13px; color:var(--text-muted); }
  .controls select, .controls input, .controls button {
    background:#11131a; color:var(--text); border:1px solid var(--border); border-radius:6px; padding:6px 10px; font-size:14px;
  }
  .controls button { cursor:pointer; background:#2563eb; border-color:#2563eb; color:#fff; }
  .controls button:hover { background:#1d4ed8; }
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
  .page-footer { margin-top:18px; font-size:12px; color:var(--text-muted); display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; }
</style>
</head>
<body>
<div class="page">

  <h1>Температура воздуха и количество осадков</h1>

  <form class="controls" method="get">
    <label for="month-select">Месяц</label>
    <select id="month-select" name="month">
      {$monthOpts}
    </select>

    <label for="year-input">Год</label>
    <input id="year-input" type="number" name="year" min="1900" max="{$currentYear}" value="{$year}">

    <button type="submit">Показать</button>
  </form>

  <div class="meta-strip">
    <div>Период: <b>{$data['period']}</b></div>
    <div>Станций: <b>{$total}</b></div>
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
            <th>Отклонение<span class="unit">от нормы</span></th>
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
    <span>Данные: pogodaiklimat.ru (страницы monitor.php по каждой станции)</span>
  </div>

</div>
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
        $data = fetchAndParseAll($month, $year);

        file_put_contents(
            JSON_OUTPUT,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        logLine('Данные сохранены: ' . JSON_OUTPUT . "\n");
        logLine('Станций получено: ' . count($data['stations']) . ' из ' . count(TARGET_STATIONS) . "\n");

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

    try {
        $data = fetchAndParseAll($month, $year);
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
    echo renderPage($data, $month, $year);
}

// ==========================================================================
//  MAIN
// ==========================================================================

if (PHP_SAPI === 'cli') {
    exit(runCli());
}

runHttp();
