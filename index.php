<?php

// Load environment variables from the .env file.
loadEnv();

// Define constants for environment variables.
define('EMAIL', getenv('EMAIL'));
define('PASSWORD', getenv('PASSWORD'));
define('LOGIN_URL', getenv('LOGIN_URL'));
define('TEST_PAGE_URL', getenv('TEST_PAGE_URL'));

/**
 * Load environment variables from a .env file.
 *
 * @throws Exception If the .env file is not found.
 * @return void
 */
function loadEnv(): void
{
    if (!file_exists(__DIR__ . '/.env')) {
        throw new Exception('The .env file was not found.');
    }

    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Skip comments in the .env file.
        }
        list($key, $value) = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

/**
 * Make an HTTP request using cURL.
 *
 * @param string $url The request URL.
 * @param string $method The HTTP method (GET, POST, etc.).
 * @param array|null $postFields The fields for POST requests.
 * @param string|null $cookies The cookies for the request.
 *
 * @return array An array containing the response body, headers, and HTTP status code.
 */
function makeCurlRequest(string $url, string $method = 'GET', ?array $postFields = null, ?string $cookies = null): array
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

/**
 * Build HTTP headers for the request.
 *
 * @param string|null $cookies The cookies to include in the request.
 *
 * @return array An array of HTTP headers.
 */
function buildHeaders(?string $cookies): array
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

/**
 * Extract the CSRF token from an HTML document.
 *
 * @param string $html The HTML content.
 *
 * @return string|null The CSRF token, or null if not found.
 */
function extractCsrfToken(string $html): ?string
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $tokenNode = $xpath->query('//meta[@name="csrf-token"]')->item(0);

    return $tokenNode ? $tokenNode->getAttribute('content') : null;
}

/**
 * Extract cookies from HTTP headers.
 *
 * @param string $headers The HTTP headers.
 *
 * @return string The cookies as a single string.
 */
function extractCookies(string $headers): string
{
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $headers, $cookieMatches);
    return implode('; ', $cookieMatches[1]);
}

/**
 * Extract data from an HTML table and return it as JSON.
 *
 * @param string $html The HTML content containing the table.
 *
 * @return string A JSON string with the table data.
 */
function fetchTableDataAsJson(string $html): string
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
                'ID' => trim($cols->item(0) ? $cols->item(0)->textContent : ''),
                'Empresa' => trim($cols->item(1) ? $cols->item(1)->textContent : ''),
                'Endereço' => trim($cols->item(2) ? $cols->item(2)->textContent : ''),
                'Referência' => trim($cols->item(3) ? $cols->item(3)->textContent : ''),
            ];
        }
    }

    return json_encode($data, JSON_PRETTY_PRINT);
}

// Allow only POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Only POST requests are allowed.']);
    exit;
}

// Fetch login page and extract CSRF token.
list($loginPageHtml, $loginHeaders) = makeCurlRequest(LOGIN_URL);
$csrfToken = extractCsrfToken($loginPageHtml);

if (!$csrfToken) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'CSRF token not found.']);
    exit;
}

// Authenticate and fetch protected page data.
$cookies = extractCookies($loginHeaders);
$postFields = ['email' => EMAIL, 'password' => PASSWORD, '_token' => $csrfToken];
list($loginResponse, $loginHeaders, $statusCode) = makeCurlRequest(LOGIN_URL, 'POST', $postFields, $cookies);
$cookies = extractCookies($loginHeaders);
list($testPageHtml) = makeCurlRequest(TEST_PAGE_URL, 'GET', null, $cookies);

// Return the table data as JSON.
header('Content-Type: application/json');
echo fetchTableDataAsJson($testPageHtml);
