<?php
/**
 * Парсер климатического монитора pogodaiklimat.ru
 * Забирает сводную таблицу (температура/осадки за текущий месяц) для
 * заданного списка метеостанций, сохраняет данные в JSON и генерирует
 * итоговую HTML-страницу с таблицей (по шаблону template.html).
 *
 * Запуск: php parser.php
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

// ==========================================================================
//  НАСТРОЙКИ
// ==========================================================================

const SOURCE_URL     = 'https://www.pogodaiklimat.ru/monitors.php?id=rus';
const JSON_OUTPUT     = __DIR__ . '/stations.json';
const HTML_TEMPLATE   = __DIR__ . '/template.html';
const HTML_OUTPUT     = __DIR__ . '/table.html';

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
    // Явно укажем кодировку, чтобы DOMDocument не пытался угадывать её сам
    $dom->loadHTML(
        '<?xml encoding="utf-8">' . $utf8Html,
        LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Заголовок с указанием месяца/года ("Сводная таблица ... Сентябрь 2026 г.")
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
            // Похоже на строку-разделитель региона без данных станции — пропускаем
            continue;
        }

        // Находим ячейку с названием станции, всё после неё — числовые колонки
        $nameCellIndex = 1; // по умолчанию: [0]=индекс, [1]=название
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

/**
 * Отбирает из общего списка станций только нужные, сохраняя порядок
 * из TARGET_STATIONS, и отдельно возвращает список ненайденных названий.
 */
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

// ==========================================================================
//  MAIN
// ==========================================================================

function main(): int
{
    try {
        fwrite(STDOUT, "Загрузка страницы: " . SOURCE_URL . "\n");
        $rawHtml  = fetchPage(SOURCE_URL);
        $utf8Html = toUtf8($rawHtml);

        fwrite(STDOUT, "Разбор сводной таблицы...\n");
        $parsed = parseStations($utf8Html);
        fwrite(STDOUT, 'Всего станций найдено на странице: ' . count($parsed['stations']) . "\n");

        $filtered = filterTargetStations($parsed['stations'], TARGET_STATIONS);
        fwrite(STDOUT, 'Отобрано станций из нашего списка: ' . count($filtered['stations']) . ' из ' . count(TARGET_STATIONS) . "\n");

        if (!empty($filtered['missing'])) {
            fwrite(STDOUT, "Не найдены на странице: " . implode(', ', $filtered['missing']) . "\n");
        }

        $output = [
            'source'       => SOURCE_URL,
            'period'       => $parsed['period'],
            'generated_at' => date('Y-m-d H:i:s'),
            'stations'     => $filtered['stations'],
            'missing'      => $filtered['missing'],
        ];

        file_put_contents(
            JSON_OUTPUT,
            json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        fwrite(STDOUT, 'Данные сохранены в JSON: ' . JSON_OUTPUT . "\n");

        if (is_file(HTML_TEMPLATE)) {
            $template = file_get_contents(HTML_TEMPLATE);
            $rowsHtml = buildTableRowsHtml($filtered['stations']);

            $finalHtml = strtr($template, [
                '{{PERIOD}}'       => e($parsed['period']),
                '{{GENERATED_AT}}' => e($output['generated_at']),
                '{{TOTAL}}'        => (string) count($filtered['stations']),
                '{{TABLE_ROWS}}'   => $rowsHtml,
            ]);

            file_put_contents(HTML_OUTPUT, $finalHtml);
            fwrite(STDOUT, 'HTML-страница с таблицей сгенерирована: ' . HTML_OUTPUT . "\n");
        } else {
            fwrite(STDOUT, "Внимание: шаблон " . HTML_TEMPLATE . " не найден, HTML-страница не сгенерирована.\n");
        }

        fwrite(STDOUT, "Готово.\n");
        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
        return 1;
    }
}

exit(main());
