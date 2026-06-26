<?php
/**
 * Anthropic API client for Zen Cortext.
 * - classify(): one short call, returns category string
 * - restructure(): longer call, returns markdown
 * - test_connection(): trivial ping
 * - stream_chat(): streams SSE chunks straight to PHP output (used by REST controller)
 */

/*
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt
 *
 * Justification: this class streams Server-Sent Events from the Anthropic
 * API directly to the visitor's browser. The request goes through the
 * WordPress HTTP API (wp_remote_post); because wp_remote_post() buffers the
 * whole body, we attach CURLOPT_WRITEFUNCTION / CURLOPT_HEADERFUNCTION to the
 * underlying cURL handle via the core http_api_curl action (the documented
 * way to set cURL options for an HTTP-API request) so the SSE body can be
 * consumed chunk-by-chunk — hence the remaining curl_setopt calls. Every AI
 * backend in this class is the hosted Anthropic HTTP API; the optional local
 * Claude Code CLI processor lives in a separate companion plugin that hooks
 * the zen_cortext_complete_text / zen_cortext_stream_internal filters.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_API {

    const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    const ANTHROPIC_VERSION = '2023-06-01';

    /**
     * The list of categories the classifier may legitimately return now
     * comes from Zen_Cortext_KB_Types::valid_for_classifier() — admin-
     * defined slugs plus the structural 'other' bucket. The const that
     * used to live here was removed when content types became data-driven.
     */

    // Admin Brainstorm page: Opus 4.6 with extended thinking. Distinct from the
    // visitor chat model so brainstorming gets the deeper reasoning model
    // regardless of what the public chat is configured to use.
    //
    // BRAINSTORM_MAX_TOKENS must be strictly greater than BRAINSTORM_THINKING_BUDGET —
    // the Anthropic API counts thinking tokens against max_tokens, so the cap has
    // to leave room for both the thinking block AND the actual response.
    const BRAINSTORM_MODEL            = 'claude-opus-4-6';
    const BRAINSTORM_THINKING_BUDGET  = 8000;
    const BRAINSTORM_MAX_TOKENS       = 24000;

    /**
     * Models the admin may pick for a brainstorm turn. Keyed by API model id;
     * each entry carries the CLI alias (for the subscription backend), a
     * human label, whether the model supports extended thinking, and a
     * model-appropriate max_tokens cap. BRAINSTORM_MODEL stays the default so
     * existing behaviour is unchanged unless the admin chooses otherwise.
     */
    public static function brainstorm_models() {
        return array(
            'claude-opus-4-6' => array(
                'label'      => 'Opus 4.6 — deepest reasoning (most expensive)',
                'cli'        => 'opus',
                'thinking'   => true,
                'max_tokens' => self::BRAINSTORM_MAX_TOKENS,
            ),
            'claude-sonnet-4-6' => array(
                'label'      => 'Sonnet 4.6 — balanced cost / quality',
                'cli'        => 'sonnet',
                'thinking'   => true,
                'max_tokens' => self::BRAINSTORM_MAX_TOKENS,
            ),
            'claude-haiku-4-5' => array(
                'label'      => 'Haiku 4.5 — fastest & cheapest',
                'cli'        => 'haiku',
                'thinking'   => false,
                'max_tokens' => 8000,
            ),
        );
    }

    /**
     * Resolve a requested brainstorm model id to its config, falling back to
     * BRAINSTORM_MODEL for anything not in the allow-list. Returns the config
     * array with the resolved 'id' merged in.
     */
    public static function brainstorm_model_config($requested = '') {
        $models = self::brainstorm_models();
        $id     = isset($models[$requested]) ? $requested : self::BRAINSTORM_MODEL;
        return array_merge($models[$id], array('id' => $id));
    }

    public static function api_key() {
        return trim((string) get_option('zen_cortext_api_key', ''));
    }

    public static function model() {
        return get_option('zen_cortext_model', 'claude-sonnet-4-6');
    }

    public static function classify_model() {
        return get_option('zen_cortext_classify_model', self::model());
    }

    public static function max_tokens() {
        return (int) get_option('zen_cortext_max_tokens', 2048);
    }

    /**
     * Run a one-shot, non-streaming text completion for an internal admin
     * job (KB classify / restructure, artifact builder/synthesizer). The
     * built-in backend is the Anthropic HTTP API. A companion plugin can
     * short-circuit this via the `zen_cortext_complete_text` filter to use an
     * alternative backend (e.g. the optional Claude Code CLI add-on):
     * returning a string or WP_Error takes over, returning null falls through
     * to the HTTP API. $opts keys: model, max_tokens (and any the companion
     * reads, e.g. timeout).
     */
    public static function complete_text($prompt, $opts = array()) {
        $alt = apply_filters('zen_cortext_complete_text', null, (string) $prompt, $opts);
        if (is_string($alt) || is_wp_error($alt)) {
            return $alt;
        }
        $response = self::request_json(array(
            'model'      => isset($opts['model']) ? $opts['model'] : self::model(),
            'max_tokens' => isset($opts['max_tokens']) ? (int) $opts['max_tokens'] : self::max_tokens(),
            'messages'   => array(array('role' => 'user', 'content' => (string) $prompt)),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        return isset($response['content'][0]['text']) ? $response['content'][0]['text'] : '';
    }

    /**
     * Stream an internal admin chat (Brainstorm, Template-Editor AI, artifact
     * chat). The built-in backend is the streaming Anthropic HTTP API. A
     * companion plugin can short-circuit via the `zen_cortext_stream_internal`
     * filter (returning an ['ok','text','stderr'] array) to use an alternative
     * backend; returning null falls through to the HTTP API. $on_event
     * receives each SSE event JSON string, exactly like stream_chat_via_api().
     */
    public static function stream_internal($system_prompt, $messages, $on_event, $opts = array()) {
        $handled = apply_filters('zen_cortext_stream_internal', null, $system_prompt, $messages, $on_event, $opts);
        if (is_array($handled)) {
            return $handled;
        }
        return self::stream_chat_via_api($system_prompt, $messages, $on_event, $opts);
    }

    /**
     * Trivial ping to verify the configured backend works.
     *
     * $overrides lets the admin Settings page test the values currently TYPED
     * in the form before they're saved (so users don't have to commit a key
     * to find out it's wrong). Any key not present in $overrides falls back
     * to the saved option. Recognised: processor, api_key, cli_path, cli_model.
     *
     * Returns array(success => bool, message => string).
     */
    public static function test_connection($overrides = array()) {
        // A companion plugin (e.g. the Claude Code CLI add-on) can handle the
        // connection test for its own backend by returning a
        // ['success'=>bool,'message'=>string] array; returning null lets the
        // built-in Anthropic HTTP API test run.
        $alt = apply_filters('zen_cortext_test_connection', null, $overrides);
        if (is_array($alt) && isset($alt['success'])) {
            return $alt;
        }

        $api_key   = isset($overrides['api_key']) ? trim((string) $overrides['api_key']) : self::api_key();
        $api_model = self::model();

        if ($api_key === '') {
            return array('success' => false, 'message' => 'API key is empty.');
        }

        $response = wp_remote_post(self::ENDPOINT, array(
            'timeout' => 60,
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => self::ANTHROPIC_VERSION,
            ),
            'body'    => wp_json_encode(array(
                'model'      => $api_model,
                'max_tokens' => 16,
                'messages'   => array(array('role' => 'user', 'content' => 'ping')),
            )),
        ));
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        $code    = wp_remote_retrieve_response_code($response);
        $body    = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if ($code !== 200) {
            $msg = isset($decoded['error']['message']) ? $decoded['error']['message'] : "HTTP {$code}";
            return array('success' => false, 'message' => 'API: ' . $msg);
        }
        if (!empty($decoded['content'][0]['text'])) {
            return array('success' => true, 'message' => 'API OK. Model: ' . $api_model);
        }
        return array('success' => false, 'message' => 'Unexpected API response shape.');
    }

    /**
     * Classify a single post. Returns a valid category slug (from
     * KB_Types + 'other') or WP_Error on API/CLI failure.
     */
    public static function classify($title, $content) {
        $template = get_option('zen_cortext_classify_prompt', '');
        if (!is_string($template) || trim($template) === '') {
            $template = Zen_Cortext_Defaults::classify_prompt();
        }
        // Substitute placeholders. <<categories>> is the data-driven
        // bullet list from the admin-managed content types option; this
        // replaces the formerly inline literal list of 5 categories.
        // Note: <<categories>> intentionally uses doubled angle brackets
        // (not {categories}) — strtr does dumb substring replace, and
        // angle-bracket pairs are unlikely to collide with admin-written
        // prompt text.
        $categories_block = class_exists('Zen_Cortext_KB_Types')
            ? Zen_Cortext_KB_Types::assemble_categories_block()
            : '- other: Anything that does not fit a category';
        $prompt = strtr($template, array(
            Zen_Cortext_KB_Types::PLACEHOLDER => $categories_block,
            '{title}'   => $title,
            '{content}' => (string) $content,
        ));

        $text = self::complete_text($prompt, array(
            'model'      => self::classify_model(),
            'max_tokens' => 32,
            'timeout'    => 90,
        ));
        if (is_wp_error($text)) {
            return new WP_Error('zen_cortext_api', $text->get_error_message());
        }

        $category = strtolower(str_replace(' ', '_', trim((string) $text)));
        $valid = class_exists('Zen_Cortext_KB_Types')
            ? Zen_Cortext_KB_Types::valid_for_classifier()
            : array('other');

        if (in_array($category, $valid, true)) {
            return $category;
        }
        // Longest-first ordered fuzzy match. With user-defined slugs we
        // can have prefix relationships (e.g. 'case' and 'case_study');
        // checking the longer slug first ensures the more specific type
        // wins. Save-time validation forbids true prefix collisions, but
        // a model returning extra text could still trigger ambiguity —
        // longest-first guarantees deterministic resolution.
        usort($valid, static function ($a, $b) { return strlen($b) - strlen($a); });
        foreach ($valid as $cat) {
            if ($cat !== '' && strpos($category, $cat) !== false) {
                return $cat;
            }
        }
        return 'other';
    }

    /**
     * Restructure a post into KB markdown using the prompt for its classification.
     *
     * Reads prompts from the admin-editable content types option via
     * KB_Types. If the row's classification refers to a slug the admin
     * has since deleted, returns WP_Error — the rebuild log will surface
     * it and the row can be reset to NULL classification to reprocess.
     */
    public static function restructure($title, $content, $classification) {
        $prompts = class_exists('Zen_Cortext_KB_Types')
            ? Zen_Cortext_KB_Types::restructure_prompts()
            : array();
        if (empty($prompts[$classification])) {
            // Fall back to the legacy option for back-compat / partial-migration
            // safety net. After migration this should rarely fire.
            $legacy = (array) get_option('zen_cortext_restructure_prompts', array());
            if (!empty($legacy[$classification])) {
                $prompts = $legacy;
            } else {
                $defaults = Zen_Cortext_Defaults::restructure_prompts();
                if (!empty($defaults[$classification])) {
                    $prompts = $defaults;
                }
            }
        }
        if (empty($prompts[$classification])) {
            return new WP_Error('zen_cortext_api', "No restructure prompt for classification '{$classification}'.");
        }

        $prompt = $prompts[$classification] . "\n\n---\n\nINPUT:\nTitle: " . $title . "\n\n" . $content;

        $text = self::complete_text($prompt, array(
            'model'      => self::model(),
            'max_tokens' => self::max_tokens(),
            'timeout'    => 180,
        ));
        if (is_wp_error($text)) {
            return new WP_Error('zen_cortext_api', $text->get_error_message());
        }

        $text = trim((string) $text);
        if ($text === '') {
            return new WP_Error('zen_cortext_api', 'Empty response from processor.');
        }
        return $text;
    }

    /**
     * Restructure a hand-authored Knowledge Artifact (free-form text) into
     * the markdown shape used by the chat context. Reads the per-type
     * restructure prompt from the unified content types option — the
     * same source the KB classifier-restructurer uses. Artifacts and
     * KB rows share one taxonomy maintained on the Knowledge Base tab.
     *
     * Returns markdown string or WP_Error.
     */
    public static function restructure_artifact($title, $type, $raw_content) {
        $prompts = class_exists('Zen_Cortext_KB_Types')
            ? Zen_Cortext_KB_Types::restructure_prompts()
            : array();
        if (empty($prompts[$type])) {
            return new WP_Error('zen_cortext_api', "No restructure prompt for type '{$type}'.");
        }
        $template = $prompts[$type];

        $prompt = $template . "\n\n---\n\nINPUT:\nTitle: " . $title . "\n\n" . $raw_content;

        $text = self::complete_text($prompt, array(
            'model'      => self::model(),
            'max_tokens' => self::max_tokens(),
            'timeout'    => 180,
        ));
        if (is_wp_error($text)) {
            return new WP_Error('zen_cortext_api', $text->get_error_message());
        }

        $text = trim((string) $text);
        if ($text === '') {
            return new WP_Error('zen_cortext_api', 'Empty response from processor.');
        }
        return $text;
    }

    /**
     * Synthesize a free-form draft body from a chat builder conversation.
     * Returns plain text — NOT yet restructured into the schema. The user
     * will review/edit it in the textarea, then save (which triggers
     * restructure_artifact() in the normal save flow).
     *
     * Returns string or WP_Error.
     */
    public static function synthesize_artifact_from_chat($messages, $type, $title, $reference_ids = array(), $exclude_id = 0) {
        if (!is_array($messages) || empty($messages)) {
            return new WP_Error('zen_cortext_api', 'No chat messages to synthesize.');
        }

        // Build a single user prompt that contains the full conversation transcript
        // and asks the model to extract a clean draft body.
        $transcript = '';
        foreach ($messages as $m) {
            if (empty($m['role']) || empty($m['content'])) continue;
            $role = $m['role'] === 'assistant' ? 'Assistant' : 'User';
            $transcript .= $role . ":\n" . trim((string) $m['content']) . "\n\n";
        }

        $type_label = ucwords(str_replace('_', ' ', $type));
        $prompt = "You are extracting a clean draft body for a Knowledge Artifact from an interview transcript.\n\n"
                . "Artifact type: {$type_label}\n"
                . "Artifact title: " . ($title !== '' ? $title : '(not specified — infer one)') . "\n\n"
                . "From the transcript below, extract the substantive facts the user provided and write a clean, organized draft of the artifact body. Preserve every concrete detail (names, numbers, versions, tools, dates, decisions, reasoning).\n\n"
                . "HARD RULES:\n"
                . "- Do NOT invent, embellish, or assume anything the user didn't explicitly say.\n"
                . "- Do NOT include the assistant's questions in the output.\n"
                . "- Do NOT add marketing language, encouragement, or filler.\n"
                . "- If the user said a fact doesn't exist, that they don't know it, or that they don't have the data — OMIT that field entirely. Do NOT paper over absence with vague phrases like 'improved performance', 'satisfied customer', 'significantly faster', or 'better results'. Absence is honest. Filler is dishonest and weakens every other claim.\n"
                . "- If a section of the typical schema for this artifact type has no real content, leave it out rather than write 'not specified' for everything.\n"
                . "- TECHNICAL DEBT vs DELIBERATE TRADE-OFFS: When the transcript mentions a missing practice (no CI, no tests, single DB, no staging, monolith, deploy via SSH, etc.), do NOT default to listing it as a weakness, gap, or debt. Distinguish: technical debt is something the engineer would actively change with more resources; deliberate trade-offs are choices the engineer made given context and would make again. Default to 'deliberate trade-off' when the project is solo-operated, the choice matches the overall pragmatic philosophy of the codebase, the missing practice would add overhead disproportionate to the project's scale, or the user gave a contextual reason. Only frame something as debt when the user explicitly said they would change it given the chance. Never write reflex textbook judgments like 'no CI = bad' or 'monolith = legacy' — frame deliberate choices as architectural decisions with the reasoning preserved.\n\n"
                . "Use plain prose with simple bullet lists where helpful — this is a draft for human review, not the final structured output.\n\n"
                . "Transcript:\n\n" . trim($transcript);

        // Append reference artifacts (read-only background) if any were selected.
        $reference_block = is_array($reference_ids) && !empty($reference_ids)
            ? Zen_Cortext_Artifacts::build_reference_block($reference_ids, (int) $exclude_id)
            : '';
        if ($reference_block !== '') {
            $prompt .= "\n\n" . $reference_block;
        }

        $text = self::complete_text($prompt, array(
            'model'      => self::model(),
            'max_tokens' => self::max_tokens(),
            'timeout'    => 180,
        ));
        if (is_wp_error($text)) {
            return new WP_Error('zen_cortext_api', $text->get_error_message());
        }

        $text = trim((string) $text);
        if ($text === '') {
            return new WP_Error('zen_cortext_api', 'Empty response from processor.');
        }
        return $text;
    }

    /**
     * Stream the artifact builder chat (admin-only). Twin of stream_chat() but
     * uses the builder system prompt and DOES NOT inject the KB context block —
     * the goal is to extract info from the user, not answer from KB.
     */
    public static function stream_artifact_chat($messages, $reference_ids = array(), $exclude_id = 0, $type = '', $title = '') {
        $system = get_option('zen_cortext_artifact_builder_prompt', Zen_Cortext_Defaults::artifact_builder_system_prompt());

        // Tell the model what the user already chose in the form so it doesn't
        // ask for type/title as "missing" data — the fields are visible in the
        // UI but aren't in the chat transcript, so without this injection the
        // model opens with "what type of artifact?" even when the type is set.
        $form_state = array();
        $type  = trim((string) $type);
        $title = trim((string) $title);
        if ($type !== '')  $form_state[] = 'type=' . $type;
        if ($title !== '') $form_state[] = 'title=' . $title;
        if (!empty($form_state)) {
            $system .= "\n\n# Current form state\n\n"
                     . "The user has already filled these fields in the form (do NOT ask about them):\n"
                     . "- " . implode("\n- ", $form_state) . "\n\n"
                     . "Use the `type` value to branch to the matching section of \"What's typically missing per artifact type\". If `title` is set, treat the artifact title as known and do not suggest alternatives unless the user asks.";
        }

        // Append reference artifacts (read-only background) if any were selected.
        if (is_array($reference_ids) && !empty($reference_ids)) {
            $system .= Zen_Cortext_Artifacts::build_reference_block($reference_ids, (int) $exclude_id);
        }

        $welcome       = get_option('zen_cortext_artifact_chat_welcome', Zen_Cortext_Defaults::artifact_chat_welcome_message());
        $full_messages = array(array('role' => 'assistant', 'content' => $welcome));
        foreach ($messages as $msg) {
            if (empty($msg['role']) || empty($msg['content'])) continue;
            $full_messages[] = array(
                'role'    => $msg['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => (string) $msg['content'],
            );
        }

        $on_event = function ($json) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $json is wp_json_encode output emitted as SSE; escaping would corrupt the SSE protocol.
            echo "data: " . $json . "\n\n";
            if (ob_get_level()) { @ob_flush(); }
            @flush();
        };
        self::stream_internal(
            $system,
            $full_messages,
            $on_event,
            array('timeout' => 180)
        );
    }

    /**
     * Build a "Lead already captured" block for the system prompt when
     * the visitor has already submitted the contact form in this chat.
     * Returns empty string when no lead is on record, or when $chat_uid
     * is empty (the anonymous one-shot path that never persists). Kept
     * in the uncached dynamic segment because it changes mid-session
     * on lead submission.
     */
    public static function build_lead_status_block($chat_uid) {
        $chat_uid = trim((string) $chat_uid);
        if ($chat_uid === '') return '';
        if (!class_exists('Zen_Cortext_Chats')) return '';
        $row = Zen_Cortext_Chats::get_by_uid($chat_uid);
        if (!is_array($row) || empty($row['lead_submitted_at'])) return '';

        $name     = isset($row['lead_name'])     ? trim((string) $row['lead_name'])     : '';
        $email    = isset($row['lead_email'])    ? trim((string) $row['lead_email'])    : '';
        $whatsapp = isset($row['lead_whatsapp']) ? trim((string) $row['lead_whatsapp']) : '';
        $when     = (string) $row['lead_submitted_at'];

        $pieces = array();
        if ($name !== '')     $pieces[] = 'Name: ' . $name;
        if ($email !== '')    $pieces[] = 'Email: ' . $email;
        if ($whatsapp !== '') $pieces[] = 'WhatsApp: ' . $whatsapp;
        $fields = empty($pieces) ? '(details on file)' : implode(' | ', $pieces);

        return "\n\n<lead_status>\n"
             . "The visitor has ALREADY submitted the contact form on " . $when . ".\n"
             . "Captured: " . $fields . "\n"
             . "\n"
             . "Rules for the rest of this conversation:\n"
             . "- Do NOT emit [contact_form] or [contact_form: Firstname] again — the form was already filled.\n"
             . "- You may still emit [invite: Firstname] if the visitor explicitly wants a live chat with a team member; if that person is offline, say so and confirm that the team already has their details on file and will follow up (do not ask them to fill a form).\n"
             . "- Reference them by their submitted first name where natural. The team has their email / WhatsApp already.\n"
             . "- If they thank you or wrap up, acknowledge warmly — no need to ask for contact info again.\n"
             . "</lead_status>";
    }

    /**
     * Build the active interview script block. Returns '' when no survey
     * is attached or the attached row is missing/disabled. The block
     * itself is rendered by Zen_Cortext_Survey_Parser::build_prompt_block()
     * so the grammar and the prompt instructions stay co-located with the
     * parser.
     */
    public static function build_survey_block($survey_id) {
        $survey_id = (int) $survey_id;
        if ($survey_id <= 0) return '';
        if (!class_exists('Zen_Cortext_Surveys')) return '';

        $parsed = Zen_Cortext_Surveys::get_parsed($survey_id);
        if (!is_array($parsed) || empty($parsed['questions'])) return '';

        return Zen_Cortext_Survey_Parser::build_prompt_block($parsed);
    }

    /**
     * Build a "Team Expertise" block for the system prompt from user profiles
     * and the optional zen_cortext_team_expertise override.
     */
    public static function build_team_expertise_block() {
        $invitable_ids = get_option('zen_cortext_invitable_users', array());
        if (!is_array($invitable_ids) || empty($invitable_ids)) return '';

        $expertise_overrides = get_option('zen_cortext_team_expertise', array());
        if (!is_array($expertise_overrides)) $expertise_overrides = array();

        $parts = array();
        foreach ($invitable_ids as $uid) {
            $uid  = (int) $uid;
            $user = get_userdata($uid);
            if (!$user) continue;

            $section = "### " . $user->display_name . "\n";

            // Role summary from user profile (zen-user-role mu-plugin).
            $role = function_exists('zen_get_user_role')
                ? zen_get_user_role($uid)
                : trim((string) get_user_meta($uid, 'zen_user_role', true));
            if ($role !== '') {
                $section .= "**Role:** " . $role . "\n\n";
            }

            // Bio from profile.
            $bio = trim(get_the_author_meta('description', $uid));
            if ($bio !== '') {
                $section .= $bio . "\n\n";
            }

            // Contact info.
            $email    = get_the_author_meta('author_email', $uid);
            if (empty($email)) $email = $user->user_email;
            $whatsapp = get_the_author_meta('author_whatsapp', $uid);
            $linkedin = get_the_author_meta('author_linkedin', $uid);

            $contacts = array();
            if ($email)    $contacts[] = "Email: " . $email;
            if ($whatsapp) $contacts[] = "WhatsApp: " . $whatsapp;
            if ($linkedin) $contacts[] = "LinkedIn: " . $linkedin;
            if (!empty($contacts)) {
                $section .= implode(" | ", $contacts) . "\n\n";
            }

            // Manual expertise override (from settings).
            $override = isset($expertise_overrides[$uid]) ? trim((string) $expertise_overrides[$uid]) : '';
            if ($override !== '') {
                $section .= "**Expertise notes:** " . $override . "\n";
            }

            $parts[] = $section;
        }

        if (empty($parts)) return '';

        return "\n\n## Team Members\n\n"
             . "These are the real team members who can join the chat via invite buttons. "
             . "Use their profiles to understand who is the best fit when a visitor's question "
             . "aligns with a specific domain. When you suggest inviting a team member, be specific "
             . "about why that person is relevant.\n\n"
             . implode("\n", $parts);
    }

    /**
     * Stream a chat completion to the current PHP output as SSE.
     * Caller is responsible for setting headers + ob_end_flush() before calling.
     *
     * If $chat_uid is provided, the conversation is upserted into wp_zen_cortext_chats
     * before streaming, the assistant's streamed response is accumulated server-side,
     * and the final messages array (incl. the assistant turn) is persisted after the
     * stream completes.
     */
    public static function stream_chat($messages, $chat_uid = '', $attribution = array(), $owner_token = '') {
        if (self::api_key() === '') {
            echo "data: " . wp_json_encode(array('type' => 'error', 'error' => 'API key not configured')) . "\n\n";
            return;
        }

        // Keep generating + persisting even if the visitor disconnects mid-
        // stream. Low-quality CPC / bot ad clicks routinely fire a prefilled
        // starter chip then bounce before Sonnet returns its first token.
        // Without this, the flush() to the dead socket aborted the script
        // BEFORE the post-stream persist/error block — leaving the chat with
        // only the user turn, no assistant answer, and no logged error.
        // ignore_user_abort keeps PHP alive past the disconnect so the answer
        // still streams to completion and lands in the DB (the visitor can
        // resume/replay it). We also raise max_execution_time so a long
        // generation isn't killed mid-stream — but only to a BOUNDED value
        // (not 0/unlimited) and only here, scoped to this one streaming
        // request, never as a global default. 180s comfortably covers the
        // upstream HTTP timeout (120s) plus the post-stream DB persist.
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- bounded (180s) lift of max_execution_time, scoped to this single Server-Sent Events streaming request only (not global/init/__construct); without it PHP kills the request mid-generation. Guarded by function_exists().
            @set_time_limit(180);
        }

        // Persist what we have so far (before streaming) so we capture even
        // sessions that bail out mid-response. owner_token is stored once,
        // on first insert, so handle_send() can refuse later writes from
        // anyone who doesn't have the original visitor's token.
        $chat_uid    = trim((string) $chat_uid);
        $owner_token = trim((string) $owner_token);
        if ($chat_uid !== '') {
            $upsert_data = array_merge(
                is_array($attribution) ? $attribution : array(),
                array(
                    'chat_uid'    => $chat_uid,
                    'messages'    => $messages,
                    'owner_token' => $owner_token,
                )
            );
            Zen_Cortext_Chats::upsert($upsert_data);
        }

        // Static blocks — same bytes on every request, so we mark the
        // combined content with a cache_control breakpoint. Anthropic's
        // ephemeral cache charges 25% extra on the write and 10% on
        // subsequent reads within a 5-minute TTL, which on a ~50K-token
        // system prompt breaks even after ~2 turns and cuts per-message
        // input cost to ~10% for the rest of the session.
        $static_system  = get_option('zen_cortext_system_prompt', Zen_Cortext_Defaults::system_prompt());
        $static_system .= Zen_Cortext_KB::build_context_block();
        $static_system .= Zen_Cortext_Artifacts::build_context_block();
        $static_system .= self::build_team_expertise_block();

        // Attribution varies per visitor (UTMs, campaign) so it is NOT
        // cached — it lives in its own uncached block AFTER the cache
        // breakpoint, keeping the model's freshest framing of WHO the
        // visitor is and WHAT campaign brought them here.
        $dynamic_system = Zen_Cortext_Attribution::build_system_block($attribution);

        // Active survey/interview block: matched-rule survey beats global
        // default. Pure prompt-only — no per-chat tracking column. The AI
        // uses conversation context to know which questions are still
        // open. Lives in the dynamic section so admin edits to the script
        // surface immediately on the next turn.
        $survey_id = (int) Zen_Cortext_Attribution::active_survey_id($attribution);
        if ($survey_id <= 0) {
            $survey_id = (int) get_option('zen_cortext_default_survey_id', 0);
        }
        $dynamic_system .= self::build_survey_block($survey_id);

        // Lead-status block: once a visitor submits the contact form,
        // tell the model so it stops emitting [contact_form] / [invite:]
        // markers on subsequent turns. Without this, the model keeps
        // offering to "bring Yury in" or "collect your details" even
        // after the form is already filled, because it has no stateful
        // awareness of prior submissions beyond the transcript.
        $dynamic_system .= self::build_lead_status_block($chat_uid);

        // Chip-usage rules: appended LAST so they are the model's freshest
        // framing every turn, regardless of whether attribution injected a
        // dense campaign brief above. Without this, attribution-matched
        // visitors got chip rules buried mid-static-prompt while the rule's
        // context_text was the most recent text — the model latched onto
        // the brief, dumped all chip equivalents in the opening reply,
        // then never emitted [chip] markers again. Keeping the rules in
        // the dynamic block (~150 tokens, uncached) trades a small per-
        // request cost for behavioral consistency across both modes.
        $dynamic_system .= Zen_Cortext_Defaults::chip_rules_block();

        $system = array(
            array(
                'type'          => 'text',
                'text'          => $static_system,
                'cache_control' => array('type' => 'ephemeral'),
            ),
        );
        if ($dynamic_system !== '') {
            $system[] = array('type' => 'text', 'text' => $dynamic_system);
        }

        // Inject welcome message at the start so the model has its own framing.
        // When a survey is active the visitor sees the survey's first question
        // as the welcome (per the attribution-context payload), so we mirror
        // that here — the model needs to see its own "previous turn" as Q1
        // or it'll re-ask question 1 on the visitor's reply.
        $welcome = get_option('zen_cortext_welcome_message', Zen_Cortext_Defaults::welcome_message());
        if ($survey_id > 0 && class_exists('Zen_Cortext_Surveys')) {
            $parsed = Zen_Cortext_Surveys::get_parsed($survey_id);
            if (is_array($parsed) && !empty($parsed['questions'][0]['text'])) {
                $welcome = (string) $parsed['questions'][0]['text'];
            }
        }
        $full_messages = array(array('role' => 'assistant', 'content' => $welcome));
        foreach ($messages as $msg) {
            if (empty($msg['role']) || empty($msg['content'])) continue;
            $full_messages[] = array(
                'role'    => $msg['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => substr((string) $msg['content'], 0, 10000),
            );
        }

        $payload = wp_json_encode(array(
            'model'      => self::model(),
            'max_tokens' => self::max_tokens(),
            'system'     => $system,
            'messages'   => $full_messages,
            'stream'     => true,
        ));

        // Buffer for parsing the assistant response server-side. We need this
        // even if $chat_uid is empty? No — only when we're persisting.
        $assistant_buffer = '';
        $sse_tail = '';

        // Captures an in-stream `error` event from a 200 response. Anthropic
        // can return HTTP 200 and then emit `event: error` (e.g. overloaded_error)
        // mid-stream instead of any text. The status-line + error_body checks
        // never see it (status is 200, the body isn't buffered), so we pull the
        // reason out here to feed the empty-stream failure branch below.
        $stream_error = '';

        // HTTP status + error-body buffering. The visitor stream is piped
        // straight through CURLOPT_WRITEFUNCTION — when Anthropic returns
        // a 4xx (insufficient credits, rate limit, invalid key, …) the
        // raw JSON error would otherwise echo into the chat as garbage.
        // We capture the status line in CURLOPT_HEADERFUNCTION, then gate
        // the echo in WRITEFUNCTION on status < 400.
        $response_status = 0;
        $error_body      = '';

        // Stream the Anthropic SSE response through the WordPress HTTP API.
        // The read callbacks are attached to the cURL handle via
        // http_api_curl (see http_stream_post) so each chunk reaches the
        // browser the instant it arrives.
        $streamed  = false;
        $header_cb = function ($handle, $header) use (&$response_status) {
            // Each response can have multiple status lines (redirects);
            // the last one wins. Parse "HTTP/1.1 429 Too Many Requests".
            if (preg_match('#^HTTP/\S+\s+(\d{3})\b#', $header, $m)) {
                $response_status = (int) $m[1];
            }
            return strlen($header);
        };
        $write_cb = function ($handle, $data) use (&$assistant_buffer, &$sse_tail, &$error_body, &$stream_error, &$response_status, &$streamed, $chat_uid) {
            $streamed = true;
            // 4xx/5xx: do NOT pass-through to the visitor. Buffer the
            // body so the post-request block can extract a clean message
            // and email the admin instead of leaking JSON to the chat.
            if ($response_status >= 400) {
                $error_body .= $data;
                return strlen($data);
            }

            // Happy path: pass straight to the client.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $data is a raw SSE chunk from Anthropic streamed through verbatim; escaping would corrupt the protocol.
            echo $data;
            if (ob_get_level()) { @ob_flush(); }
            @flush();

            // Server-side parse of the SSE stream so we can persist the
            // assistant's full text after it finishes. Only when we're
            // saving (chat_uid present) — otherwise it's wasted work.
            if ($chat_uid !== '') {
                $sse_tail .= $data;
                while (($pos = strpos($sse_tail, "\n")) !== false) {
                    $line = substr($sse_tail, 0, $pos);
                    $sse_tail = substr($sse_tail, $pos + 1);
                    $line = rtrim($line, "\r");
                    if (strpos($line, 'data: ') !== 0) continue;
                    $json = substr($line, 6);
                    if ($json === '' || $json === '[DONE]') continue;
                    $event = json_decode($json, true);
                    if (!is_array($event)) continue;
                    if (
                        isset($event['type']) && $event['type'] === 'content_block_delta' &&
                        isset($event['delta']['text'])
                    ) {
                        $assistant_buffer .= $event['delta']['text'];
                    } elseif (isset($event['type']) && $event['type'] === 'error') {
                        // 200 + in-stream error (overloaded_error, etc.).
                        $stream_error = isset($event['error']['message'])
                            ? (string) $event['error']['message']
                            : (isset($event['error']['type']) ? (string) $event['error']['type'] : 'unknown stream error');
                    }
                }
            }

            return strlen($data);
        };

        $response = self::http_stream_post($payload, 120, $header_cb, $write_cb);

        // Fallback: on hosts where WP did not use the cURL transport the
        // callbacks never fired — replay the buffered body through the same
        // handler so the visitor still gets the full reply (just not chunked).
        if (!is_wp_error($response) && !$streamed) {
            $response_status = (int) wp_remote_retrieve_response_code($response);
            $buffered = (string) wp_remote_retrieve_body($response);
            if ($buffered !== '') { $write_cb(null, $buffered); }
        }

        // Detect every failure path: transport (curl_errno), bad HTTP
        // status, or success that produced no assistant text. Each one
        // emits the same `service_unavailable` SSE event for the JS to
        // act on (replace bubble with fallback + auto-open lead form)
        // and triggers a throttled admin email so the team learns about
        // outages immediately. Without this, errors used to either echo
        // raw JSON to the chat or silently produce an empty bubble.
        $service_error_msg = '';
        if (is_wp_error($response) && !$streamed) {
            // A genuine transport failure: we couldn't connect / nothing was
            // streamed. (When the stream DID run, the WP HTTP API's Requests
            // layer can still return a WP_Error like "Missing header/body
            // separator" — because our CURLOPT_WRITEFUNCTION already consumed
            // the body, leaving Requests nothing to re-parse. That is NOT a
            // failure: the visitor got the full streamed answer, so we ignore
            // it and fall through to the status / empty-text checks below.)
            $service_error_msg = 'transport: ' . $response->get_error_message();
        } elseif ($response_status >= 400) {
            $service_error_msg = 'Anthropic ' . $response_status . ': ' . self::extract_anthropic_error_message($error_body, $response_status);
        } elseif ($chat_uid !== '' && $assistant_buffer === '') {
            // HTTP 200 (or no status at all) but zero assistant text. Anthropic
            // delivers some failures — overloaded_error, an in-stream `error`
            // event, an empty stream — as a 200 whose body carries no
            // content_block_delta. The two checks above miss it entirely, so
            // it used to leave a silent dead bubble: no fallback, no admin
            // email, no log, nothing persisted. Surface it like any other
            // outage. Prefer the in-stream `error` event reason captured during
            // parsing; on a truly empty 200 fall back to a generic message.
            $reason = $stream_error !== '' ? $stream_error : 'no assistant text in response';
            $service_error_msg = 'empty stream (HTTP ' . ($response_status ?: 200) . '): ' . $reason;
        }

        if ($service_error_msg !== '') {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic only; gated on operational error paths to land in the WP debug.log when WP_DEBUG_LOG is on.
            error_log('Zen Cortext stream_chat error — ' . $service_error_msg);
            self::notify_admin_ai_error(array(
                'http_status' => $response_status,
                'error'       => $service_error_msg,
                'raw_body'    => $error_body,
                'model'       => self::model(),
                'chat_uid'    => $chat_uid,
                'last_user'   => self::last_user_message($messages),
            ));
            echo "data: " . wp_json_encode(array(
                'type'    => 'service_unavailable',
                'error'   => $service_error_msg, // for client-side logging only; the visitor sees `message`
                'message' => __('The AI consultant is currently unavailable. Leave your contact below and our team will follow up shortly.', 'zen-cortext'),
            )) . "\n\n";
            @flush();
        }

        // After streaming: persist the full conversation including the
        // assistant's response so the saved chat is complete even if the
        // visitor bails before sending another message.
        if ($chat_uid !== '' && $assistant_buffer !== '') {
            $final_messages = array();
            foreach ($messages as $msg) {
                if (empty($msg['role']) || empty($msg['content'])) continue;
                $role  = $msg['role'] === 'assistant' ? 'assistant' : 'user';
                $entry = array(
                    'role'    => $role,
                    'content' => (string) $msg['content'],
                );
                if ($role === 'user' && !empty($msg['enrichment']) && is_array($msg['enrichment'])) {
                    $entry['enrichment'] = $msg['enrichment'];
                }
                $final_messages[] = $entry;
            }
            $final_messages[] = array('role' => 'assistant', 'content' => $assistant_buffer);
            Zen_Cortext_Chats::set_messages_by_uid($chat_uid, $final_messages);
        }
    }

    /**
     * POST $payload to the Anthropic streaming endpoint through the
     * WordPress HTTP API (wp_remote_post). To consume the SSE body live —
     * chunk-by-chunk as it arrives — we attach $header_cb / $write_cb to the
     * underlying cURL handle via the http_api_curl action, which fires right
     * before curl_exec() so our setopt wins over WP_Http_Curl's own buffering
     * callbacks. (A cURL handle is an object/resource, so the handle passed
     * to the action points at the same session — no by-reference needed.)
     *
     * Returns the wp_remote_post() result (array on success, WP_Error on a
     * transport failure). On hosts where WP is NOT using the cURL transport
     * the callbacks never fire; the caller detects that (its own "streamed"
     * flag stays false) and replays wp_remote_retrieve_body() through the
     * same handler — correct, just not chunked.
     *
     * @param string   $payload   JSON request body.
     * @param int      $timeout   Request timeout in seconds.
     * @param callable $header_cb function($handle, $header_line): int
     * @param callable $write_cb  function($handle, $chunk): int
     * @return array|WP_Error
     */
    private static function http_stream_post($payload, $timeout, $header_cb, $write_cb) {
        $attach = function ($handle) use ($header_cb, $write_cb) {
            if (function_exists('curl_setopt')) {
                curl_setopt($handle, CURLOPT_HEADERFUNCTION, $header_cb);
                curl_setopt($handle, CURLOPT_WRITEFUNCTION, $write_cb);
            }
        };
        add_action('http_api_curl', $attach);
        try {
            $response = wp_remote_post(self::ENDPOINT, array(
                'method'      => 'POST',
                'httpversion' => '1.1',
                'timeout'     => (int) $timeout,
                'blocking'    => true,
                'headers'     => array(
                    'Content-Type'      => 'application/json',
                    'x-api-key'         => self::api_key(),
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                ),
                'body'        => $payload,
            ));
        } finally {
            remove_action('http_api_curl', $attach);
        }
        return $response;
    }

    /**
     * Stream a chat through the Anthropic API and forward each Anthropic
     * SSE event to the caller-provided $on_event callback (which prepends
     * "data: " and "\n\n" before flushing to the browser).
     *
     * Same signature shape as stream_chat_via_cli() so the two are swappable
     * via the zen_cortext_processor setting.
     *
     * $opts:
     *   - model           (string, default self::model())
     *   - max_tokens      (int,    default self::max_tokens())
     *   - timeout         (int seconds, default 120)
     *   - thinking_budget (int, optional — when set, enables extended thinking with this budget;
     *                      caller MUST ensure max_tokens > thinking_budget)
     *   - cache_static    (bool, default false — when true, system is wrapped as
     *                      a single typed text block with cache_control: ephemeral)
     *
     * Returns ['ok' => bool, 'text' => string, 'stderr' => '' (always; for parity)].
     */
    public static function stream_chat_via_api($system_prompt, $messages, $on_event, $opts = array()) {
        if (self::api_key() === '') {
            $on_event(wp_json_encode(array('type' => 'error', 'error' => 'API key not configured.')));
            return array('ok' => false, 'text' => '', 'stderr' => '');
        }

        $model           = isset($opts['model']) ? (string) $opts['model'] : self::model();
        $max_tokens      = isset($opts['max_tokens']) ? (int) $opts['max_tokens'] : self::max_tokens();
        $timeout         = isset($opts['timeout']) ? (int) $opts['timeout'] : 120;
        $thinking_budget = isset($opts['thinking_budget']) ? (int) $opts['thinking_budget'] : 0;
        $cache_static    = !empty($opts['cache_static']);

        $body = array(
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'system'     => $cache_static ? array(
                array(
                    'type'          => 'text',
                    'text'          => (string) $system_prompt,
                    'cache_control' => array('type' => 'ephemeral'),
                ),
            ) : (string) $system_prompt,
            'messages'   => $messages,
            'stream'     => true,
        );
        if ($thinking_budget > 0) {
            $body['thinking'] = array(
                'type'          => 'enabled',
                'budget_tokens' => $thinking_budget,
            );
        }
        $payload = wp_json_encode($body);

        $response_status  = 0;
        $error_body       = '';
        $assistant_buffer = '';
        $sse_tail         = '';

        // Stream via the WordPress HTTP API; read callbacks are attached to
        // the cURL handle through http_api_curl (see http_stream_post).
        $streamed  = false;
        $header_cb = function ($handle, $header) use (&$response_status) {
            if ($response_status === 0 && preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                $response_status = (int) $m[1];
            }
            return strlen($header);
        };
        $write_cb = function ($handle, $data) use (&$response_status, &$error_body, &$assistant_buffer, &$sse_tail, &$streamed, $on_event) {
            $streamed = true;
            if ($response_status >= 400) {
                // Buffer the JSON error body and surface it cleanly afterwards.
                $error_body .= $data;
                return strlen($data);
            }
            // Forward each complete SSE "data:" line to the callback in
            // the same shape stream_chat_via_cli emits, so handlers don't
            // have to care which path served them.
            $sse_tail .= $data;
            while (($pos = strpos($sse_tail, "\n")) !== false) {
                $line     = substr($sse_tail, 0, $pos);
                $sse_tail = substr($sse_tail, $pos + 1);
                $line     = rtrim($line, "\r");
                if (strpos($line, 'data: ') !== 0) continue;
                $json = substr($line, 6);
                if ($json === '' || $json === '[DONE]') continue;
                $event = json_decode($json, true);
                if (!is_array($event)) continue;
                $on_event(wp_json_encode($event));
                if (
                    isset($event['type'], $event['delta']['type'], $event['delta']['text']) &&
                    $event['type']         === 'content_block_delta' &&
                    $event['delta']['type'] === 'text_delta'
                ) {
                    $assistant_buffer .= $event['delta']['text'];
                }
            }
            return strlen($data);
        };

        $response = self::http_stream_post($payload, $timeout, $header_cb, $write_cb);

        // Fallback for hosts not using the cURL transport: replay the
        // buffered body through the same handler.
        if (!is_wp_error($response) && !$streamed) {
            $response_status = (int) wp_remote_retrieve_response_code($response);
            $buffered = (string) wp_remote_retrieve_body($response);
            if ($buffered !== '') { $write_cb(null, $buffered); }
        }

        if (is_wp_error($response) && !$streamed) {
            // Genuine transport failure (nothing streamed). A WP_Error AFTER a
            // successful stream is just Requests failing to re-parse the body
            // our CURLOPT_WRITEFUNCTION already consumed — not a real error.
            $on_event(wp_json_encode(array('type' => 'error', 'error' => $response->get_error_message())));
        } elseif ($response_status >= 400) {
            $msg = self::extract_anthropic_error_message($error_body, $response_status);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic only; gated on operational error paths to land in the WP debug.log when WP_DEBUG_LOG is on.
            error_log('Zen Cortext API stream: HTTP ' . $response_status . ' ' . $msg);
            $on_event(wp_json_encode(array(
                'type'  => 'error',
                'error' => 'Anthropic ' . $response_status . ': ' . $msg,
            )));
        }

        return array(
            'ok'     => $assistant_buffer !== '',
            'text'   => $assistant_buffer,
            'stderr' => '',
        );
    }

    /**
     * Pull a human-readable message out of an Anthropic JSON error body.
     */
    private static function extract_anthropic_error_message($body, $status) {
        $body = trim((string) $body);
        if ($body === '') return 'no response body (HTTP ' . (int) $status . ')';
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            if (isset($decoded['error']['message'])) return (string) $decoded['error']['message'];
            if (isset($decoded['error']['type']))    return (string) $decoded['error']['type'];
        }
        return substr($body, 0, 300);
    }

    /**
     * Pull the last user message out of a messages array — used in the
     * admin error email so the team can see what the visitor was asking
     * when the AI failed. Returns an empty string if no user turn found.
     */
    private static function last_user_message($messages) {
        if (!is_array($messages)) return '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $m = $messages[$i];
            if (!empty($m['role']) && $m['role'] === 'user' && !empty($m['content'])) {
                return substr((string) $m['content'], 0, 2000);
            }
        }
        return '';
    }

    /**
     * Notify the admin team when the AI consultant fails — billing /
     * rate-limit / API outage / bad key / transport. Throttled to one
     * email per ERROR_EMAIL_THROTTLE_SEC so a sustained outage doesn't
     * spam the team inbox. Recipients come from zen_cortext_invitable_users
     * (the same pool that receives lead notifications); falls back to
     * the site's admin_email if no invitable users are configured.
     *
     * $payload keys:
     *   http_status, error, raw_body, model, chat_uid, last_user
     */
    public static function notify_admin_ai_error($payload) {
        $throttle = (int) apply_filters('zen_cortext_ai_error_email_throttle_sec', 1800);
        $last_at  = (int) get_transient('zen_cortext_ai_error_email_at');
        if ($last_at > 0 && (time() - $last_at) < $throttle) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic only; gated on operational error paths to land in the WP debug.log when WP_DEBUG_LOG is on.
            error_log('Zen Cortext: AI error email throttled (last sent ' . (time() - $last_at) . 's ago)');
            return false;
        }

        $recipients = self::ai_error_email_recipients();
        if (!$recipients) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic only; gated on operational error paths to land in the WP debug.log when WP_DEBUG_LOG is on.
            error_log('Zen Cortext: AI error — no admin recipients configured, email skipped.');
            return false;
        }

        $status     = isset($payload['http_status']) ? (int) $payload['http_status'] : 0;
        $err        = isset($payload['error'])       ? (string) $payload['error']    : '';
        $raw_body   = isset($payload['raw_body'])    ? (string) $payload['raw_body'] : '';
        $model      = isset($payload['model'])       ? (string) $payload['model']    : '';
        $chat_uid   = isset($payload['chat_uid'])    ? (string) $payload['chat_uid'] : '';
        $last_user  = isset($payload['last_user'])   ? (string) $payload['last_user']: '';

        $site_name  = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $short_err  = $status > 0 ? ($status . ' ' . self::http_status_label($status)) : 'transport failure';
        $subject    = '[' . $site_name . '] AI consultant error — ' . $short_err;

        $chat_link = '';
        if ($chat_uid !== '') {
            $chat_link = admin_url('admin.php?page=zen-cortext-chats&chat=' . rawurlencode($chat_uid));
        }

        $rows = array(
            'When'         => wp_date('Y-m-d H:i:s T'),
            'HTTP status'  => $status > 0 ? (string) $status : '— (transport-level error)',
            'Error'        => $err,
            'Model'        => $model,
            'Chat UID'     => $chat_uid !== '' ? $chat_uid : '—',
            'Last visitor message' => $last_user !== '' ? $last_user : '—',
        );

        $body  = '<p>The AI consultant returned an error while serving a visitor. The visitor was shown a "service unavailable" message and an inline contact form so the conversation continues even while this is broken.</p>';
        $body .= '<h3 style="margin-bottom:6px;">Error details</h3>';
        $body .= '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#ddd;font-family:system-ui,sans-serif;font-size:14px;">';
        foreach ($rows as $k => $v) {
            $body .= '<tr><th align="left" style="background:#f6f6f6;width:160px;">' . esc_html($k) . '</th>'
                  .  '<td style="word-break:break-word;">' . esc_html((string) $v) . '</td></tr>';
        }
        $body .= '</table>';

        if ($raw_body !== '') {
            $body .= '<h3 style="margin-top:18px;margin-bottom:6px;">Raw response body (truncated)</h3>';
            $body .= '<pre style="background:#f6f6f6;border:1px solid #ddd;padding:10px;white-space:pre-wrap;word-break:break-word;font-size:12px;">'
                  .  esc_html(substr($raw_body, 0, 4000))
                  .  '</pre>';
        }

        $body .= '<h3 style="margin-top:18px;margin-bottom:6px;">Common causes</h3>';
        $body .= '<ul>';
        $body .= '<li><b>401 / authentication_error</b> — API key invalid or revoked. Reissue at console.anthropic.com.</li>';
        $body .= '<li><b>402 / billing</b> — workspace out of credit. Top up the Anthropic balance.</li>';
        $body .= '<li><b>429 / rate_limit_error</b> — hitting org or model rate limits. Wait or request a limit increase.</li>';
        $body .= '<li><b>500 / 529 / overloaded_error</b> — Anthropic-side outage. Usually transient; check status.anthropic.com.</li>';
        $body .= '<li><b>Transport error</b> — server can\'t reach api.anthropic.com (DNS, firewall, TLS).</li>';
        $body .= '</ul>';

        if ($chat_link !== '') {
            $body .= '<p style="margin-top:18px;"><a href="' . esc_url($chat_link) . '">Open this chat in the admin</a></p>';
        }
        $body .= '<p style="color:#777;font-size:12px;margin-top:18px;">Further error emails are throttled to one per ' . (int) $throttle . ' seconds.</p>';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>',
        );

        $sent_any = false;
        foreach ($recipients as $to) {
            if (wp_mail($to, $subject, $body, $headers)) $sent_any = true;
        }
        if ($sent_any) {
            set_transient('zen_cortext_ai_error_email_at', time(), $throttle);
        }
        return $sent_any;
    }

    /**
     * Build the recipient list for AI-error emails. Prefers the team
     * members in zen_cortext_invitable_users (same pool the lead form
     * notifies); falls back to the site admin_email if the team list
     * is empty so a fresh install isn't silent.
     */
    private static function ai_error_email_recipients() {
        $emails = array();
        $ids = (array) get_option('zen_cortext_invitable_users', array());
        foreach ($ids as $uid) {
            $u = get_userdata((int) $uid);
            if ($u && !empty($u->user_email) && is_email($u->user_email)) {
                $emails[$u->user_email] = true;
            }
        }
        if (!$emails) {
            $fallback = (string) get_option('admin_email', '');
            if (is_email($fallback)) $emails[$fallback] = true;
        }
        return array_keys($emails);
    }

    /** Short human label for the HTTP statuses Anthropic returns. */
    private static function http_status_label($status) {
        $map = array(
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            408 => 'Request Timeout',
            413 => 'Payload Too Large',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            529 => 'Overloaded',
        );
        return isset($map[$status]) ? $map[$status] : 'HTTP error';
    }

    /**
     * Non-streaming POST helper. Returns decoded array on success or WP_Error.
     */
    private static function request_json($body) {
        $response = wp_remote_post(self::ENDPOINT, array(
            'timeout' => 120,
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => self::api_key(),
                'anthropic-version' => self::ANTHROPIC_VERSION,
            ),
            'body'    => wp_json_encode($body),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_str = wp_remote_retrieve_body($response);
        $decoded = json_decode($body_str, true);

        if ($code !== 200) {
            $msg = isset($decoded['error']['message']) ? $decoded['error']['message'] : "HTTP {$code}";
            return new WP_Error('zen_cortext_api', $msg);
        }
        return $decoded;
    }
}
