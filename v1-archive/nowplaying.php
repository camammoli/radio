<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
$url = trim($_GET['url'] ?? '');
if (!$url || !preg_match('#^https?://#i', $url)) { echo '{"ok":false}'; exit; }
$cache = sys_get_temp_dir() . '/radio_np_' . md5($url) . '.json';
if (file_exists($cache) && (time() - filemtime($cache)) < 30) { echo file_get_contents($cache); exit; }
$ctx = stream_context_create(['http'=>['timeout'=>5,'header'=>"Icy-MetaData: 1\r\nUser-Agent: Mozilla/5.0\r\n",'method'=>'GET']]);
$fp = @fopen($url, 'r', false, $ctx);
if (!$fp) { echo '{"ok":false}'; exit; }
$meta = stream_get_meta_data($fp);
$metaint = 0;
foreach (($meta['wrapper_data'] ?? []) as $h) {
    if (stripos($h,'icy-metaint:')===0) { $metaint=(int)trim(explode(':',$h,2)[1]); break; }
}
$result = ['ok'=>false,'title'=>''];
if ($metaint > 0) {
    $skip = @fread($fp, $metaint);
    $lb = @fread($fp, 1);
    if ($skip!==false && $lb!==false && strlen($lb)===1) {
        $len = ord($lb)*16;
        if ($len>0 && $len<4096) {
            $ms = @fread($fp,$len);
            if ($ms && preg_match("/StreamTitle='([^;']*)'/", $ms, $m)) {
                $t = trim($m[1]);
                if ($t) $result = ['ok'=>true,'title'=>$t];
            }
        }
    }
}
@fclose($fp);
$json = json_encode($result);
@file_put_contents($cache, $json);
echo $json;
