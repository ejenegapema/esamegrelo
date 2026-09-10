<?php
/**
 * Парсер климатического монитора pogodaiklimat.ru
 * Забирает данные (температура/осадки за выбранный месяц и год) для
 * заданного списка станций (по их WMO-индексам).
 *
 * Работает в двух режимах:
 *  1) CLI (php parser.php [month] [year]) — сохраняет stations.json и
 *     table.html рядом со скриптом.
 *  2) Web / serverless — отдаёт HTML или JSON (?format=json), параметры
 *     месяца/года берутся из GET (?month=&year=).
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

// ==========================================================================
//  НАСТРОЙКИ
// ==========================================================================

const SOURCE_BASE_URL = 'https://www.pogodaiklimat.ru/monitors.php';
const STYLE_HREF      = '/style.css';

const JSON_OUTPUT   = __DIR__ . '/stations.json';
const HTML_OUTPUT   = __DIR__ . '/table.html';
const HTML_TEMPLATE = __DIR__ . '/template.html';

/**
 * Список станций произвольной длины: WMO id => подпись (используется
 * только для читаемости кода и для сообщений "не найдено на странице";
 * настоящее имя станции берётся из ответа сайта).
 *
 * Чтобы добавить/убрать город — просто добавьте/удалите строку,
 * ограничений на длину списка нет.
 *
 * ВАЖНО: значения id ниже нужно сверить на странице конкретной станции,
 * например https://www.pogodaiklimat.ru/monitor.php?id=27612 (Москва) —
 * там же в разделе "Источник данных" видно название станции.
 */
const TARGET_STATIONS = [
    27612 => 'Москва',
    26063 => 'Санкт-Петербург',
    26850 => 'Минск',            // проверьте id
    33345 => 'Киев',              // проверьте id
    26702 => 'Калининград',       // проверьте id
    27459 => 'Нижний Новгород',   // проверьте id
    22113 => 'Мурманск',          // проверьте id
    22550 => 'Архангельск',       // проверьте id
    34731 => 'Ростов-на-Дону',    // проверьте id
    37099 => 'Сочи',              // проверьте id
    35121 => 'Оренбург',          // проверьте id
    28440 => 'Екатеринбург',      // проверьте id
    29638 => 'Новосибирск',       // проверьте id
    23078 => 'Норильск',          // проверьте id
    30710 => 'Иркутск',
    24959 => 'Якутск',            // проверьте id
    20087 => 'Остров Визе',       // проверьте id
    31960 => 'Владивосток',       // проверьте id
];

const EMBEDDED_TEMPLATE = <<<'HTML'
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Климатический монитор — выбранные станции</title>
  <link rel="stylesheet" href="{{STYLE_HREF}}" />
</head>
<body>
  <div class="page">

    <header class="page-header">
      <span class="eyebrow"><span class="dot"></span> Погода и климат · сводка</span>
      <h1>Климатический монитор</h1>
      <p class="subtitle">{{PERIOD}} &middot; данные по <b>{{TOTAL}}</b> выбранным метеостанциям</p>
    </header>

    <section class="meta-strip">
      <div class="meta-item">
        <span class="label">Обновлено</span>
        <span class="value">{{GENERATED_AT}}</span>
      </div>
      <div class="meta-item">
        <span class="label">Источник</span>
        <span class="value"><a href="{{SOURCE_URL}}" target="_blank" rel="noopener noreferrer">pogodaiklimat.ru</a></span>
      </div>
      <div class="meta-item">
        <span class="label">Станций</span>
        <span class="value">{{TOTAL}}</span>
      </div>
      <div class="meta-item">
        <span class="label">Период</span>
        <span class="value">{{MONTH}} / {{YEAR}}</span>
      </div>
    </section>

    <div class="table-card">
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th class="col-id">Индекс</th>
              <th class="col-name">Станция</th>
              <th>Т° сред.<span class="unit">°C</span></th>
              <th>Т° норма<span class="unit">°C</span></th>
              <th>Отклонение<span class="unit">от нормы</span></th>
              <th>Осадки<span class="unit">мм</span></th>
              <th>Норма<span class="unit">мм</span></th>
              <th>% от нормы<span class="unit">осадков</span></th>
            </tr>
          </thead>
          <tbody>
{{TABLE_ROWS}}
          </tbody>
        </table>
      </div>
    </div>

    <div class="legend">
      <span class="legend-item"><span class="legend-swatch pos"></span> теплее нормы</span>
      <span class="legend-item"><span class="legend-swatch neg"></span> холоднее нормы</span>
      <span class="legend-item"><span class="legend-swatch zero"></span> около нормы</span>
    </div>

    <footer class="page-footer">
      <span>Данные: pogodaiklimat.ru · сформировано автоматическим PHP-парсером</span>
      <a href="{{SOURCE_URL}}" target="_blank" rel="noopener noreferrer">Открыть климатический монитор →</a>
    </footer>

  </div>
</body>
</html>
HTML;

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

