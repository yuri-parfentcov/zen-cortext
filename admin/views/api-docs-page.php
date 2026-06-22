<?php
if (!defined("ABSPATH")) { exit; }
/**
 * Zen Cortext — API › Documentation tab.
 * Static reference for wp-json/zc/v1/*. Read-only by design:
 * anything that could drift from the running code (scope catalog,
 * base URL) is pulled live. Included from views/api-page.php; the
 * wrap div + h1 + nav-tab-wrapper are emitted by that wrapper.
 */
if (!defined('ABSPATH')) exit;

$base_url      = esc_url(rest_url('zc/v1'));
$keys_admin    = esc_url(admin_url('admin.php?page=zen-cortext-api-keys&tab=keys'));
$scopes        = class_exists('Zen_Cortext_Api_Keys') ? Zen_Cortext_Api_Keys::scope_catalog() : array();
$token_example = 'zcpa_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
?>
<div class="zcdocs">
<div class="zcdocs-layout">

    <!-- Sticky TOC -->
    <nav class="zcdocs-toc" aria-label="Table of contents">
        <strong><?php esc_html_e('Contents', 'zen-cortext'); ?></strong>
        <ul>
            <li><a href="#overview"><?php esc_html_e('Overview', 'zen-cortext'); ?></a></li>
            <li><a href="#auth"><?php esc_html_e('Authentication', 'zen-cortext'); ?></a></li>
            <li><a href="#scopes"><?php esc_html_e('Scopes', 'zen-cortext'); ?></a></li>
            <li><a href="#rate-limit"><?php esc_html_e('Rate limits', 'zen-cortext'); ?></a></li>
            <li><a href="#errors"><?php esc_html_e('Errors', 'zen-cortext'); ?></a></li>
            <li><a href="#pagination"><?php esc_html_e('Pagination', 'zen-cortext'); ?></a></li>
            <li><a href="#endpoints"><?php esc_html_e('Endpoints', 'zen-cortext'); ?></a>
                <ul>
                    <li><a href="#ep-chats">GET /chats</a></li>
                    <li><a href="#ep-chat-detail">GET /chats/{id}</a></li>
                    <li><a href="#ep-stats">GET /chats/stats</a></li>
                    <li><a href="#ep-leads">GET /leads</a></li>
                    <li><a href="#ep-attribution">GET /attribution-rules</a></li>
                    <li><a href="#ep-knowledge">GET /knowledge</a></li>
                    <li><a href="#ep-sessions">GET /sessions</a></li>
                    <li><a href="#ep-session-detail">GET /sessions/{id}</a></li>
                </ul>
            </li>
            <li><a href="#filters"><?php esc_html_e('Chat filters', 'zen-cortext'); ?></a></li>
            <li><a href="#session-filters"><?php esc_html_e('Session filters', 'zen-cortext'); ?></a></li>
            <li><a href="#outcomes"><?php esc_html_e('Outcomes', 'zen-cortext'); ?></a></li>
            <li><a href="#shapes"><?php esc_html_e('Data shapes', 'zen-cortext'); ?></a></li>
        </ul>
    </nav>

    <main class="zcdocs-content">

        <!-- Overview -->
        <section id="overview">
            <h2><?php esc_html_e('Overview', 'zen-cortext'); ?></h2>
            <p><?php esc_html_e('A read-only JSON REST API for external integrations (CRM, BI, audit tooling). All endpoints are GET. Bearer-token auth with scoped per-integration keys. Cursor-based pagination on list endpoints. Outcomes are derived at query time from the chat lifecycle — no schema-of-truth column to keep in sync.', 'zen-cortext'); ?></p>
            <table class="zcdocs-meta">
                <tr><th><?php esc_html_e('Base URL', 'zen-cortext'); ?></th><td><code><?php echo esc_html($base_url); ?></code></td></tr>
                <tr><th><?php esc_html_e('Auth', 'zen-cortext'); ?></th><td><code>Authorization: Bearer &lt;token&gt;</code> — manage tokens on the <a href="<?php echo esc_url($keys_admin); ?>"><?php esc_html_e('API Keys', 'zen-cortext'); ?></a> page.</td></tr>
                <tr><th><?php esc_html_e('Format', 'zen-cortext'); ?></th><td><?php esc_html_e('JSON request/response (application/json). All timestamps ISO 8601 UTC.', 'zen-cortext'); ?></td></tr>
                <tr><th><?php esc_html_e('Versioning', 'zen-cortext'); ?></th><td><?php esc_html_e('Namespace v1. Breaking changes will ship as zc/v2 alongside v1.', 'zen-cortext'); ?></td></tr>
            </table>

            <h3><?php esc_html_e('Quick start', 'zen-cortext'); ?></h3>
            <p>
                <?php
                printf(
                    /* translators: %s = link to API Keys admin page */
                    esc_html__('1. Generate a key on %s with the scopes you need (typically the minimum set).', 'zen-cortext'),
                    '<a href="' . esc_url($keys_admin) . '">' . esc_html__('API Keys', 'zen-cortext') . '</a>'
                );
                ?>
                <br>
                <?php esc_html_e('2. Copy the raw token from the one-time panel (the server only stores a hash; you can\'t see it again).', 'zen-cortext'); ?>
                <br>
                <?php esc_html_e('3. Call any endpoint with that token in the Authorization header. Example:', 'zen-cortext'); ?>
            </p>
