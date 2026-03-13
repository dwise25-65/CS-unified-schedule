<?php
/**
 * Schedule App — API Documentation
 * Included as a tab inside index.php via showTab('api-docs').
 * Visible to admin, manager, supervisor only (not employee role).
 */
if (!defined('APP_VERSION')) {
    require_once __DIR__ . '/auth_user_management.php';
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit;
    }
    $apiDocsRole = $_SESSION['user_role'] ?? 'employee';
    if ($apiDocsRole === 'employee') {
        header('Location: index.php');
        exit;
    }
}
?>
<style>
/* ============================================================
   API DOCS — completely theme-immune light-mode palette.
   Strategy:
     1. Shell gets white bg + dark text
     2. Blanket * reset: color:inherit + background-color:transparent
        (kills any theme bg injection on child elements)
     3. Every element that needs a bg/color re-declares it AFTER
        the blanket rule so cascade order wins over specificity ties.
   ============================================================ */

/* 1. Shell */
.api-doc-shell {
    background-color: #ffffff !important;
    color: #1e293b !important;
    border-radius: 12px !important;
    padding: 24px 28px 48px !important;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08) !important;
}

/* 2. Blanket kill — remove all theme bg and text overrides on children */
.api-doc-shell * {
    color: inherit !important;
    background-color: transparent !important;
}

/* 3. Re-declare every element that needs its own bg/color (AFTER blanket so cascade wins) */

.api-wrap {
    max-width: 1000px !important;
    margin: 0 auto !important;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
    font-size: 14px !important;
    line-height: 1.7 !important;
    color: #1e293b !important;
}

