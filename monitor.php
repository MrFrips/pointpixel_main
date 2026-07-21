<?php
// показывать ошибки вместо пустого HTTP 500 — так видно, что сломалось
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ================================================================
// PointPixel — единый cron-скрипт мониторинга. Делает две вещи:
//
//   1. Онлайн игроков  → stats_history.json + stats7d.json
//      (график «онлайн за 7 дней» на главной)
//   2. Проверка сервисов → monitor_history.json + status.json
//      (страница status.html — реальный аптайм за 90 дней)
//
// Подключение (ispmanager 6 lite):
//   Планировщик (cron) → новое задание, каждые 15 минут:
//       php /путь/до/сайта/monitor.php
//   или по URL: https://pointpixel.ru/monitor.php?key=pointpixel-cron
//
// База данных НЕ нужна — вся история хранится в json-файлах рядом.
// Пока status.json / stats7d.json не появились, сайт показывает демо.
// ================================================================

$SECRET    = 'pointpixel-cron';        // смени, если дёргаешь по URL
$MC_SERVER = 'play.pointpixel.ru';
$SLOW_MS   = 4000;                     // ответ дольше — считаем «сбои»

// Сервисы для мониторинга.
//   host  — ПРОСТО ТЕКСТ для отображения на странице
//   check — РЕАЛЬНЫЙ адрес, который проверяется (не показывается).
//           пустая строка '' = не проверять, на странице будет «НЕТ ДАННЫХ»
//   type  — 'mc' = minecraft-сервер, 'http' = сайт (ok = 2xx/3xx быстрее $SLOW_MS)
$SERVICES = [
    ['id' => 'proxy',    'name' => 'Прокси',   'host' => 'play.pointpixel.ru',           'check' => 'play.pointpixel.ru',  'type' => 'mc'],
    ['id' => 'hub',      'name' => 'Hub',      'host' => 'hub.pointpixel.ru:25584',      'check' => 'd33.joinserver.xyz:25584',  'type' => 'mc'],
    ['id' => 'survival', 'name' => 'Survival', 'host' => 'survival.pointpixel.ru:25661', 'check' => 's16.joinserver.xyz:25661',  'type' => 'mc'],
    ['id' => 'creative', 'name' => 'Creative', 'host' => 'creative.pointpixel.ru:25665', 'check' => 'f3.joinserver.xyz:25665',   'type' => 'mc'],
    ['id' => 'fun',      'name' => 'PixelFun', 'host' => 'fun.pointpixel.ru',            'check' => '',                          'type' => 'mc'], // впиши реальный ip в check, когда появится
    ['id' => 'shop',     'name' => 'Магазин',  'host' => 'shop.pointpixel.ru',           'check' => '', 'type' => 'http'],
];

$DIR         = __DIR__;
$STATS_HIST  = $DIR . '/stats_history.json';   // максимум онлайна по дням
$STATS_OUT   = $DIR . '/stats7d.json';         // 7 чисел для графика
$MON_HIST    = $DIR . '/monitor_history.json'; // счётчики проверок по дням
$STATUS_OUT  = $DIR . '/status.json';          // готовые данные для status.html

// --- защита при вызове через браузер (для cron по URL) ---
if (php_sapi_name() !== 'cli') {
    if (!isset($_GET['key']) || $_GET['key'] !== $SECRET) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// запрос с поддержкой хостингов, где выключен allow_url_fopen (тогда — через cURL)
function http_get($url, $timeout) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'PointPixel-Monitor',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['body' => $body === false ? false : $body, 'code' => $code];
    }
    if (!ini_get('allow_url_fopen')) return ['body' => false, 'code' => 0];
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true, 'user_agent' => 'PointPixel-Monitor']]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
        }
    }
    return ['body' => $body, 'code' => $code];
}

function fetch_json($url) {
    $r = http_get($url, 8);
    return ($r['body'] !== false && $r['body'] !== '') ? json_decode($r['body'], true) : null;
}

function load_json($file) {
    $d = file_exists($file) ? json_decode(file_get_contents($file), true) : null;
    return is_array($d) ? $d : [];
}

// ok / degraded / down по HTTP-ответу и времени
function http_check($url, $slow_ms) {
    $t0 = microtime(true);
    $r  = http_get($url, 10);
    $ms = (microtime(true) - $t0) * 1000;
    if ($r['body'] === false || $r['code'] === 0 || $r['code'] >= 500) return 'down';
    if ($r['code'] >= 400) return 'degraded';      // отвечает, но с ошибкой
    return $ms > $slow_ms ? 'degraded' : 'ok';
}