<pre><code>curl -H "Authorization: Bearer <?php echo esc_html($token_example); ?>" \
  '<?php echo esc_html($base_url); ?>/chats?limit=10&outcome=qualified'</code></pre>
        </section>

        <!-- Auth -->
        <section id="auth">
            <h2><?php esc_html_e('Authentication', 'zen-cortext'); ?></h2>
            <p><?php esc_html_e('Every request must carry a Bearer token in the Authorization header. Tokens start with zcpa_ (Zen Cortext Public API) and are 53 characters long.', 'zen-cortext'); ?></p>
<pre><code>Authorization: Bearer <?php echo esc_html($token_example); ?></code></pre>
            <ul>
                <li><strong><?php esc_html_e('No header', 'zen-cortext'); ?></strong> → <code>401 zc_unauthorized</code></li>
                <li><strong><?php esc_html_e('Unknown / malformed token', 'zen-cortext'); ?></strong> → <code>401 zc_unauthorized</code></li>
                <li><strong><?php esc_html_e('Revoked token', 'zen-cortext'); ?></strong> → <code>401 zc_unauthorized</code> (revocation is instant; revoked rows are kept for audit but rejected by the auth layer)</li>
                <li><strong><?php esc_html_e('Missing required scope', 'zen-cortext'); ?></strong> → <code>403 zc_forbidden_scope</code></li>
            </ul>
        </section>

        <!-- Scopes -->
        <section id="scopes">
            <h2><?php esc_html_e('Scopes', 'zen-cortext'); ?></h2>
            <p><?php esc_html_e('Each key carries a list of scopes. Each endpoint declares the scope it requires (the badge on every endpoint heading below). Calling an endpoint without the matching scope returns 403 — even if the key is otherwise valid.', 'zen-cortext'); ?></p>
            <table class="zcdocs-table">
                <thead><tr><th><?php esc_html_e('Scope', 'zen-cortext'); ?></th><th><?php esc_html_e('Endpoints', 'zen-cortext'); ?></th><th><?php esc_html_e('Description', 'zen-cortext'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($scopes as $slug => $info): ?>
                    <tr>
                        <td><code><?php echo esc_html($slug); ?></code></td>
                        <td>
                            <?php
                            $eps = array(
                                'read:chats'       => 'GET /chats, /chats/{id}',
                                'read:leads'       => 'GET /leads',
                                'read:stats'       => 'GET /chats/stats',
                                'read:attribution' => 'GET /attribution-rules',
                                'read:knowledge'   => 'GET /knowledge',
                                'read:sessions'    => 'GET /sessions, /sessions/{id}',
                            );
                            echo isset($eps[$slug]) ? '<code>' . esc_html($eps[$slug]) . '</code>' : '&mdash;';
                            ?>
                        </td>
                        <td><?php echo esc_html((string) ($info['description'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <!-- Rate limit -->
        <section id="rate-limit">
            <h2><?php esc_html_e('Rate limits', 'zen-cortext'); ?></h2>
            <p><?php esc_html_e('Per-key sliding-window rate limit with two buckets: per minute and per hour. Defaults: 60/min and 3000/hour. Configurable per key on the API Keys page. Exceeding either bucket returns:', 'zen-cortext'); ?></p>
<pre><code>HTTP/1.1 429 Too Many Requests

{
  "code": "zc_rate_limited",
  "message": "Rate limit exceeded.",
  "data": { "status": 429, "retry_after": 60 }
}</code></pre>
            <p><?php esc_html_e('retry_after is the number of seconds the caller should wait before retrying. Use exponential backoff if your integration loops.', 'zen-cortext'); ?></p>
        </section>

        <!-- Errors -->
        <section id="errors">
            <h2><?php esc_html_e('Errors', 'zen-cortext'); ?></h2>
            <p><?php esc_html_e('All errors use WordPress\'s standard WP_Error JSON envelope:', 'zen-cortext'); ?></p>
<pre><code>{
  "code":    "zc_forbidden_scope",
  "message": "This key lacks the required scope: read:leads",
  "data":    { "status": 403 }
}</code></pre>
            <table class="zcdocs-table">
                <thead><tr><th>Status</th><th>Code</th><th>When</th></tr></thead>
                <tbody>
                    <tr><td><code>400</code></td><td><code>zc_bad_outcome</code></td><td>Filter <code>outcome</code> is not one of the supported values (active, qualified, handoff, resolved, abandoned). The value <code>disqualified</code> is explicitly rejected with a v1-not-implemented message.</td></tr>
                    <tr><td><code>401</code></td><td><code>zc_unauthorized</code></td><td>Missing/malformed header, unknown token, or revoked token.</td></tr>
                    <tr><td><code>403</code></td><td><code>zc_forbidden_scope</code></td><td>Token valid but lacks the scope this endpoint requires.</td></tr>
                    <tr><td><code>404</code></td><td><code>zc_not_found</code></td><td><code>/chats/{id}</code> with an id/uid that doesn't exist.</td></tr>
                    <tr><td><code>429</code></td><td><code>zc_rate_limited</code></td><td>Per-key rate limit exceeded. See <code>data.retry_after</code>.</td></tr>
                </tbody>
            </table>
        </section>

        <!-- Pagination -->
        <section id="pagination">
            <h2><?php esc_html_e('Cursor pagination', 'zen-cortext'); ?></h2>
            <p><?php esc_html_e('List endpoints (/chats, /leads, /knowledge, /sessions) return results in pages. The envelope:', 'zen-cortext'); ?></p>
<pre><code>{
  "data": [ /* up to `limit` items, newest first */ ],
  "meta": {
    "next_cursor": "eyJ1IjoxNzc4NzQxMTA1LCJpIjoyOTAyfQ",
    "has_more":    true,
    "limit":       50
  }
}</code></pre>
            <p><?php esc_html_e('To fetch the next page, pass the returned cursor unchanged as ?cursor=…. Pagination is keyset on (updated_at, id) so concurrent inserts can\'t cause duplicates or skips — except /sessions, which paginates on (last_seen_at, id) since that\'s the sort dimension for visitor sessions. Stop paginating when has_more is false (next_cursor will be null).', 'zen-cortext'); ?></p>
            <p><strong>limit</strong> — 1..200, default 50.</p>
<pre><code>cursor=null
while True:
    r = GET /chats?limit=50&cursor={cursor}
    process(r["data"])
    if not r["meta"]["has_more"]:
        break
    cursor = r["meta"]["next_cursor"]</code></pre>
        </section>

        <!-- Endpoints -->
        <section id="endpoints">
            <h2><?php esc_html_e('Endpoints', 'zen-cortext'); ?></h2>

            <article id="ep-chats" class="zcdocs-endpoint">
                <h3>GET <code>/chats</code> <span class="zcdocs-scope">read:chats</span></h3>
                <p><?php esc_html_e('Paginated list of chats, newest first. Returns the chat shape WITHOUT the messages array — fetch /chats/{id} for the transcript.', 'zen-cortext'); ?></p>
                <p><strong>Query params:</strong> see <a href="#filters"><?php esc_html_e('Chat filters', 'zen-cortext'); ?></a> + <code>cursor</code> + <code>limit</code>.</p>
                <p><strong>Example:</strong></p>
<pre><code>curl -H "Authorization: Bearer $TOKEN" \
  '<?php echo esc_html($base_url); ?>/chats?utm_source=google&outcome=qualified&limit=20'</code></pre>
            </article>

            <article id="ep-chat-detail" class="zcdocs-endpoint">
                <h3>GET <code>/chats/{id}</code> <span class="zcdocs-scope">read:chats</span></h3>
                <p><?php esc_html_e('Single chat with the full messages transcript. {id} can be the numeric primary key OR the chat_uid (alphanumeric-with-dashes string).', 'zen-cortext'); ?></p>
                <p><strong>Returns:</strong> <?php esc_html_e('Same shape as the list rows, plus a top-level messages array of {role, content, …} objects.', 'zen-cortext'); ?></p>
<pre><code>curl -H "Authorization: Bearer $TOKEN" \
  '<?php echo esc_html($base_url); ?>/chats/5qaaotqji61zluhvjbrpmzdv'</code></pre>
                <p><?php esc_html_e('404 zc_not_found when neither the id nor the chat_uid match.', 'zen-cortext'); ?></p>
            </article>

            <article id="ep-stats" class="zcdocs-endpoint">
                <h3>GET <code>/chats/stats</code> <span class="zcdocs-scope">read:stats</span></h3>
                <p><?php esc_html_e('Aggregated counts grouped by outcome, by utm_source, and by day. Default window is the last 30 days; pass from_date/to_date to override.', 'zen-cortext'); ?></p>
                <p><strong>Query params:</strong> <code>from_date</code>, <code>to_date</code> (ISO 8601 / parseable date).</p>
<pre><code>curl -H "Authorization: Bearer $TOKEN" \
  '<?php echo esc_html($base_url); ?>/chats/stats?from_date=2026-04-01&to_date=2026-04-30'</code></pre>
                <p><strong>Returns:</strong></p>
<pre><code>{
  "window": { "from": "2026-04-01", "to": "2026-04-30" },
  "totals": { "active": 4, "qualified": 53, "handoff": 8, "resolved": 21, "abandoned": 17 },
  "by_utm_source": [
    { "utm_source": "google", "total": 60, "qualified": 18,
      "handoff": 4, "resolved": 12, "abandoned": 8 }
  ],
  "by_day": [
    { "date": "2026-04-13", "total": 12, "qualified": 4 }
  ]
}</code></pre>
            </article>

            <article id="ep-leads" class="zcdocs-endpoint">
                <h3>GET <code>/leads</code> <span class="zcdocs-scope">read:leads</span></h3>
                <p><?php esc_html_e('Chats where the visitor submitted the contact form (lead_submitted_at IS NOT NULL). Same shape as /chats/{id} including the full messages transcript so downstream systems can parse Q&A themselves (no structured answers extraction in v1). Accepts the same filters as /chats.', 'zen-cortext'); ?></p>
<pre><code>curl -H "Authorization: Bearer $TOKEN" \
  '<?php echo esc_html($base_url); ?>/leads?from_date=2026-04-01&limit=50'</code></pre>
            </article>

            <article id="ep-attribution" class="zcdocs-endpoint">
                <h3>GET <code>/attribution-rules</code> <span class="zcdocs-scope">read:attribution</span></h3>
                <p><?php esc_html_e('Enabled attribution rules (campaign-rule configuration). Returns match conditions, priority, attached survey_id, and flags for whether the rule has custom context_text / intro_card / chips (counts only, no body content).', 'zen-cortext'); ?></p>
<pre><code>curl -H "Authorization: Bearer $TOKEN" \
  '<?php echo esc_html($base_url); ?>/attribution-rules'</code></pre>
            </article>

            <article id="ep-knowledge" class="zcdocs-endpoint">
                <h3>GET <code>/knowledge</code> <span class="zcdocs-scope">read:knowledge</span></h3>
                <p><?php esc_html_e('Knowledge-base item metadata for audit (title, classification, source URL, last_updated). Raw content and structured-form bodies are not exposed in v1.', 'zen-cortext'); ?></p>
                <p><strong>Query params:</strong> <code>cursor</code>, <code>limit</code>.</p>
<pre><code>curl -H "Authorization: Bearer $TOKEN" \
  '<?php echo esc_html($base_url); ?>/knowledge?limit=100'</code></pre>
                <p><strong>classification values:</strong> <code>case_study</code> · <code>technical_article</code> · <code>marketing</code> · <code>faq</code> · <code>other</code> · <code>null</code> (not yet classified)</p>
            </article>

            <article id="ep-sessions" class="zcdocs-endpoint">
                <h3>GET <code>/sessions</code> <span class="zcdocs-scope">read:sessions</span></h3>
                <p><?php esc_html_e('Paginated list of visitor sessions, newest first (ordered by last_seen_at). A session is one browser visit, GA-style: a new session is minted on arrival when the visitor has no active session OR when their attribution changes OR when last_seen_at is older than 30 minutes. Chats started during a session get stamped with its session_uid (see the session field on the Chat shape).', 'zen-cortext'); ?></p>
                <p><strong>Query params:</strong> see <a href="#session-filters"><?php esc_html_e('Session filters', 'zen-cortext'); ?></a> + <code>cursor</code> + <code>limit</code>.</p>
                <p><strong>Example:</strong></p>
<pre><code>curl -H "Authorization: Bearer $TOKEN" \
  '<?php echo esc_html($base_url); ?>/sessions?enriched=1&has_chats=1&limit=20'</code></pre>
            </article>

            <article id="ep-session-detail" class="zcdocs-endpoint">
                <h3>GET <code>/sessions/{id}</code> <span class="zcdocs-scope">read:sessions</span></h3>
                <p><?php esc_html_e('Single session with the compact list of every chat attached to it. {id} can be the numeric primary key OR the session_uid (alphanumeric-with-dashes string).', 'zen-cortext'); ?></p>
                <p><strong>Returns:</strong> <?php esc_html_e('Same shape as the list rows, plus a top-level chats array of compact chat summaries (id, chat_uid, message_count, lead flags, timestamps). Transcripts are not inlined — call /chats/{id} for the messages array.', 'zen-cortext'); ?></p>
<pre><code>curl -H "Authorization: Bearer $TOKEN" \
  '<?php echo esc_html($base_url); ?>/sessions/SmfaN8Eohqv0HvQS2vW0XNEgioNeIbMX'</code></pre>
                <p><?php esc_html_e('404 zc_not_found when neither the id nor the session_uid match.', 'zen-cortext'); ?></p>
            </article>
        </section>

        <!-- Filters -->
        <section id="filters">
            <h2><?php esc_html_e('Chat filters', 'zen-cortext'); ?></h2>
            <p><?php esc_html_e('All filters are optional query params on /chats and /leads. Combine freely — they AND together. Unknown filters are ignored.', 'zen-cortext'); ?></p>
            <table class="zcdocs-table">
                <thead><tr><th>Param</th><th>Type</th><th>Behavior</th></tr></thead>
                <tbody>
                    <tr><td><code>from_date</code>, <code>to_date</code></td><td>ISO 8601 / date string</td><td><?php esc_html_e('Filter on chat created_at. Inclusive on both ends.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>utm_source</code>, <code>utm_medium</code>, <code>utm_campaign</code></td><td>string</td><td><?php esc_html_e('Exact match. Trailing * does prefix-match (LIKE \'foo%\'). Example: utm_source=google or utm_source=foreman-*.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>gclid</code>, <code>fbc</code></td><td>string | <code>present</code> | <code>absent</code></td><td><?php esc_html_e('Specific click-id value, or the sentinel "present" / "absent" to filter on its presence.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>outcome</code></td><td>enum</td><td><?php esc_html_e('See Outcomes below. Allowed: active, qualified, handoff, resolved, abandoned.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>has_email</code>, <code>has_phone</code></td><td>bool</td><td><?php esc_html_e('lead_email / lead_whatsapp non-empty. Accepts 1/true/yes.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>min_messages</code>, <code>max_messages</code></td><td>int</td><td><?php esc_html_e('Bounds on message_count.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>cursor</code></td><td>opaque string</td><td><?php esc_html_e('Returned by a previous page. Do not construct manually.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>limit</code></td><td>int 1..200</td><td><?php esc_html_e('Default 50.', 'zen-cortext'); ?></td></tr>
                </tbody>
            </table>
        </section>

        <!-- Session filters -->
        <section id="session-filters">
            <h2><?php esc_html_e('Session filters', 'zen-cortext'); ?></h2>
            <p><?php esc_html_e('All filters are optional query params on /sessions. Combine freely — they AND together. Unknown filters are ignored.', 'zen-cortext'); ?></p>
            <table class="zcdocs-table">
                <thead><tr><th>Param</th><th>Type</th><th>Behavior</th></tr></thead>
                <tbody>
                    <tr><td><code>from_date</code>, <code>to_date</code></td><td>ISO 8601 / date string</td><td><?php esc_html_e('Filter on last_seen_at (not created_at — sessions are about "when did we last see this visitor"). Inclusive on both ends.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>utm_source</code>, <code>utm_medium</code>, <code>utm_campaign</code></td><td>string</td><td><?php esc_html_e('Exact match. Trailing * does prefix-match (LIKE \'foo%\'). Example: utm_campaign=spring-*.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>gclid</code>, <code>msclkid</code></td><td>string | <code>present</code> | <code>absent</code></td><td><?php esc_html_e('Specific click-id value, or the sentinel "present" / "absent" to filter on its presence.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>enriched</code></td><td>bool</td><td><?php esc_html_e('1/true = sessions that carry any UTM / click-id / referrer signal. 0/false = direct visits.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>has_chats</code></td><td>bool</td><td><?php esc_html_e('1/true = sessions where the visitor opened the chat (chat_count > 0). 0/false = arrival-only sessions.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>rule_id</code></td><td>int</td><td><?php esc_html_e('Filter to sessions matched by a specific attribution rule (see /attribution-rules for the id list).', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>cursor</code></td><td>opaque string</td><td><?php esc_html_e('Returned by a previous page. Do not construct manually.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>limit</code></td><td>int 1..200</td><td><?php esc_html_e('Default 50.', 'zen-cortext'); ?></td></tr>
                </tbody>
            </table>
        </section>

        <!-- Outcomes -->
        <section id="outcomes">
            <h2><?php esc_html_e('Outcomes', 'zen-cortext'); ?></h2>
            <p><?php esc_html_e('Outcomes are derived at query time from the chat lifecycle — no schema column to keep in sync. The same logic powers the outcome field in the response and the /chats/stats SUM(CASE) aggregates. Evaluated top-to-bottom; the first matching row wins.', 'zen-cortext'); ?></p>
            <table class="zcdocs-table">
                <thead><tr><th>Outcome</th><th>Condition</th><th>Meaning</th></tr></thead>
                <tbody>
                    <tr><td><code>abandoned</code></td><td><code>deleted_at IS NOT NULL</code> OR (no lead, no admin, idle &gt; 7d)</td><td><?php esc_html_e('Visitor deleted, or aged-out idle conversation.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>resolved</code></td><td><code>admin_detached_at IS NOT NULL AND lead_submitted_at IS NOT NULL</code></td><td><?php esc_html_e('Admin took over, captured the lead, then released the chat.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>handoff</code></td><td><code>admin_attached_at IS NOT NULL</code></td><td><?php esc_html_e('Admin currently attached (or attached at some point). Live conversation.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>qualified</code></td><td><code>lead_submitted_at IS NOT NULL</code></td><td><?php esc_html_e('Visitor submitted the contact form. Admin never attached.', 'zen-cortext'); ?></td></tr>
                    <tr><td><code>active</code></td><td><?php esc_html_e('default', 'zen-cortext'); ?></td><td><?php esc_html_e('Ongoing AI conversation, no lead yet, no admin yet, not idle.', 'zen-cortext'); ?></td></tr>
                </tbody>
            </table>
            <p class="zcdocs-note">
                <?php esc_html_e('Note: disqualified is not implemented in v1. The chat data model has no signal for it today (would need either a new column or a survey-driven flag). Filtering on it returns 400.', 'zen-cortext'); ?>
            </p>
        </section>

        <!-- Data shapes -->
        <section id="shapes">
            <h2><?php esc_html_e('Data shapes', 'zen-cortext'); ?></h2>

            <h3>Chat</h3>
<pre><code>{
  "id": 2905,
  "chat_uid": "5qaaotqji61zluhvjbrpmzdv",
  "outcome": "qualified",
  "message_count": 2,
  "created_at": "2026-05-14T08:19:32+00:00",
  "updated_at": "2026-05-14T08:23:00+00:00",
  "attribution": {
    "utm_source": "google", "utm_medium": "cpc", "utm_campaign": "...",
    "utm_term": "...", "utm_content": "...",
    "gclid": "...", "msclkid": "...", "fbc": "...", "fbp": "...",
    "referrer": "...", "landing_page": "..."
  },
  "lead":    { "name": "...", "email": "...", "whatsapp": "...",
               "submitted_at": "2026-05-13T06:13:15+00:00" }  | null,
  "handoff": { "admin_user_id": 1,
               "attached_at": "...", "detached_at": "..." }    | null,
  "survey":  { "id": 45, "label": "Pre-qual SaaS" }            | null,
  "session": { "session_uid": "SmfaN8Eohqv0...", "first_seen_at": "...",
               "last_seen_at": "...", "enriched": true, "chat_count": 1,
               "rule_id": null, "utm_source": "google", "utm_campaign": "..." ,
               "...": "..." }                                  | null,
  "messages": [ ... ]   // only on /chats/{id} and /leads
}</code></pre>
            <p><?php esc_html_e('Empty attribution becomes {} rather than [] so JSON consumers can treat it as an object unconditionally. Only non-empty fields are included. session is null when the chat predates the visitor-sessions layer or its parent session row was hard-deleted; otherwise it carries the full compact session block (every field present, empties as "").', 'zen-cortext'); ?></p>

            <h3>Attribution rule</h3>
<pre><code>{
  "id": 7, "label": "Foreman Pro Q2",
  "enabled": true, "priority": 0,
  "match": {
    "utm_source": "google", "utm_medium": "cpc", "utm_campaign": "...",
    "referrer_host": "...", "gclid_present": false
  },
  "survey_id": 45,
  "has_context_text": true, "has_intro_card": true, "chips_count": 3,
  "created_at": "...", "updated_at": "..."
}</code></pre>
            <p><?php esc_html_e('Bodies (context_text, intro_card_json, chips_json) are not exposed in v1 — those are admin-curated content surfaces. The flags + counts are sufficient for audit and for routing decisions in downstream systems.', 'zen-cortext'); ?></p>

            <h3>Knowledge item</h3>
<pre><code>{
  "id": 72, "post_id": 1563, "post_type": "post",
  "title": "...",
  "classification": "case_study",  // or technical_article | marketing | faq | other | null
  "source_url": "https://.../permalink",
  "is_classified": true,
  "is_structured": true,
  "last_updated": "2026-04-10T14:20:10+00:00"
}</code></pre>

            <h3>Session (list row)</h3>
<pre><code>{
  "id": 12,
  "session_uid": "SmfaN8Eohqv0HvQS2vW0XNEgioNeIbMX",
  "enriched": true,
  "chat_count": 1,
  "rule_id": 7,                              // matched attribution rule, or null
  "first_seen_at": "2026-05-18T06:45:40+00:00",
  "last_seen_at":  "2026-05-18T06:46:24+00:00",
  "attribution": {
    "utm_source": "google", "utm_campaign": "...",
    "gclid": "...", "referrer": "...", "landing_page": "..."
    // only non-empty fields included; {} when nothing captured
  }
}</code></pre>
            <p><?php esc_html_e('On /sessions/{id} the same shape is returned with an extra top-level chats array of compact chat summaries (no transcripts):', 'zen-cortext'); ?></p>
<pre><code>"chats": [
  {
    "id": 2908, "chat_uid": "...",
    "message_count": 2,
    "lead_submitted": false, "lead_name": "", "lead_email": "",
    "admin_user_id": null,
    "created_at": "...", "updated_at": "...", "deleted_at": null
  }
]</code></pre>
            <p><?php esc_html_e('Pull /chats/{id} for the full messages array of any attached chat.', 'zen-cortext'); ?></p>
        </section>
    </main>
</div>
</div>

