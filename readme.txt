=== Zen Cortext - Your AI SDR for inbound ===
Contributors: infozenrepublices
Tags: chatbot, ai chat, lead generation, knowledge base, customer support
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.39.19
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI SDR that reads your site, talks to your visitors, and knows when to call you.

== Description ==

Zen Cortext is an AI sales development representative (SDR) for inbound traffic on your WordPress site. It reads your published pages, posts, FAQs, and case studies into a knowledge base, talks to your visitors in a streaming chat that cites your actual work, and knows when to hand the conversation to a human on your team. Conversations stay in your own WordPress database. You bring your own AI provider key, so there is no SaaS subscription on top.

= What you get =

For your visitors:

* A streaming AI chat that types responses live, embeddable on any page via the `[zen_cortext]` shortcode or on a dedicated full-page template.
* A floating chat button that follows visitors across the site (optional, configurable).
* A speaker intro card, welcome message, and four configurable starter question chips.
* Inline follow-up suggestions the AI offers mid-conversation as clickable chips.
* Optional interview / survey scripts the AI weaves into the conversation — qualifying questions, intake forms, custom discovery flows.
* An inline contact-capture form the AI surfaces when the visitor is ready to talk or when it doesn't know an answer.
* A "talk to a human" handover — the AI can invite specific team members into the chat in real time via Web Push. Offline? It falls back to the contact form automatically.
* Optional voice input on mobile via Groq or OpenAI Whisper.
* Graceful fallback when the AI provider is unavailable — visitors see a polite contact-form message; your team gets an email with the failure details.

For you (the admin):

* Knowledge Base — one-click indexer that pulls in your pages, posts, FAQs, portfolio items; auto-re-syncs when you publish or edit content.
* Knowledge Artifacts — hand-curated structured documents (case studies, positioning notes, specs) that complement the auto-indexed KB.
* Brainstorm — an admin-only AI collaborator with access to your KB + artifacts for drafting new content.
* Live takeover — jump into any visitor conversation in real time from the admin or a mobile PWA.
* Saved Chats — read every conversation with full UTM attribution, AI reasoning, and any leads captured.
* Surveys — build interview scripts the AI runs through naturally.
* Attribution Context — swap the AI's framing per campaign (different positioning per UTM source).
* Design — brand color tokens with a live preview, font picker, and a configurable float button.
* Prompts — full control over the AI's persona (system prompt), opening line, and survey framing.
* Team members — pick who can be invited into chats, who receives lead notifications, who gets AI-error alerts.
* Webhooks — fire outbound notifications on `lead.captured`, `chat.started`, `admin.joined`, and more.
* Public API — scoped read-only keys for pulling data into external dashboards.
* Google Ads sync — pull campaign metadata so the AI knows what an ad-clicking visitor was promised before arriving.

= How it works =

1. You install the plugin and paste your Anthropic API key.
2. The Knowledge Base auto-indexes your published content.
3. You embed the chat on a page (shortcode) or use the full-page template.
4. A visitor arrives. Their UTM / campaign info attaches to the chat. The AI greets them with your welcome message.
5. They ask a question. The AI answers from your Knowledge Base + artifacts — citing your real case studies, FAQs, and service pages.
6. If the AI hits a natural decision point, it can offer the contact form or invite a specific team member.
7. Everything — transcript, lead, attribution, AI errors — is logged in your WordPress database.

= Requirements =

* WordPress 5.9 or newer.
* PHP 7.4 or newer.
* An Anthropic API key (you bring your own — no markup, no SaaS fees). Sign up at console.anthropic.com.
* Optional: a Groq or OpenAI API key, only if you want voice input on mobile.
* A modern browser on the visitor side (Server-Sent Events support).

= Bundled shortcodes =

* `[zen_cortext]` — embed the chat anywhere.
* `[zen_cortext_author_bio]` — display the author bio card (absorbed from the deprecated zen-author-bio mu-plugin; the legacy tag `[zen_author_bio]` still works as a deprecated alias).
* `[zen_cortext_author_posts_heading]` — display an "Author: [name]" heading (legacy alias: `[zen_author_posts_heading]`).

= Non-affiliation =

Zen Cortext is an independent plugin. It is not affiliated with, endorsed by, or sponsored by Anthropic, Groq, OpenAI, Google, or Microsoft.

== Installation ==