/* Header */
.api-doc-shell .api-header {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    margin-bottom: 32px !important;
    padding-bottom: 18px !important;
    border-bottom: 2px solid #e2e8f0 !important;
}
.api-doc-shell .api-header h1 {
    margin: 0 !important;
    font-size: 22px !important;
    font-weight: 700 !important;
    color: #0f172a !important;
}
.api-doc-shell .api-header p {
    margin: 4px 0 0 !important;
    font-size: 13px !important;
    color: #64748b !important;
}
.api-doc-shell .api-icon {
    width: 48px !important; height: 48px !important;
    background: linear-gradient(135deg, #667eea, #764ba2) !important;
    border-radius: 12px !important;
    display: flex !important; align-items: center !important; justify-content: center !important;
    font-size: 22px !important; flex-shrink: 0 !important;
}

/* Base URL banner */
.api-doc-shell .api-base-url {
    background-color: #eef2ff !important;
    border: 1px solid #c7d2fe !important;
    border-radius: 8px !important;
    padding: 12px 16px !important;
    margin-bottom: 28px !important;
    font-size: 13px !important;
    color: #1e293b !important;
}
.api-doc-shell .api-base-url strong { color: #4338ca !important; }
.api-doc-shell .api-base-url code {
    background-color: #e0e7ff !important;
    padding: 2px 8px !important;
    border-radius: 4px !important;
    font-family: 'Fira Code', 'Courier New', monospace !important;
    font-size: 13px !important;
    color: #3730a3 !important;
}

/* Auth notice */
.api-doc-shell .api-auth-notice {
    background-color: #fffbeb !important;
    border: 1px solid #fcd34d !important;
    border-radius: 8px !important;
    padding: 12px 16px !important;
    margin-bottom: 28px !important;
    font-size: 13px !important;
    color: #78350f !important;
}
.api-doc-shell .api-auth-notice strong { color: #92400e !important; }
.api-doc-shell .api-auth-notice code {
    background-color: #fef3c7 !important;
    color: #78350f !important;
}

/* Section headings */
.api-doc-shell .api-section-title {
    font-size: 11px !important;
    font-weight: 700 !important;
    letter-spacing: 1.2px !important;
    text-transform: uppercase !important;
    color: #94a3b8 !important;
    margin: 36px 0 14px !important;
    padding-bottom: 6px !important;
    border-bottom: 1px solid #e2e8f0 !important;
}

/* Endpoint card */
.api-doc-shell .api-endpoint {
    background-color: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 10px !important;
    margin-bottom: 14px !important;
    overflow: hidden !important;
}
.api-doc-shell .api-endpoint-header {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 13px 16px !important;
    cursor: pointer !important;
    user-select: none !important;
    background-color: #f8fafc !important;
}
.api-doc-shell .api-endpoint-header:hover { background-color: #f1f5f9 !important; }

/* Method badges */
.api-doc-shell .api-method {
    font-size: 11px !important;
    font-weight: 700 !important;
    letter-spacing: 0.5px !important;
    padding: 3px 9px !important;
    border-radius: 5px !important;
    flex-shrink: 0 !important;
    font-family: 'Fira Code', monospace !important;
}
.api-doc-shell .method-get    { background-color: #dcfce7 !important; color: #15803d !important; }
.api-doc-shell .method-post   { background-color: #dbeafe !important; color: #1d4ed8 !important; }
.api-doc-shell .method-delete { background-color: #fee2e2 !important; color: #b91c1c !important; }

.api-doc-shell .api-endpoint-path {
    font-family: 'Fira Code', 'Courier New', monospace !important;
    font-size: 13px !important;
    color: #1e293b !important;
    flex: 1 !important;
}
.api-doc-shell .api-endpoint-desc {
    font-size: 12px !important;
    color: #64748b !important;
    margin-left: auto !important;
    text-align: right !important;
    max-width: 240px !important;
}
.api-doc-shell .api-chevron {
    font-size: 10px !important;
    color: #94a3b8 !important;
    transition: transform 0.2s !important;
}
.api-doc-shell .api-endpoint.open .api-chevron { transform: rotate(180deg) !important; }

.api-doc-shell .api-endpoint-body {
    display: none !important;
    padding: 0 16px 16px !important;
    border-top: 1px solid #e2e8f0 !important;
    background-color: #ffffff !important;
}
.api-doc-shell .api-endpoint.open .api-endpoint-body { display: block !important; }
.api-doc-shell .api-endpoint-body p { color: #334155 !important; }
.api-doc-shell .api-endpoint-body code {
    background-color: #f1f5f9 !important;
    color: #3730a3 !important;
    padding: 1px 5px !important;
    border-radius: 3px !important;
    font-family: 'Fira Code', monospace !important;
    font-size: 12px !important;
}

/* Param table */
.api-doc-shell .api-params {
    width: 100% !important;
    border-collapse: collapse !important;
    font-size: 13px !important;
    margin-top: 12px !important;
}
.api-doc-shell .api-params th {
    text-align: left !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    letter-spacing: 0.8px !important;
    text-transform: uppercase !important;
    color: #94a3b8 !important;
    padding: 6px 10px !important;
    border-bottom: 1px solid #e2e8f0 !important;
    background-color: #f8fafc !important;
}
.api-doc-shell .api-params td {
    padding: 7px 10px !important;
    border-bottom: 1px solid #f1f5f9 !important;
    vertical-align: top !important;
    color: #334155 !important;
}
.api-doc-shell .api-params tr:last-child td { border-bottom: none !important; }
.api-doc-shell .param-name {
    font-family: 'Fira Code', monospace !important;
    font-size: 12px !important;
    color: #4338ca !important;
    white-space: nowrap !important;
}
.api-doc-shell .param-type {
    font-size: 11px !important;
    color: #94a3b8 !important;
    font-style: italic !important;
}
.api-doc-shell .param-req {
    font-size: 10px !important;
    padding: 1px 6px !important;
    border-radius: 4px !important;
    background-color: #fee2e2 !important;
    color: #b91c1c !important;
    white-space: nowrap !important;
}
.api-doc-shell .param-opt {
    font-size: 10px !important;
    padding: 1px 6px !important;
    border-radius: 4px !important;
    background-color: #f1f5f9 !important;
    color: #64748b !important;
    white-space: nowrap !important;
}

/* Code blocks — dark bg with syntax highlighting */
.api-doc-shell .api-code-label {
    font-size: 11px !important;
    font-weight: 700 !important;
    letter-spacing: 0.5px !important;
    text-transform: uppercase !important;
    color: #64748b !important;
    margin: 14px 0 6px !important;
}
.api-doc-shell .api-code {
    background-color: #1e293b !important;
    border: 1px solid #334155 !important;
    border-radius: 7px !important;
    padding: 12px 14px !important;
    font-family: 'Fira Code', 'Courier New', monospace !important;
    font-size: 12px !important;
    color: #e2e8f0 !important;
    white-space: pre !important;
    overflow-x: auto !important;
    line-height: 1.6 !important;
}
/* Syntax colours — body prefix pushes specificity above any theme stylesheet override */
body .api-doc-shell .api-code          { color: #e2e8f0 !important; }
body .api-doc-shell .api-code *        { color: #e2e8f0 !important; }
body .api-doc-shell .api-code .key     { color: #93c5fd !important; }
body .api-doc-shell .api-code .str     { color: #86efac !important; }
body .api-doc-shell .api-code .num     { color: #fde68a !important; }
body .api-doc-shell .api-code .bool    { color: #f9a8d4 !important; }
body .api-doc-shell .api-code .cmnt    { color: #94a3b8 !important; font-style: italic !important; }

/* Response chips */
.api-doc-shell .api-response-chips {
    display: flex !important;
    gap: 8px !important;
    flex-wrap: wrap !important;
    margin-top: 12px !important;
}
.api-doc-shell .api-chip {
    font-size: 11px !important;
    padding: 3px 10px !important;
    border-radius: 20px !important;
    font-weight: 600 !important;
}
.api-doc-shell .chip-200 { background-color: #dcfce7 !important; color: #15803d !important; }
.api-doc-shell .chip-400 { background-color: #fef3c7 !important; color: #92400e !important; }
.api-doc-shell .chip-401 { background-color: #fee2e2 !important; color: #b91c1c !important; }
.api-doc-shell .chip-403 { background-color: #fee2e2 !important; color: #b91c1c !important; }
.api-doc-shell .chip-404 { background-color: #f1f5f9 !important; color: #475569 !important; }
.api-doc-shell .chip-500 { background-color: #fee2e2 !important; color: #b91c1c !important; }

/* Permission badge */
.api-doc-shell .api-perm {
    display: inline-flex !important;
    align-items: center !important;
    gap: 5px !important;
    font-size: 11px !important;
    padding: 3px 10px !important;
    border-radius: 20px !important;
    background-color: #f3e8ff !important;
    color: #7e22ce !important;
    margin-top: 10px !important;
    margin-bottom: 2px !important;
}

/* Error section paragraph */
.api-doc-shell p { color: #334155 !important; }

/* Copy button */
.api-copy-btn {
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
    padding: 2px 8px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    border-radius: 4px !important;
    border: 1px solid #cbd5e1 !important;
    background-color: #f8fafc !important;
    color: #475569 !important;
    cursor: pointer !important;
    transition: all 0.15s !important;
    font-family: 'Inter', sans-serif !important;
    white-space: nowrap !important;
    flex-shrink: 0 !important;
    line-height: 1.6 !important;
}
.api-copy-btn:hover {
    background-color: #e2e8f0 !important;
    border-color: #94a3b8 !important;
    color: #1e293b !important;
}
.api-copy-btn.copied {
    background-color: #dcfce7 !important;
    border-color: #86efac !important;
    color: #15803d !important;
}

/* Wrapper for code blocks so we can position the copy button */
.api-code-wrap {
    position: relative !important;
}
.api-code-wrap .api-copy-btn {
    position: absolute !important;
    top: 8px !important;
    right: 8px !important;
    background-color: #334155 !important;
    border-color: #475569 !important;
    color: #94a3b8 !important;
}
.api-code-wrap .api-copy-btn:hover {
    background-color: #475569 !important;
    color: #e2e8f0 !important;
}
.api-code-wrap .api-copy-btn.copied {
    background-color: #166534 !important;
    border-color: #15803d !important;
    color: #86efac !important;
}

/* Endpoint path row — make it sit nicely beside the copy btn */
.api-endpoint-path-wrap {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    flex: 1 !important;
}

/* ============================================================
   Dark theme overrides — only active when <body data-theme="dark">
   The [data-theme="dark"] prefix raises specificity above every
   rule above, so these always win when dark mode is active.
   ============================================================ */

/* Shell background + base text */
[data-theme="dark"] .api-doc-shell {
    background-color: #1e293b !important;
    color: #f1f5f9 !important;
    box-shadow: 0 2px 12px rgba(0,0,0,0.4) !important;
}

/* Blanket inherit — children pick up white from the shell */
[data-theme="dark"] .api-doc-shell * {
    color: inherit !important;
}

/* Wrap + headings */
[data-theme="dark"] .api-doc-shell .api-wrap,
[data-theme="dark"] .api-doc-shell h1,
[data-theme="dark"] .api-doc-shell h2,
[data-theme="dark"] .api-doc-shell h3,
[data-theme="dark"] .api-doc-shell h4 {
    color: #f1f5f9 !important;
}

/* Section titles (muted) */
[data-theme="dark"] .api-doc-shell .api-section-title {
    color: #94a3b8 !important;
    border-bottom-color: #334155 !important;
}

/* Base URL bar */
[data-theme="dark"] .api-doc-shell .api-base-url {
    background-color: #0f172a !important;
    color: #f1f5f9 !important;
}
[data-theme="dark"] .api-doc-shell .api-base-url strong { color: #93c5fd !important; }

/* Auth notice */
[data-theme="dark"] .api-doc-shell .api-auth-notice {
    background-color: #2d2510 !important;
    color: #fde68a !important;
    border-color: #78350f !important;
}
[data-theme="dark"] .api-doc-shell .api-auth-notice strong { color: #fbbf24 !important; }
[data-theme="dark"] .api-doc-shell .api-key-chip {
    background-color: #451a03 !important;
    color: #fed7aa !important;
}

/* Endpoint header (collapsed row) */
[data-theme="dark"] .api-doc-shell .api-endpoint-header {
    background-color: #1e293b !important;
    color: #f1f5f9 !important;
    border-color: #334155 !important;
}
[data-theme="dark"] .api-doc-shell .api-endpoint-header:hover {
    background-color: #253549 !important;
}

/* Endpoint path */
[data-theme="dark"] .api-doc-shell .api-endpoint-path { color: #f1f5f9 !important; }
[data-theme="dark"] .api-doc-shell .api-endpoint-desc { color: #94a3b8 !important; }

/* Endpoint body (expanded panel) */
[data-theme="dark"] .api-doc-shell .api-endpoint-body {
    background-color: #0f172a !important;
    border-color: #334155 !important;
}
[data-theme="dark"] .api-doc-shell .api-endpoint-body p,
[data-theme="dark"] .api-doc-shell p { color: #cbd5e1 !important; }

/* Parameters table */
[data-theme="dark"] .api-doc-shell .api-params-table { border-color: #334155 !important; }
[data-theme="dark"] .api-doc-shell .param-header {
    background-color: #1e293b !important;
    color: #94a3b8 !important;
}
[data-theme="dark"] .api-doc-shell .param-row {
    background-color: #0f172a !important;
    color: #f1f5f9 !important;
    border-color: #1e293b !important;
}
[data-theme="dark"] .api-doc-shell .param-name  { color: #93c5fd !important; }
[data-theme="dark"] .api-doc-shell .param-desc  { color: #cbd5e1 !important; }
[data-theme="dark"] .api-doc-shell .param-type  { color: #94a3b8 !important; }
[data-theme="dark"] .api-doc-shell .param-optional {
    background-color: #1e293b !important;
    color: #94a3b8 !important;
}

/* Copy button (non-code-block) */
[data-theme="dark"] .api-doc-shell .api-copy-btn {
    background-color: #1e293b !important;
    border-color: #475569 !important;
    color: #94a3b8 !important;
}
[data-theme="dark"] .api-doc-shell .api-copy-btn:hover {
    background-color: #334155 !important;
    color: #e2e8f0 !important;
}

/* Response chips — keep semantic colours, just lighten backgrounds */
[data-theme="dark"] .api-doc-shell .chip-200 { background-color: #14532d !important; color: #86efac !important; }
[data-theme="dark"] .api-doc-shell .chip-400 { background-color: #451a03 !important; color: #fcd34d !important; }
[data-theme="dark"] .api-doc-shell .chip-401,
[data-theme="dark"] .api-doc-shell .chip-403,
[data-theme="dark"] .api-doc-shell .chip-500 { background-color: #450a0a !important; color: #fca5a5 !important; }
[data-theme="dark"] .api-doc-shell .chip-404 { background-color: #1e293b !important; color: #94a3b8 !important; }

/* Permission badge */
[data-theme="dark"] .api-doc-shell .api-perm {
    background-color: #2e1065 !important;
    color: #d8b4fe !important;
}

/* Code blocks already have dark bg — just ensure border blends */
[data-theme="dark"] .api-doc-shell .api-code-block { border-color: #334155 !important; }
</style>

<div class="api-doc-shell">
<div class="api-wrap">

    <div class="api-header">
        <div class="api-icon">🔌</div>
        <div>
            <h1>API Reference</h1>
            <p>REST API for the Employee Scheduling System — all endpoints, parameters, and example payloads.</p>
        </div>
    </div>

    <div class="api-base-url">
        <strong>Endpoint:</strong>&nbsp;
        <code>api.php?action=<em>{action}</em></code>
        &nbsp;&nbsp;·&nbsp;&nbsp;
        <strong>Data store:</strong>&nbsp;<code>Database (MariaDB)</code>
        &nbsp;&nbsp;·&nbsp;&nbsp;
        <strong>Format:</strong>&nbsp;<code>application/json</code>
    </div>

    <div class="api-auth-notice">
        <strong>🔒 Authentication required.</strong>
        All endpoints require an active session (you must be logged in) <strong>or</strong> a valid
        <code>X-API-Key</code> request header. Endpoints marked with a permission badge require the
        appropriate user role. Unauthenticated requests return <code>401</code>; insufficient
        permission returns <code>403</code>.
    </div>

    <!-- X-API-Key / UAT Authentication -->
    <div class="api-endpoint" style="margin-bottom:10px;">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=generate_api_key</span>
            <span class="api-endpoint-desc">Generate an API key for programmatic / UAT access</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <p style="margin:10px 0 8px;font-size:13px;">
                Generates (or regenerates) a 64-character API key for a user. Once generated, pass the
                key in the <code>X-API-Key</code> request header on any subsequent call — no browser
                session needed. The <strong>uat</strong> role has full access to all endpoints.
            </p>
            <p style="margin:0 0 10px;font-size:13px;">
                <strong>This endpoint can be called without any prior authentication</strong> — just supply
                <code>username</code> + <code>password</code> in the JSON body and a key will be issued.
            </p>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">username</td><td class="param-type">string</td><td><span class="param-opt">optional*</span></td><td>Username or email — use with <code>password</code> for local accounts.</td></tr>
                <tr><td class="param-name">password</td><td class="param-type">string</td><td><span class="param-opt">optional*</span></td><td>Account password — use with <code>username</code> for local accounts.</td></tr>
                <tr><td class="param-name">google_id_token</td><td class="param-type">string</td><td><span class="param-opt">optional*</span></td><td>Google ID token (JWT) from a completed Google SSO sign-in — alternative to username/password for Google accounts.</td></tr>
                <tr><td class="param-name">user_id</td><td class="param-type">integer</td><td><span class="param-opt">optional</span></td><td>Generate key for a different user. Requires manage_users permission.</td></tr>
            </table>
            <p style="margin:8px 0 4px;font-size:12px;color:#64748b;">* One auth method is required when no session is active: either <code>username</code>+<code>password</code> <em>or</em> <code>google_id_token</code>.</p>
            <div class="api-code-label">Option A — Local account (username + password)</div>
            <div class="api-code">curl -s -X POST "https://znader.com/api.php?action=generate_api_key" \
     -H <span class="str">"Content-Type: application/json"</span> \
     -d <span class="str">'{"username":"UAT_TEST","password":"yourpassword"}'</span></div>
            <div class="api-code-label">Option B — Google SSO account (Google ID token)</div>
            <div class="api-code">curl -s -X POST "https://znader.com/api.php?action=generate_api_key" \
     -H <span class="str">"Content-Type: application/json"</span> \
     -d <span class="str">'{"google_id_token":"eyJhbGci...your-google-jwt"}'</span></div>
            <div class="api-code-label">Example Response</div>
            <div class="api-code">{ <span class="key">"api_key"</span>: <span class="str">"a3f8c2...64chars"</span>, <span class="key">"user_id"</span>: <span class="num">42</span> }</div>
            <div class="api-code-label">Step 2 — use the key on every request going forward</div>
            <div class="api-code">curl -H <span class="str">"X-API-Key: a3f8c2...64chars"</span> \
     "https://znader.com/api.php?action=get_employees"</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-401">401 Bad credentials / expired token</span>
                <span class="api-chip chip-403">403 Forbidden</span>
            </div>
        </div>
    </div>

    <div class="api-endpoint" style="margin-bottom:20px;">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=revoke_api_key</span>
            <span class="api-endpoint-desc">Revoke an API key</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <p style="margin:10px 0 8px;font-size:13px;">Clears the stored API key for a user. The key is immediately invalidated.</p>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">user_id</td><td class="param-type">integer</td><td><span class="param-opt">optional</span></td><td>Revoke key for this user. Defaults to your own account.</td></tr>
            </table>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-401">401 Unauthenticated</span>
            </div>
        </div>
    </div>

    <!-- ======================================================
         EMPLOYEES
         ====================================================== -->
    <div class="api-section-title">Employees</div>

    <!-- GET get_employees -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-get">GET</span>
            <span class="api-endpoint-path">api.php?action=get_employees</span>
            <span class="api-endpoint-desc">List all active employees</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <p style="margin:10px 0 4px;font-size:13px;">Returns all employees with active status, joined to their linked user account.</p>
            <div class="api-code-label">Example Response</div>
            <div class="api-code"><span class="key">{
  "employees"</span>: [
    {
      <span class="key">"id"</span>: <span class="num">1</span>,
      <span class="key">"name"</span>: <span class="str">"Jane Smith"</span>,
      <span class="key">"email"</span>: <span class="str">"jane@company.com"</span>,
      <span class="key">"team"</span>: <span class="str">"Support"</span>,
      <span class="key">"level"</span>: <span class="str">"Supervisor"</span>,
      <span class="key">"shift"</span>: <span class="str">"Day"</span>,
      <span class="key">"hours"</span>: <span class="str">"9A-5P"</span>,
      <span class="key">"username"</span>: <span class="str">"jsmith"</span>
    }
  ]
}</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-401">401 Unauthenticated</span>
            </div>
        </div>
    </div>

    <!-- GET get_employee -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-get">GET</span>
            <span class="api-endpoint-path">api.php?action=get_employee&amp;id={id}</span>
            <span class="api-endpoint-desc">Get single employee</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <table class="api-params">
                <tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">id</td><td class="param-type">integer</td><td><span class="param-req">required</span></td><td>Employee ID</td></tr>
            </table>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-404">404 Not Found</span>
                <span class="api-chip chip-401">401 Unauthenticated</span>
            </div>
        </div>
    </div>

    <!-- GET search_employees -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-get">GET</span>
            <span class="api-endpoint-path">api.php?action=search_employees</span>
            <span class="api-endpoint-desc">Filter employees by team, shift, level, skill, or name</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <p style="margin:10px 0 4px;font-size:13px;">Returns a filtered list of employees. All params are optional — omitting all returns every active employee.</p>
            <table class="api-params">
                <tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">q</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Partial name <em>or</em> email match (case-insensitive)</td></tr>
                <tr><td class="param-name">team</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Exact team name, e.g. <code>Support</code></td></tr>
                <tr><td class="param-name">shift</td><td class="param-type">integer</td><td><span class="param-opt">optional</span></td><td>Shift number <code>1</code>, <code>2</code>, or <code>3</code></td></tr>
                <tr><td class="param-name">level</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Exact level string, e.g. <code>Supervisor</code></td></tr>
                <tr><td class="param-name">supervisor</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Partial supervisor name match</td></tr>
                <tr><td class="param-name">skill</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Filter to employees with this skill enabled: <code>mh</code>, <code>ma</code>, or <code>win</code></td></tr>
                <tr><td class="param-name">active</td><td class="param-type">integer</td><td><span class="param-opt">optional</span></td><td><code>1</code> = active only (default), <code>0</code> = inactive only</td></tr>
            </table>
            <div class="api-code-label">Example — all 2nd-shift Support employees</div>
            <div class="api-code">GET api.php?action=search_employees&amp;team=Support&amp;shift=2</div>
            <div class="api-code-label">Example — name / email search</div>
            <div class="api-code">GET api.php?action=search_employees&amp;q=alice</div>
            <div class="api-code-label">Example — all MH-skilled employees on the ESG team</div>
            <div class="api-code">GET api.php?action=search_employees&amp;team=esg&amp;skill=mh</div>
            <div class="api-code-label">Example Response</div>
            <div class="api-code">{ <span class="key">"employees"</span>: [ { <span class="key">"id"</span>: <span class="num">2</span>, <span class="key">"name"</span>: <span class="str">"Alice Smith"</span>, <span class="key">"team"</span>: <span class="str">"Support"</span>, <span class="key">"shift"</span>: <span class="num">2</span> } ], <span class="key">"count"</span>: <span class="num">1</span> }</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-401">401 Unauthenticated</span>
            </div>
        </div>
    </div>

    <!-- GET get_employees_by_skill -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-get">GET</span>
            <span class="api-endpoint-path">api.php?action=get_employees_by_skill</span>
            <span class="api-endpoint-desc">Export all employees who have a specific skill enabled</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <p style="margin:10px 0 4px;font-size:13px;">Returns all active employees with the requested skill (<code>mh</code>, <code>ma</code>, or <code>win</code>) enabled. Each employee record includes a decoded <code>skills</code> object and a human-readable <code>active_skills</code> array.</p>
            <table class="api-params">
                <tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">skill</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td><code>mh</code>, <code>ma</code>, or <code>win</code></td></tr>
                <tr><td class="param-name">team</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Further filter by exact team name</td></tr>
                <tr><td class="param-name">format</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td><code>summary</code> — returns id, name, email, team, active_skills only</td></tr>
            </table>
            <div class="api-code-label">Example — all MH employees</div>
            <div class="api-code">GET api.php?action=get_employees_by_skill&amp;skill=mh</div>
            <div class="api-code-label">Example — WIN employees on ESG team (summary only)</div>
            <div class="api-code">GET api.php?action=get_employees_by_skill&amp;skill=win&amp;team=esg&amp;format=summary</div>
            <div class="api-code-label">Example Response</div>
            <div class="api-code">{
  <span class="key">"skill"</span>: <span class="str">"MH"</span>,
  <span class="key">"count"</span>: <span class="num">3</span>,
  <span class="key">"employees"</span>: [
    {
      <span class="key">"id"</span>: <span class="num">5</span>,
      <span class="key">"name"</span>: <span class="str">"Alice Smith"</span>,
      <span class="key">"email"</span>: <span class="str">"alice@company.com"</span>,
      <span class="key">"team"</span>: <span class="str">"ESG"</span>,
      <span class="key">"skills"</span>: { <span class="key">"mh"</span>: <span class="num">true</span>, <span class="key">"ma"</span>: <span class="num">false</span>, <span class="key">"win"</span>: <span class="num">false</span> },
      <span class="key">"active_skills"</span>: [ <span class="str">"MH"</span> ]
    }
  ]
}</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Invalid skill</span>
                <span class="api-chip chip-401">401 Unauthenticated</span>
            </div>
        </div>
    </div>

    <!-- GET get_emails_by_team -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-get">GET</span>
            <span class="api-endpoint-path">api.php?action=get_emails_by_team</span>
            <span class="api-endpoint-desc">Export email addresses grouped by team</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <p style="margin:10px 0 4px;font-size:13px;">Returns email addresses for all active employees with a non-empty email. Optionally filter by team. Use the <code>format</code> parameter to get a plain <code>csv</code> or newline-separated <code>list</code> string for easy copy-paste into email clients.</p>
            <table class="api-params">
                <tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">team</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Filter to a specific team name (exact match). Omit to return all teams.</td></tr>
                <tr><td class="param-name">format</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td><code>csv</code> — comma-separated email string &nbsp;|&nbsp; <code>list</code> — newline-separated &nbsp;|&nbsp; omit for full JSON grouped by team</td></tr>
            </table>
            <div class="api-code-label">Example — all emails, JSON grouped by team</div>
            <div class="api-code">GET api.php?action=get_emails_by_team</div>
            <div class="api-code-label">Example — ESG team emails as CSV string</div>
            <div class="api-code">GET api.php?action=get_emails_by_team&amp;team=ESG&amp;format=csv</div>
            <div class="api-code-label">Example — all emails as newline list</div>
            <div class="api-code">GET api.php?action=get_emails_by_team&amp;format=list</div>
            <div class="api-code-label">Example JSON Response (default)</div>
            <div class="api-code">{
  <span class="key">"total"</span>: <span class="num">6</span>,
  <span class="key">"team"</span>: <span class="key">null</span>,
  <span class="key">"teams"</span>: [
    {
      <span class="key">"team"</span>: <span class="str">"ESG"</span>,
      <span class="key">"count"</span>: <span class="num">3</span>,
      <span class="key">"employees"</span>: [
        { <span class="key">"id"</span>: <span class="num">1</span>, <span class="key">"name"</span>: <span class="str">"Alice Smith"</span>, <span class="key">"email"</span>: <span class="str">"alice@company.com"</span> },
        { <span class="key">"id"</span>: <span class="num">2</span>, <span class="key">"name"</span>: <span class="str">"Bob Jones"</span>,  <span class="key">"email"</span>: <span class="str">"bob@company.com"</span>   }
      ]
    },
    {
      <span class="key">"team"</span>: <span class="str">"Support"</span>,
      <span class="key">"count"</span>: <span class="num">2</span>,
      <span class="key">"employees"</span>: [
        { <span class="key">"id"</span>: <span class="num">5</span>, <span class="key">"name"</span>: <span class="str">"Carol Lee"</span>, <span class="key">"email"</span>: <span class="str">"carol@company.com"</span> }
      ]
    }
  ]
}</div>
            <div class="api-code-label">Example CSV Response (<code>format=csv</code>)</div>
            <div class="api-code">alice@company.com, bob@company.com, carol@company.com</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-401">401 Unauthenticated</span>
            </div>
        </div>
    </div>

    <!-- POST save_employee -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=save_employee</span>
            <span class="api-endpoint-desc">Create or update employee</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_employees</span>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">id</td><td class="param-type">integer</td><td><span class="param-opt">optional</span></td><td>Omit to create; include to update</td></tr>
                <tr><td class="param-name">name</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Full name</td></tr>
                <tr><td class="param-name">email</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Work email address</td></tr>
                <tr><td class="param-name">team</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Team or department</td></tr>
                <tr><td class="param-name">level</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Job level (e.g. Supervisor, Staff)</td></tr>
                <tr><td class="param-name">shift</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Default shift (e.g. Day, Night)</td></tr>
                <tr><td class="param-name">hours</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Shift hours (e.g. "9A-5P", "10A-6P")</td></tr>
                <tr><td class="param-name">supervisor</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Supervisor name</td></tr>
                <tr><td class="param-name">user_id</td><td class="param-type">integer</td><td><span class="param-opt">optional</span></td><td>Link to a users table row</td></tr>
                <tr><td class="param-name">active</td><td class="param-type">boolean</td><td><span class="param-opt">optional</span></td><td>1 = active (default), 0 = inactive</td></tr>
            </table>
            <div class="api-code-label">Example Request Body</div>
            <div class="api-code">{
  <span class="key">"name"</span>: <span class="str">"John Doe"</span>,
  <span class="key">"email"</span>: <span class="str">"john@company.com"</span>,
  <span class="key">"team"</span>: <span class="str">"Support"</span>,
  <span class="key">"shift"</span>: <span class="str">"Day"</span>,
  <span class="key">"hours"</span>: <span class="str">"9A-5P"</span>
}</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Validation Error</span>
                <span class="api-chip chip-403">403 Forbidden</span>
            </div>
        </div>
    </div>

    <!-- DELETE delete_employee -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-delete">DELETE</span>
            <span class="api-endpoint-path">api.php?action=delete_employee&amp;id={id}</span>
            <span class="api-endpoint-desc">Soft-delete employee</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_employees</span>
            <p style="margin:10px 0 4px;font-size:13px;">Sets <code>active = 0</code> — does not permanently delete the record.</p>
            <table class="api-params">
                <tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">id</td><td class="param-type">integer</td><td><span class="param-req">required</span></td><td>Employee ID to deactivate</td></tr>
            </table>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Missing ID</span>
                <span class="api-chip chip-403">403 Forbidden</span>
            </div>
        </div>
    </div>

    <!-- ======================================================
         SCHEDULES
         ====================================================== -->
    <div class="api-section-title">Schedules</div>

    <!-- GET get_schedule -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-get">GET</span>
            <span class="api-endpoint-path">api.php?action=get_schedule&amp;employee_id={id}&amp;year={y}&amp;month={m}</span>
            <span class="api-endpoint-desc">Get employee's monthly schedule</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <table class="api-params">
                <tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">employee_id</td><td class="param-type">integer</td><td><span class="param-req">required</span></td><td>Employee ID</td></tr>
                <tr><td class="param-name">year</td><td class="param-type">integer</td><td><span class="param-opt">optional</span></td><td>4-digit year (default: current year)</td></tr>
                <tr><td class="param-name">month</td><td class="param-type">integer</td><td><span class="param-opt">optional</span></td><td>Month 1–12 (default: current month)</td></tr>
            </table>
            <div class="api-code-label">Example Response</div>
            <div class="api-code">{
  <span class="key">"schedule"</span>: {
    <span class="key">"2026-03-03"</span>: { <span class="key">"shift"</span>: <span class="str">"Day"</span> },
    <span class="key">"2026-03-04"</span>: { <span class="key">"shift"</span>: <span class="str">"Day"</span> }
  },
  <span class="key">"overrides"</span>: [
    {
      <span class="key">"override_date"</span>: <span class="str">"2026-03-10"</span>,
      <span class="key">"override_type"</span>: <span class="str">"pto"</span>,
      <span class="key">"custom_hours"</span>: <span class="num">0</span>,
      <span class="key">"notes"</span>: <span class="str">"Approved vacation"</span>
    }
  ],
  <span class="key">"updated_at"</span>: <span class="str">"2026-02-15 14:22:01"</span>
}</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Missing employee_id</span>
            </div>
        </div>
    </div>

    <!-- GET get_overrides_range -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-get">GET</span>
            <span class="api-endpoint-path">api.php?action=get_overrides_range</span>
            <span class="api-endpoint-desc">All overrides across a date range</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <p style="margin:10px 0 4px;font-size:13px;">Returns every override within the given date range, joined to employee name, email, and team. Great for planning views — "who has PTO next week?"</p>
            <table class="api-params">
                <tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">start_date</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Range start <code>YYYY-MM-DD</code></td></tr>
                <tr><td class="param-name">end_date</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Range end <code>YYYY-MM-DD</code></td></tr>
                <tr><td class="param-name">employee_ids</td><td class="param-type">CSV int</td><td><span class="param-opt">optional</span></td><td>Narrow to specific employees, e.g. <code>1,2,3</code></td></tr>
                <tr><td class="param-name">type</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Filter by type: <code>pto</code>, <code>sick</code>, <code>holiday</code>, <code>custom_hours</code>, <code>off</code></td></tr>
            </table>
            <div class="api-code-label">Example — all PTO next week company-wide</div>
            <div class="api-code">GET api.php?action=get_overrides_range&amp;start_date=2026-03-02&amp;end_date=2026-03-06&amp;type=pto</div>
            <div class="api-code-label">Example Response</div>
            <div class="api-code">{
  <span class="key">"overrides"</span>: [
    {
      <span class="key">"employee_id"</span>: <span class="num">2</span>, <span class="key">"employee_name"</span>: <span class="str">"Alice Smith"</span>, <span class="key">"team"</span>: <span class="str">"Support"</span>,
      <span class="key">"override_date"</span>: <span class="str">"2026-03-03"</span>, <span class="key">"override_type"</span>: <span class="str">"pto"</span>, <span class="key">"notes"</span>: <span class="str">"Approved"</span>
    }
  ],
  <span class="key">"count"</span>: <span class="num">1</span>
}</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Missing dates</span>
                <span class="api-chip chip-401">401 Unauthenticated</span>
            </div>
        </div>
    </div>

    <!-- POST copy_schedule -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=copy_schedule</span>
            <span class="api-endpoint-desc">Copy a monthly schedule to other employees / months</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_schedules</span>
            <p style="margin:10px 0 4px;font-size:13px;">Copies the schedule data blob from a source employee+month to one or more target employees. Optionally copies to a different month. Overwrites any existing target schedule. Day-level overrides are <strong>not</strong> copied.</p>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">from_employee_id</td><td class="param-type">integer</td><td><span class="param-req">required</span></td><td>Source employee ID</td></tr>
                <tr><td class="param-name">from_year</td><td class="param-type">integer</td><td><span class="param-req">required</span></td><td>Source year</td></tr>
                <tr><td class="param-name">from_month</td><td class="param-type">integer</td><td><span class="param-req">required</span></td><td>Source month (1–12)</td></tr>
                <tr><td class="param-name">to_employee_ids</td><td class="param-type">array&lt;int&gt;</td><td><span class="param-opt">one of three</span></td><td>Target employee IDs</td></tr>
                <tr><td class="param-name">to_emails</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">one of three</span></td><td>Target employee e-mails</td></tr>
                <tr><td class="param-name">to_names</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">one of three</span></td><td>Target employee name fragments</td></tr>
                <tr><td class="param-name">to_year</td><td class="param-type">integer</td><td><span class="param-opt">optional</span></td><td>Target year (defaults to <code>from_year</code>)</td></tr>
                <tr><td class="param-name">to_month</td><td class="param-type">integer</td><td><span class="param-opt">optional</span></td><td>Target month (defaults to <code>from_month</code>)</td></tr>
            </table>
            <div class="api-code-label">Example — copy March schedule to two employees for April</div>
            <div class="api-code">{
  <span class="key">"from_employee_id"</span>: <span class="num">1</span>,
  <span class="key">"from_year"</span>: <span class="num">2026</span>, <span class="key">"from_month"</span>: <span class="num">3</span>,
  <span class="key">"to_emails"</span>: [<span class="str">"alice@co.com"</span>, <span class="str">"bob@co.com"</span>],
  <span class="key">"to_year"</span>: <span class="num">2026</span>, <span class="key">"to_month"</span>: <span class="num">4</span>
}</div>
            <div class="api-code-label">Example Response</div>
            <div class="api-code">{ <span class="key">"success"</span>: <span class="bool">true</span>, <span class="key">"schedules_copied"</span>: <span class="num">2</span> }</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Validation Error</span>
                <span class="api-chip chip-403">403 Forbidden</span>
                <span class="api-chip chip-404">404 Source Not Found</span>
            </div>
        </div>
    </div>

    <!-- POST swap_shifts -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=swap_shifts</span>
            <span class="api-endpoint-desc">Swap overrides between two employees on specific dates</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_schedules</span>
            <p style="margin:10px 0 4px;font-size:13px;">Swaps the day-level overrides between two employees on each given date. If one employee has no override on a date (regular schedule), the other's override moves to them and the first reverts to regular schedule.</p>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">employee_id_a</td><td class="param-type">integer</td><td><span class="param-req">required</span></td><td>First employee ID</td></tr>
                <tr><td class="param-name">employee_id_b</td><td class="param-type">integer</td><td><span class="param-req">required</span></td><td>Second employee ID (must differ from A)</td></tr>
                <tr><td class="param-name">dates</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-req">required</span></td><td>One or more dates in <code>YYYY-MM-DD</code> format</td></tr>
            </table>
            <div class="api-code-label">Example — swap Saturday coverage between two employees</div>
            <div class="api-code">{
  <span class="key">"employee_id_a"</span>: <span class="num">2</span>,
  <span class="key">"employee_id_b"</span>: <span class="num">5</span>,
  <span class="key">"dates"</span>: [<span class="str">"2026-03-14"</span>, <span class="str">"2026-03-21"</span>]
}</div>
            <div class="api-code-label">Example Response</div>
            <div class="api-code">{ <span class="key">"success"</span>: <span class="bool">true</span>, <span class="key">"dates_swapped"</span>: <span class="num">2</span> }</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Validation Error</span>
                <span class="api-chip chip-403">403 Forbidden</span>
                <span class="api-chip chip-500">500 Server Error</span>
            </div>
        </div>
    </div>

    <!-- POST save_schedule -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=save_schedule</span>
            <span class="api-endpoint-desc">Save a full monthly schedule</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_schedules</span>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">employee_id</td><td class="param-type">integer</td><td><span class="param-req">required</span></td><td>Employee ID</td></tr>
                <tr><td class="param-name">year</td><td class="param-type">integer</td><td><span class="param-req">required</span></td><td>4-digit year</td></tr>
                <tr><td class="param-name">month</td><td class="param-type">integer</td><td><span class="param-req">required</span></td><td>Month 1–12</td></tr>
                <tr><td class="param-name">schedule_data</td><td class="param-type">object</td><td><span class="param-req">required</span></td><td>Keyed by <code>YYYY-MM-DD</code>, each value is a day object with a <code>shift</code> key</td></tr>
            </table>
            <div class="api-code-label">Example Request Body</div>
            <div class="api-code">{
  <span class="key">"employee_id"</span>: <span class="num">1</span>,
  <span class="key">"year"</span>: <span class="num">2026</span>,
  <span class="key">"month"</span>: <span class="num">3</span>,
  <span class="key">"schedule_data"</span>: {
    <span class="key">"2026-03-02"</span>: { <span class="key">"shift"</span>: <span class="str">"Day"</span> },
    <span class="key">"2026-03-03"</span>: { <span class="key">"shift"</span>: <span class="str">"Day"</span> }
  }
}</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Validation Error</span>
                <span class="api-chip chip-403">403 Forbidden</span>
            </div>
        </div>
    </div>

    <!-- POST save_override -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=save_override</span>
            <span class="api-endpoint-desc">Add or update a single day override</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_schedules</span>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">employee_id</td><td class="param-type">integer</td><td><span class="param-req">required</span></td><td>Employee ID</td></tr>
                <tr><td class="param-name">date</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Date in <code>YYYY-MM-DD</code> format</td></tr>
                <tr><td class="param-name">type</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td><code>pto</code>, <code>sick</code>, <code>holiday</code>, <code>custom</code></td></tr>
                <tr><td class="param-name">custom_hours</td><td class="param-type">decimal</td><td><span class="param-opt">optional</span></td><td>Hours worked on that day (for custom type)</td></tr>
                <tr><td class="param-name">notes</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Note or reason</td></tr>
            </table>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Validation Error</span>
                <span class="api-chip chip-403">403 Forbidden</span>
            </div>
        </div>
    </div>

    <!-- DELETE delete_override -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-delete">DELETE</span>
            <span class="api-endpoint-path">api.php?action=delete_override&amp;employee_id={id}&amp;date={date}</span>
            <span class="api-endpoint-desc">Remove a day override</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_schedules</span>
            <table class="api-params">
                <tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">employee_id</td><td class="param-type">integer</td><td><span class="param-req">required</span></td><td>Employee ID</td></tr>
                <tr><td class="param-name">date</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Date in <code>YYYY-MM-DD</code> format</td></tr>
            </table>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Missing params</span>
                <span class="api-chip chip-403">403 Forbidden</span>
            </div>
        </div>
    </div>

    <!-- ======================================================
         BULK SCHEDULE
         ====================================================== -->
    <div class="api-section-title">Bulk Schedule Operations</div>

    <!-- GET/POST lookup_employees -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-get">GET</span>
            <span class="api-endpoint-path">api.php?action=lookup_employees</span>
            <span class="api-endpoint-desc">Resolve ids / emails / names → employee records</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <p style="margin:10px 0 4px;font-size:13px;">Preview which employees will be affected before running a bulk operation. Accepts any combination of <code>employee_ids</code>, <code>emails</code>, and <code>names</code> and returns the matching employee records. Also accepts POST with a JSON body.</p>
            <table class="api-params">
                <tr><th>Field / Param</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">employee_ids</td><td class="param-type">int[] / CSV</td><td><span class="param-opt">one of three</span></td><td>Numeric IDs — POST array or GET comma-separated</td></tr>
                <tr><td class="param-name">emails</td><td class="param-type">string[] / CSV</td><td><span class="param-opt">one of three</span></td><td>Employee e-mail addresses — resolved to IDs</td></tr>
                <tr><td class="param-name">names</td><td class="param-type">string[] / CSV</td><td><span class="param-opt">one of three</span></td><td>Partial name match (case-insensitive LIKE) — resolved to IDs</td></tr>
            </table>
            <div class="api-code-label">Example GET — look up by e-mail and name</div>
            <div class="api-code">GET api.php?action=lookup_employees&amp;emails=alice@co.com,bob@co.com&amp;names=Charlie</div>
            <div class="api-code-label">Example POST body</div>
            <div class="api-code">{
  <span class="key">"emails"</span>: [<span class="str">"alice@co.com"</span>, <span class="str">"bob@co.com"</span>],
  <span class="key">"names"</span>: [<span class="str">"Charlie"</span>]
}</div>
            <div class="api-code-label">Example Response</div>
            <div class="api-code">{
  <span class="key">"employees"</span>: [
    { <span class="key">"id"</span>: <span class="num">2</span>, <span class="key">"name"</span>: <span class="str">"Alice Smith"</span>, <span class="key">"email"</span>: <span class="str">"alice@co.com"</span>, <span class="key">"team"</span>: <span class="str">"Support"</span>, <span class="key">"shift"</span>: <span class="num">1</span> },
    { <span class="key">"id"</span>: <span class="num">5</span>, <span class="key">"name"</span>: <span class="str">"Bob Jones"</span>,  <span class="key">"email"</span>: <span class="str">"bob@co.com"</span>,   <span class="key">"team"</span>: <span class="str">"Support"</span>, <span class="key">"shift"</span>: <span class="num">1</span> },
    { <span class="key">"id"</span>: <span class="num">9</span>, <span class="key">"name"</span>: <span class="str">"Charlie Ray"</span>, <span class="key">"email"</span>: <span class="str">"cray@co.com"</span>,  <span class="key">"team"</span>: <span class="str">"Ops"</span>,     <span class="key">"shift"</span>: <span class="num">2</span> }
  ],
  <span class="key">"count"</span>: <span class="num">3</span>
}</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 No matching employees</span>
                <span class="api-chip chip-401">401 Unauthenticated</span>
            </div>
        </div>
    </div>

    <!-- POST bulk_change_shift -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=bulk_change_shift</span>
            <span class="api-endpoint-desc">Change 1st / 2nd / 3rd shift for multiple employees</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_schedules</span>
            <p style="margin:10px 0 4px;font-size:13px;">Updates <code>employees.shift</code> directly — the shift assignment the app displays on every schedule view. Supply employees via any combination of <code>employee_ids</code>, <code>emails</code>, and/or <code>names</code> — at least one is required.</p>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">employee_ids</td><td class="param-type">array&lt;int&gt;</td><td><span class="param-opt">one of three</span></td><td>Numeric employee IDs</td></tr>
                <tr><td class="param-name">emails</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">one of three</span></td><td>Employee e-mail addresses — resolved to IDs</td></tr>
                <tr><td class="param-name">names</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">one of three</span></td><td>Partial name match (case-insensitive) — resolved to IDs</td></tr>
                <tr><td class="param-name">shift</td><td class="param-type">integer</td><td><span class="param-req">required</span></td><td><code>1</code> = 1st Shift, <code>2</code> = 2nd Shift, <code>3</code> = 3rd Shift</td></tr>
                <tr><td class="param-name">start_date</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Range start <code>YYYY-MM-DD</code> — when set, also writes per-day overrides for the schedule grid</td></tr>
                <tr><td class="param-name">end_date</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Range end <code>YYYY-MM-DD</code> (required with <code>start_date</code>)</td></tr>
                <tr><td class="param-name">days_of_week</td><td class="param-type">array&lt;int&gt;</td><td><span class="param-opt">optional</span></td><td>Only apply overrides on these days (0=Sun…6=Sat) — used with date range</td></tr>
                <tr><td class="param-name">skip_days_off</td><td class="param-type">boolean</td><td><span class="param-opt">optional</span></td><td>Skip days the employee is off per their weekly pattern — used with date range</td></tr>
            </table>
            <div class="api-code-label">Example — permanent shift change by ID</div>
            <div class="api-code">{
  <span class="key">"employee_ids"</span>: [<span class="num">2</span>, <span class="num">5</span>, <span class="num">9</span>],
  <span class="key">"shift"</span>: <span class="num">2</span>
}</div>
            <div class="api-code-label">Example — shift change scoped to a date range</div>
            <div class="api-code">{
  <span class="key">"emails"</span>: [<span class="str">"alice@co.com"</span>, <span class="str">"bob@co.com"</span>],
  <span class="key">"shift"</span>: <span class="num">2</span>,
  <span class="key">"start_date"</span>: <span class="str">"2026-03-01"</span>,
  <span class="key">"end_date"</span>:   <span class="str">"2026-03-31"</span>,
  <span class="key">"skip_days_off"</span>: <span class="bool">true</span>
}</div>
            <div class="api-code-label">Example Response (no date range)</div>
            <div class="api-code">{ <span class="key">"success"</span>: <span class="bool">true</span>, <span class="key">"employees_updated"</span>: <span class="num">2</span> }</div>
            <div class="api-code-label">Example Response (with date range)</div>
            <div class="api-code">{ <span class="key">"success"</span>: <span class="bool">true</span>, <span class="key">"employees_updated"</span>: <span class="num">2</span>, <span class="key">"overrides_written"</span>: <span class="num">44</span> }</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Validation Error</span>
                <span class="api-chip chip-403">403 Forbidden</span>
                <span class="api-chip chip-500">500 Server Error</span>
            </div>
        </div>
    </div>

    <!-- POST bulk_change_hours -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=bulk_change_hours</span>
            <span class="api-endpoint-desc">Change working hours text for multiple employees</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_schedules</span>
            <p style="margin:10px 0 4px;font-size:13px;">Updates <code>employees.hours</code> — a free-text string showing what hours the employee works. This is what appears on the schedule view (e.g. changing a team from <code>12pm-8pm</code> to <code>9am-5pm</code>). Supply employees via any combination of <code>employee_ids</code>, <code>emails</code>, and/or <code>names</code> — at least one is required.</p>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">employee_ids</td><td class="param-type">array&lt;int&gt;</td><td><span class="param-opt">one of three</span></td><td>Numeric employee IDs</td></tr>
                <tr><td class="param-name">emails</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">one of three</span></td><td>Employee e-mail addresses — resolved to IDs</td></tr>
                <tr><td class="param-name">names</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">one of three</span></td><td>Partial name match (case-insensitive) — resolved to IDs</td></tr>
                <tr><td class="param-name">hours</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Any text describing the hours, e.g. <code>"9am-5pm"</code>, <code>"7:00 AM - 3:00 PM"</code>, <code>"12pm-8pm"</code></td></tr>
                <tr><td class="param-name">start_date</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Range start <code>YYYY-MM-DD</code> — when set, also writes per-day <code>custom_hours</code> overrides for the schedule grid</td></tr>
                <tr><td class="param-name">end_date</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Range end <code>YYYY-MM-DD</code> (required with <code>start_date</code>)</td></tr>
                <tr><td class="param-name">numeric_hours</td><td class="param-type">decimal</td><td><span class="param-opt">optional</span></td><td>Numeric hour count stored on the per-day override record (e.g. <code>8</code> for a full day) — used with date range</td></tr>
                <tr><td class="param-name">days_of_week</td><td class="param-type">array&lt;int&gt;</td><td><span class="param-opt">optional</span></td><td>Only apply overrides on these days (0=Sun…6=Sat) — used with date range</td></tr>
                <tr><td class="param-name">skip_days_off</td><td class="param-type">boolean</td><td><span class="param-opt">optional</span></td><td>Skip days the employee is off per their weekly pattern — used with date range</td></tr>
            </table>
            <div class="api-code-label">Example — permanent hours change</div>
            <div class="api-code">{
  <span class="key">"emails"</span>: [<span class="str">"alice@co.com"</span>, <span class="str">"bob@co.com"</span>],
  <span class="key">"hours"</span>: <span class="str">"9am-5pm"</span>
}</div>
            <div class="api-code-label">Example — hours change scoped to a date range</div>
            <div class="api-code">{
  <span class="key">"names"</span>: [<span class="str">"Support"</span>],
  <span class="key">"hours"</span>: <span class="str">"7am-3pm"</span>,
  <span class="key">"numeric_hours"</span>: <span class="num">8</span>,
  <span class="key">"start_date"</span>: <span class="str">"2026-04-01"</span>,
  <span class="key">"end_date"</span>:   <span class="str">"2026-04-30"</span>,
  <span class="key">"skip_days_off"</span>: <span class="bool">true</span>
}</div>
            <div class="api-code-label">Example Response (no date range)</div>
            <div class="api-code">{ <span class="key">"success"</span>: <span class="bool">true</span>, <span class="key">"employees_updated"</span>: <span class="num">3</span> }</div>
            <div class="api-code-label">Example Response (with date range)</div>
            <div class="api-code">{ <span class="key">"success"</span>: <span class="bool">true</span>, <span class="key">"employees_updated"</span>: <span class="num">3</span>, <span class="key">"overrides_written"</span>: <span class="num">66</span> }</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Validation Error</span>
                <span class="api-chip chip-403">403 Forbidden</span>
                <span class="api-chip chip-500">500 Server Error</span>
            </div>
        </div>
    </div>

    <!-- POST bulk_change_weekly_pattern -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=bulk_change_weekly_pattern</span>
            <span class="api-endpoint-desc">Set 7-day work pattern for multiple employees</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_schedules</span>
            <p style="margin:10px 0 4px;font-size:13px;">Updates <code>employees.weekly_schedule</code> — the recurring 7-day on/off pattern used to render the schedule grid. Index 0 = Sunday, index 6 = Saturday. <code>1</code> = working, <code>0</code> = off. Supply employees via any combination of <code>employee_ids</code>, <code>emails</code>, and/or <code>names</code> — at least one is required.</p>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">employee_ids</td><td class="param-type">array&lt;int&gt;</td><td><span class="param-opt">one of three</span></td><td>Numeric employee IDs</td></tr>
                <tr><td class="param-name">emails</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">one of three</span></td><td>Employee e-mail addresses — resolved to IDs</td></tr>
                <tr><td class="param-name">names</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">one of three</span></td><td>Partial name match (case-insensitive) — resolved to IDs</td></tr>
                <tr><td class="param-name">weekly_schedule</td><td class="param-type">array&lt;int&gt;</td><td><span class="param-req">required</span></td><td>Exactly 7 values (0 or 1), Sun→Sat. E.g. <code>[0,1,1,1,1,1,0]</code> = Mon–Fri</td></tr>
            </table>
            <div class="api-code-label">Example — Set Mon–Fri pattern by name</div>
            <div class="api-code">{
  <span class="key">"names"</span>: [<span class="str">"Support Team"</span>],
  <span class="key">"weekly_schedule"</span>: [<span class="num">0</span>, <span class="num">1</span>, <span class="num">1</span>, <span class="num">1</span>, <span class="num">1</span>, <span class="num">1</span>, <span class="num">0</span>]
}</div>
            <div class="api-code-label">Example Response</div>
            <div class="api-code">{ <span class="key">"success"</span>: <span class="bool">true</span>, <span class="key">"employees_updated"</span>: <span class="num">4</span> }</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Validation Error</span>
                <span class="api-chip chip-403">403 Forbidden</span>
                <span class="api-chip chip-500">500 Server Error</span>
            </div>
        </div>
    </div>

    <!-- POST bulk_add_override -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=bulk_add_override</span>
            <span class="api-endpoint-desc">Add PTO / holiday / sick / off to multiple employees</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_schedules</span>
            <p style="margin:10px 0 4px;font-size:13px;">Inserts or updates day-level overrides in <code>schedule_overrides</code>. Accepts either a <code>dates[]</code> array or a <code>start_date</code>+<code>end_date</code> range. Supply employees via any combination of <code>employee_ids</code>, <code>emails</code>, and/or <code>names</code> — at least one is required.</p>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">employee_ids</td><td class="param-type">array&lt;int&gt;</td><td><span class="param-opt">one of three</span></td><td>Numeric employee IDs</td></tr>
                <tr><td class="param-name">emails</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">one of three</span></td><td>Employee e-mail addresses — resolved to IDs</td></tr>
                <tr><td class="param-name">names</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">one of three</span></td><td>Partial name match (case-insensitive) — resolved to IDs</td></tr>
                <tr><td class="param-name">type</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td><code>pto</code>, <code>sick</code>, <code>holiday</code>, <code>custom_hours</code>, <code>off</code></td></tr>
                <tr><td class="param-name">dates</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">optional*</span></td><td>Specific dates in <code>YYYY-MM-DD</code> format. Use this <em>or</em> start/end.</td></tr>
                <tr><td class="param-name">start_date</td><td class="param-type">string</td><td><span class="param-opt">optional*</span></td><td>Range start <code>YYYY-MM-DD</code>. Use with <code>end_date</code> instead of <code>dates</code>.</td></tr>
                <tr><td class="param-name">end_date</td><td class="param-type">string</td><td><span class="param-opt">optional*</span></td><td>Range end <code>YYYY-MM-DD</code></td></tr>
                <tr><td class="param-name">days_of_week</td><td class="param-type">array&lt;int&gt;</td><td><span class="param-opt">optional</span></td><td>Only apply on these days of the week (0=Sun…6=Sat)</td></tr>
                <tr><td class="param-name">skip_days_off</td><td class="param-type">boolean</td><td><span class="param-opt">optional</span></td><td>Skip dates where the employee is off per their weekly pattern</td></tr>
                <tr><td class="param-name">custom_hours</td><td class="param-type">decimal</td><td><span class="param-opt">optional</span></td><td>Required when <code>type=custom_hours</code> (e.g. <code>4</code> for a half day)</td></tr>
                <tr><td class="param-name">notes</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Comment visible on the schedule</td></tr>
            </table>
            <div class="api-code-label">Example — Mark company holiday by ID</div>
            <div class="api-code">{
  <span class="key">"employee_ids"</span>: [<span class="num">1</span>, <span class="num">2</span>, <span class="num">3</span>, <span class="num">4</span>, <span class="num">5</span>],
  <span class="key">"type"</span>: <span class="str">"holiday"</span>,
  <span class="key">"dates"</span>: [<span class="str">"2026-07-04"</span>],
  <span class="key">"notes"</span>: <span class="str">"Independence Day"</span>
}</div>
            <div class="api-code-label">Example — PTO for a team by email, weekdays only</div>
            <div class="api-code">{
  <span class="key">"emails"</span>: [<span class="str">"alice@co.com"</span>, <span class="str">"bob@co.com"</span>],
  <span class="key">"type"</span>: <span class="str">"pto"</span>,
  <span class="key">"start_date"</span>: <span class="str">"2026-08-03"</span>,
  <span class="key">"end_date"</span>:   <span class="str">"2026-08-07"</span>,
  <span class="key">"skip_days_off"</span>: <span class="bool">true</span>
}</div>
            <div class="api-code-label">Example Response</div>
            <div class="api-code">{ <span class="key">"success"</span>: <span class="bool">true</span>, <span class="key">"overrides_written"</span>: <span class="num">10</span> }</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Validation Error</span>
                <span class="api-chip chip-403">403 Forbidden</span>
                <span class="api-chip chip-500">500 Server Error</span>
            </div>
        </div>
    </div>

    <!-- POST bulk_clear_overrides -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=bulk_clear_overrides</span>
            <span class="api-endpoint-desc">Remove overrides and restore default schedule</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_schedules</span>
            <p style="margin:10px 0 4px;font-size:13px;">Deletes all <code>schedule_overrides</code> rows for the given employees within the date range, restoring them to their recurring weekly pattern. Supply employees via any combination of <code>employee_ids</code>, <code>emails</code>, and/or <code>names</code> — at least one is required.</p>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">employee_ids</td><td class="param-type">array&lt;int&gt;</td><td><span class="param-opt">one of three</span></td><td>Numeric employee IDs</td></tr>
                <tr><td class="param-name">emails</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">one of three</span></td><td>Employee e-mail addresses — resolved to IDs</td></tr>
                <tr><td class="param-name">names</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">one of three</span></td><td>Partial name match (case-insensitive) — resolved to IDs</td></tr>
                <tr><td class="param-name">start_date</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Range start <code>YYYY-MM-DD</code></td></tr>
                <tr><td class="param-name">end_date</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Range end <code>YYYY-MM-DD</code></td></tr>
            </table>
            <div class="api-code-label">Example — Clear March overrides by name</div>
            <div class="api-code">{
  <span class="key">"names"</span>: [<span class="str">"Alice"</span>, <span class="str">"Bob"</span>],
  <span class="key">"start_date"</span>: <span class="str">"2026-03-01"</span>,
  <span class="key">"end_date"</span>:   <span class="str">"2026-03-31"</span>
}</div>
            <div class="api-code-label">Example Response</div>
            <div class="api-code">{ <span class="key">"success"</span>: <span class="bool">true</span> }</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Validation Error</span>
                <span class="api-chip chip-403">403 Forbidden</span>
                <span class="api-chip chip-500">500 Server Error</span>
            </div>
        </div>
    </div>

    <!-- POST bulk_schedule_change (index.php form action) -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <div class="api-endpoint-path-wrap">
                <span class="api-endpoint-path">index.php</span>
                <span style="font-size:11px;padding:2px 7px;border-radius:4px;background-color:#fef3c7;color:#92400e;font-weight:600;white-space:nowrap;">action=bulk_schedule_change</span>
            </div>
            <span class="api-endpoint-desc">Apply overrides + shift changes to multiple employees over a date range</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 edit_schedule</span>
            <p style="margin:10px 0 4px;font-size:13px;">
                Writes per-day <code>schedule_overrides</code> records for every day in the range for each selected employee,
                and optionally updates each employee's <code>shift</code> assignment at the same time.
                This is the power tool for configuring on-call rotations, shift transitions, and coverage blocks in bulk.
            </p>
            <p style="margin:0 0 10px;font-size:12px;color:#64748b;">
                ⚠️ This action submits to <code>index.php</code> as <strong>form-encoded</strong> data
                (<code>Content-Type: application/x-www-form-urlencoded</code>), not JSON.
                Requires an active browser session or a valid <code>X-API-Key</code> header.
            </p>

            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">action</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Must be <code>bulk_schedule_change</code></td></tr>
                <tr><td class="param-name">startDate</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Range start — <code>YYYY-MM-DD</code></td></tr>
                <tr><td class="param-name">endDate</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Range end inclusive — <code>YYYY-MM-DD</code></td></tr>
                <tr><td class="param-name">selectedEmployees[]</td><td class="param-type">int[]</td><td><span class="param-req">required</span></td><td>Employee IDs — repeat the key for each: <code>selectedEmployees[]=5&amp;selectedEmployees[]=9</code></td></tr>
                <tr><td class="param-name">bulkStatus</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Status to write on each day. Values: <code>on</code> · <code>off</code> · <code>custom_hours</code> · <code>pto</code> · <code>sick</code> · <code>holiday</code> · <code>schedule</code> (clears overrides)</td></tr>
                <tr><td class="param-name">bulkCustomHours</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Time range shown in each cell — required when <code>bulkStatus=custom_hours</code>. E.g. <code>20:00-05:00</code></td></tr>
                <tr><td class="param-name">bulkComment</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Note stored with each override (visible as a cell tooltip)</td></tr>
                <tr><td class="param-name">newSchedule[]</td><td class="param-type">int[]</td><td><span class="param-opt">optional</span></td><td>Restrict to specific days of the week — <code>0</code>=Sun · <code>1</code>=Mon · … · <code>6</code>=Sat. Omit to apply every day in the range.</td></tr>
                <tr><td class="param-name">skipDaysOff</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Send <code>on</code> to skip dates that fall on an employee's regular day off</td></tr>
                <tr><td class="param-name">changeShift</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Send <code>on</code> to also update each employee's shift number</td></tr>
                <tr><td class="param-name">newShift</td><td class="param-type">integer</td><td><span class="param-opt">optional</span></td><td><code>1</code>=1st Shift (7AM–3PM) · <code>2</code>=2nd Shift (3PM–11PM) · <code>3</code>=3rd Shift (11PM–7AM)</td></tr>
                <tr><td class="param-name">shiftChangeWhen</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td><code>now</code> (default) — update immediately · <code>start_date</code> — schedule for future effective date</td></tr>
                <tr><td class="param-name">current_tab</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Set to <code>schedule</code> to redirect back to the Schedule tab after saving</td></tr>
            </table>

            <!-- Nested rotation examples toggle -->
            <div style="margin-top:16px;">
                <div onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display==='none' ? 'block' : 'none'; this.querySelector('.bsc-chev').style.transform = this.nextElementSibling.style.display==='none' ? 'rotate(0deg)' : 'rotate(180deg');"
                     style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:8px 10px;background:#f1f5f9;border-radius:7px;user-select:none;">
                    <span style="font-size:13px;font-weight:700;color:#1e3a8a;">📘 Rotation Examples</span>
                    <span class="bsc-chev" style="font-size:10px;color:#94a3b8;margin-left:auto;transition:transform 0.2s;">▼</span>
                </div>
                <div style="display:none;padding:14px 2px 4px;">

                    <!-- Example 1 -->
                    <p style="margin:0 0 6px;font-size:12px;font-weight:700;color:#1e3a8a;">Example 1 — 2-Week Rotation: 10 Admins, 1st ↔ 3rd Shift</p>
                    <p style="margin:0 0 8px;font-size:12px;color:#475569;">
                        Group A (IDs 101–105) and Group B (IDs 106–110) swap between 1st and 3rd shift every week.
                        Submit two requests per week — one per group. Repeat for each 2-week block.
                    </p>

                    <div class="api-code-label">Week 1 — Group A on 1st Shift (Mon–Fri, 07:00–15:00)</div>
                    <div class="api-code">POST index.php
<span class="cmnt"># Group A on 1st shift, Mon–Fri only</span>
action=bulk_schedule_change
&amp;startDate=<span class="str">2026-03-09</span>&amp;endDate=<span class="str">2026-03-13</span>
&amp;selectedEmployees[]=<span class="num">101</span>&amp;selectedEmployees[]=<span class="num">102</span>&amp;selectedEmployees[]=<span class="num">103</span>
&amp;selectedEmployees[]=<span class="num">104</span>&amp;selectedEmployees[]=<span class="num">105</span>
&amp;bulkStatus=<span class="str">custom_hours</span>&amp;bulkCustomHours=<span class="str">07:00-15:00</span>
&amp;bulkComment=<span class="str">Rotation+Week+1+-+1st+Shift</span>
&amp;newSchedule[]=<span class="num">1</span>&amp;newSchedule[]=<span class="num">2</span>&amp;newSchedule[]=<span class="num">3</span>&amp;newSchedule[]=<span class="num">4</span>&amp;newSchedule[]=<span class="num">5</span>
&amp;changeShift=on&amp;newShift=<span class="num">1</span>&amp;shiftChangeWhen=<span class="str">start_date</span>
&amp;current_tab=schedule</div>

                    <div class="api-code-label">Week 1 — Group B on 3rd Shift (Mon–Fri, 23:00–07:00)</div>
                    <div class="api-code">POST index.php
<span class="cmnt"># Group B on 3rd shift — same dates, employees 106–110</span>
action=bulk_schedule_change
&amp;startDate=<span class="str">2026-03-09</span>&amp;endDate=<span class="str">2026-03-13</span>
&amp;selectedEmployees[]=<span class="num">106</span>&amp;selectedEmployees[]=<span class="num">107</span>&amp;selectedEmployees[]=<span class="num">108</span>
&amp;selectedEmployees[]=<span class="num">109</span>&amp;selectedEmployees[]=<span class="num">110</span>
&amp;bulkStatus=<span class="str">custom_hours</span>&amp;bulkCustomHours=<span class="str">23:00-07:00</span>
&amp;bulkComment=<span class="str">Rotation+Week+1+-+3rd+Shift</span>
&amp;newSchedule[]=<span class="num">1</span>&amp;newSchedule[]=<span class="num">2</span>&amp;newSchedule[]=<span class="num">3</span>&amp;newSchedule[]=<span class="num">4</span>&amp;newSchedule[]=<span class="num">5</span>
&amp;changeShift=on&amp;newShift=<span class="num">3</span>&amp;shiftChangeWhen=<span class="str">start_date</span>
&amp;current_tab=schedule</div>

                    <div class="api-code-label">Week 2 — Groups swap (repeat with new dates + swapped newShift)</div>
                    <div class="api-code"><span class="cmnt"># Group A → 3rd Shift, Group B → 1st Shift</span>
startDate=<span class="str">2026-03-16</span>&amp;endDate=<span class="str">2026-03-20</span>
Group A: bulkCustomHours=<span class="str">23:00-07:00</span>&amp;newShift=<span class="num">3</span>
Group B: bulkCustomHours=<span class="str">07:00-15:00</span>&amp;newShift=<span class="num">1</span>
<span class="cmnt"># Repeat this pair every 2 weeks for the full rotation period</span></div>

                    <!-- Divider -->
                    <div style="border-top:1px solid #e2e8f0;margin:16px 0;"></div>

                    <!-- Example 2 -->
                    <p style="margin:0 0 6px;font-size:12px;font-weight:700;color:#1e3a8a;">Example 2 — 3-Month On-Call Rotation (Q2 2026, SecOps / Abuse)</p>
                    <p style="margin:0 0 8px;font-size:12px;color:#475569;">
                        Three sub-teams rotate through a 20:00–05:00 on-call block. Each team holds the block for ~4 weeks,
                        then resets to their default schedule. 6 total requests configure the full quarter.
                    </p>

                    <div class="api-code-label">Block 1 — Team Alpha on-call, Apr 1–28 (all 7 days, 20:00–05:00)</div>
                    <div class="api-code">POST index.php
action=bulk_schedule_change
&amp;startDate=<span class="str">2026-04-01</span>&amp;endDate=<span class="str">2026-04-28</span>
&amp;selectedEmployees[]=<span class="num">201</span>&amp;selectedEmployees[]=<span class="num">202</span>&amp;selectedEmployees[]=<span class="num">203</span>
&amp;bulkStatus=<span class="str">custom_hours</span>&amp;bulkCustomHours=<span class="str">20:00-05:00</span>
&amp;bulkComment=<span class="str">Q2+On-Call+Block+1+-+Team+Alpha</span>
<span class="cmnt"># No newSchedule[] = applies every day in range (7 days/week coverage)</span>
&amp;changeShift=on&amp;newShift=<span class="num">3</span>&amp;shiftChangeWhen=<span class="str">start_date</span>
&amp;current_tab=schedule</div>

                    <div class="api-code-label">End of Block 1 — Reset Team Alpha to default schedule</div>
                    <div class="api-code">POST index.php
action=bulk_schedule_change
&amp;startDate=<span class="str">2026-04-29</span>&amp;endDate=<span class="str">2026-06-30</span>
&amp;selectedEmployees[]=<span class="num">201</span>&amp;selectedEmployees[]=<span class="num">202</span>&amp;selectedEmployees[]=<span class="num">203</span>
&amp;bulkStatus=<span class="str">schedule</span>
<span class="cmnt"># bulkStatus=schedule removes all overrides → cells revert to default weekly pattern</span>
&amp;current_tab=schedule</div>

                    <div class="api-code-label">Block 2 — Team Beta on-call, Apr 29–May 26</div>
                    <div class="api-code">startDate=<span class="str">2026-04-29</span>&amp;endDate=<span class="str">2026-05-26</span>
selectedEmployees[]=<span class="num">204</span>&amp;selectedEmployees[]=<span class="num">205</span>&amp;selectedEmployees[]=<span class="num">206</span>
bulkStatus=<span class="str">custom_hours</span>&amp;bulkCustomHours=<span class="str">20:00-05:00</span>&amp;changeShift=on&amp;newShift=<span class="num">3</span></div>

                    <div class="api-code-label">Block 3 — Team Gamma on-call, May 27–Jun 30</div>
                    <div class="api-code">startDate=<span class="str">2026-05-27</span>&amp;endDate=<span class="str">2026-06-30</span>
selectedEmployees[]=<span class="num">207</span>&amp;selectedEmployees[]=<span class="num">208</span>&amp;selectedEmployees[]=<span class="num">209</span>
bulkStatus=<span class="str">custom_hours</span>&amp;bulkCustomHours=<span class="str">20:00-05:00</span>&amp;changeShift=on&amp;newShift=<span class="num">3</span></div>

                    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:10px 14px;margin-top:10px;">
                        <p style="margin:0;font-size:12px;color:#14532d;">
                            <strong>Full Q2 = 6 requests:</strong> 3 on-call blocks (one per team) + 3 reset requests (one per team after their block ends). Blocks for Beta and Gamma can be submitted at any time in advance — overrides are keyed by date so future entries have no effect until that date arrives.
                        </p>
                    </div>

                </div><!-- /rotation examples content -->
            </div><!-- /rotation examples toggle -->

            <div class="api-response-chips" style="margin-top:14px;">
                <span class="api-chip chip-200">200 Redirect (success message shown)</span>
                <span class="api-chip chip-400">400 Missing required fields</span>
                <span class="api-chip chip-403">403 Forbidden</span>
            </div>
        </div>
    </div>

    <!-- POST bulk_assign_team -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=bulk_assign_team</span>
            <span class="api-endpoint-desc">Move multiple employees to a new team</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_employees</span>
            <p style="margin:10px 0 4px;font-size:13px;">Updates <code>employees.team</code> for all matched employees. Supply employees via any combination of <code>employee_ids</code>, <code>emails</code>, and/or <code>names</code> — at least one is required.</p>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">employee_ids</td><td class="param-type">array&lt;int&gt;</td><td><span class="param-opt">one of three</span></td><td>Numeric employee IDs</td></tr>
                <tr><td class="param-name">emails</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">one of three</span></td><td>Employee e-mail addresses</td></tr>
                <tr><td class="param-name">names</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">one of three</span></td><td>Partial name match (case-insensitive)</td></tr>
                <tr><td class="param-name">team</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>New team name, e.g. <code>"Ops"</code></td></tr>
            </table>
            <div class="api-code-label">Example</div>
            <div class="api-code">{
  <span class="key">"emails"</span>: [<span class="str">"alice@co.com"</span>, <span class="str">"bob@co.com"</span>],
  <span class="key">"team"</span>: <span class="str">"Ops"</span>
}</div>
            <div class="api-code-label">Example Response</div>
            <div class="api-code">{ <span class="key">"success"</span>: <span class="bool">true</span>, <span class="key">"employees_updated"</span>: <span class="num">2</span> }</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Validation Error</span>
                <span class="api-chip chip-403">403 Forbidden</span>
                <span class="api-chip chip-500">500 Server Error</span>
            </div>
        </div>
    </div>

    <!-- POST bulk_assign_supervisor -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=bulk_assign_supervisor</span>
            <span class="api-endpoint-desc">Reassign supervisor for multiple employees</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_employees</span>
            <p style="margin:10px 0 4px;font-size:13px;">Updates <code>employees.supervisor</code> for all matched employees. Supply employees via any combination of <code>employee_ids</code>, <code>emails</code>, and/or <code>names</code> — at least one is required.</p>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">employee_ids</td><td class="param-type">array&lt;int&gt;</td><td><span class="param-opt">one of three</span></td><td>Numeric employee IDs</td></tr>
                <tr><td class="param-name">emails</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">one of three</span></td><td>Employee e-mail addresses</td></tr>
                <tr><td class="param-name">names</td><td class="param-type">array&lt;string&gt;</td><td><span class="param-opt">one of three</span></td><td>Partial name match (case-insensitive)</td></tr>
                <tr><td class="param-name">supervisor</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Supervisor's name as stored on the employee record</td></tr>
            </table>
            <div class="api-code-label">Example</div>
            <div class="api-code">{
  <span class="key">"names"</span>: [<span class="str">"Support"</span>],
  <span class="key">"supervisor"</span>: <span class="str">"Jane Smith"</span>
}</div>
            <div class="api-code-label">Example Response</div>
            <div class="api-code">{ <span class="key">"success"</span>: <span class="bool">true</span>, <span class="key">"employees_updated"</span>: <span class="num">4</span> }</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Validation Error</span>
                <span class="api-chip chip-403">403 Forbidden</span>
                <span class="api-chip chip-500">500 Server Error</span>
            </div>
        </div>
    </div>

    <!-- ======================================================
         USERS
         ====================================================== -->
    <div class="api-section-title">Users</div>

    <!-- GET get_users -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-get">GET</span>
            <span class="api-endpoint-path">api.php?action=get_users</span>
            <span class="api-endpoint-desc">List all users</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_users</span>
            <p style="margin:10px 0 4px;font-size:13px;">Returns all users joined to their linked employee record where applicable.</p>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-403">403 Forbidden</span>
            </div>
        </div>
    </div>

    <!-- POST save_user -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=save_user</span>
            <span class="api-endpoint-desc">Create or update user account</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_users</span>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">id</td><td class="param-type">integer</td><td><span class="param-opt">optional</span></td><td>Omit to create; include to update</td></tr>
                <tr><td class="param-name">username</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Login username</td></tr>
                <tr><td class="param-name">email</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Email address</td></tr>
                <tr><td class="param-name">role</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td><code>admin</code>, <code>manager</code>, <code>supervisor</code>, <code>employee</code></td></tr>
                <tr><td class="param-name">auth_method</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td><code>google</code>, <code>local</code>, or <code>both</code> (default: <code>local</code>)</td></tr>
                <tr><td class="param-name">active</td><td class="param-type">boolean</td><td><span class="param-opt">optional</span></td><td>1 = active (default)</td></tr>
            </table>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Validation Error</span>
                <span class="api-chip chip-403">403 Forbidden</span>
            </div>
        </div>
    </div>

    <!-- ======================================================
         MOTD
         ====================================================== -->
    <div class="api-section-title">Message of the Day (MOTD)</div>

    <!-- GET get_motd_messages -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-get">GET</span>
            <span class="api-endpoint-path">api.php?action=get_motd_messages</span>
            <span class="api-endpoint-desc">List all active MOTD messages</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <p style="margin:10px 0 4px;font-size:13px;">Returns active MOTD messages where today falls within the optional <code>start_date</code>/<code>end_date</code> window, ordered newest first.</p>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-401">401 Unauthenticated</span>
            </div>
        </div>
    </div>

    <!-- POST save_motd -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=save_motd</span>
            <span class="api-endpoint-desc">Create a new MOTD message</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_users</span>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">message</td><td class="param-type">string</td><td><span class="param-req">required</span></td><td>Message text (HTML allowed)</td></tr>
                <tr><td class="param-name">start_date</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Show from this date <code>YYYY-MM-DD</code></td></tr>
                <tr><td class="param-name">end_date</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Hide after this date <code>YYYY-MM-DD</code></td></tr>
                <tr><td class="param-name">include_anniversaries</td><td class="param-type">boolean</td><td><span class="param-opt">optional</span></td><td>Show work anniversaries alongside (default: <code>true</code>)</td></tr>
            </table>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Message required</span>
                <span class="api-chip chip-403">403 Forbidden</span>
            </div>
        </div>
    </div>

    <!-- POST deactivate_motd -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=deactivate_motd</span>
            <span class="api-endpoint-desc">Deactivate a MOTD message</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_users</span>
            <p style="margin:10px 0 4px;font-size:13px;">Sets <code>active = 0</code> on the specified MOTD message — it will no longer appear to users. The record is kept for audit history.</p>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">id</td><td class="param-type">integer</td><td><span class="param-req">required</span></td><td>MOTD message ID to deactivate</td></tr>
            </table>
            <div class="api-code-label">Example</div>
            <div class="api-code">{ <span class="key">"id"</span>: <span class="num">5</span> }</div>
            <div class="api-code-label">Example Response</div>
            <div class="api-code">{ <span class="key">"success"</span>: <span class="bool">true</span> }</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-400">400 Missing id</span>
                <span class="api-chip chip-403">403 Forbidden</span>
            </div>
        </div>
    </div>

    <!-- ======================================================
         AUDIT LOG
         ====================================================== -->
    <div class="api-section-title">Audit Log</div>

    <!-- GET get_audit_log -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-get">GET</span>
            <span class="api-endpoint-path">api.php?action=get_audit_log</span>
            <span class="api-endpoint-desc">Read the system audit log with pagination</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_users</span>
            <p style="margin:10px 0 4px;font-size:13px;">Returns paginated audit log entries showing who changed what and when. Every API write operation is logged here automatically.</p>
            <table class="api-params">
                <tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">limit</td><td class="param-type">integer</td><td><span class="param-opt">optional</span></td><td>Max records to return (default <code>50</code>, max <code>500</code>)</td></tr>
                <tr><td class="param-name">offset</td><td class="param-type">integer</td><td><span class="param-opt">optional</span></td><td>Pagination offset (default <code>0</code>)</td></tr>
                <tr><td class="param-name">table_name</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Filter by table: <code>employees</code>, <code>schedules</code>, <code>schedule_overrides</code>, etc.</td></tr>
                <tr><td class="param-name">action</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Partial keyword match on action, e.g. <code>SHIFT</code>, <code>OVERRIDE</code>, <code>DELETE</code></td></tr>
                <tr><td class="param-name">user_id</td><td class="param-type">integer</td><td><span class="param-opt">optional</span></td><td>Filter by which user performed the action</td></tr>
            </table>
            <div class="api-code-label">Example — recent shift changes</div>
            <div class="api-code">GET api.php?action=get_audit_log&amp;action=SHIFT&amp;limit=25</div>
            <div class="api-code-label">Example Response</div>
            <div class="api-code">{
  <span class="key">"entries"</span>: [
    {
      <span class="key">"id"</span>: <span class="num">142</span>,
      <span class="key">"action"</span>: <span class="str">"BULK_CHANGE_SHIFT"</span>,
      <span class="key">"table_name"</span>: <span class="str">"employees"</span>,
      <span class="key">"username"</span>: <span class="str">"jsmith"</span>,
      <span class="key">"new_data"</span>: <span class="str">"{\"employee_ids\":[2,5],\"shift\":2}"</span>,
      <span class="key">"created_at"</span>: <span class="str">"2026-02-23 10:14:00"</span>
    }
  ],
  <span class="key">"count"</span>: <span class="num">1</span>, <span class="key">"total"</span>: <span class="num">142</span>, <span class="key">"limit"</span>: <span class="num">25</span>, <span class="key">"offset"</span>: <span class="num">0</span>
}</div>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-403">403 Forbidden</span>
            </div>
        </div>
    </div>

    <!-- ======================================================
         BACKUPS
         ====================================================== -->
    <div class="api-section-title">Backups</div>

    <!-- POST create_backup -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-post">POST</span>
            <span class="api-endpoint-path">api.php?action=create_backup</span>
            <span class="api-endpoint-desc">Trigger a system backup</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_backups</span>
            <table class="api-params">
                <tr><th>Field</th><th>Type</th><th>Required</th><th>Description</th></tr>
                <tr><td class="param-name">type</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Backup type: <code>manual</code> (default) or <code>auto</code></td></tr>
                <tr><td class="param-name">description</td><td class="param-type">string</td><td><span class="param-opt">optional</span></td><td>Human-readable description of this backup</td></tr>
            </table>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-403">403 Forbidden</span>
                <span class="api-chip chip-500">500 Server Error</span>
            </div>
        </div>
    </div>

    <!-- GET get_backups -->
    <div class="api-endpoint">
        <div class="api-endpoint-header" onclick="this.parentElement.classList.toggle('open')">
            <span class="api-method method-get">GET</span>
            <span class="api-endpoint-path">api.php?action=get_backups</span>
            <span class="api-endpoint-desc">List recent backups</span>
            <span class="api-chevron">▼</span>
        </div>
        <div class="api-endpoint-body">
            <span class="api-perm">🔐 manage_backups</span>
            <p style="margin:10px 0 4px;font-size:13px;">Returns the 50 most recent backup records ordered by creation date descending.</p>
            <div class="api-response-chips">
                <span class="api-chip chip-200">200 OK</span>
                <span class="api-chip chip-403">403 Forbidden</span>
            </div>
        </div>
    </div>

    <!-- ======================================================
         ERROR FORMAT
         ====================================================== -->
    <div class="api-section-title">Error Response Format</div>
    <p style="font-size:13px;margin:0 0 12px;">All errors return a JSON object with an <code>error</code> key and the appropriate HTTP status code.</p>
    <div class="api-code">{ <span class="key">"error"</span>: <span class="str">"Authentication required"</span> }   <span class="cmnt">// HTTP 401</span>
{ <span class="key">"error"</span>: <span class="str">"Insufficient permissions"</span> }  <span class="cmnt">// HTTP 403</span>
{ <span class="key">"error"</span>: <span class="str">"Employee ID required"</span> }      <span class="cmnt">// HTTP 400</span>
{ <span class="key">"error"</span>: <span class="str">"Employee not found"</span> }         <span class="cmnt">// HTTP 404</span></div>

</div><!-- /.api-wrap -->
</div><!-- /.api-doc-shell -->

<script>
// Keep all endpoints collapsed by default — clicking the header toggles them.
// (Handled by the onclick="this.parentElement.classList.toggle('open')" on each header.)

// ── Copy-to-clipboard buttons ──────────────────────────────────────────────
(function () {
    function makeCopyBtn(darkStyle) {
        var btn = document.createElement('button');
        btn.className = 'api-copy-btn';
        btn.setAttribute('type', 'button');
        btn.innerHTML = '⎘ Copy';
        if (darkStyle) btn.setAttribute('data-dark', '1');
        return btn;
    }

    function flashCopied(btn) {
        btn.innerHTML = '✓ Copied';
        btn.classList.add('copied');
        setTimeout(function () {
            btn.innerHTML = '⎘ Copy';
            btn.classList.remove('copied');
        }, 1800);
    }

    function copyText(text, btn) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () { flashCopied(btn); });
        } else {
            // Fallback for non-HTTPS
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); flashCopied(btn); } catch (e) {}
            document.body.removeChild(ta);
        }
    }

    // 1. Wrap every .api-code block and inject a dark copy button
    document.querySelectorAll('.api-code').forEach(function (block) {
        // Skip if already wrapped
        if (block.parentElement.classList.contains('api-code-wrap')) return;

        var wrap = document.createElement('div');
        wrap.className = 'api-code-wrap';
        block.parentNode.insertBefore(wrap, block);
        wrap.appendChild(block);

        var btn = makeCopyBtn(true);
        wrap.appendChild(btn);

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            copyText(block.innerText || block.textContent, btn);
        });
    });

    // 2. Add a copy button next to every .api-endpoint-path span
    document.querySelectorAll('.api-endpoint-path').forEach(function (span) {
        // Skip if already wrapped
        if (span.parentElement.classList.contains('api-endpoint-path-wrap')) return;

        var wrap = document.createElement('span');
        wrap.className = 'api-endpoint-path-wrap';
        span.parentNode.insertBefore(wrap, span);
        wrap.appendChild(span);

        var btn = makeCopyBtn(false);
        wrap.appendChild(btn);

        btn.addEventListener('click', function (e) {
            e.stopPropagation(); // don't toggle the accordion
            copyText(span.innerText || span.textContent, btn);
        });
    });
}());
</script>
