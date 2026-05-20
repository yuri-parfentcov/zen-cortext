# Zen Cortext

**An AI sales consultant for your WordPress site that answers from YOUR content, not generic AI knowledge.**

Zen Cortext turns your published pages, posts, FAQs, and case studies into a knowledge base that Claude reads from. Visitors get a streaming chat that cites your real work — not made-up advice. Conversations stay in your WordPress database. Your team gets notified when someone's ready to talk. You bring your own Anthropic API key, so there's no SaaS subscription on top.

- **Trained on your site automatically** — one click indexes everything you've published, classifies it, and restructures it into a clean format the AI can quote.
- **Bring your own Anthropic API key** — no markup, no proxy server, no third-party data sharing. The chat talks to Anthropic directly from your WordPress host.
- **Lives entirely in your WordPress install** — every conversation, lead, and analytic event is stored in your own database. Uninstall the plugin and it all goes with it.

> **Current version:** 2.34.16 · **License:** GPL v2 or later · **WordPress:** 5.9+ · **PHP:** 7.4+

---

## What you get

### For your visitors

- A **streaming AI chat** that types responses live, embedded on any page via shortcode or on a dedicated full-page template.
- A **floating chat button** that follows visitors across the site (optional).
- A **speaker intro card** so the chat doesn't feel like ChatGPT — show your team's identity, role, and a short pitch.
- A **welcome message** + **four starter quick-question chips** you control. Click a chip → conversation starts.
- **Inline follow-up suggestions** the AI offers mid-conversation as clickable chips.
- **Optional interview / survey scripts** the AI weaves naturally into the conversation — qualifying questions, intake forms, custom discovery flows.
- An **inline contact-capture form** the AI surfaces when the visitor is ready to talk, or when it doesn't know the answer.
- A **"talk to a real human now" handover** — the AI can invite specific team members into the chat in real time via push notification. Offline? It falls back to the contact form automatically.
- **Voice input on mobile** — mic button → speech-to-text via Groq or OpenAI Whisper.
- Visitors can **save, email, or delete** their own conversation.
- **Graceful fallback** if the AI is unavailable (API outage, billing issue): the visitor sees a calm "we'll get back to you" message and a contact form. Your team gets an email explaining what failed.

### For you (the admin)

- **Knowledge Base** — one-click indexer that pulls in your pages, posts, FAQs, portfolio items. Classifies each entry into a content type (case study, technical article, service page, FAQ) and restructures it into a clean format the AI can cite confidently. Re-syncs automatically when you publish or edit content.
- **Knowledge Artifacts** — hand-curated structured documents (case studies, internal positioning notes, technical specs) that complement the auto-indexed Knowledge Base.
- **Brainstorm** — an admin-only AI collaborator with full access to your Knowledge Base + artifacts. Use it to draft new content, ideate campaign angles, write outlines.
- **Live takeover** — jump into any visitor conversation in real time from the admin (or a mobile PWA) when the AI escalates.
- **Saved Chats** — read every conversation your site has had, with full attribution (which campaign brought the visitor in), the AI's reasoning, and any leads captured.
- **Surveys** — build interview scripts the AI runs through naturally — qualifying questions, intake forms, custom flows.
- **Attribution Context** — swap the AI's framing per campaign. Visitors arriving from your "speed audit" ad see different positioning than visitors from "ad agency hire" ads.
- **Design** — 13 brand color tokens with a live preview, base font / size picker, configurable float button (position, color, icon, hover text).
- **Prompts** — full control over the AI's persona (system prompt), opening line, and survey framing. An "Adapt to my KB" button drafts a site-tailored prompt suggestion.
- **Team members** — pick who can be invited into chats, who receives lead notifications, who gets AI-error alerts. Each member gets a profile card with avatar, WhatsApp, LinkedIn, and role.
- **Webhooks** — fire outbound notifications on `lead.captured`, `chat.started`, `admin.joined`, and more to your CRM, Slack, Zapier, or anything that accepts a POST.
- **Public API** — scoped read-only keys (`read:chats`, `read:leads`, `read:stats`, `read:knowledge`, `read:attribution`, `read:sessions`) for pulling data into external dashboards.
- **Google Ads sync** — pull live campaign metadata so the AI knows what an ad-clicking visitor was promised before they arrived.

---

## How it works

