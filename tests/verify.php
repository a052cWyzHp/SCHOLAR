<?php
declare(strict_types=1); // typed test helpers should not silently convert incorrect values

// this is a terminal test script, not an application page
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

// report each expected result; throwing stops the test if a requirement fails
function check(bool $condition, string $description): void {
    if (!$condition) {
        throw new RuntimeException($description);
    }
    echo "PASS: $description\n";
}

// cURL is PHP's HTTP client; this helper sends one request to our existing Apache
// form is either an array of submitted fields or null for a normal GET request
function requestPage(CurlHandle $client, string $baseUrl, string $page, $form = null): array {
    $isPost = false;
    if ($form !== null) {
        $isPost = true;
    }
    $options = [
        CURLOPT_URL => $baseUrl . $page,
        CURLOPT_POST => $isPost,
        CURLOPT_RETURNTRANSFER => true, // return the response instead of printing page HTML
        CURLOPT_HEADER => true, // include headers so redirects/cookies can be inspected
        CURLOPT_FOLLOWLOCATION => false, // check the original redirect before following it
        CURLOPT_TIMEOUT => 10,
        CURLOPT_PROXY => '', // send local test requests directly to Apache
    ];
    curl_setopt_array($client, $options);
    if ($form !== null) {
        curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($form)); // encode fields like a browser form
    }
    $response = curl_exec($client);
    if ($response === false) {
        throw new RuntimeException('HTTP request failed.');
    }
    $headerLength = curl_getinfo($client, CURLINFO_HEADER_SIZE);
    // separate the status, headers and HTML body so each check has named values to read
    return [
        'status' => curl_getinfo($client, CURLINFO_HTTP_CODE),
        'headers' => substr($response, 0, $headerLength),
        'body' => substr($response, $headerLength),
    ];
}

// find the hidden CSRF input in the returned HTML, just as a browser form would use it
function tokenFrom(array $response): string {
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $response['body'], $matches)) {
        throw new RuntimeException('CSRF token missing.');
    }
    return $matches[1];
}

// look up the session ID held by our HTTP client to check rotation and logout
function sessionCookie(CurlHandle $client): string {
    $cookies = curl_getinfo($client, CURLINFO_COOKIELIST);
    foreach ($cookies as $cookie) {
        $parts = explode("\t", $cookie); // cURL stores cookie details separated by tabs
        if ($parts[5] === 'scholar_session') {
            return $parts[6];
        }
    }
    return '';
}

