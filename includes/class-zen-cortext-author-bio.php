<?php
/**
 * Zen Cortext — Author Bio (absorbed from the zen-author-bio mu-plugin).
 *
 * Provides the [zen_cortext_author_bio] + [zen_cortext_author_posts_heading] shortcodes
 * and the `the_content` filter that auto-styles inline "Author: Name"
 * lines in single posts. Pulls per-user data from the same custom user
 * meta keys (zen_user_avatar, author_email, author_whatsapp,
 * author_linkedin) that the chat-page side rail reads — staying on the
 * same data shape avoids any migration when the mu-plugin is retired.
 *
 * The mu-plugin file (wp-content/mu-plugins/zen-author-bio.php) is
 * disabled when this class loads — keeping both active would
 * double-register the shortcodes and content filter.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zen_Cortext_Author_Bio {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Canonical, fully-prefixed shortcode tags. (The short-prefixed
        // [zen_author_*] aliases were removed in 2.39.19 for WordPress.org
        // prefix compliance — every public shortcode now carries the full
        // zen_cortext_ prefix.)
        add_shortcode('zen_cortext_author_bio',           array(__CLASS__, 'shortcode_bio'));
        add_shortcode('zen_cortext_author_posts_heading', array(__CLASS__, 'shortcode_heading'));
        add_filter('the_content',                  array(__CLASS__, 'filter_content'), 20);
        // Author-bio + inline post-author card styles/JS go through the
        // enqueue pipeline (author-bio.css / author-bio.js) instead of
        // inline <style>/<script> blocks.
        add_action('wp_enqueue_scripts',           array(__CLASS__, 'register_assets'));
    }

    /**
     * Register and (where relevant) enqueue the author-bio assets. The
     * inline post-author card can appear on any single post, and the
     * [zen_cortext_author_bio] card appears on author archives — enqueue on both.
     */
    public static function register_assets() {
        wp_register_style(
            'zen-cortext-author-bio',
            ZEN_CORTEXT_PLUGIN_URL . 'public/assets/author-bio.css',
            array(),
            ZEN_CORTEXT_VERSION
        );
        wp_register_script(
            'zen-cortext-author-bio',
            ZEN_CORTEXT_PLUGIN_URL . 'public/assets/author-bio.js',
            array(),
            ZEN_CORTEXT_VERSION,
            true
        );
        if (is_singular('post') || is_author()) {
            wp_enqueue_style('zen-cortext-author-bio');
            wp_enqueue_script('zen-cortext-author-bio');
        }
    }

    /**
     * [zen_cortext_author_bio] — 2-column layout: left (photo, name, contacts), right (bio).
     * Only renders on author archive pages so it's safe to drop into a
     * shared author template without leaking onto unrelated pages.
     */
    public static function shortcode_bio() {
        if (!is_author()) {
            return '';
        }

        $author    = get_queried_object();
        $author_id = $author->ID;
        $name      = get_the_author_meta('display_name', $author_id);
        $role      = function_exists('zen_get_user_role') ? zen_get_user_role($author_id) : '';
        $bio       = get_the_author_meta('description', $author_id);

        // Custom photo from zen-user-avatar plugin, fallback to gravatar.
        $avatar_att = (int) get_user_meta($author_id, 'zen_user_avatar', true);
        if ($avatar_att && ($avatar_url = wp_get_attachment_image_url($avatar_att, 'medium'))) {
            $avatar = '<img src="' . esc_url($avatar_url) . '" alt="' . esc_attr($name) . '" class="zen-ab-avatar-img" width="180" height="180" />';
        } else {
            $avatar = get_avatar(get_the_author_meta('email', $author_id), 180, '', $name, array('class' => 'zen-ab-avatar-img'));
        }

        // Contact fields. Falls back to the WP account email when the
        // custom author_email field is empty.
        $email = get_the_author_meta('author_email', $author_id);
        if (empty($email)) {
            $email = get_the_author_meta('user_email', $author_id);
        }
        $whatsapp = get_the_author_meta('author_whatsapp', $author_id);
        $linkedin = get_the_author_meta('author_linkedin', $author_id);

        // Format bio — autoparagraph + linkify any inline email addresses.
        $bio_html = '';
        if (!empty($bio)) {
            $bio_html = esc_html($bio);
            $bio_html = preg_replace(
                '/([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/',
                '<a href="mailto:$1">$1</a>',
                $bio_html
            );
            $bio_html = wpautop($bio_html);
        }

        // WhatsApp: digits only (the wa.me deep link rejects formatting).
        $wa_number = '';
        if (!empty($whatsapp)) {
            $wa_number = preg_replace('/[^0-9]/', '', $whatsapp);
        }

        ob_start();
        ?>
        <div class="zen-ab">
            <div class="zen-ab-left">
                <div class="zen-ab-avatar">
                    <?php echo wp_kses_post($avatar); ?>
                </div>
                <h3 class="zen-ab-name"><?php echo esc_html($name); ?></h3>
                <?php if (!empty($role)) : ?>
                    <div class="zen-ab-role"><?php echo esc_html($role); ?></div>
                <?php endif; ?>

                <?php if ($email || $wa_number || $linkedin) : ?>
                    <div class="zen-ab-contacts">

                        <?php if (!empty($email)) : ?>
                            <a href="mailto:<?php echo esc_attr($email); ?>"
                               class="zen-ab-contact zen-ab-email"
                               data-email="<?php echo esc_attr($email); ?>"
                               title="<?php esc_attr_e('Copy email', 'zen-cortext'); ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                                <span class="zen-ab-contact-label"><?php echo esc_html($email); ?></span>
                                <span class="zen-ab-toast">Copied!</span>
                            </a>
                        <?php endif; ?>

                        <?php if (!empty($wa_number)) : ?>
                            <a href="https://wa.me/<?php echo esc_attr($wa_number); ?>"
                               class="zen-ab-contact zen-ab-wa"
                               target="_blank" rel="noopener noreferrer"
                               title="<?php esc_attr_e('Chat on WhatsApp', 'zen-cortext'); ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/></svg>
                                <span class="zen-ab-contact-label">WhatsApp</span>
                            </a>
                        <?php endif; ?>

                        <?php if (!empty($linkedin)) : ?>
                            <a href="<?php echo esc_url($linkedin); ?>"
                               class="zen-ab-contact zen-ab-li"
                               target="_blank" rel="noopener noreferrer"
                               title="<?php esc_attr_e('LinkedIn profile', 'zen-cortext'); ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                                <span class="zen-ab-contact-label">LinkedIn</span>
                            </a>
                        <?php endif; ?>

                    </div>
                <?php endif; ?>
            </div>

            <?php if ($bio_html) : ?>
                <div class="zen-ab-right">
                    <?php echo wp_kses_post($bio_html); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        // Ensure the card's styles/JS are present even if the shortcode is
        // dropped somewhere register_assets() didn't pre-enqueue. Enqueuing
        // is idempotent; late calls print in the footer.
        wp_enqueue_style('zen-cortext-author-bio');
        wp_enqueue_script('zen-cortext-author-bio');
        return ob_get_clean();
    }

    /**
     * [zen_cortext_author_posts_heading] — "Articles by Author Name".
     */
    public static function shortcode_heading() {
        if (!is_author()) {
            return '';
        }
        $author = get_queried_object();
        $name   = get_the_author_meta('display_name', $author->ID);
        return '<h2 style="margin-top:10px;margin-bottom:0;">Articles by ' . esc_html($name) . '</h2>';
    }

    /**
     * Replace "Author: Name" lines inside single posts with an avatar +
     * linked-name card. Filter runs at priority 20 so wpautop / the
     * default content filters have already wrapped the line in <p>.
     */
    public static function filter_content($content) {
        if (!is_singular('post')) {
            return $content;
        }

        $post_id   = get_the_ID();
        $author_id = (int) get_post_field('post_author', $post_id);
        if (!$author_id) {
            return $content;
        }

        $name       = get_the_author_meta('display_name', $author_id);
        $role       = function_exists('zen_get_user_role') ? zen_get_user_role($author_id) : '';
        $author_url = get_author_posts_url($author_id);

        // Avatar: custom upload first, then gravatar.
        $avatar_att = (int) get_user_meta($author_id, 'zen_user_avatar', true);
        if ($avatar_att && ($avatar_url = wp_get_attachment_image_url($avatar_att, 'thumbnail'))) {
            $img = '<img src="' . esc_url($avatar_url) . '" alt="' . esc_attr($name) . '" class="zen-post-author-avatar" width="48" height="48" />';
        } else {
            $img = get_avatar(get_the_author_meta('email', $author_id), 48, '', $name, array('class' => 'zen-post-author-avatar'));
        }

        $name_block = '<a href="' . esc_url($author_url) . '">' . esc_html($name) . '</a>';
        if ($role !== '') {
            $name_block .= '<span class="zen-post-author-role">' . esc_html($role) . '</span>';
        }

        $replacement = '<span class="zen-post-author">'
            . $img
            . '<span class="zen-post-author-info">'
            . '<span class="zen-post-author-label">Author</span>'
            . $name_block
            . '</span>'
            . '</span>';

        $escaped_name = preg_quote($name, '/');
        $content = preg_replace(
            '/<p>\s*Author:\s*(?:<a[^>]*>)?' . $escaped_name . '(?:<\/a>)?\s*<\/p>/i',
            '<p>' . $replacement . '</p>',
            $content
        );

        return $content;
    }

}
