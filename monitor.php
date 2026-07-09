<?php
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

// Сервисы для мониторинга. type: 'mc' — сам игровой сервер,
// 'http' — любой сайт/API (ok = ответ 2xx/3xx быстрее $SLOW_MS).
$SERVICES = [
    ['id' => 'proxy', 'name' => 'Прокси', 'host' => 'play.pointpixel.ru', 'type' => 'mc'],
    ['id' => 'survival', 'name' => 'Выживание',     'host' => 'wiki.pointpixel.ru',=> 'mc'],
    ['id' => 'creative', 'name' => 'Креатив',  'host' => 'shop.pointpixel.ru',=> 'mc'],
    ['id' => 'shop',  'name' => 'Магазин',  'host' => 'hop.pointpixel.ru', 'type' => 'http', 'url' => 'https://shop.pointpixel.ru'],
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

function fetch_json($url) {
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'PointPixel-Monitor']]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? json_decode($raw, true) : null;
}

function load_json($file) {
    $d = file_exists($file) ? json_decode(file_get_contents($file), true) : null;
    return is_array($d) ? $d : [];
}

// ok / degraded / down по HTTP-ответу и времени
function http_check($url, $slow_ms) {
    $t0  = microtime(true);
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'user_agent' => 'PointPixel-Monitor']]);
    $raw = @file_get_contents($url, false, $ctx);
    $ms  = (microtime(true) - $t0) * 1000;
    $code = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
        }
    }
    if ($raw === false || $code === 0 || $code >= 500) return 'down';
    if ($code >= 400) return 'degraded';           // отвечает, но с ошибкой
    return $ms > $slow_ms ? 'degraded' : 'ok';
}

$today = date('Y-m-d');

// ================================================================
// 1. Онлайн игроков (для графика на главной)
// ================================================================
$mc_up = null; $online = 0;
$d = fetch_json('https://api.mcsrvstat.us/3/' . $MC_SERVER);
if (is_array($d) && isset($d['online'])) {
    $mc_up  = !empty($d['online']);
    $online = ($mc_up && isset($d['players']['online'])) ? (int)$d['players']['online'] : 0;
} else {
    $d = fetch_json('https://api.mcstatus.io/v2/status/java/' . $MC_SERVER);
    if (is_array($d) && isset($d['online'])) {
        $mc_up  = !empty($d['online']);
        $online = ($mc_up && isset($d['players']['online'])) ? (int)$d['players']['online'] : 0;
    }
}

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
    if ($svc['type'] === 'mc') {
        // оба API недоступны → не знаем, пропускаем замер (nodata)
        $st = ($mc_up === null) ? null : ($mc_up ? 'ok' : 'down');
    } else {
        $st = http_check($svc['url'], $SLOW_MS);
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