/**
 * Определяет месяц и год запроса:
 *  - в CLI: из аргументов (php parser.php 7 2025), иначе — текущие;
 *  - в web: из $_GET['month'] / $_GET['year'], иначе — текущие.
 * Всегда возвращает валидные значения (month: 1-12, year: разумный диапазон).
 */
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
    $year  = max(1900, min(((int) date('Y')) + 1, $year));

    return [$month, $year];
}

/**
 * Строит URL сводной таблицы для конкретных станций/месяца/года.
 * Пример: monitors.php?id=27612,26063&month=7&year=2025
 */
function buildSourceUrl(array $ids, int $month, int $year): string
{
    $idsParam = implode(',', array_map('strval', $ids));

    return SOURCE_BASE_URL
        . '?id=' . $idsParam
        . '&month=' . $month
        . '&year=' . $year;
}

// ==========================================================================
//  1. ЗАГРУЗКА СТРАНИЦЫ
// ==========================================================================

function fetchPage(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                    . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Ошибка cURL при загрузке $url: $error");
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new RuntimeException("Сервер вернул HTTP $httpCode для $url");
        }

        return $body;
    }

    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: Mozilla/5.0\r\nAccept-Language: ru-RU,ru;q=0.9\r\n",
            'timeout' => 25,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException("Не удалось загрузить $url (file_get_contents).");
    }

    return $body;
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

// ==========================================================================
//  2. РАЗБОР ТАБЛИЦЫ
// ==========================================================================

function parseStations(string $utf8Html): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(
        '<?xml encoding="utf-8">' . $utf8Html,
        LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $period = '';
    $h1Nodes = $xpath->query('//h1');
    if ($h1Nodes->length > 0) {
        $period = normalizeText($h1Nodes->item(0)->textContent);
    }

    $rowNodes = $xpath->query('//tr[.//a[contains(@href, "monitor.php?id=")]]');

    $stations = [];

    foreach ($rowNodes as $tr) {
        /** @var DOMElement $tr */
        $linkNode = $xpath->query('.//a[contains(@href, "monitor.php?id=")]', $tr)->item(0);
        if (!$linkNode) {
            continue;
        }

        $name = normalizeText($linkNode->textContent);

        preg_match('/id=(\d+)/', $linkNode->getAttribute('href'), $idMatch);
        $wmoId = $idMatch[1] ?? '';

        $cellNodes = $xpath->query('.//td', $tr);
        $cells = [];
        foreach ($cellNodes as $td) {
            $cells[] = normalizeText($td->textContent);
        }

        if (count($cells) < 3) {
            continue;
        }

        $nameCellIndex = 1;
        foreach ($cells as $i => $c) {
            if ($c === $name) {
                $nameCellIndex = $i;
                break;
            }
        }

        $index = $cells[0] ?? '';
        $numericCells = array_slice($cells, $nameCellIndex + 1);
        $numericCells = array_pad($numericCells, 6, null);

        [$tempAvg, $tempNorm, $tempDeviation, $precipFallen, $precipNorm, $precipPercent] = $numericCells;

        $stations[] = [
            'wmo_id'         => $wmoId,
            'index'          => $index,
            'name'           => $name,
            'temp_avg'       => $tempAvg,
            'temp_norm'      => $tempNorm,
            'temp_deviation' => $tempDeviation,
            'precip_fallen'  => $precipFallen,
            'precip_norm'    => $precipNorm,
            'precip_percent' => $precipPercent,
        ];
    }

    return [
        'period'   => $period,
        'stations' => $stations,
    ];
}

