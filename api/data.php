<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '1');


// ----------------- cURL helpers -----------------
function curl_get($url, $cookieJar, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new Exception("cURL error: $err");
    return $res;
}

function curl_post($url, $postFields, $cookieJar, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new Exception("cURL error: $err");
    return $res;
}

// ----------------- GET parameters -----------------
$fd = isset($_GET['fd']) ? intval($_GET['fd']) : 1;
$fm = isset($_GET['fm']) ? intval($_GET['fm']) : 1;
$fy = isset($_GET['fy']) ? intval($_GET['fy']) : date('Y');

$ld = isset($_GET['ld']) ? intval($_GET['ld']) : date('d');
$lm = isset($_GET['lm']) ? intval($_GET['lm']) : date('m');
$ly = isset($_GET['ly']) ? intval($_GET['ly']) : date('Y');

$station = isset($_GET['station']) ? $_GET['station'] : '27612';

// Format dates as dd.mm.yyyy
$date_2 = sprintf('%02d.%02d.%04d', $fd, $fm, $fy);
$date_3 = sprintf('%02d.%02d.%04d', $ld, $lm, $ly);

// ----------------- Fetch data -----------------
$request = "tema, $date_2, $date_3, $station";

if (stripos($request, 'tema') === 0) {
    $parts   = array_map('trim', explode(',', $request));
    $date_2  = $parts[1];
    $date_3  = $parts[2];
    $station = $parts[3];

    $cookieJar   = sys_get_temp_dir() . '/pogoda_cookie.txt';
    $userHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/67.0.3396.99'
    ];

    // Login
    $loginUrl  = 'https://www.pogodaiklimat.ru/login.php';
    $loginPage = curl_get($loginUrl, $cookieJar, $userHeaders);

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($loginPage, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $submitValue = '';
    $nodes = $xpath->query("//input[@name='submit-login']");
    if ($nodes->length > 0) $submitValue = $nodes->item(0)->getAttribute('value');

    $post = [
        'submit-login' => $submitValue ?: 'post',
        'username' => 'Parser',
        'password' => 'Parser'
    ];
    curl_post($loginUrl, $post, $cookieJar, $userHeaders);

    // ----------------- Collect data -----------------
    $dates = [];
    $data  = [
        'tema'   => [], // anomaly
        'tmin'   => [], // min temp
        'tmax'   => [], // max temp
        'precip' => [], // precipitation
        'snow'   => []  // snow depth
    ];

    $startYear = intval(substr($date_2, -4));
    $endYear   = intval(substr($date_3, -4));

    for ($y = $startYear; $y <= $endYear; $y++) {
        $url  = "https://www.pogodaiklimat.ru/summary.php?y={$y}&id={$station}";
        $html = curl_get($url, $cookieJar, $userHeaders);
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'CP1251');

        $dom2 = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom2->loadHTML($html);
        libxml_clear_errors();
        $tables = $dom2->getElementsByTagName('table');
        if ($tables->length == 0) continue;
        $table = $tables->item(0);

        foreach ($table->getElementsByTagName('tr') as $ri => $tr) {
            if ($ri < 2) continue;
            $cells = $tr->getElementsByTagName('td');
            if ($cells->length < 28) continue;

            $dateCell = trim($cells->item(2)->textContent);
            if ($dateCell === '') continue;

            // Helper to clean numeric values
            $clean = function($txt) {
                $txt = trim($txt);
                if ($txt === '' || $txt === '-') return null;
                $val = str_replace(',', '.', preg_replace('/[^\d\-,\.]/u', '', $txt));
                return ($val === '' ? null : floatval($val));
            };

            $temaVal   = $clean($cells->item(4)->textContent);
            $tminVal   = $clean($cells->item(6)->textContent);
            $tmaxVal   = $clean($cells->item(7)->textContent);
            $precipVal = $clean($cells->item(26)->textContent);
            $snowVal   = $clean($cells->item(27)->textContent);

            if ($temaVal===null && $tminVal===null && $tmaxVal===null && $precipVal===null && $snowVal===null) continue;

            $dates[]          = $dateCell;
            $data['tema'][]   = $temaVal;
            $data['tmin'][]   = $tminVal;
            $data['tmax'][]   = $tmaxVal;
            $data['precip'][] = $precipVal;
            $data['snow'][]   = $snowVal;
        }
    }

    // ----------------- Slice arrays by requested range -----------------
    $timestamps = array_map(function($d){
        $dt = DateTime::createFromFormat('d.m.Y', $d);
        return $dt ? $dt->getTimestamp() : null;
    }, $dates);

    $tsStart = DateTime::createFromFormat('d.m.Y', $date_2)->getTimestamp();
    $tsEnd   = DateTime::createFromFormat('d.m.Y', $date_3)->getTimestamp();

    $sliceDates = [];
    $sliceData  = [
        'tema'=>[], 'tmin'=>[], 'tmax'=>[], 'precip'=>[], 'snow'=>[]
    ];

    foreach ($timestamps as $i => $ts) {
        if ($ts === null) continue;
        if ($ts >= $tsStart && $ts <= $tsEnd) {
            $sliceDates[]          = $dates[$i];
            $sliceData['tema'][]   = $data['tema'][$i];
            $sliceData['tmin'][]   = $data['tmin'][$i];
            $sliceData['tmax'][]   = $data['tmax'][$i];
            $sliceData['precip'][] = $data['precip'][$i];
            $sliceData['snow'][]   = $data['snow'][$i];
        }
    }

    // ----------------- Output -----------------
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'dates'  => $sliceDates,
        'tema'   => $sliceData['tema'],
        'tmin'   => $sliceData['tmin'],
        'tmax'   => $sliceData['tmax'],
        'precip' => $sliceData['precip'],
        'snow'   => $sliceData['snow']
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
?>
