<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

date_default_timezone_set('UTC');

$monitorMonth = getenv('MONITOR_MONTH') ?: '2026-07';

echo "=====================================\n";
echo "SEMERU MONITOR (FIXED)\n";
echo "Month : {$monitorMonth}\n";
echo "Time  : ".date('Y-m-d H:i:s')." UTC\n";
echo "=====================================\n";

$html = fetchSemeruData($monitorMonth);

if (!$html) {
    echo "ERROR: gagal mengambil data TNBTS\n";
    exit(1);
}

$current = parseQuotaTable($html);

echo "Jumlah tanggal ditemukan : ".count($current)."\n";

if (count($current) === 0) {
    echo "ERROR: parser tidak menemukan data\n";
    exit(1);
}

/*
 * HEARTBEAT (opsional)
 * hanya jalan saat scheduled run
 */
checkHeartbeat($current);

$statusFile = __DIR__.'/status.json';
$old = [];

if (file_exists($statusFile)) {

    $old = json_decode(file_get_contents($statusFile), true);

    if (!is_array($old)) {
        $old = [];
    }
}

echo "Data lama : ".count($old)."\n";

$changeCount = 0;

/*
 * LOOP CEK PERUBAHAN DATA
 * KIRIM WA SETIAP ADA PERUBAHAN
 */
foreach ($current as $tanggal => $data) {

    $oldStatus = $old[$tanggal]['status'] ?? null;
    $newStatus = $data['status'] ?? null;

    // skip jika data baru (belum ada pembanding)
    if ($oldStatus === null) {
        echo "[NEW] {$tanggal}\n";
        continue;
    }

    // NORMALIZE (biar tidak false trigger karena spasi)
    $oldStatusClean = trim($oldStatus);
    $newStatusClean = trim($newStatus);

    if ($oldStatusClean !== $newStatusClean) {

        $message =
            "🔔 PERUBAHAN KUOTA SEMERU\n\n".
            "Tanggal : {$tanggal}\n".
            "Status Lama : {$oldStatusClean}\n".
            "Status Baru : {$newStatusClean}\n\n".
            "Cek segera:\n".
            "https://bromotenggersemeru.id";

        sendWA($message);

        echo "[CHANGE SENT] {$tanggal}\n";

        $changeCount++;
    }
}

/*
 * SIMPAN DATA TERBARU
 */
file_put_contents(
    $statusFile,
    json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "Perubahan data : {$changeCount}\n";
echo "status.json updated\n";

exit(0);

/* ===================================================
   FUNCTIONS
=================================================== */

function fetchSemeruData($month)
{
    $url = 'https://bromotenggersemeru.id/website/home/get_view';

    $postData = [
        'action'     => 'kapasitas',
        'id_site'    => '8',
        'year_month' => $month
    ];

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'X-Requested-With: XMLHttpRequest'
        ]
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo "CURL ERROR FETCH : ".curl_error($ch)."\n";
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    return $response;
}

function parseQuotaTable($html)
{
    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

    $xpath = new DOMXPath($dom);

    $rows = $xpath->query('//tbody/tr');

    $result = [];

    foreach ($rows as $row) {

        $tds = $row->getElementsByTagName('td');

        if ($tds->length < 2) continue;

        $tanggal = trim(preg_replace('/\s+/', ' ', $tds->item(0)->textContent));
        $status = trim(preg_replace('/\s+/', ' ', $tds->item(1)->textContent));

        $status = preg_replace('/-?\d+$/', '', trim($status));

        $result[$tanggal] = [
            'status' => $status
        ];
    }

    return $result;
}

/*
 * HEARTBEAT
 */
function checkHeartbeat($current)
{
    $event = getenv('EVENT_NAME');

    // hanya untuk scheduled run
    if ($event !== 'schedule' && $event !== '0 1 * * *') {
        return;
    }

    $message =
        "📊 MONITOR SEMERU HEARTBEAT\n\n".
        "Tanggal : ".date('Y-m-d')."\n".
        "Jam UTC : ".date('H:i')."\n".
        "Jumlah Data : ".count($current)."\n\n".
        "Status : OK";

    sendWA($message);

    echo "HEARTBEAT SENT\n";
}

/*
 * SEND WHATSAPP
 */
function sendWA($message)
{
    $token = getenv('FONNTE_TOKEN');
    $target = getenv('WA_NUMBER');

    if (empty($token) || empty($target)) {
        echo "FONNTE_TOKEN / WA_NUMBER kosong\n";
        return;
    }

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'target' => $target,
            'message' => $message
        ],
        CURLOPT_HTTPHEADER => [
            "Authorization: {$token}"
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $response = curl_exec($curl);

    if (curl_errno($curl)) {
        echo "WA ERROR : ".curl_error($curl)."\n";
    } else {
        echo "WA RESPONSE : ".$response."\n";
    }

    curl_close($curl);
}
