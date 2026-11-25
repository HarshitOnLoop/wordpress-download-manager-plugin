<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings access, field configuration, design variables and form markup.
 */
class CDM_Form_Manager {
    public static function settings() {
        $saved = get_option('cdm_settings', array());
        if (!is_array($saved)) {
            $saved = array();
        }
        return array_merge(CDM_Installer::defaults(), $saved);
    }

    /**
     * Form fields the visitor can be asked for.
     */
    public static function fields() {
        return array(
            'name' => array('type' => 'text', 'autocomplete' => 'name', 'locked' => false),
            'email' => array('type' => 'email', 'autocomplete' => 'email', 'locked' => true),
            'phone' => array('type' => 'tel', 'autocomplete' => 'tel', 'locked' => false),
            'company' => array('type' => 'text', 'autocomplete' => 'organization', 'locked' => false),
            'message' => array('type' => 'textarea', 'autocomplete' => 'off', 'locked' => false),
            'consent' => array('type' => 'checkbox', 'autocomplete' => 'off', 'locked' => false),
        );
    }

    public static function field_enabled($key, $settings) {
        return $key === 'email' ? true : !empty($settings[$key . '_enabled']);
    }

    public static function field_required($key, $settings) {
        return $key === 'email' ? true : !empty($settings[$key . '_required']);
    }

    private static function color($settings, $key) {
        $default = CDM_Installer::defaults();
        $value = isset($settings[$key]) ? sanitize_hex_color($settings[$key]) : '';
        return $value ? $value : $default[$key];
    }

    /**
     * CSS custom properties that drive the form design.
     */
    public static function design_vars($settings) {
        $shadows = array(
            'none' => 'none',
            'soft' => '0 10px 30px rgba(15,23,42,.08)',
            'strong' => '0 20px 50px rgba(15,23,42,.22)',
        );
        $shadow = isset($settings['shadow'], $shadows[$settings['shadow']]) ? $shadows[$settings['shadow']] : $shadows['soft'];

        return '--cdm-primary:' . self::color($settings, 'primary_color') . ';'
            . '--cdm-bg:' . self::color($settings, 'bg_color') . ';'
            . '--cdm-text:' . self::color($settings, 'text_color') . ';'
            . '--cdm-border:' . self::color($settings, 'border_color') . ';'
            . '--cdm-input-bg:' . self::color($settings, 'input_bg') . ';'
            . '--cdm-radius:' . min(60, max(0, absint($settings['radius']))) . 'px;'
            . '--cdm-pad:' . min(80, max(8, absint($settings['padding']))) . 'px;'
            . '--cdm-max:' . min(1000, max(280, absint($settings['max_width']))) . 'px;'
            . '--cdm-fs:' . min(22, max(12, absint($settings['font_size']))) . 'px;'
            . '--cdm-bar:' . self::color($settings, 'progress_bar_color') . ';'
            . '--cdm-bar-h:' . min(30, max(4, absint($settings['progress_bar_height']))) . 'px;'
            . '--cdm-shadow:' . $shadow . ';';
    }

    /**
     * Frontend CSS: design variables followed by the sanitized custom CSS.
     */
    public static function frontend_css($settings = null) {
        if ($settings === null) {
            $settings = self::settings();
        }
        $css = '.cdm-inline,.cdm-modal,.cdm-open-popup{' . self::design_vars($settings) . '}';
        foreach (array('form_custom_css', 'progress_custom_css') as $css_key) {
            $custom = CDM_Style_Manager::sanitize_custom_css(isset($settings[$css_key]) ? $settings[$css_key] : '', 'form');
            if ($custom !== '') {
                $css .= "\n" . $custom;
            }
        }
        return $css;
    }

    /**
     * Markup of the sending progress bar. JavaScript fills and reveals it after the click.
     * In the admin preview it is rendered with sample content so the design can be checked.
     */
    private static function progress_box($settings, $preview) {
        $enabled = !empty($settings['progress_enabled']);
        if (!$enabled && !$preview) {
            return '';
        }
        $text = (string) $settings['progress_text'];
        $sample = str_replace(array('{seconds}', '{percent}'), array((string) max(1, absint($settings['progress_seconds'])), '40'), $text);
        return '<div class="cdm-progress" role="status" aria-live="polite" data-text="' . esc_attr($text) . '"' . ((!$preview || !$enabled) ? ' hidden' : '') . '>' .
            '<div class="cdm-progress-text">' . ($preview ? esc_html($sample) : '') . '</div>' .
            '<div class="cdm-progress-bar"><div class="cdm-progress-fill"' . ($preview ? ' style="width:40%"' : '') . '></div></div>' .
            '</div>';
    }

    public static function allowed_label_html() {
        return array(
            'a' => array('href' => true, 'target' => true, 'rel' => true),
            'strong' => array(),
            'em' => array(),
        );
    }