function normalizeText(string $text): string
{
    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

// ==========================================================================
//  3. ОТБОР / ПОРЯДОК НУЖНЫХ СТАНЦИЙ (по WMO id)
// ==========================================================================

/**
 * Переставляет станции в порядке TARGET_STATIONS (т.к. сайт может вернуть
 * их в своём порядке/группировке по региону) и находит отсутствующие.
 */
function reorderStations(array $allStations, array $targetStations): array
{
    $byId = [];
    foreach ($allStations as $st) {
        $byId[$st['wmo_id']] = $st;
    }

    $ordered = [];
    $missing = [];

    foreach ($targetStations as $id => $label) {
        $idStr = (string) $id;
        if (isset($byId[$idStr])) {
            $ordered[] = $byId[$idStr];
        } else {
            $missing[] = "$label (id $idStr)";
        }
    }

    return ['stations' => $ordered, 'missing' => $missing];
}

// ==========================================================================
//  4. РЕНДЕР HTML-ТАБЛИЦЫ
// ==========================================================================

function renderDeviationCell(?string $raw): string
{
    $raw = $raw === null ? '' : trim($raw);

    if ($raw === '' || strtoupper($raw) === 'N/A') {
        return '<span class="dev dev-na">н/д</span>';
    }

    if (str_starts_with($raw, '+')) {
        $cls = 'dev-pos';
    } elseif (str_starts_with($raw, '-') || str_starts_with($raw, '−')) {
        $cls = 'dev-neg';
    } else {
        $cls = 'dev-zero';
    }

    return '<span class="dev ' . $cls . '">' . e($raw) . '</span>';
}

function renderPlainCell(?string $raw): string
{
    $raw = $raw === null ? '' : trim($raw);
    if ($raw === '' || strtoupper($raw) === 'N/A') {
        return '<span class="cell-na">н/д</span>';
    }
    return e($raw);
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function buildTableRowsHtml(array $stations): string
{
    $out = '';

    foreach ($stations as $s) {
        $monitorUrl = 'https://www.pogodaiklimat.ru/monitor.php?id=' . rawurlencode($s['wmo_id']);

        $out .= "        <tr>\n";
        $out .= '          <td class="col-id">' . e($s['index']) . "</td>\n";
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

function renderFullHtml(array $vars): string
{
    $template = EMBEDDED_TEMPLATE;

    if (PHP_SAPI === 'cli' && is_file(HTML_TEMPLATE)) {
        $fileTemplate = @file_get_contents(HTML_TEMPLATE);
        if ($fileTemplate !== false && $fileTemplate !== '') {
            $template = $fileTemplate;
        }
    }

    return strtr($template, $vars);
}

// ==========================================================================
//  5. ОСНОВНАЯ ЛОГИКА
// ==========================================================================

function fetchAndFilter(int $month, int $year): array
{
    $ids = array_keys(TARGET_STATIONS);
    $url = buildSourceUrl($ids, $month, $year);

    logLine("Загрузка страницы: $url\n");
    $rawHtml  = fetchPage($url);
    $utf8Html = toUtf8($rawHtml);

    logLine("Разбор сводной таблицы...\n");
    $parsed = parseStations($utf8Html);
    logLine('Станций получено от сайта: ' . count($parsed['stations']) . "\n");

    $result = reorderStations($parsed['stations'], TARGET_STATIONS);
    logLine('Отобрано станций из нашего списка: ' . count($result['stations']) . ' из ' . count(TARGET_STATIONS) . "\n");

    if (!empty($result['missing'])) {
        logLine("Не найдены на странице: " . implode(', ', $result['missing']) . "\n");
    }

    return [
        'source'       => $url,
        'period'       => $parsed['period'],
        'month'        => $month,
        'year'         => $year,
        'generated_at' => date('c'),
        'stations'     => $result['stations'],
        'missing'      => $result['missing'],
    ];
}

// ==========================================================================
//  6a. CLI-РЕЖИМ
// ==========================================================================

function runCli(): int
{
    try {
        [$month, $year] = resolvePeriod();
        $data = fetchAndFilter($month, $year);

        file_put_contents(
            JSON_OUTPUT,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        logLine('Данные сохранены в JSON: ' . JSON_OUTPUT . "\n");

        $html = renderFullHtml([
            '{{STYLE_HREF}}'   => './style.css',
            '{{PERIOD}}'       => e($data['period']),
            '{{GENERATED_AT}}' => e($data['generated_at']),
            '{{TOTAL}}'        => (string) count($data['stations']),
            '{{MONTH}}'        => (string) $data['month'],
            '{{YEAR}}'         => (string) $data['year'],
            '{{SOURCE_URL}}'   => e($data['source']),
            '{{TABLE_ROWS}}'   => buildTableRowsHtml($data['stations']),
        ]);

        file_put_contents(HTML_OUTPUT, $html);
        logLine('HTML-страница с таблицей сгенерирована: ' . HTML_OUTPUT . "\n");
        logLine("Готово.\n");

        return 0;
    } catch (Throwable $e) {
        logError('Ошибка: ' . $e->getMessage() . "\n");
        return 1;
    }
}

// ==========================================================================
//  6b. WEB / SERVERLESS-РЕЖИМ
// ==========================================================================

function runHttp(): void
{
    header('Cache-Control: public, s-maxage=1800, stale-while-revalidate=3600');

    $format = isset($_GET['format']) && $_GET['format'] === 'json' ? 'json' : 'html';
    [$month, $year] = resolvePeriod();

    try {
        $data = fetchAndFilter($month, $year);
    } catch (Throwable $e) {
        logError('Ошибка: ' . $e->getMessage() . "\n");
        http_response_code(502);

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => 'Не удалось получить данные с источника.',
                'month' => $month,
                'year'  => $year,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><meta charset="utf-8">'
                . '<p style="font-family:sans-serif">Не удалось получить данные с источника. '
                . 'Попробуйте обновить страницу через минуту.</p>';
        }
        return;
    }

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return;
    }

    $html = renderFullHtml([
        '{{STYLE_HREF}}'   => STYLE_HREF,
        '{{PERIOD}}'       => e($data['period']),
        '{{GENERATED_AT}}' => e($data['generated_at']),
        '{{TOTAL}}'        => (string) count($data['stations']),
        '{{MONTH}}'        => (string) $data['month'],
        '{{YEAR}}'         => (string) $data['year'],
        '{{SOURCE_URL}}'   => e($data['source']),
        '{{TABLE_ROWS}}'   => buildTableRowsHtml($data['stations']),
    ]);

    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}

// ==========================================================================
//  MAIN
// ==========================================================================

if (PHP_SAPI === 'cli') {
    exit(runCli());
}

runHttp();
