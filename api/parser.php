<?php
/**
 * Парсер климатического монитора pogodaiklimat.ru
 * Забирает сводную таблицу (температура/осадки за текущий месяц) для
 * заданного списка метеостанций.
 *
 * Работает в двух режимах:
 *
 *  1) CLI (php parser.php) — например, на своём сервере/VPS по cron:
 *     скачивает данные, сохраняет stations.json и генерирует table.html
 *     рядом со скриптом (нужен доступ на запись).
 *
 *  2) Web / serverless (Vercel, любой обычный HTTP-хостинг) — файловая
 *     система там чаще всего доступна только на чтение, поэтому в этом
 *     режиме скрипт ничего не пишет на диск: он забирает свежие данные
 *     и сразу отдаёт готовую HTML-страницу (или JSON при ?format=json)
 *     прямо в ответ на запрос.
 *
 * Деплой на Vercel (важно):
 *   - Этот файл кладите в /api/parser.php — Vercel отдаст его по адресу
 *     /api/parser (PHP-рантайм сам обрабатывает маршрут).
 *   - style.css положите в корень проекта (или в /public) — то есть
 *     РЯДОМ с /api, а не внутри него, чтобы Vercel отдавал его как
 *     статический файл. Ниже, в STYLE_HREF, указан абсолютный путь
 *     "/style.css" — поправьте, если разместите файл иначе.
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

// ==========================================================================
//  НАСТРОЙКИ
// ==========================================================================

const SOURCE_URL   = 'https://www.pogodaiklimat.ru/monitors.php?id=rus';
const STYLE_HREF   = '/style.css'; // путь к style.css от корня сайта

// Локальные файлы используются только в CLI-режиме (запись на диск)
const JSON_OUTPUT   = __DIR__ . '/stations.json';
const HTML_OUTPUT   = __DIR__ . '/table.html';
const HTML_TEMPLATE = __DIR__ . '/template.html'; // если файла нет — используется встроенный шаблон ниже

// Список станций, которые нужно выбрать из общей таблицы (порядок сохраняется в выводе)
const TARGET_STATIONS = [
    'Москва',
    'Санкт-Петербург',
    'Минск',
    'Киев',
    'Калининград',
    'Нижний Новгород',
    'Мурманск',
    'Архангельск',
    'Ростов-на-Дону',
    'Сочи',
    'Оренбург',
    'Екатеринбург',
    'Новосибирск',
    'Норильск',
    'Иркутск',
    'Якутск',
    'Остров Визе',
    'Владивосток',
];

// Встроенный шаблон страницы — используется всегда в web/serverless-режиме
// (чтобы скрипт не зависел от чтения соседних файлов на хостинге) и как
// запасной вариант в CLI-режиме, если template.html не найден рядом.
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
        <span class="value"><a href="https://www.pogodaiklimat.ru/monitors.php?id=rus" target="_blank" rel="noopener noreferrer">pogodaiklimat.ru</a></span>
      </div>
      <div class="meta-item">
        <span class="label">Станций</span>
        <span class="value">{{TOTAL}}</span>
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
      <a href="https://www.pogodaiklimat.ru/monitor.php" target="_blank" rel="noopener noreferrer">Открыть климатический монитор →</a>
    </footer>

  </div>
</body>
</html>
HTML;

// ==========================================================================
//  ЛОГИРОВАНИЕ (безопасно для CLI и web/serverless)
// ==========================================================================
//
// STDOUT/STDERR — это константы, которые PHP определяет ТОЛЬКО в CLI SAPI.
// На Vercel (и вообще на любом web/serverless рантайме) их не существует,
// поэтому прямое fwrite(STDOUT, ...) там падает с Fatal error. Вне CLI
// пишем в error_log() — это уходит в логи хостинга, а не в тело HTTP-ответа
// (что важно: иначе служебные сообщения испортили бы HTML/JSON в ответе).

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
//  1. ЗАГРУЗКА СТРАНИЦЫ
// ==========================================================================

/**
 * Скачивает страницу по URL через cURL (с fallback на file_get_contents).
 */
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
            CURLOPT_ENCODING       => '', // разрешить gzip/deflate автоматически
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

    // Fallback, если расширение cURL недоступно
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

