<?php

$clientId = '40vRtVxKzjQqpaqxwGOUfWUTA0bGaXVB';
$clientSecret = 'RfiflYLvb1-cOmPz3tCucJNxezbfdZ-ymyAiwm0TkFeyHIpQGhDi53fGXFUxMgI';

$authPayload = json_encode([
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'grant_type' => 'client_credentials',
    'audience' => 'https://topups-sandbox.reloadly.com',
], JSON_UNESCAPED_SLASHES);

$auth = curl_init('https://auth.reloadly.com/oauth/token');
curl_setopt($auth, CURLOPT_RETURNTRANSFER, true);
curl_setopt($auth, CURLOPT_POST, true);
curl_setopt($auth, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
]);
curl_setopt($auth, CURLOPT_POSTFIELDS, $authPayload);
curl_setopt($auth, CURLOPT_TIMEOUT, 30);
$authResponseBody = curl_exec($auth);
$authError = curl_error($auth);
$authStatus = curl_getinfo($auth, CURLINFO_HTTP_CODE);
curl_close($auth);

echo 'AUTH_STATUS=' . $authStatus . PHP_EOL;
if ($authError !== '') {
    echo 'AUTH_ERROR=' . $authError . PHP_EOL;
    exit(1);
}

$authJson = json_decode((string) $authResponseBody, true);
$token = $authJson['access_token'] ?? null;
if (!is_string($token) || $token === '') {
    echo 'AUTH_BODY=' . (string) $authResponseBody . PHP_EOL;
    exit(1);
}

$ops = curl_init('https://topups-sandbox.reloadly.com/operators/countries/GN');
curl_setopt($ops, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ops, CURLOPT_HTTPGET, true);
curl_setopt($ops, CURLOPT_HTTPHEADER, [
    'Accept: application/com.reloadly.topups-v1+json',
    'Authorization: Bearer ' . $token,
]);
curl_setopt($ops, CURLOPT_TIMEOUT, 30);
$opsBody = curl_exec($ops);
$opsError = curl_error($ops);
$opsStatus = curl_getinfo($ops, CURLINFO_HTTP_CODE);
curl_close($ops);

echo 'OPERATORS_STATUS=' . $opsStatus . PHP_EOL;
if ($opsError !== '') {
    echo 'OPERATORS_ERROR=' . $opsError . PHP_EOL;
    exit(1);
}

$opsJson = json_decode((string) $opsBody, true);
if (is_array($opsJson)) {
    echo 'OPERATORS_COUNT=' . count($opsJson) . PHP_EOL;
}

echo 'OPERATORS_SAMPLE=' . json_encode(array_slice((array) $opsJson, 0, 2), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
