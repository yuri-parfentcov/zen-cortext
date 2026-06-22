# Zen Cortext — WordPress.org Pre-Submission Review

Plugin `2.38.0` · 11 custom tables · BYO Anthropic key. Reviewed against the six
WordPress.org reviewer concerns plus a full security/compliance sweep.

## Reviewer's six concerns — all hold ✅

| # | Concern | Verdict |
|---|---------|---------|
| 1 | `proc_open` CLI processor | **Opt-in & safe — now constant-gated (see resolution below).** Visitor chat (`stream_chat()`) is hardcoded to the API path and never calls `processor()`. The CLI is unreachable in the distributed default: every `proc_open()` path is gated behind `Zen_Cortext_API::cli_enabled()`, which is true only when `ZEN_CORTEXT_ENABLE_CLI` is defined in wp-config.php. File-level justification at `class-zen-cortext-api.php:10–28`. |
| 2 | Raw cURL vs `wp_remote_*` | **Justified.** SSE streaming genuinely needs `CURLOPT_WRITEFUNCTION`; non-streaming calls (`test_connection`, `request_json`) correctly use `wp_remote_post`. |
| 3 | `$wpdb` table interpolation | **Clean.** 21 data-layer files carry file-level `phpcs:disable` + justification. Every user *value* is `prepare()`'d / format-spec'd / int-cast. No user-controlled ORDER BY / LIMIT / column. IN-clauses expanded safely. 11-table count exact (schema ↔ `uninstall.php` agree). |
| 4 | AJAX nonce via `check_request()` | **All 37 `wp_ajax_*` handlers gated** at statement #1. Helper runs `current_user_can('manage_options')` **and** `check_ajax_referer`, hard-dies on failure. **Zero `wp_ajax_nopriv_*`.** Public REST mutations each carry a secondary in-handler credential (owner-token / magic-link / rate-limit). |
| 5 | Activation redirect | **Guarded.** Transient one-shot, bails on ajax/cron/network-admin/non-`manage_options`/bulk-activation. |
| 6 | Unminified bundled assets | **Confirmed.** No `.min.*`, no build config, no vendored libs, normal line lengths. |

## Additional issues found & dispositioned

| ID | Issue | Status |
|----|-------|--------|
| **A** | `Stable tag` (2.35.0) ≠ plugin `Version` — a hard wp.org release blocker | **FIXED** → readme Stable tag, changelog, Upgrade Notice all aligned to plugin version (`2.38.0`) |
| **B** | Shortcode embed loaded Yanone from Google Fonts on every page, undisclosed (visitor-IP-to-Google / GDPR flag) | **FIXED** → load now gated on the Design → Base font setting, mirroring the standalone templates. Default install makes **zero** external font request. |
| **C** | `Tested up to: 7.0` looked like a non-existent version | **VALID** — verified live install is WordPress `7.0`. No change. |
| **D** | `processor()` `'cli'` fallback reads as "CLI default" in isolation | **No change** — deliberate design (internal prefers CLI when enabled; seed keeps fresh installs on API). Observation only. |

## Residual risks — resolved

- **`proc_open` shell-out — RESOLVED via constant gate.** The CLI processor is
  now inert in the distributed product. `Zen_Cortext_API::cli_enabled()` returns
  true only when the site owner defines `ZEN_CORTEXT_ENABLE_CLI` in wp-config.php
  (a server-operator action, unreachable from the WP admin UI). When the constant
  is absent:
  - `processor()` returns `'api'` regardless of the saved option;
  - the Settings → Connection CLI radio and the entire "Claude Code CLI" section
    are hidden, replaced by a note pointing at the constant;
  - `test_connection()` refuses CLI probes;
  - all three functions that call `proc_open()` (`cli_ping`, `cli_request`,
    `stream_chat_via_cli`) hard-return at entry on `!cli_enabled()`.

  So no `proc_open()` call is reachable on a default install. Documented in the
  readme changelog + a dedicated FAQ entry. CLI-first-for-internal behaviour is
  fully preserved once the constant is defined (the author's own deployment).

## Style nit — RESOLVED

- The four privileged visitor-write routes (`/chat/{uid}/delete`, `/lead`,
  `/email`, `/invite`) now declare their owner-token gate in
  `permission_callback` at route registration via a shared
  `owner_token_permission_cb($message)` factory, instead of `__return_true` +
  an in-handler check. The redundant in-handler checks were removed (the
  router runs `permission_callback` before the handler). The remaining
  `__return_true` routes are reads (`/chat/{uid}` replay, `/status`, `/poll`)
  or intentionally-public endpoints that can't be owner-gated — notably
  `/send`, which must create the chat row and persist the owner token on the
  first message (legitimate create-on-demand), plus the magic-link
  login/verify and voice/beacon endpoints, each with their own in-handler
  protection (rate limit / feature toggle).

## Files changed during review

- `readme.txt` — Stable tag 2.38.0 + changelog/upgrade notice (A); CLI-constant changelog line + FAQ (proc_open gate); permission_callback changelog line (style nit)
- `zen-cortext.php` — plugin Version + `ZEN_CORTEXT_VERSION` bumped to 2.38.0
- `includes/class-zen-cortext-shortcode.php` — Yanone load gated on Design font setting (B)
- `includes/class-zen-cortext-api.php` — `cli_enabled()` gate on `processor()`, `test_connection()`, and all three `proc_open()` callers; file-header justification updated (proc_open gate)
- `admin/views/settings-page.php` — CLI radio + CLI settings section hidden unless `ZEN_CORTEXT_ENABLE_CLI` is defined (proc_open gate)
- `includes/class-zen-cortext-rest.php` — owner-token gate moved into `permission_callback` (`owner_token_permission_cb`) for the 4 visitor-write routes; redundant in-handler checks removed (style nit)

## Bottom line

With A and B fixed, no remaining hard blockers. The data layer, AJAX surface,
and streaming justifications are solid and well-documented. The only open item
is a product decision on whether to keep `proc_open` in the directory build.
