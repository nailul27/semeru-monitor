<?php

$url = 'https://bromotenggersemeru.id/website/home/get_view';

$postData = [
    'action' => 'kapasitas',
    'id_site' => '8',
    'year_month' => date('Y-m')
];

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($postData),
    CURLOPT_HTTPHEADER => [
        'X-Requested-With: XMLHttpRequest'
    ]
]);

$html = curl_exec($ch);
curl_close($ch);

$current = [];

preg_match_all(
    '/<td>(.*?)<\/td>.*?<span class="text-red">(.*?)<\/span>.*?<span class="hide">(-?\d+)<\/span>/s',
    $html,
    $matches,
    PREG_SET_ORDER
);

foreach($matches as $m){

    $tanggal = strip_tags($m[1]);

    $status = trim($m[2]);

    $hidden = (int)$m[3];

    $current[$tanggal] = [
        'status' => $status,
        'hidden' => $hidden
    ];
}

$statusFile = 'status.json';

$old = [];

if(file_exists($statusFile)){
    $old = json_decode(
        file_get_contents($statusFile),
        true
    ) ?? [];
}

foreach($current as $tanggal => $data){

    if(
        !isset($old[$tanggal])
    ){
        continue;
    }

    if(
        $old[$tanggal] != $data
    ){

        $message =
            "⚠️ Perubahan Kuota Semeru\n\n".
            "Tanggal: ".$tanggal."\n".
            "Status: ".$data['status']."\n".
            "Nilai: ".$data['hidden'];

        sendWA($message);
    }

    if(
        strpos(
            $old[$tanggal]['status'],
            'Kuota Penuh'
        ) !== false
        &&
        strpos(
            $data['status'],
            'Kuota Penuh'
        ) === false
    ){

        sendWA(
            "🚨 KUOTA TERSEDIA\n\n".$tanggal
        );
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

function sendWA($message)
{
    $token = getenv('FONNTE_TOKEN');
    $target = getenv('WA_NUMBER');

    $curl = curl_init();

    curl_setopt_array($curl, [

        CURLOPT_URL =>
        'https://api.fonnte.com/send',

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS => [
            'target' => $target,
            'message' => $message
        ],

        CURLOPT_HTTPHEADER => [
            "Authorization: $token"
        ]
    ]);

    curl_exec($curl);

    curl_close($curl);
}