// allow the tested XAMPP URL to be supplied as the first terminal argument
$baseUrl = 'http://scholar.localhost';
if (isset($argv[1])) {
    $baseUrl = $argv[1];
}
$baseUrl = rtrim($baseUrl, '/') . '/'; // exactly one slash before appending a page filename
$pdo = connectDatabase();
// randomly named synthetic account; only this test's account is removed in finally below
$username = 'verify_' . bin2hex(random_bytes(6));
$email = $username . '@example.invalid';
$password = bin2hex(random_bytes(20));
$client = curl_init(); // open an HTTP client, not a new web server
curl_setopt($client, CURLOPT_COOKIEFILE, ''); // keep received cookies in memory between requests
curl_setopt($client, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']); // resolve the local name without editing hosts
$failed = false;
try {
    // verify the schema ledger and the joined synthetic thesis/author rows
    check((int) $pdo->query('SELECT COUNT(*) FROM phinxlog')->fetchColumn() === 4, 'All four Phinx migrations recorded');
    $rows = $pdo->query(
        "SELECT t.title, a.author_name, a.author_order FROM theses t
         JOIN thesis_authors a ON a.thesis_id = t.id
         WHERE t.fixture_key = 'synthetic_multi_author' ORDER BY a.author_order"
    )->fetchAll();
    check(count($rows) === 2 && $rows[0]['author_name'] === 'Synthetic Author Alpha'
        && $rows[1]['author_name'] === 'Synthetic Author Beta', 'Synthetic thesis reads with two ordered authors');
    // intentionally attempt an orphan author; always undo this attempt in its own transaction
    $pdo->beginTransaction();
    $orphanRejected = false;
    try {
        $pdo->exec("INSERT INTO thesis_authors (thesis_id, author_name, author_order) VALUES (18446744073709551615, 'Synthetic orphan', 1)");
    } catch (PDOException $exception) {
        $orphanRejected = $exception->getCode() === '23000'; // SQLSTATE for an integrity constraint violation
    } finally {
        $pdo->rollBack();
    }
    check($orphanRejected, 'Foreign key rejects orphan author');

    // invoke the real provisioning command, passing the password through the environment
    putenv('SCHOLAR_ADMIN_PASSWORD=' . $password);
    // proc_open starts a child PHP command; the three pipes are its input/output/error streams
    $process = proc_open([PHP_BINARY, __DIR__ . '/../bin/console.php', 'provision-admin', $username, $email],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); // no keyboard input is needed for this command
    stream_get_contents($pipes[1]); // consume normal output without exposing it
    stream_get_contents($pipes[2]); // also consume error output without printing credentials
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'CLI provisions temporary administrator without committed password');
    putenv('SCHOLAR_ADMIN_PASSWORD');
    $lookup = $pdo->prepare('SELECT password_hash FROM users WHERE username = :username');
    $lookup->execute(['username' => $username]);
    $hash = $lookup->fetchColumn();
    check(is_string($hash) && $hash !== $password && password_verify($password, $hash), 'Password API hash verifies');

    // direct protected-page access must redirect before any authenticated HTML is returned
    $response = requestPage($client, $baseUrl, 'admin.php');
    check($response['status'] === 302 && !(strpos($response['body'], 'Login succeeded') !== false), 'Unauthenticated direct admin request denied');
    $login = requestPage($client, $baseUrl, 'login.php');
    check($login['status'] === 200, 'Application opens through XAMPP Apache');
    $token = tokenFrom($login); // use the token the application actually generated
    $response = requestPage($client, $baseUrl, 'login.php', ['identifier' => $username, 'password' => $password]);
    check($response['status'] === 403, 'Login requires CSRF token');
    $response = requestPage($client, $baseUrl, 'login.php', ['identifier' => ['bad'], 'password' => ['bad'], 'csrf_token' => $token]);
    check($response['status'] === 422, 'Array-shaped login input rejected safely');
    // known and unknown users must receive the same generic credential error
    $wrong = requestPage($client, $baseUrl, 'login.php', ['identifier' => $username, 'password' => 'incorrect', 'csrf_token' => $token]);
    $unknown = requestPage($client, $baseUrl, 'login.php', ['identifier' => 'unknown_' . $username, 'password' => 'incorrect', 'csrf_token' => $token]);
    check($wrong['status'] === 401 && $unknown['status'] === 401
        && (strpos($wrong['body'], 'Invalid username/email or password.') !== false)
        && (strpos($unknown['body'], 'Invalid username/email or password.') !== false), 'Wrong and unknown credentials use generic rejection');
    // malformed identifiers must not turn a prepared query into executable SQL
    $injection = requestPage($client, $baseUrl, 'login.php', ['identifier' => "' OR 1=1 --", 'password' => $password, 'csrf_token' => $token]);
    check($injection['status'] === 401, 'SQL injection identifier cannot authenticate');
    // HTML-like form input should appear as escaped text, not a script element
    $escaped = requestPage($client, $baseUrl, 'login.php', ['identifier' => '<script>alert(1)</script>', 'password' => '', 'csrf_token' => $token]);
    check((strpos($escaped['body'], '&lt;script&gt;') !== false) && !(strpos($escaped['body'], '<script>') !== false), 'Retained identifier escaped');
    $overlong = requestPage($client, $baseUrl, 'login.php', ['identifier' => $username, 'password' => str_repeat('a', 73), 'csrf_token' => $token]);
    check($overlong['status'] === 422, 'Overlong password rejected before bcrypt verification');

    // repeat the entire login/logout flow once for username and once for email
    foreach ([$username, $email] as $identifier) {
        $login = requestPage($client, $baseUrl, 'login.php');
        $previousSession = sessionCookie($client);
        $response = requestPage($client, $baseUrl, 'login.php', ['identifier' => $identifier, 'password' => $password, 'csrf_token' => tokenFrom($login)]);
        if ($identifier === $username) {
            $loginDescription = 'Username login succeeds via Apache/MySQL';
        } else {
            $loginDescription = 'Email login succeeds via Apache/MySQL';
        }
        check($response['status'] === 303, $loginDescription);
        check($previousSession !== '' && sessionCookie($client) !== $previousSession, 'Successful login regenerates session identifier');
        check((strpos($response['headers'], 'HttpOnly') !== false) && (strpos($response['headers'], 'SameSite=Lax') !== false), 'Session cookie safeguards present');
        $admin = requestPage($client, $baseUrl, 'admin.php');
        check($admin['status'] === 200 && (strpos($admin['body'], 'Login succeeded.') !== false), 'Authenticated administrative page opens');
        $response = requestPage($client, $baseUrl, 'logout.php');
        check($response['status'] === 405, 'GET cannot log out');
        $response = requestPage($client, $baseUrl, 'logout.php', ['csrf_token' => 'invalid']);
        check($response['status'] === 403, 'Logout requires valid CSRF token');
        $oldSession = sessionCookie($client);
        $response = requestPage($client, $baseUrl, 'logout.php', ['csrf_token' => tokenFrom($admin)]);
        check($response['status'] === 303, 'Logout succeeds');
        check(requestPage($client, $baseUrl, 'admin.php')['status'] === 302, 'Logout ends authenticated access');
        // try the logged-out ID in a different client; it must not regain access
        $replay = curl_init();
        curl_setopt($replay, CURLOPT_COOKIE, 'scholar_session=' . $oldSession);
        curl_setopt($replay, CURLOPT_RESOLVE, ['scholar.localhost:80:127.0.0.1']);
        check(requestPage($replay, $baseUrl, 'admin.php')['status'] === 302, 'Logged-out session cannot be replayed');
        curl_close($replay);
    }
    // private files must be inaccessible through both the default Apache root and public URLs
    foreach (['.env', 'config/local.php', 'vendor/autoload.php', 'phinx.php', 'app/bootstrap.php', 'storage/logs/application.log'] as $path) {
        check(requestPage($client, 'http://127.0.0.1/scholar%20proj/scholar/', $path)['status'] === 403, 'Default Apache root denies private ' . $path);
        $response = requestPage($client, $baseUrl, $path);
        check(in_array($response['status'], [403, 404], true), 'Public app route cannot serve ' . $path);
    }
    check(requestPage($client, 'http://127.0.0.1/scholar%20proj/', 'for_codex/requirements.md')['status'] === 403, 'Reference files denied through default Apache root');
} catch (Throwable $exception) {
    $failed = true;
    // Test descriptions contain no secrets; show which assertion failed.
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
} finally {
    // cleanup runs on either success or failure and targets only this invocation's account
    putenv('SCHOLAR_ADMIN_PASSWORD');
    $statement = $pdo->prepare('DELETE FROM users WHERE username = :username AND email = :email');
    $statement->execute(['username' => $username, 'email' => $email]);
    curl_close($client);
}
// terminal callers can inspect the exit code: 1 means failed, 0 means passed
if ($failed) {
    exit(1);
}
exit(0);
