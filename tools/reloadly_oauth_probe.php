<?php

$id = '40vRtVxKzjQqpaqxwGOUfWUTA0bGaXVB';
$secret = 'RfiflYLvb1-cOmPz3tCucJNxezbfdZ-ymyAiwm0TkFeyHIpQGhDi53fGXFUxMgI';

$payload = json_encode([
    'client_id' => $id,
    'client_secret' => $secret,
    'grant_type' => 'client_credentials',
    'audience' => 'https://topups-sandbox.reloadly.com',
], JSON_UNESCAPED_SLASHES);

$ch = curl_init('https://auth.reloadly.com/oauth/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo 'HTTP=' . $httpCode . PHP_EOL;
if ($error !== '') {
    echo 'ERR=' . $error . PHP_EOL;
}
echo ($response ?: '') . PHP_EOL;
