# Zen Cortext — Data Layer Events

The frontend chat pushes semantic events to `window.dataLayer` (Google Tag
Manager / GA4) and mirrors each one as a DOM `CustomEvent` named `zc:<name>`
so analytics that don't use a tag manager (Plausible, Matomo, custom
listeners) can subscribe too.

**Privacy:** no PII is ever emitted. Names, emails, phone numbers, and
message text never enter the data layer — only the chat uid, page path,
counts, and boolean flags.

## Enabling / disabling

Events are **on by default**. Disable them site-wide with a filter:

```php
add_filter('zen_cortext_data_layer_enabled', '__return_false');
```

## Common payload fields

Every event includes:

| Field          | Type           | Notes                                   |
|----------------|----------------|-----------------------------------------|
| `event`        | string         | `zc_<name>` — the GTM trigger key       |
| `zc_chat_uid`  | string \| null | Conversation id (null before bound)     |
| `zc_page_path` | string         | `location.pathname` where it fired      |

## Events

| `event`                  | Fires when…                                              | Extra fields |
|--------------------------|---------------------------------------------------------|--------------|
| `zc_chat_started`        | the visitor sends their **first** message of the session | `zc_attributed` (bool — an attribution rule matched) |
| `zc_message_sent`        | the visitor sends any message                            | `zc_message_index`, `zc_char_count` |
| `zc_message_received`    | an AI reply finishes streaming                           | `zc_message_index`, `zc_char_count` |
| `zc_admin_requested`     | a human is requested (the "admin request" / handoff)    | `zc_source` (`auto` \| `lead_form` \| `manual_bar`), `zc_target_user`, `zc_message_index` |
| `zc_admin_joined`        | a human actually takes over the chat                    | `zc_message_index` |
| `zc_lead_submitted`      | the contact form is saved — **conversion**              | `zc_has_whatsapp` (bool), `zc_message_index` |
| `zc_chat_shared`         | the visitor copies the share link                       | `zc_message_index` |
| `zc_transcript_emailed`  | the "email me a copy" form succeeds                     | `zc_message_index` |
| `zc_service_unavailable` | the AI backend errors and the fallback form is shown    | `zc_reason` (`service_unavailable` \| `error`) |

## GA4 mapping suggestions

- `zc_chat_started` → engagement / "begin_checkout"-style funnel entry
- `zc_admin_requested`, `zc_admin_joined` → high-intent custom conversions
- `zc_lead_submitted` → mark as a **GA4 Conversion** (this is the lead)

## Listening without GTM

```js
document.addEventListener('zc:lead_submitted', function (e) {
    console.log('lead captured', e.detail.zc_chat_uid, e.detail.zc_has_whatsapp);
});
```