// проверка, что вообще можем писать файлы рядом со скриптом
if (@file_put_contents($DIR . '/.write_test', 'ok') === false) {
    exit("ОШИБКА: нет прав на запись в каталог $DIR — выстави права в файловом менеджере ispmanager\n");
}
@unlink($DIR . '/.write_test');

// проверка minecraft-сервера (с кэшем, чтобы не дёргать API дважды)
// возвращает ['up' => true|false|null, 'online' => N]; null = API недоступны
function mc_check($host) {
    static $cache = [];
    if (isset($cache[$host])) return $cache[$host];
    $d = fetch_json('https://api.mcsrvstat.us/3/' . $host);
    if (!is_array($d) || !isset($d['online'])) {
        $d = fetch_json('https://api.mcstatus.io/v2/status/java/' . $host);
    }
    if (!is_array($d) || !isset($d['online'])) {
        return $cache[$host] = ['up' => null, 'online' => 0];
    }
    $up = !empty($d['online']);
    $online = ($up && isset($d['players']['online'])) ? (int)$d['players']['online'] : 0;
    return $cache[$host] = ['up' => $up, 'online' => $online];
}

$today = date('Y-m-d');

// ================================================================
// 1. Онлайн игроков (для графика на главной)
// ================================================================
$mc = mc_check($MC_SERVER);
$mc_up = $mc['up']; $online = $mc['online'];

$hist = load_json($STATS_HIST);
$hist[$today] = max($online, isset($hist[$today]) ? (int)$hist[$today] : 0);
ksort($hist);
$hist = array_slice($hist, -30, null, true);
file_put_contents($STATS_HIST, json_encode($hist, JSON_PRETTY_PRINT));

$week = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $week[] = isset($hist[$day]) ? (int)$hist[$day] : 0;
}
file_put_contents($STATS_OUT, json_encode($week));

// ================================================================
// 2. Проверка сервисов (для status.html)
// ================================================================
$mon = load_json($MON_HIST);
$current = [];

foreach ($SERVICES as $svc) {
    $chk = isset($svc['check']) ? $svc['check'] : $svc['host'];
    if ($chk === '') {
        $st = null; // проверка выключена — на странице будет «НЕТ ДАННЫХ»
    } elseif ($svc['type'] === 'mc') {
        // оба API недоступны → не знаем, пропускаем замер (nodata)
        $r  = mc_check($chk);
        $st = ($r['up'] === null) ? null : ($r['up'] ? 'ok' : 'down');
    } else {
        $st = http_check($chk, $SLOW_MS);
    }
    $current[$svc['id']] = $st;
    if ($st === null) continue;

    if (!isset($mon[$svc['id']])) $mon[$svc['id']] = [];
    if (!isset($mon[$svc['id']][$today])) $mon[$svc['id']][$today] = ['o' => 0, 'd' => 0, 'x' => 0];
    $key = $st === 'ok' ? 'o' : ($st === 'degraded' ? 'd' : 'x');
    $mon[$svc['id']][$today][$key]++;
}

foreach ($mon as $id => $days) {
    ksort($days);
    $mon[$id] = array_slice($days, -92, null, true);
}
file_put_contents($MON_HIST, json_encode($mon));

// собрать status.json: 90 дней (от старого к новому) + аптайм по замерам
$out = ['updated' => time(), 'services' => []];
foreach ($SERVICES as $svc) {
    $h = isset($mon[$svc['id']]) ? $mon[$svc['id']] : [];
    $days = []; $up = 0; $total = 0;
    for ($i = 89; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days"));
        if (isset($h[$day])) {
            $c = $h[$day];
            $total += $c['o'] + $c['d'] + $c['x'];
            $up    += $c['o'] + $c['d'];
            $days[] = $c['x'] > 0 ? 'down' : ($c['d'] > 0 ? 'degraded' : 'ok');
        } else {
            $days[] = 'nodata';
        }
    }
    $st = $current[$svc['id']];
    if ($st === null) {
        $st = 'nodata';
        for ($j = count($days) - 1; $j >= 0; $j--) {
            if ($days[$j] !== 'nodata') { $st = $days[$j]; break; }
        }
    }
    $out['services'][] = [
        'name'   => $svc['name'],
        'host'   => $svc['host'],
        'status' => $st,
        'uptime' => $total ? number_format($up / $total * 100, 2, '.', '') : '100.00',
        'days'   => $days,
    ];
}
file_put_contents($STATUS_OUT, json_encode($out, JSON_UNESCAPED_UNICODE));

echo "OK: онлайн $online (максимум сегодня {$hist[$today]})\n";
foreach ($SERVICES as $svc) {
    echo str_pad($svc['name'], 12) . ' → ' . ($current[$svc['id']] === null ? 'нет данных' : $current[$svc['id']]) . "\n";
}
echo "записаны stats7d.json + status.json\n";