1. You install the plugin and paste your Anthropic API key.
2. The Knowledge Base auto-indexes your published content.
3. You embed the chat on a page (shortcode) or use the full-page template.
4. A visitor arrives. Their UTM / campaign info attaches to the chat. The AI greets them with your welcome message.
5. They ask a question. The AI answers from your Knowledge Base + artifacts, citing your actual case studies, FAQs, and service pages — not generic web knowledge.
6. If the AI hits a natural decision point, it can offer the contact form or invite a specific team member (whose availability the system already knows).
7. Everything — transcript, lead, attribution, AI errors — is logged in your WordPress database and visible in the admin.

---

## Requirements

| | Required |
|---|---|
| WordPress | 5.9 or newer |
| PHP | 7.4 or newer |
| Anthropic API key | Required — you bring your own. No markup, no SaaS fees. Sign up at [console.anthropic.com](https://console.anthropic.com). |
| Groq **or** OpenAI API key | Optional, only if you want voice input. [Groq](https://console.groq.com/keys) has a generous free tier. |
| Modern browser | Visitors need a recent Chromium / Firefox / Safari (Server-Sent Events support). |

**Explicit no-list:**

- No required SaaS subscription beyond Anthropic.
- No proxy server. Your visitor's chat goes directly from your WordPress host to Anthropic.
- No third-party data sharing.

---

## What it costs to run

Zen Cortext itself is free — the only ongoing cost is the AI provider you choose.

- **Anthropic** charges per million tokens of input/output. A typical visitor question + a paragraph-length answer on Claude Sonnet runs a few cents. Prompt caching (enabled by default) cuts that to a fraction of a cent for follow-up turns within the same session.
- **Set a monthly spend cap** in your Anthropic console while you're getting comfortable — the Getting Started page links straight to the right settings page.
- **Voice transcription via Groq** has a free tier that covers thousands of minutes per month. OpenAI Whisper is paid per minute (~$0.006/min).

We don't quote specific dollar amounts because Anthropic adjusts pricing periodically — check [anthropic.com/pricing](https://www.anthropic.com/pricing) for the current rates.

---

## Install

1. Download the latest release from this repo, or clone it into `wp-content/plugins/zen-cortext/`:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/yuri-parfentcov/zen-cortext.git
   ```
2. Go to **Plugins** in your WordPress admin and activate **Zen Cortext**.
3. WordPress redirects you to the **Getting Started** page on first activation.
4. Follow the steps in order. The page tracks your progress and shows a ✓ next to completed steps.

You can return to Getting Started any time from the **Zen Cortext → Getting Started** menu in the WP admin sidebar.

---

## The 12 setup steps

The Getting Started page in the admin is the canonical, interactive version of this list — each step links straight to the screen where you configure it and tracks completion. Here's the overview:

| # | Step | Status |
|---|------|--------|
| 1 | Connect Claude (Anthropic API key) | Required |
| 2 | Build the Knowledge Base | Required |
| 3 | Add Knowledge Artifacts | Optional |
| 4 | Create or pick a chat page | Required |
| 5 | Design — palette, typography, float button | Required |
| 6 | Prompts — system, welcome, survey framing | Required |
| 7 | Team members — invites + lead/error routing | Optional |
| 8 | Float button | Optional |
| 9 | Voice input — speech-to-text key | Optional |
| 10 | Surveys / interview scripts | Optional |
| 11 | Attribution context rules | Optional |
| 12 | Test the chat | Required |

---

## Embedding the chat

There are three ways to put the chat in front of visitors:

### Shortcode (anywhere)

Drop `[zen_cortext]` into any page or post. Works with Gutenberg, the Classic editor, Avada, Elementor, and most page builders.

```
[zen_cortext]
```

### Full-page template

When editing a page, set **Page Attributes → Template → "Zen Cortext — Full-page client chat"**. This bypasses your theme's header / footer and lets the chat own the whole viewport. Best for a dedicated `/talk/` or `/consultant/` URL.

### Float button

Enable on the **Design** tab. A small floating chat icon appears on every public page (except the chat page itself) and links to your chat page. Configure the position, color, icon, and hover text.

### Bundled bonus shortcodes

For sites that previously used the deprecated `zen-author-bio` mu-plugin, two extra shortcodes are absorbed into Zen Cortext:

- `[zen_author_bio]` — display the author bio card.
- `[zen_author_posts_heading]` — display an "Author: [name]" heading.

---

## What's stored in your database

The plugin creates 11 tables in your WordPress database. Everything stays on your server.

| Table | What it holds |
|-------|---------------|
| `wp_zen_cortext_kb` | Auto-indexed Knowledge Base entries (extracted from your published content). |
| `wp_zen_cortext_artifacts` | Hand-curated structured documents (case studies, technical specs, etc.). |
| `wp_zen_cortext_chats` | Every visitor conversation, with attribution + lead capture state. |
| `wp_zen_cortext_chat_events` | Lifecycle log of chat events (auto-purged after 48 hours). |
| `wp_zen_cortext_brainstorm_chats` | Your admin-only collaborator conversations. |
| `wp_zen_cortext_sessions` | Visitor sessions (GA-style, 30-minute inactivity window). |
| `wp_zen_cortext_surveys` | Interview / discovery scripts you've defined. |
| `wp_zen_cortext_attribution_contexts` | Campaign-specific framing rules. |
| `wp_zen_cortext_ads_campaigns` | Synced Google Ads campaign metadata (optional). |
| `wp_zen_cortext_api_keys` | Scoped public-API keys you've issued. |
| `wp_zen_cortext_push_subscriptions` | Push-notification registrations for admin alerts. |

### Clean uninstall

When you delete the plugin from the WordPress admin (Plugins → Delete), `uninstall.php` runs a complete cleanup: all 11 tables dropped, every `zen_cortext_*` option removed, every transient cleared, the writable assets directory wiped, and user meta swept. No orphan data left behind. Multisite installs sweep every blog in the network.

---

## Privacy and data

- **Conversations live in your WordPress database.** They are never sent to Zen Republic Agency or any third party we control.
- **Outbound calls** the plugin makes are limited to: Anthropic (for the AI), Groq or OpenAI (only if you enable voice transcription), and any webhook endpoints you yourself configure.
- **GDPR mode** in the User Sessions settings gates session tracking on Google Consent Mode v2 — the beacon only fires after the visitor grants `analytics_storage`.

---

## Public REST API

The plugin exposes a scoped read-only REST API under `/wp-json/zc/v1/` for pulling data into external tools (dashboards, CRMs, business-intelligence pipelines). Issue keys in the admin's **API** tab and assign one or more scopes:

| Scope | Lets the key… |
|-------|---------------|
| `read:chats` | List and retrieve visitor conversations. |
| `read:leads` | List captured leads (contact-form submissions). |
| `read:stats` | Read aggregated metrics (chats per day, conversion rates, attribution mix). |
| `read:attribution` | Read the current campaign-context configuration. |
| `read:knowledge` | List Knowledge Base entries (metadata only, not full content). |
| `read:sessions` | List visitor sessions with their attribution. |

On the outbound side, a **webhooks** layer pushes events as they happen — `lead.captured`, `chat.started`, `admin.joined`, and more — to a URL you provide (Slack, Zapier, your CRM's inbound webhook).

A detailed API reference is on the roadmap (`docs/api.md`).

---

## Troubleshooting

**"The chat shows an 'AI consultant is currently unavailable' message and a contact form."**
The AI returned an error (billing, rate limit, outage). Your team already received an email with the details. Check your Anthropic account balance and rate limits at [console.anthropic.com](https://console.anthropic.com).

**"My base font size doesn't affect the chat."**
The Design tab's font-size field defaults to empty, which means "inherit from the host theme." Pick a non-empty value to override and the chat scales accordingly.

**"The chat is showing the old olive/lime palette instead of the WordPress-styling default colors."**
Your writable `chat.css` was seeded before version 2.34.7 and still contains the older palette. The Chat Editor has a "Reset to factory" option, or the next plugin reactivation re-seeds it from the latest factory copy.

**"How do I know if my Knowledge Base is up to date?"**
The Knowledge Base admin badge shows a count of pending items needing classification or restructuring. Click **Rebuild KB** to process them in one go. New / edited posts get queued automatically.

**"Voice input isn't working."**
Check that **Enable voice input** is toggled on in the Voice tab, and that at least one of the Groq or OpenAI keys is saved. Voice is a mobile-only feature by default (where typing is most painful) — desktop browsers don't show the mic button.

---

## License & credits

- **License:** GPL v2 or later.
- **Built by** [Zen Republic Agency](https://zenrepublic.agency).
- **Contains code absorbed** from the previously separate `zen-author-bio` mu-plugin.
- **Powered by** [Anthropic's Claude](https://www.anthropic.com), with optional speech-to-text via [Groq Whisper](https://groq.com) or [OpenAI Whisper](https://platform.openai.com).

---

## Changelog

This repo's `git log` is the source of truth for changes. Browse recent commits at [github.com/yuri-parfentcov/zen-cortext/commits/main](https://github.com/yuri-parfentcov/zen-cortext/commits/main) — every commit message describes what changed and why.