    /**
     * Form markup. $args: title, description, button, class, preview.
     */
    public static function render($resource_slug, $args = array()) {
        $settings = self::settings();
        $args = wp_parse_args($args, array(
            'title' => '',
            'description' => '',
            'button' => '',
            'class' => '',
            'preview' => false,
            'heading' => true,
        ));
        $preview = !empty($args['preview']);

        $title = $args['title'] !== '' ? $args['title'] : $settings['form_title'];
        $description = $args['description'] !== '' ? $args['description'] : $settings['form_description'];
        $button = $args['button'] !== '' ? $args['button'] : $settings['submit_text'];

        $classes = array('cdm-form', 'cdm-align-' . ($settings['align'] === 'center' ? 'center' : 'left'));
        $classes[] = $settings['button_style'] === 'outline' ? 'cdm-btn-outline' : 'cdm-btn-solid';
        if (!empty($settings['btn_full'])) {
            $classes[] = 'cdm-btn-full';
        }
        foreach (preg_split('/\s+/', (string) $args['class'], -1, PREG_SPLIT_NO_EMPTY) as $extra) {
            $classes[] = sanitize_html_class($extra);
        }

        $uid = wp_unique_id('cdm-');

        ob_start();
        ?>
        <?php
        $progress_attr = (!$preview && !empty($settings['progress_enabled'])) ? ' data-progress="' . (int) max(1, absint($settings['progress_seconds'])) . '"' : '';
        ?>
        <form class="<?php echo esc_attr(implode(' ', $classes)); ?>" data-resource="<?php echo esc_attr($resource_slug); ?>"<?php echo $progress_attr; ?> novalidate>
            <?php if ($args['heading']) : ?>
                <?php if ($title !== '' && ($preview || !empty($settings['show_title']))) : ?>
                    <h2 class="cdm-title"<?php echo ($preview && empty($settings['show_title'])) ? ' hidden' : ''; ?>><?php echo esc_html($title); ?></h2>
                <?php elseif ($preview) : ?>
                    <h2 class="cdm-title" hidden></h2>
                <?php endif; ?>
                <?php if ($description !== '' && ($preview || !empty($settings['show_description']))) : ?>
                    <p class="cdm-description"<?php echo ($preview && empty($settings['show_description'])) ? ' hidden' : ''; ?>><?php echo esc_html($description); ?></p>
                <?php elseif ($preview) : ?>
                    <p class="cdm-description" hidden></p>
                <?php endif; ?>
            <?php endif; ?>

            <input type="text" name="website" class="cdm-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">

            <?php foreach (self::fields() as $key => $config) :
                $enabled = self::field_enabled($key, $settings);
                if (!$enabled && !$preview) {
                    continue;
                }
                $required = self::field_required($key, $settings);
                $label = isset($settings[$key . '_label']) ? $settings[$key . '_label'] : '';
                $placeholder = isset($settings[$key . '_placeholder']) ? $settings[$key . '_placeholder'] : '';
                $id = $uid . '-' . $key;
                $hidden = ($preview && !$enabled) ? ' hidden' : '';
                $req_attr = $required ? ' required aria-required="true"' : '';
                ?>
                <?php if ($config['type'] === 'checkbox') : ?>
                    <div class="cdm-field cdm-field-<?php echo esc_attr($key); ?> cdm-field-checkbox" data-field="<?php echo esc_attr($key); ?>"<?php echo $hidden; ?>>
                        <label class="cdm-checkbox-label" for="<?php echo esc_attr($id); ?>">
                            <input id="<?php echo esc_attr($id); ?>" type="checkbox" name="<?php echo esc_attr($key); ?>" value="1"<?php echo $req_attr; ?>>
                            <span class="cdm-checkbox-text"><?php echo wp_kses($label, self::allowed_label_html()); ?></span>
                        </label>
                    </div>
                <?php else : ?>
                    <div class="cdm-field cdm-field-<?php echo esc_attr($key); ?>" data-field="<?php echo esc_attr($key); ?>"<?php echo $hidden; ?>>
                        <label for="<?php echo esc_attr($id); ?>"><span class="cdm-label-text"><?php echo esc_html($label); ?></span><span class="cdm-required" aria-hidden="true"<?php echo $required ? '' : ' hidden'; ?>> *</span></label>
                        <?php if ($config['type'] === 'textarea') : ?>
                            <textarea id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($key); ?>" rows="4" placeholder="<?php echo esc_attr($placeholder); ?>"<?php echo $req_attr; ?>></textarea>
                        <?php else : ?>
                            <input id="<?php echo esc_attr($id); ?>" type="<?php echo esc_attr($config['type']); ?>" name="<?php echo esc_attr($key); ?>" autocomplete="<?php echo esc_attr($config['autocomplete']); ?>" placeholder="<?php echo esc_attr($placeholder); ?>"<?php echo $req_attr; ?>>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <button type="submit" class="cdm-submit">
                <span class="cdm-submit-text"><?php echo esc_html($button); ?></span>
                <span class="cdm-loader" aria-hidden="true"></span>
            </button>
            <?php echo self::progress_box($settings, $preview); ?>
            <div class="cdm-message" role="status" aria-live="polite"></div>
            <div class="cdm-limit-offer" hidden>
                <p class="cdm-etsy-text"><?php echo esc_html($settings['etsy_offer_text']); ?></p>
                <a class="cdm-etsy-button" href="#" target="_blank" rel="noopener noreferrer"><?php echo esc_html($settings['etsy_button_text']); ?></a>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }
}
