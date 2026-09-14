<?php
/**
 * CSRF token helpers — first module in this repo to introduce them.
 *
 * Every state-changing form in the Task Tracker (and any new module that
 * follows) MUST render `csrf_field()` inside the form and call
 * `csrf_check()` at the top of the POST handler.
 *
 * Older modules are not required to retrofit CSRF unless the operator
 * asks — the concern here is not breaking existing forms.
 */

require_once __DIR__ . '/auth.php';   // brings session_start() side-effect

/**
 * Returns the current CSRF token, generating one on first access. The
 * token lives for the duration of the session — long enough for a
 * user's forms to survive a browser back / refresh, short enough that a
 * logout invalidates it.
 */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/**
 * Echoes a hidden CSRF input. Call inside every state-changing <form>.
 */
function csrf_field(): void
{
    $t = csrf_token();
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verifies the incoming request's CSRF token against the session token.
 * Uses hash_equals for constant-time comparison so a mismatched token
 * doesn't leak character-position hints via a timing side channel.
 *
 * Returns bool. Handlers that receive a bad token should respond with
 * HTTP 400 and a plain "Bad request" message — don't leak whether the
 * token was missing vs. mismatched.
 */
function csrf_check(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $sess = (string) ($_SESSION['_csrf_token'] ?? '');
    $post = (string) ($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
    if ($sess === '' || $post === '') return false;
    return hash_equals($sess, $post);
}

/**
 * Convenience: reject the request with 400 if the CSRF check fails.
 * Call at the top of any state-changing handler after auth is
 * verified.
 */
function csrf_check_or_die(): void
{
    if (!csrf_check()) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad request — CSRF token missing or invalid. Please refresh the page and try again.';
        exit;
    }
}
