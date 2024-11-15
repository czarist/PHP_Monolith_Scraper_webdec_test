<?php

loadEnv();

define('EMAIL', getenv('EMAIL'));
define('PASSWORD', getenv('PASSWORD'));
define('LOGIN_URL', getenv('LOGIN_URL'));
define('TEST_PAGE_URL', getenv('TEST_PAGE_URL'));

function loadEnv()
{
    if (!file_exists(__DIR__ . '/.env')) {
        throw new Exception('Arquivo .env não encontrado');
    }

    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        list($key, $value) = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

function makeCurlRequest($url, $method = 'GET', $postFields = null, $cookies = null)
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => buildHeaders($cookies),
        CURLOPT_POSTFIELDS => $postFields ? http_build_query($postFields) : null,
    ]);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$body, $headers, $statusCode];
}

function buildHeaders($cookies)
{
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/89.0.4389.82 Safari/537.36',
        'Content-Type: application/x-www-form-urlencoded',
    ];
    if ($cookies) {
        $headers[] = "Cookie: $cookies";
    }

    return $headers;
}

function extractCsrfToken($html)
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    return $xpath->query('//meta[@name="csrf-token"]')->item(0)?->getAttribute('content');
}

function extractCookies($headers)
{
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $headers, $cookieMatches);
    return implode('; ', $cookieMatches[1]);
}

function fetchTableDataAsJson($html)
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $rows = $xpath->query('//table/tbody/tr');
    $data = [];

    foreach ($rows as $row) {
        if ($row instanceof DOMElement) {
            $cols = $row->getElementsByTagName('td');
            $data[] = [
                'ID' => trim($cols->item(0)?->textContent ?? ''),
                'Empresa' => trim($cols->item(1)?->textContent ?? ''),
                'Endereço' => trim($cols->item(2)?->textContent ?? ''),
                'Referência' => trim($cols->item(3)?->textContent ?? ''),
            ];
        }
    }
    return json_encode($data, JSON_PRETTY_PRINT);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Only POST requests are allowed.']);
    exit;
}

list($loginPageHtml, $loginHeaders) = makeCurlRequest(LOGIN_URL);
$csrfToken = extractCsrfToken($loginPageHtml);
if (!$csrfToken) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'CSRF token not found.']);
    exit;
}

$cookies = extractCookies($loginHeaders);
$postFields = ['email' => EMAIL, 'password' => PASSWORD, '_token' => $csrfToken];
list($loginResponse, $loginHeaders, $statusCode) = makeCurlRequest(LOGIN_URL, 'POST', $postFields, $cookies);
$cookies = extractCookies($loginHeaders);
list($testPageHtml) = makeCurlRequest(TEST_PAGE_URL, 'GET', null, $cookies);

header('Content-Type: application/json');
echo fetchTableDataAsJson($testPageHtml);