1. Upload the `zen-cortext` folder to `/wp-content/plugins/`, or install via Plugins → Add New.
2. Activate **Zen Cortext** from the Plugins menu. WordPress redirects you to the Getting Started page on first activation.
3. Paste your Anthropic API key in **Zen Cortext → Settings → Connection**.
4. Click **Build Knowledge Base** in **Zen Cortext → Knowledge Base** to index your published content.
5. Embed the chat with the `[zen_cortext]` shortcode on any page, or assign the "Zen Cortext — Full-page client chat" template from **Page Attributes → Template**.

The Getting Started page in the admin tracks your progress through twelve setup steps and links to each configuration screen.

== Frequently Asked Questions ==

= Do I need a paid AI plan? =

You bring your own Anthropic API key. Anthropic bills you directly per million tokens. A typical visitor question with a paragraph-length response runs a few cents on Claude Sonnet. Set a monthly spend cap in the Anthropic console while you are getting comfortable.

= Where are conversations stored? =

Every conversation, lead, and analytic event is stored in your own WordPress database (eleven custom tables prefixed `wp_zen_cortext_*`). Nothing is sent to Zen Republic Agency or any third party we control.

= Does uninstalling really remove everything? =

Yes. Deleting the plugin from the WordPress Plugins screen runs the bundled `uninstall.php`, which drops every `wp_zen_cortext_*` table, removes every `zen_cortext_*` option and transient, deletes the writable assets directory, and sweeps user meta. Multisite installs sweep every blog in the network.

= My base font size doesn't affect the chat. =

The Design tab's font-size field defaults to empty, which means "inherit from the host theme." Pick a non-empty value to override and the chat scales accordingly.

= Voice input isn't working. =

Voice is a mobile-only feature (where typing is most painful). Check that **Enable voice input** is toggled on under the Voice tab and that at least one of the Groq or OpenAI keys is saved.

= How do I know if my Knowledge Base is up to date? =

The Knowledge Base admin badge shows a count of pending items needing classification or restructuring. Click **Rebuild KB** to process them. New and edited posts get queued automatically.

= Can the plugin run AI jobs through a local Claude binary instead of the API? =

This plugin itself always uses the Anthropic HTTP API and never executes system commands. Running internal admin jobs (knowledge-base classify/restructure, Brainstorm, the artifact builder, the Template Editor AI) through a locally-installed, authenticated Claude Code CLI is available as a separate optional add-on plugin ("Zen Cortext — Claude Code CLI Processor") distributed outside the WordPress.org directory, because it shells out to the binary. When that add-on is installed and active it transparently takes over those internal jobs via documented filter hooks; the visitor-facing chat always uses the HTTP API regardless (the CLI cannot stream into the browser).

= Can I use this without sending data to any third party? =

No — the entire feature is an AI consultant, so chat turns are sent to the AI provider you configure. See the External services section below for the full disclosure.

== External services ==

This plugin connects to external AI and analytics services to do its job. By using the plugin you accept that visitor chat content is sent to the providers you configure.

= Anthropic API (required) =

* What it does: every chat message the visitor sends, plus your system prompt and any relevant knowledge-base snippets, are sent to the Anthropic API so the AI can compose a response. Responses stream back to the visitor's browser.
* When it is called: every time a visitor sends a chat message; when the admin runs Knowledge Base classification / restructuring; when the admin tests the connection.
* Data sent: chat transcript so far, the system prompt, knowledge-base context snippets, attribution metadata (UTM / source / campaign), the active model name.
* Service: Anthropic.
* Terms of Service: https://www.anthropic.com/legal/consumer-terms
* Privacy Policy: https://www.anthropic.com/legal/privacy

= Groq API (optional — voice input only) =

* What it does: when voice input is enabled and the visitor presses the mic button, the recorded audio is sent to Groq Whisper for speech-to-text transcription.
* When it is called: only when a visitor uses the voice input button on the chat.
* Data sent: the audio blob recorded by the visitor's microphone.
* Service: Groq.
* Terms of Service: https://groq.com/terms-of-use/
* Privacy Policy: https://groq.com/privacy-policy/

= OpenAI API (optional — voice input fallback) =

* What it does: same as Groq, used as the fallback when Groq is unavailable or not configured.
* When it is called: only when a visitor uses the voice input button on the chat AND Groq has failed or is not configured.
* Data sent: the audio blob recorded by the visitor's microphone.
* Service: OpenAI.
* Terms of Service: https://openai.com/policies/terms-of-use
* Privacy Policy: https://openai.com/policies/privacy-policy

