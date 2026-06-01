<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

date_default_timezone_set('UTC');

$monitorMonth = getenv('MONITOR_MONTH') ?: '2026-07';

echo "=====================================\n";
echo "SEMERU MONITOR\n";
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

checkHeartbeat($current);

$statusFile = __DIR__.'/status.json';

$old = [];

if (file_exists($statusFile)) {

    $old = json_decode(
        file_get_contents($statusFile),
        true
    );

    if (!is_array($old)) {
        $old = [];
    }
}

echo "Data lama : ".count($old)."\n";

$openedCount = 0;
$changeCount = 0;

foreach ($current as $tanggal => $data) {

    if (!isset($old[$tanggal])) {

        echo "[NEW] {$tanggal}\n";
        continue;
    }

    if ($old[$tanggal] != $data) {

        $changeCount++;

        echo "[CHANGE] {$tanggal}\n";
    }

    $oldStatus = $old[$tanggal]['status'] ?? '';
    $newStatus = $data['status'] ?? '';

    $oldFull =
        stripos($oldStatus,'Kuota Penuh') !== false;

    $newFull =
        stripos($newStatus,'Kuota Penuh') !== false;

    /*
     * HANYA KIRIM WA
     * JIKA STATUS BERUBAH
     * DARI PENUH MENJADI TIDAK PENUH
     */

    if ($oldFull && !$newFull) {

        $message =
            "🚨 KUOTA SEMERU TERSEDIA\n\n".
            "Tanggal : {$tanggal}\n".
            "Status  : {$newStatus}\n\n".
            "Segera cek:\n".
            "https://bromotenggersemeru.id";

        sendWA($message);

        echo "[OPEN] {$tanggal}\n";

        $openedCount++;
    }
}

file_put_contents(
    $statusFile,
    json_encode(
        $current,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE
    )
);

echo "Perubahan data : {$changeCount}\n";
echo "Kuota terbuka  : {$openedCount}\n";
echo "status.json updated\n";

exit(0);

/* ===================================================
   FUNCTIONS
=================================================== */

function fetchSemeruData($month)
{
    $url =
        'https://bromotenggersemeru.id/website/home/get_view';

    $postData = [

        'action'     => 'kapasitas',
        'id_site'    => '8',
        'year_month' => $month

    ];

    $ch = curl_init();

    curl_setopt_array($ch,[

        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postData),
        CURLOPT_TIMEOUT        => 30,

        CURLOPT_HTTPHEADER => [
            'X-Requested-With: XMLHttpRequest'
        ]

    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {

        echo "CURL ERROR : ".
            curl_error($ch)."\n";

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

    $dom->loadHTML(
        '<?xml encoding="utf-8" ?>'.$html
    );

    $xpath = new DOMXPath($dom);

    $rows = $xpath->query('//tbody/tr');

    $result = [];

    foreach ($rows as $row) {

        $tds = $row->getElementsByTagName('td');

        if ($tds->length < 2) {
            continue;
        }

        $tanggal = trim(
            preg_replace(
                '/\s+/',
                ' ',
                $tds->item(0)->textContent
            )
        );

        $statusCell = $tds->item(1);

        $status = trim(
            preg_replace(
                '/\s+/',
                ' ',
                $statusCell->textContent
            )
        );

        $hidden = null;

        $spans =
            $statusCell->getElementsByTagName('span');

        foreach ($spans as $span) {

            $class =
                $span->getAttribute('class');

            if (
                strpos($class,'hide')
                !== false
            ) {

                $hidden =
                    (int)trim(
                        $span->textContent
                    );
            }
        }

        $status = preg_replace(
            '/-?\d+$/',
            '',
            trim($status)
        );

        $result[$tanggal] = [

            'status' => trim($status),
            'hidden' => $hidden

        ];
    }

    return $result;
}

function checkHeartbeat($current)
{
    /*
     * Trigger hanya untuk cron:
     * 0 1 * * *
     *
     * (08:00 WIB)
     */

    $event = getenv('EVENT_NAME');

    if ($event !== '0 1 * * *') {
        return;
    }

    $message =
        "📊 Monitor Semeru Aktif\n\n".
        "Tanggal : ".date('Y-m-d')."\n".
        "Jam UTC : ".date('H:i')."\n\n".
        "Bulan Dipantau : ".
        (getenv('MONITOR_MONTH') ?: '-') . "\n".
        "Jumlah Tanggal : ".
        count($current)."\n\n".
        "Status : OK";

    sendWA($message);

    echo "HEARTBEAT SENT\n";
}

function sendWA($message)
{
    $token =
        getenv('FONNTE_TOKEN');

    $target =
        getenv('WA_NUMBER');

    if (
        empty($token)
        ||
        empty($target)
    ) {

        echo "FONNTE_TOKEN / WA_NUMBER kosong\n";
        return;
    }

    $curl = curl_init();

    curl_setopt_array($curl,[

        CURLOPT_URL =>
            'https://api.fonnte.com/send',

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS => [

            'target'  => $target,
            'message' => $message

        ],

        CURLOPT_HTTPHEADER => [

            "Authorization: {$token}"

        ]

    ]);

    $response = curl_exec($curl);

    if (curl_errno($curl)) {

        echo "WA ERROR : ".
            curl_error($curl)."\n";

    } else {

        echo "WA SENT\n";
    }

    curl_close($curl);
}
