<?php
if (!defined('ABSPATH')) {
    exit;
}

class CDM_Shortcode_Manager {
    private static $assets_loaded = false;
    private static $needs_modal = false;

    public static function init() {
        add_shortcode('custom_download_button', array(__CLASS__, 'button_shortcode'));
        add_shortcode('custom_download_form', array(__CLASS__, 'form_shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'maybe_load_assets'));
        add_action('wp_footer', array(__CLASS__, 'popup_shell'));
    }

    /**
     * Loads CSS/JS in <head> when the current page's content contains one of the shortcodes.
     * (The shortcodes also load assets themselves, which covers widgets, templates and page builders.)
     */
    public static function maybe_load_assets() {
        global $post;
        if (!is_a($post, 'WP_Post')) {
            return;
        }
        $content = $post->post_content;
        if (class_exists('\Elementor\Plugin')) {
            $elementor_content = get_post_meta($post->ID, '_elementor_data', true);
            if (is_string($elementor_content) && strpos($elementor_content, 'custom_download_') !== false) {
                $content .= $elementor_content;
            }
        }
        if (has_shortcode($content, 'custom_download_button') || has_shortcode($content, 'custom_download_form')) {
            self::load_assets();
        }
    }

    public static function load_assets() {
        if (self::$assets_loaded) {
            return;
        }
        self::$assets_loaded = true;
        $settings = CDM_Form_Manager::settings();

        wp_enqueue_style('cdm-front', CDM_PLUGIN_URL . 'assets/frontend.css', array(), CDM_VERSION);
        wp_add_inline_style('cdm-front', CDM_Form_Manager::frontend_css($settings));

        wp_enqueue_script('cdm-front', CDM_PLUGIN_URL . 'assets/frontend.js', array(), CDM_VERSION, true);
        wp_localize_script('cdm-front', 'CDM_Ajax', array(
            'url' => admin_url('admin-ajax.php'),
            'i18n' => array(
                'missing' => $settings['missing_fields_message'],
                'invalid_email' => $settings['invalid_email_message'],
                'error' => __('An unexpected error occurred. Please try again.', 'custom-download-manager'),
            ),
        ));
    }

    private static function resolve_resource_slug($resource_slug) {
        $resource_slug = sanitize_title($resource_slug);
        if ($resource_slug !== '') {
            return $resource_slug;
        }
        $page_resource = CDM_Resource_Manager::get_by_page_id(get_the_ID());
        return $page_resource ? $page_resource->slug : '';
    }

    public static function button_shortcode($atts) {
        $atts = shortcode_atts(array('resource' => '', 'text' => 'Download Now', 'class' => ''), $atts, 'custom_download_button');
        $resource = self::resolve_resource_slug($atts['resource']);
        if (!$resource) {
            return current_user_can('manage_options')
                ? '<p class="cdm-error">No download resource is assigned to this page. Assign one under Download Manager → Resources or specify its resource ID in the shortcode.</p>'
                : '';
        }
        self::load_assets();
        self::$needs_modal = true;

        $classes = array('cdm-open-popup');
        foreach (preg_split('/\s+/', (string) $atts['class'], -1, PREG_SPLIT_NO_EMPTY) as $extra) {
            $classes[] = sanitize_html_class($extra);
        }

        return '<button type="button" class="' . esc_attr(implode(' ', $classes)) . '" data-resource="' . esc_attr($resource) . '">' . esc_html($atts['text']) . '</button>' .
            '<template class="cdm-popup-template" data-resource="' . esc_attr($resource) . '">' . CDM_Form_Manager::render($resource) . '</template>';
    }

    /**
     * [custom_download_form resource="" title="" description="" button="" class=""]
     */
    public static function form_shortcode($atts) {
        $atts = shortcode_atts(array(
            'resource' => '',
            'title' => '',
            'description' => '',
            'button' => '',
            'class' => '',
        ), $atts, 'custom_download_form');
        $resource = self::resolve_resource_slug($atts['resource']);
        if (!$resource) {
            return current_user_can('manage_options')
                ? '<p class="cdm-error">No download resource is assigned to this page. Assign one under Download Manager → Resources or specify its resource ID in the shortcode.</p>'
                : '';
        }
        self::load_assets();

        return '<div class="cdm-inline" data-resource="' . esc_attr($resource) . '">' .
            CDM_Form_Manager::render($resource, array(
                'title' => $atts['title'],
                'description' => $atts['description'],
                'button' => $atts['button'],
                'class' => $atts['class'],
            )) . '</div>';
    }

    public static function popup_shell() {
        if (!self::$needs_modal) {
            return;
        }
        ?>
        <div class="cdm-modal" id="cdm-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__('Download form', 'custom-download-manager'); ?>">
            <div class="cdm-modal-overlay" data-cdm-close></div>
            <div class="cdm-modal-panel" role="document">
                <button type="button" class="cdm-modal-close" data-cdm-close aria-label="<?php echo esc_attr__('Close', 'custom-download-manager'); ?>">&times;</button>
                <div id="cdm-modal-content"></div>
            </div>
        </div>
        <?php
    }
}
