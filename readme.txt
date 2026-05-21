=== Zen Cortext - Your AI SDR for inbound ===
Contributors: zenrepublic
Tags: chatbot, ai chat, lead generation, knowledge base, customer support
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.35.0
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
* `[zen_author_bio]` — display the author bio card (absorbed from the deprecated zen-author-bio mu-plugin).
* `[zen_author_posts_heading]` — display an "Author: [name]" heading.

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

== Screenshots ==

1. The visitor-facing AI chat in action — streaming response, intro card, follow-up chips.
2. The Knowledge Base admin — auto-indexed pages and posts with classification status.
3. The Saved Chats admin — every visitor conversation with attribution and lead capture state.
4. The Design tab — brand color tokens, font picker, float-button configuration with live preview.
5. The Getting Started admin page — twelve setup steps with completion tracking.

== Changelog ==

= 2.35.0 =
* First public release on wordpress.org.
* Full readme.txt + external services disclosure.
* Consolidated version constants and metadata for the WordPress Plugin Directory submission.

= 2.34.x (development / internal) =
* See the project's GitHub repository commit history for the full pre-1.0 development log.

== Upgrade Notice ==

= 2.35.0 =
First wordpress.org release. No data migration required.
