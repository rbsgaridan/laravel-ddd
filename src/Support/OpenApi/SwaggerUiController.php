<?php

namespace Incoder\DDD\Support\OpenApi;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Serves the Swagger UI single-page app and the live OpenAPI JSON spec.
 *
 * Routes registered by IncoderDDDServiceProvider (when api-docs.enabled is true):
 *   GET  /api/docs       → SwaggerUiController@ui   (Swagger UI HTML)
 *   GET  /api/docs/spec  → SwaggerUiController@spec  (Live OA3 JSON)
 */
class SwaggerUiController extends Controller
{
    /**
     * Render the Swagger UI HTML page.
     * Uses the official swagger-ui CDN — no npm install required.
     */
    public function ui(): Response
    {
        // Use a relative path to keep the same origin/scheme as the docs page.
        $specUrl = config('api-docs.spec_path', '/api/docs/spec');
        $title = e(config('api-docs.title', config('app.name', 'API').' API'));
        $version = e(config('api-docs.version', '1.0.0'));
        $loginUrl = e(url('/login'));
        $apiLoginUrl = e(url('/api/auth/login'));
        $meUrl = e(url('/api/auth/me'));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>{$title} — API Docs</title>
  <link  rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css"/>
  <style>
    /* ── Layout ── */
    body { margin: 0; background: #fafafa; }
    :root {
      color-scheme: light dark;
      --swagger-page-bg: #fafafa;
      --swagger-surface: #ffffff;
      --swagger-surface-muted: #f8fafc;
      --swagger-border: #e5e7eb;
      --swagger-text: #111827;
      --swagger-text-muted: #4b5563;
      --swagger-tag-bg: #f0f4ff;
      --swagger-tag-text: #1f2937;
      --swagger-tag-border: #c7d2fe;
    }
    @media (prefers-color-scheme: dark) {
      :root {
        --swagger-page-bg: #0f172a;
        --swagger-surface: #111827;
        --swagger-surface-muted: #0b1220;
        --swagger-border: #334155;
        --swagger-text: #e5e7eb;
        --swagger-text-muted: #cbd5e1;
        --swagger-tag-bg: #1e293b;
        --swagger-tag-text: #f8fafc;
        --swagger-tag-border: #475569;
      }
    }
    body { background: var(--swagger-page-bg); color: var(--swagger-text); }
    .swagger-ui,
    .swagger-ui .wrapper,
    .swagger-ui .information-container,
    .swagger-ui .scheme-container {
      background: var(--swagger-page-bg);
      color: var(--swagger-text);
    }
    .topbar-wrapper img[alt="Swagger UI"] { display: none; }
    .topbar .topbar-wrapper::before {
      content: "{$title}";
      color: #fff;
      font-size: 1.2rem;
      font-weight: 600;
      margin-left: 1rem;
    }
    /* Auth notice banner */
    #auth-notice {
      background: #fffbeb; border-bottom: 1px solid #f59e0b;
      padding: 10px 20px; font-size: 14px; color: #92400e;
      display: flex; align-items: center; gap: 10px;
    }
    #auth-notice a { color: #b45309; font-weight: 600; }
    #auth-notice.authenticated { background: #f0fdf4; border-color: #86efac; color: #166534; }
    #auth-helper {
      display: grid;
      gap: 12px;
      padding: 16px 20px;
      background: var(--swagger-surface);
      border-bottom: 1px solid var(--swagger-border);
    }
    #auth-helper h2 {
      margin: 0;
      font-size: 16px;
      color: var(--swagger-text);
    }
    #auth-helper p {
      margin: 0;
      font-size: 14px;
      color: var(--swagger-text-muted);
    }
    .auth-helper-form {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 12px;
      align-items: end;
    }
    .auth-helper-field {
      display: grid;
      gap: 6px;
    }
    .auth-helper-field label {
      font-size: 12px;
      font-weight: 600;
      color: var(--swagger-text-muted);
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .auth-helper-field input,
    .auth-helper-field textarea {
      width: 100%;
      box-sizing: border-box;
      padding: 10px 12px;
      border: 1px solid var(--swagger-border);
      border-radius: 8px;
      font: inherit;
      color: var(--swagger-text);
      background: var(--swagger-surface-muted);
    }
    .auth-helper-field textarea {
      min-height: 96px;
      resize: vertical;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      font-size: 12px;
    }
    .auth-helper-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    .auth-helper-actions button {
      border: 0;
      border-radius: 8px;
      padding: 10px 14px;
      font: inherit;
      font-weight: 600;
      cursor: pointer;
    }
    #swagger-login-submit {
      background: #2563eb;
      color: #ffffff;
    }
    #swagger-copy-token {
      background: #e5e7eb;
      color: #111827;
    }
    #auth-helper-status {
      font-size: 13px;
      color: var(--swagger-text-muted);
    }
    #auth-helper-status.status-error { color: #b91c1c; }
    #auth-helper-status.status-success { color: #166534; }
    /* ── Custom tag/operation colours ── */
    .swagger-ui .opblock-tag,
    .swagger-ui .opblock-tag.no-desc {
      background: var(--swagger-tag-bg) !important;
      color: var(--swagger-tag-text) !important;
      border-bottom: 1px solid var(--swagger-tag-border);
    }
    .swagger-ui .opblock-tag small,
    .swagger-ui .opblock-tag svg,
    .swagger-ui .opblock-tag .nostyle {
      color: var(--swagger-tag-text) !important;
      fill: var(--swagger-tag-text) !important;
    }
    .swagger-ui .info,
    .swagger-ui .info p,
    .swagger-ui .info li,
    .swagger-ui .info a,
    .swagger-ui .scheme-container .schemes > label,
    .swagger-ui .tab li,
    .swagger-ui .response-col_status,
    .swagger-ui .response-col_description,
    .swagger-ui .parameter__name,
    .swagger-ui .parameter__type,
    .swagger-ui .parameter__deprecated,
    .swagger-ui .opblock-description-wrapper p,
    .swagger-ui .opblock-external-docs-wrapper p,
    .swagger-ui .opblock-title_normal p,
    .swagger-ui .responses-inner h4,
    .swagger-ui .responses-inner h5,
    .swagger-ui .model-title,
    .swagger-ui .model,
    .swagger-ui section.models h4,
    .swagger-ui section.models h5,
    .swagger-ui .prop-type,
    .swagger-ui .prop-format,
    .swagger-ui label,
    .swagger-ui .btn,
    .swagger-ui select,
    .swagger-ui input,
    .swagger-ui textarea {
      color: var(--swagger-text);
    }
    .swagger-ui .scheme-container,
    .swagger-ui .information-container,
    .swagger-ui section.models,
    .swagger-ui .model-box,
    .swagger-ui .responses-inner,
    .swagger-ui .opblock .opblock-section-header {
      background: var(--swagger-surface);
    }
    .swagger-ui .opblock .opblock-summary-description,
    .swagger-ui .markdown p,
    .swagger-ui .markdown code,
    .swagger-ui .renderedMarkdown p {
      color: var(--swagger-text-muted);
    }
    .opblock.opblock-get    .opblock-summary-method { background: #6166f1; }
    .opblock.opblock-post   .opblock-summary-method { background: #38a169; }
    .opblock.opblock-put    .opblock-summary-method { background: #d69e2e; }
    .opblock.opblock-patch  .opblock-summary-method { background: #dd6b20; }
    .opblock.opblock-delete .opblock-summary-method { background: #e53e3e; }
  </style>
</head>
<body>
<div id="auth-notice">
  <strong>Browser / SPA:</strong> <a href="{$loginUrl}" target="_blank" rel="noreferrer">Log in</a> first so the session cookie is sent automatically. <span>|</span>
  <strong>Bearer token:</strong> use the helper below or call <code>POST /api/auth/login</code>, then Swagger will send <code>Authorization: Bearer &lt;token&gt;</code>.
</div>
<section id="auth-helper">
  <div>
    <h2>Authorize secured endpoints</h2>
    <p>Sign in here to create a Laravel Sanctum bearer token and automatically apply it to Swagger requests. This token is not a JWT.</p>
  </div>
  <form id="swagger-login-form" class="auth-helper-form">
    <div class="auth-helper-field">
      <label for="swagger-login-email">Email</label>
      <input id="swagger-login-email" name="email" type="email" autocomplete="username" placeholder="name@example.com" required />
    </div>
    <div class="auth-helper-field">
      <label for="swagger-login-password">Password</label>
      <input id="swagger-login-password" name="password" type="password" autocomplete="current-password" required />
    </div>
    <div class="auth-helper-field">
      <label for="swagger-login-device">Device Name</label>
      <input id="swagger-login-device" name="device_name" type="text" value="swagger-ui" placeholder="swagger-ui" />
    </div>
    <div class="auth-helper-actions">
      <button id="swagger-login-submit" type="submit">Login and authorize</button>
      <button id="swagger-copy-token" type="button">Copy token</button>
    </div>
  </form>
  <div class="auth-helper-field">
    <label for="swagger-token-output">Issued bearer token</label>
    <textarea id="swagger-token-output" readonly placeholder="The token returned by /api/auth/login will appear here."></textarea>
  </div>
  <div id="auth-helper-status">Swagger will keep authorization between refreshes when possible.</div>
</section>
<div id="swagger-ui"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-standalone-preset.js"></script>
<script>
  window.onload = function () {
    var storedTokenKey = 'swagger-sanctum-token';
    var loginEndpoint = "{$apiLoginUrl}";
    var meEndpoint = "{$meUrl}";
    var tokenOutput = document.getElementById('swagger-token-output');
    var statusEl = document.getElementById('auth-helper-status');
    var noticeEl = document.getElementById('auth-notice');

    function setStatus(message, tone) {
      statusEl.textContent = message;
      statusEl.className = tone ? 'status-' + tone : '';
    }

    function setNotice(message, authenticated) {
      noticeEl.innerHTML = message;
      noticeEl.classList.toggle('authenticated', Boolean(authenticated));
    }

    function currentToken() {
      return tokenOutput.value.trim() || window.localStorage.getItem(storedTokenKey) || '';
    }

    function authHeaders() {
      var headers = { 'Accept': 'application/json' };
      var token = currentToken();
      if (token) {
        headers['Authorization'] = 'Bearer ' + token;
      }
      return headers;
    }

    function applyBearerToken(token) {
      if (!token) {
        return;
      }

      tokenOutput.value = token;
      window.localStorage.setItem(storedTokenKey, token);
      ui.preauthorizeApiKey('sanctum', token);
    }

    function updateAuthState() {
      fetch(meEndpoint, {
        method: 'GET',
        credentials: 'same-origin',
        headers: authHeaders()
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Not authenticated yet.');
          }

          return response.json();
        })
        .then(function (user) {
          var name = [user.first_name, user.last_name].filter(Boolean).join(' ').trim();
          var label = name || user.email || 'Authenticated user';
          setNotice('<strong>Authorized:</strong> Swagger can now call secured endpoints as ' + label + '.', true);
          setStatus('Authorization is active for secured endpoints.', 'success');
        })
        .catch(function () {
          setNotice('<strong>Browser / SPA:</strong> <a href="{$loginUrl}" target="_blank" rel="noreferrer">Log in</a> first so the session cookie is sent automatically. <span>|</span> <strong>Bearer token:</strong> use the helper below or call <code>POST /api/auth/login</code>, then Swagger will send <code>Authorization: Bearer &lt;token&gt;</code>.', false);
        });
    }

    const ui = SwaggerUIBundle({
      url: "{$specUrl}",
      dom_id: '#swagger-ui',
      deepLinking: true,
      withCredentials: true,
      presets: [
        SwaggerUIBundle.presets.apis,
        SwaggerUIStandalonePreset
      ],
      plugins: [
        SwaggerUIBundle.plugins.DownloadUrl
      ],
      layout: "StandaloneLayout",
      persistAuthorization: true,
      displayRequestDuration: true,
      filter: true,
      tryItOutEnabled: true,
      requestInterceptor: function(request) {
        // Inject CSRF token for all state-mutating requests (cookie-session auth)
        const token = document.cookie
          .split('; ')
          .find(row => row.startsWith('XSRF-TOKEN='));
        if (token) {
          request.headers['X-XSRF-TOKEN'] = decodeURIComponent(token.split('=')[1]);
        }
        return request;
      }
    });
    window.ui = ui;

    var storedToken = window.localStorage.getItem(storedTokenKey);
    if (storedToken) {
      applyBearerToken(storedToken);
    }

    document.getElementById('swagger-login-form').addEventListener('submit', function (event) {
      event.preventDefault();
      setStatus('Requesting bearer token...', '');

      var form = event.currentTarget;
      var email = form.elements.email.value.trim();
      var password = form.elements.password.value;
      var deviceName = form.elements.device_name.value.trim();

      fetch(loginEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          email: email,
          password: password,
          device_name: deviceName || 'swagger-ui'
        })
      })
        .then(async function (response) {
          var data = await response.json().catch(function () {
            return {};
          });

          if (!response.ok) {
            throw new Error(data.message || 'Login failed.');
          }

          if (!data.token) {
            throw new Error('Login succeeded, but no bearer token was returned.');
          }

          applyBearerToken(data.token);
          setStatus('Bearer token applied. You can now try secured endpoints.', 'success');
          updateAuthState();
        })
        .catch(function (error) {
          setStatus(error.message, 'error');
        });
    });

    document.getElementById('swagger-copy-token').addEventListener('click', function () {
      var token = currentToken();
      if (!token) {
        setStatus('No bearer token is available to copy yet.', 'error');
        return;
      }

      navigator.clipboard.writeText(token)
        .then(function () {
          setStatus('Bearer token copied to the clipboard.', 'success');
        })
        .catch(function () {
          setStatus('Bearer token is visible above, but the browser blocked clipboard access.', 'error');
        });
    });

    updateAuthState();
  };
</script>
</body>
</html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Return the live OpenAPI JSON specification.
     * Re-generated on every request — use the artisan command for a cached file.
     */
    public function spec(): JsonResponse
    {
        $generator = new OpenApiGenerator;
        $spec = $generator->generate();

        return response()->json($spec);
    }
}