/**
 * Приводит HTML к UTF-8, определяя исходную кодировку по meta-тегу
 * (сайт исторически может отдавать windows-1251).
 */
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

/**
 * Разбирает HTML сводной таблицы и возвращает список всех найденных станций
 * плюс подпись периода (например, "... Сентябрь 2026 г.").
 *
 * Строки таблицы отбираются по наличию ссылки вида monitor.php?id=NNNNN —
 * это устойчиво к мелким изменениям вёрстки (классы, доп. атрибуты и т.п.),
 * так как именно такая ссылка есть только в строках со станциями.
 */
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
            continue; // строка-разделитель региона без данных станции
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
    $text = str_replace("\xC2\xA0", ' ', $text); // неразрывный пробел
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

// ==========================================================================
//  3. ОТБОР НУЖНЫХ СТАНЦИЙ
// ==========================================================================

function filterTargetStations(array $allStations, array $targetNames): array
{
    $byName = [];
    foreach ($allStations as $st) {
        $byName[$st['name']] = $st;
    }

    $ordered = [];
    $missing = [];

    foreach ($targetNames as $target) {
        if (isset($byName[$target])) {
            $ordered[] = $byName[$target];
        } else {
            $missing[] = $target;
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

/**
 * Собирает финальный HTML на основе шаблона. В CLI-режиме, если рядом
 * лежит template.html, используется он (удобно для локальной правки
 * вёрстки без изменения PHP). Иначе — встроенный шаблон.
 */
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
//  5. ОСНОВНАЯ ЛОГИКА (общая для CLI и web)
// ==========================================================================

/**
 * Загружает страницу-источник, парсит и отбирает нужные станции.
 * Бросает исключение при любой ошибке — вызывающий код решает,
 * как её показать (в CLI — в stderr, в web — как HTTP 502/JSON-ошибку).
 */
function fetchAndFilter(): array
{
    logLine("Загрузка страницы: " . SOURCE_URL . "\n");
    $rawHtml  = fetchPage(SOURCE_URL);
    $utf8Html = toUtf8($rawHtml);

    logLine("Разбор сводной таблицы...\n");
    $parsed = parseStations($utf8Html);
    logLine('Всего станций найдено на странице: ' . count($parsed['stations']) . "\n");

    $filtered = filterTargetStations($parsed['stations'], TARGET_STATIONS);
    logLine('Отобрано станций из нашего списка: ' . count($filtered['stations']) . ' из ' . count(TARGET_STATIONS) . "\n");

    if (!empty($filtered['missing'])) {
        logLine("Не найдены на странице: " . implode(', ', $filtered['missing']) . "\n");
    }

    return [
        'source'       => SOURCE_URL,
        'period'       => $parsed['period'],
        'generated_at' => date('c'),
        'stations'     => $filtered['stations'],
        'missing'      => $filtered['missing'],
    ];
}

// ==========================================================================
//  6a. CLI-РЕЖИМ — сохраняет stations.json и table.html на диск
// ==========================================================================

function runCli(): int
{
    try {
        $data = fetchAndFilter();

        file_put_contents(
            JSON_OUTPUT,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        logLine('Данные сохранены в JSON: ' . JSON_OUTPUT . "\n");

        $html = renderFullHtml([
            '{{STYLE_HREF}}'   => './style.css', // локально — рядом лежащий файл
            '{{PERIOD}}'       => e($data['period']),
            '{{GENERATED_AT}}' => e($data['generated_at']),
            '{{TOTAL}}'        => (string) count($data['stations']),
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
//  6b. WEB / SERVERLESS-РЕЖИМ — отдаёт результат прямо в HTTP-ответ
// ==========================================================================

function runHttp(): void
{
    // Небольшое кэширование на стороне CDN/браузера, чтобы не дёргать
    // источник при каждом заходе (данные меняются не чаще пары раз в час).
    header('Cache-Control: public, s-maxage=1800, stale-while-revalidate=3600');

    $format = isset($_GET['format']) && $_GET['format'] === 'json' ? 'json' : 'html';

    try {
        $data = fetchAndFilter();
    } catch (Throwable $e) {
        logError('Ошибка: ' . $e->getMessage() . "\n");
        http_response_code(502);

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => 'Не удалось получить данные с источника.',
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