= Google Ads API (optional — campaign metadata sync) =

* What it does: pulls campaign metadata (campaign name, headlines, status) from your Google Ads account so the AI knows what an ad-clicking visitor was promised before they arrived. The data flow is initiated by a Google Ads Script that you paste into the Google Ads UI; the script POSTs metadata to a `wp-json/zc/v1/ads-sync` endpoint on your own site.
* When it is called: on the schedule you set inside Google Ads (typically daily).
* Data sent (outbound from Google Ads to your site): campaign id, campaign name, status, headlines, keywords.
* Service: Google Ads.
* Terms of Service: https://policies.google.com/terms
* Privacy Policy: https://policies.google.com/privacy

= Web Push (optional — admin notifications) =

When admin push notifications are enabled, web-push messages are sent through the visitor's browser-vendor push service (FCM for Chrome, Mozilla autopush for Firefox, Apple push for Safari). These services are governed by the browser vendor's terms.

= Custom code fields (optional — your own tracking) =

The Settings → Tracking tab provides Header / Body / Footer code fields that are output verbatim on the standalone chat pages. The plugin itself makes no call to any third party here — it only prints whatever code you enter. If you paste third-party tags (for example Google Tag Manager, Google Analytics, or the Meta Pixel), those tags will load on the chat page and connect to their respective services according to your own configuration and their terms.

== Screenshots ==

1. The visitor-facing AI chat in action — streaming response, intro card, follow-up chips.
2. The Knowledge Base admin — auto-indexed pages and posts with classification status.
3. The Saved Chats admin — every visitor conversation with attribution and lead capture state.
4. The Design tab — brand color tokens, font picker, float-button configuration with live preview.
5. The Getting Started admin page — twelve setup steps with completion tracking.

== Changelog ==

