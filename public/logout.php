<?php
declare(strict_types=1); // keep typed function arguments strict

use Scholar\Auth\Session;

require_once __DIR__ . '/../app/bootstrap.php';
Session::start(); // restore the session we are about to end

// the logout button uses POST, opening this URL as a GET must not log someone out
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

Session::requireAuthentication(); // users who aren't logged in go back to login page
$submittedToken = null;
if (isset($_POST['csrf_token'])) {
    $submittedToken = $_POST['csrf_token']; //since logout button uses POST, take the csrf token submitted inside it
}
if (!Session::verifyCsrf($submittedToken)) {
    http_response_code(403);
    exit('The form expired. Return to the administrative page and try again.');
}

Session::invalidate(); // clear the session data, server-side file and browser cookie
header('Location: login.php', true, 303);
exit;
