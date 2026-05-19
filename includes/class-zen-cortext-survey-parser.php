<?php
/**
 * Survey/Interview script parser.
 *
 * Translates the admin-friendly multi-line block format into a structured
 * questions array for AI prompt assembly, and back. The grammar is forgiving:
 *
 *   INTRO: One or more lines describing what the AI should ask about.
 *   Subsequent intro lines until the first blank line are part of the intro.
 *
 *   1. First question text [optional flag: [multi] or [open]]
 *   - option A
 *   - option B
 *
 *   2. Next question (multi-select)? [multi]
 *   - choice 1
 *   - choice 2
 *
 *   3. Open-ended question with no options.
 *
 * Rules:
 *   - A question with zero option lines is implicitly type "open".
 *   - A question with options defaults to "single" unless marked [multi].
 *   - Question IDs are auto-assigned q1, q2, … for use in the AI's option
 *     marker emit.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_Survey_Parser {

    const TYPE_SINGLE = 'single';
    const TYPE_MULTI  = 'multi';
    const TYPE_OPEN   = 'open';

    /**
     * Parse the admin script into a structured array.
     *
     * Returns:
     *   array(
     *     'intro'     => string,
     *     'questions' => array(
     *       array('id' => 'q1', 'text' => '...', 'type' => 'single'|'multi'|'open', 'options' => array(...)),
     *       ...
     *     ),
     *   )
     *
     * On a fatal grammar problem (no INTRO:, malformed flag) returns a
     * WP_Error so the save flow can reject. Whitespace and stray blank
     * lines are tolerated silently.
     */
    public static function parse($script) {
        $script = (string) $script;
        // Normalize newlines.
        $script = preg_replace('/\r\n|\r/', "\n", $script);

        $lines = explode("\n", $script);
        $intro_parts = array();
        $questions = array();

        $mode = 'seek_intro'; // seek_intro | intro | between | question
        $current = null;
        $found_intro = false;

        foreach ($lines as $raw_line) {
            $line = $raw_line;
            // Trailing whitespace doesn't carry meaning.
            $line = rtrim($line);

            $trimmed = ltrim($line);

            // Recognize INTRO: header (optionally with text on the same line).
            if (preg_match('/^INTRO:\s*(.*)$/i', $trimmed, $m)) {
                if ($found_intro) {
                    return new WP_Error('zen_cortext_survey_parser', 'Only one INTRO: header is allowed.');
                }
                $found_intro = true;
                $first = trim($m[1]);
                if ($first !== '') {
                    $intro_parts[] = $first;
                }
                $mode = 'intro';
                continue;
            }

            $is_blank = ($trimmed === '');

            // Recognize a question starter: "1.", "12.", etc.
            if (!$is_blank && preg_match('/^(\d+)\.\s*(.*)$/', $trimmed, $m)) {
                if (!$found_intro) {
                    return new WP_Error('zen_cortext_survey_parser', 'Survey must begin with an INTRO: header before the first question.');
                }
                // Close previous question.
                if ($current !== null) {
                    $questions[] = self::finalize_question($current);
                }
                $rest = trim($m[2]);
                $type = self::TYPE_SINGLE; // tentative — flips to OPEN if no options collected
                $type_explicit = false;

                // Strip a trailing flag like [multi] / [open].
                if (preg_match('/^(.*?)\s*\[(multi|open|single)\]\s*$/i', $rest, $fm)) {
                    $rest = trim($fm[1]);
                    $flag = strtolower($fm[2]);
                    if ($flag === 'multi')  { $type = self::TYPE_MULTI;  $type_explicit = true; }
                    if ($flag === 'open')   { $type = self::TYPE_OPEN;   $type_explicit = true; }
                    if ($flag === 'single') { $type = self::TYPE_SINGLE; $type_explicit = true; }
                }

                if ($rest === '') {
                    return new WP_Error('zen_cortext_survey_parser', 'Question ' . $m[1] . ' has no text.');
                }

                $current = array(
                    'id'            => 'q' . (count($questions) + 1),
                    'text'          => $rest,
                    'type'          => $type,
                    'type_explicit' => $type_explicit,
                    'options'       => array(),
                );
                $mode = 'question';
                continue;
            }

            // Recognize an option line: "- something".
            if (!$is_blank && preg_match('/^-\s+(.+)$/', $trimmed, $m)) {
                if ($current === null) {
                    // Stray option outside a question — ignore but don't fail.
                    continue;
                }
                $opt = trim($m[1]);
                if ($opt !== '') {
                    $current['options'][] = $opt;
                }
                continue;
            }

            // Otherwise — depending on mode it's intro continuation or filler.
            if ($mode === 'intro' && !$is_blank) {
                $intro_parts[] = $trimmed;
                continue;
            }

            if ($is_blank) {
                if ($mode === 'intro') {
                    // First blank closes the intro paragraph; subsequent blanks
                    // are just spacing.
                    $mode = 'between';
                }
                continue;
            }

            // Free-floating non-blank text outside intro/question — tolerate
            // by appending to current question's text continuation, or skip.
            if ($mode === 'question' && $current !== null && empty($current['options'])) {
                // Multi-line question text continuation.
                $current['text'] .= ' ' . $trimmed;
                continue;
            }
            // Otherwise silently skip stray text.
        }

        if ($current !== null) {
            $questions[] = self::finalize_question($current);
        }

        if (!$found_intro) {
            return new WP_Error('zen_cortext_survey_parser', 'Survey is missing the INTRO: header.');
        }

        if (empty($questions)) {
            return new WP_Error('zen_cortext_survey_parser', 'Survey must contain at least one question.');
        }

        return array(
            'intro'     => trim(implode("\n", $intro_parts)),
            'questions' => $questions,
        );
    }

    /**
     * Round-trip a parsed structure back into editor source. Used after
     * load to re-render the textarea in canonical form.
     */
    public static function format($parsed) {
        if (!is_array($parsed)) return '';
        $intro = isset($parsed['intro']) ? (string) $parsed['intro'] : '';
        $questions = isset($parsed['questions']) && is_array($parsed['questions'])
            ? $parsed['questions'] : array();

        $out = array();
        $out[] = 'INTRO: ' . $intro;
        $out[] = '';

        $i = 1;
        foreach ($questions as $q) {
            if (!is_array($q)) continue;
            $text    = isset($q['text']) ? trim((string) $q['text']) : '';
            $type    = isset($q['type']) ? (string) $q['type'] : self::TYPE_SINGLE;
            $options = isset($q['options']) && is_array($q['options']) ? $q['options'] : array();
            if ($text === '') continue;

            $line = $i . '. ' . $text;
            if ($type === self::TYPE_MULTI)  $line .= ' [multi]';
            if ($type === self::TYPE_OPEN && !empty($options)) {
                // Edge case: explicit open with options — preserve flag so
                // reparse keeps the type.
                $line .= ' [open]';
            }
            $out[] = $line;
            foreach ($options as $opt) {
                $opt = trim((string) $opt);
                if ($opt === '') continue;
                $out[] = '- ' . $opt;
            }
            $out[] = '';
            $i++;
        }

        return rtrim(implode("\n", $out)) . "\n";
    }

    /**
     * Build the AI prompt block from a parsed survey. Reads the admin-
     * editable framing template from the zen_cortext_survey_prompt_template
     * option (so the topic-override clause can be tuned per site) and
     * substitutes three placeholders with per-survey content:
     *
     *   {intro}     — the survey's intro paragraph
     *   {questions} — the rendered question list
     *   {outcome}   — the survey's outcome_instructions
     *
     * Empty pieces are substituted as empty strings — admins can drop
     * any section by removing its placeholder from the template.
     */
    public static function build_prompt_block($parsed) {
        if (!is_array($parsed)) return '';
        $questions = isset($parsed['questions']) && is_array($parsed['questions'])
            ? $parsed['questions'] : array();
        if (empty($questions)) return '';

        $template = '';
        if (function_exists('get_option')) {
            $template = (string) get_option('zen_cortext_survey_prompt_template', '');
        }
        if (trim($template) === '' && class_exists('Zen_Cortext_Defaults')) {
            $template = Zen_Cortext_Defaults::survey_prompt_template();
        }
        if (trim($template) === '') return '';

        $intro = isset($parsed['intro']) ? trim((string) $parsed['intro']) : '';
        $outcome = isset($parsed['outcome_instructions'])
            ? trim((string) $parsed['outcome_instructions'])
            : '';

        $rendered_qs = self::render_questions_for_prompt($questions);

        $out = strtr($template, array(
            '{intro}'     => $intro,
            '{questions}' => $rendered_qs,
            '{outcome}'   => $outcome,
        ));

        return "\n\n" . trim($out);
    }

    /**
     * Render the questions array as the multi-line block that gets pasted
     * into the {questions} placeholder. Same shape as the previous hard-
     * coded version: number, text, type, suggested options + the
     * [survey_options:] emit rule for selectable questions.
     */
    private static function render_questions_for_prompt($questions) {
        $lines = array();
        $i = 1;
        foreach ($questions as $q) {
            if (!is_array($q)) continue;
            $text    = isset($q['text']) ? trim((string) $q['text']) : '';
            $type    = isset($q['type']) ? (string) $q['type'] : self::TYPE_OPEN;
            $options = isset($q['options']) && is_array($q['options']) ? $q['options'] : array();
            if ($text === '') continue;

            if ($lines) $lines[] = '';
            $lines[] = $i . '. ' . $text;

            if (!empty($options) && $type !== self::TYPE_OPEN) {
                $opt_str = implode(' | ', array_map('trim', $options));
                $marker  = ($type === self::TYPE_MULTI)
                    ? '[survey_options:multi: ' . $opt_str . ']'
                    : '[survey_options: ' . $opt_str . ']';
                if ($type === self::TYPE_MULTI) {
                    $lines[] = '   Type: multi-select (visitor may pick multiple).';
                } else {
                    $lines[] = '   Type: single-select (one answer expected).';
                }
                $lines[] = '   Suggested options: ' . $opt_str;
                $lines[] = '   When you ask this, append on its OWN line at the end of your message:';
                $lines[] = '     ' . $marker;
                if ($type === self::TYPE_MULTI) {
                    $lines[] = '   The chat UI will render those as toggle chips with a "Done" button — the visitor';
                    $lines[] = '   may select any number of them. They may also type a free-text answer instead.';
                } else {
                    $lines[] = '   The chat UI will turn that marker into clickable suggestion chips. The visitor';
                    $lines[] = '   may still type any free-text answer instead.';
                }
            } else {
                $lines[] = '   Type: open-ended (free-text answer, no options).';
            }
            $i++;
        }
        return implode("\n", $lines);
    }

    private static function finalize_question($q) {
        // Auto-derive type for questions that didn't explicitly declare one:
        // no options → open; otherwise leave as the tentative single/multi.
        $explicit = !empty($q['type_explicit']);
        if (!$explicit && empty($q['options'])) {
            $q['type'] = self::TYPE_OPEN;
        }
        unset($q['type_explicit']);
        return $q;
    }
}