= 2.39.19 =
* WordPress.org review compliance: removed a one-time migration that authored a Google Tag Manager script snippet directly in the plugin's PHP source. Tracking/analytics code is now strictly admin-pasted via the Settings → Tracking Header/Body/Footer custom-code fields; the plugin no longer generates any script markup of its own. Existing installs keep whatever code they previously saved in those fields.
* WordPress.org review compliance: the long-running visitor-chat stream now raises the PHP time limit to a bounded value (180s) scoped to that single streaming request, instead of removing it entirely; it is never set globally.
* WordPress.org review compliance: the public voice-transcription endpoint (/transcribe) now requires a valid WordPress REST nonce (X-WP-Nonce) before reading the uploaded audio, validating that the request originated from a page this site served.
* WordPress.org review compliance: removed the blanket file-level suppression of the input-sanitization checks in the admin and REST classes. Every request input is now unslashed and sanitized at its point of use with a context-appropriate function (including per-field sanitization of JSON payloads and individual validation of the audio upload's fields), and Plugin Check passes with the sanitization sniffs enabled.
* WordPress.org review compliance: prefix audit. Removed the short-prefixed [zen_author_bio]/[zen_author_posts_heading] shortcode aliases (the canonical [zen_cortext_author_*] tags remain), renamed the livechat JS config global from zlcConfig to zenCortextLivechat, and renamed six global admin-view helper functions from the zcc_/zcs_ prefixes to the full zen_cortext_ prefix. Every public function, class, shortcode, option, hook, and JS global the plugin defines now carries the full zen_cortext prefix.
* WordPress.org review compliance: output-escaping audit. The float-button markup is now escaped at output through wp_kses() with an explicit allow-list. Remaining raw outputs are documented at the point of output: inline CSS (every value sanitized for CSS context; wp_add_inline_style has no escaping function), Server-Sent-Events stream chunks (JSON-encoded), the chat template renderer (escapes every placeholder; the custom Header/Body/Footer code fields follow WordPress core's Custom HTML widget model, stored verbatim only for users with the unfiltered_html capability and run through wp_kses_post() for everyone else).

= 2.39.13 =
* Custom code for the chat pages: the full-page chat (/talk/) renders its own document and bypasses the theme, so analytics/scripts added via your theme or a header-footer plugin did not load there. Settings → Tracking now has Header, Body, and Footer code fields that inject your own code (Google Tag Manager, GA4, Meta Pixel, site-verification meta tags, etc.) directly into the chat page. This replaces an earlier theme-specific Google Tag Manager hook; existing tag-manager setups are migrated into the Header/Body fields automatically. The fields are stored raw only for users with the unfiltered_html capability (administrators); other roles' input is filtered through wp_kses_post().

= 2.39.12 =
* PHP 8.2 compatibility: declared the admin class's $init_hook property so it no longer triggers a "Creation of dynamic property" deprecation under WP_DEBUG. Verified the plugin passes WordPress Plugin Check with zero errors and zero warnings.

= 2.39.11 =
* Fixed a regression from the HTTP-API streaming change (2.39.0): a successful visitor chat could still be reported as a transport error ("Missing header/body separator") and trigger a false "service unavailable" message + admin alert email. The streaming read callback consumes the response body, so the WP HTTP API's Requests layer has nothing left to re-parse and returns a WP_Error even on success — that is now ignored when the stream actually delivered. Genuine failures (no data streamed, HTTP 4xx/5xx, empty responses) are still detected and reported.

= 2.39.10 =
* Database-safety review. Confirmed every query that takes user input binds it through $wpdb->prepare() with placeholders; the only thing interpolated into a query is the plugin's own table name (built from $wpdb->prefix, which prepare() cannot parameterize). Documented the schema-migration ALTER TABLE statements and added targeted phpcs justifications for the install/upgrade schema changes, the one-time uploads-cleanup rmdir, and the set_time_limit() used by the SSE chat stream. The plugin now passes WordPress Plugin Check with zero errors.

= 2.39.9 =
* Unique-prefix cleanup. Renamed the few transient cache keys that used short prefixes (zc_, zci_, zce_) to the plugin's full zen_cortext_ prefix. Added fully-prefixed shortcode tags [zen_cortext_author_bio] and [zen_cortext_author_posts_heading]; the legacy [zen_author_bio] / [zen_author_posts_heading] tags continue to work as deprecated aliases. All options, transients, classes, hooks and constants now consistently use the zen_cortext / Zen_Cortext / ZEN_CORTEXT prefix.

= 2.39.8 =
* Output-escaping audit. The two flagged cases were already resolved by the earlier enqueue refactor (the REST URL is now passed to JavaScript via wp_localize_script, and the chat font-family is sanitized for CSS context). The full-page chat body is emitted by a template engine that escapes every dynamic placeholder (esc_html / esc_url / esc_attr) with only pre-escaped HTML passed through raw — documented inline, since wp_kses_post() would strip its SVG icons and Alpine.js attributes.

= 2.39.7 =
* Hardened input sanitization on several admin AJAX handlers and server variables. The User-Agent header and client IP ($_SERVER) are now run through sanitize_text_field(); the attribution context/invite text, survey description/script/outcome fields, and the Knowledge Artifact source are sanitized on input (validating UTF-8 and stripping control characters, while preserving the technical content those fields legitimately hold); and the decoded synthesize-from-chat transcript is sanitized after json_decode().

= 2.39.6 =
* Settings → Connection now shows an at-a-glance "Backend for internal AI jobs" status line (green dot + "Claude Code CLI" when the optional CLI add-on is active, blue dot + "Anthropic HTTP API" otherwise).

= 2.39.5 =
* Removed the optional local Claude Code CLI processor (and all proc_open / shell-execution code) from the plugin. The plugin now uses the Anthropic HTTP API exclusively. Internal admin AI jobs (KB classify/restructure, Brainstorm, artifact builder, Template Editor AI) run through new pluggable filter hooks (zen_cortext_complete_text, zen_cortext_stream_internal, zen_cortext_test_connection). The CLI backend now ships as a separate optional add-on plugin that implements those hooks — keeping this plugin free of system-command execution per WordPress.org guidelines.

= 2.39.4 =
* Hardened the public chat status/poll REST endpoints. The /chat/{uid}/status and /chat/{uid}/poll routes now require the per-chat owner token (the same credential already used by send/invite/delete) instead of being fully public, so only the originating visitor can read their own conversation's live takeover state and events. The chat widget passes the token automatically; legacy conversations with no stored token remain accessible for backward compatibility.

= 2.39.3 =
* Hardened the sanitize callbacks on register_setting() fields. The welcome message is now sanitized with sanitize_textarea_field(); the intro-card body (rendered as HTML) with wp_kses_post(); and the AI prompt/template fields (system prompt, classify prompt, survey template) with a sanitizer that validates UTF-8 and strips control characters while preserving the angle-bracket template tokens those prompts depend on.

= 2.39.2 =
* The Template Editor (editable chat templates + chat.css + version history) now stores its data in the WordPress database instead of writing source files to wp-content/uploads/. Factory defaults still ship read-only inside the plugin. A one-time migration imports any existing edited copies from uploads into the database and removes the old files, so no editable source is kept on disk (where it would be publicly readable and lost on upgrade). When the chat stylesheet hasn't been customized it loads as the cacheable bundled file; a customized stylesheet is printed as inline CSS.

= 2.39.1 =
* Fixed the "Talk to a real person" availability status: an admin who is manually online/away and actively present (live heartbeat) now shows as available to visitors even outside their configured availability-schedule window. The schedule still forces offline once the admin goes idle or closes the live-chat app — so it no longer hides someone who is genuinely online right now (e.g. working a weekend that falls outside their Mon–Fri schedule).

= 2.39.0 =
* All CSS and JavaScript is now loaded through the WordPress enqueue API (wp_enqueue_style / wp_enqueue_script / wp_register_* / wp_add_inline_style / wp_add_inline_script / wp_localize_script). Every hand-written <style>, <script>, and stylesheet <link> tag has been removed from the templates, admin views, and helper classes.
* The standalone full-page chat and live-chat (PWA) templates now register the plugin's own assets and print only those handles, so they go through the core pipeline while still keeping theme / other-plugin assets off the page.
* Removed the bundled Yanone Kaffeesatz Google Fonts request entirely — the plugin no longer makes any external font request. Fonts remain fully configurable in the Design tab; the chosen font-family / size is emitted as enqueued inline CSS.
* REST and asset URLs now use the portable WordPress location helpers (rest_url(), plugins_url()) instead of hardcoded /wp-json or site-root paths, so they work on any install (custom REST prefixes, subdirectory installs, etc.).
* The chat icons (mobile chat trigger, PWA touch icon, browser/push notification icons) are bundled with the plugin and driven by the Design → float-button icon setting — no hardcoded references.
* The Anthropic chat requests now go through the WordPress HTTP API (wp_remote_post); the Server-Sent Events body is streamed chunk-by-chunk by attaching the read callbacks via the core http_api_curl action, replacing the direct curl_init/curl_exec calls.
* No functional changes to the chat, admin tools, float button, or author-bio card — this is a WordPress.org compliance pass (asset loading, location helpers, HTTP API).

= 2.38.0 =
* First public release on wordpress.org.
* Full readme.txt + external services disclosure.
* Consolidated version constants and metadata for the WordPress Plugin Directory submission.
* The Yanone Kaffeesatz webfont is now requested from Google Fonts only when the Design → Base font setting asks for it; the default install makes no external font request.
* The optional local Claude Code CLI processor (which shells out to the `claude` binary) is now OFF by default and can only be enabled by defining the `ZEN_CORTEXT_ENABLE_CLI` constant in wp-config.php. Without it the plugin always uses the Anthropic HTTP API and never invokes proc_open().
* Visitor write endpoints (delete / lead / email / invite) now declare their owner-token authorization in the REST permission_callback at route registration.

= 2.34.x (development / internal) =
* See the project's GitHub repository commit history for the full pre-1.0 development log.

== Upgrade Notice ==

= 2.39.11 =
Fixes false "service unavailable" messages and admin alert emails that could fire after a successful visitor chat. Recommended update.

= 2.39.9 =
Internal cache keys and shortcode tags now use the full zen_cortext_ prefix. The old [zen_author_bio] / [zen_author_posts_heading] shortcodes still work as aliases, so no action is required.

= 2.39.7 =
Stronger input sanitization on admin handlers and server variables. No action required.

= 2.39.5 =
The plugin no longer executes system commands; the optional Claude Code CLI backend moved to a separate add-on plugin. If you used it, install the "Zen Cortext — Claude Code CLI Processor" add-on; otherwise no action is needed.

= 2.39.4 =
The live-chat status/poll endpoints are now gated by the per-chat owner token. No action required.

= 2.39.3 =
Stronger input sanitization on the settings fields. No action required.

= 2.39.2 =
Template Editor data (templates, chat.css, version history) moves from uploads files into the database. Existing edits are migrated automatically on upgrade.

= 2.39.1 =
Fixes the live-chat availability status so an actively-online admin is no longer hidden as offline outside their schedule window.

= 2.39.0 =
Assets are now loaded via the WordPress enqueue API and the bundled Google Fonts request was removed. No data migration required.

= 2.38.0 =
First wordpress.org release. No data migration required.
