<?php
if (!defined('ABSPATH')) {
    exit;
}

class CDM_Installer {
    /**
     * Every setting the plugin uses, with its default value.
     */
    public static function defaults() {
        return array(
            // Restrictions.
            'daily_limit' => 2,
            'email_restriction' => 1,
            'ip_restriction' => 1,
            'ip_exemptions' => '',
            'trusted_proxy_ips' => '',
            'retention_days' => 365,
            'delete_data_on_uninstall' => 0,
            'limit_message' => "You've used today's {limit} free downloads. Please come back tomorrow to download again.",
            // 0 = count per calendar day (original behaviour). 1-720 = rolling window of that many hours.
            'limit_window_hours' => 0,
            // Optional opening hours (site timezone). Submissions outside them are refused.
            'active_hours_enabled' => 0,
            'active_start' => '09:00',
            'active_end' => '18:00',
            'inactive_hours_message' => 'Downloads are available between {start} and {end}. Please come back during those hours.',

            // Sending progress bar (shown after the click; the data is sent to the webhook when it ends).
            'progress_enabled' => 0,
            'progress_seconds' => 5,
            'progress_text' => 'Sending your download link...',
            'progress_bar_color' => '#2563eb',
            'progress_bar_height' => 10,
            'progress_custom_css' => '',

            // Form content.
            'form_title' => 'Download Resource',
            'form_description' => 'Enter your details to receive the download link.',
            'show_title' => 1,
            'show_description' => 1,
            'submit_text' => 'Send Download Link',
            'success_message' => 'Thank you! Your request has been received.',
            'invalid_email_message' => 'Please enter a valid email address.',
            'missing_fields_message' => 'Please complete all required fields.',
            'email_error_message' => "We couldn't submit your request right now. Please try again later.",
            'inactive_message' => 'This download is currently unavailable.',
            'etsy_offer_text' => 'Want to get this resource from Etsy?',
            'etsy_button_text' => 'Get it on Etsy',

            // Form fields.
            'name_enabled' => 1,
            'name_required' => 1,
            'name_label' => 'Full Name',
            'name_placeholder' => 'Enter your full name',
            'email_label' => 'Email Address',
            'email_placeholder' => 'you@example.com',
            'phone_enabled' => 0,
            'phone_required' => 0,
            'phone_label' => 'Phone Number',
            'phone_placeholder' => '+1 555 123 4567',
            'company_enabled' => 0,
            'company_required' => 0,
            'company_label' => 'Company',
            'company_placeholder' => 'Your company name',
            'message_enabled' => 0,
            'message_required' => 0,
            'message_label' => 'Message',
            'message_placeholder' => 'How can we help?',
            'consent_enabled' => 0,
            'consent_required' => 1,
            'consent_label' => 'I agree to be contacted about my request.',

            // Form design.
            'primary_color' => '#2563eb',
            'bg_color' => '#ffffff',
            'text_color' => '#111827',
            'border_color' => '#d1d5db',
            'input_bg' => '#ffffff',
            'radius' => 10,
            'padding' => 28,
            'max_width' => 520,
            'font_size' => 15,
            'shadow' => 'soft',
            'align' => 'left',
            'button_style' => 'solid',
            'btn_full' => 1,
            'form_custom_css' => '',

            // Webhook delivery (replaces email).
            'webhook_url' => '',
            'webhook_secret' => '',
            'webhook_timeout' => 15,
        );
    }

    public static function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $resource_table = $wpdb->prefix . 'cdm_resources';
        $request_table = $wpdb->prefix . 'cdm_requests';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // NOTE: dbDelta needs two spaces after PRIMARY KEY and each column on its own line.
        dbDelta("CREATE TABLE {$resource_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            page_id bigint(20) unsigned NOT NULL DEFAULT 0,
            download_url text NOT NULL,
            etsy_url text NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            description text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) {$charset};");

        // NOTE: email_status / email_error are legacy column names. They now store the WEBHOOK delivery
        // status (pending / sent / failed / skipped) and error message.
        dbDelta("CREATE TABLE {$request_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL,
            ip_address varchar(45) NOT NULL,
            resource_id varchar(190) NOT NULL,
            submitted_at datetime NOT NULL,
            request_status varchar(20) NOT NULL,
            email_status varchar(20) NOT NULL,
            email_error text NULL,
            extra_data longtext NULL,
            PRIMARY KEY  (id),
            KEY email_submitted (email,submitted_at),
            KEY ip_submitted (ip_address,submitted_at),
            KEY resource_submitted (resource_id,submitted_at),
            KEY status_submitted (request_status,submitted_at)
        ) {$charset};");

        $defaults = self::defaults();
        $existing = get_option('cdm_settings', array());
        if (!is_array($existing)) {
            $existing = array();
        }
        if (isset($existing['limit_message']) && $existing['limit_message'] === 'You have reached your daily download limit of 2 downloads. Please try again tomorrow.') {
            $existing['limit_message'] = $defaults['limit_message'];
        }
        $old_defaults = array(
            'success_message' => 'Thank you! Your download link has been sent to your email address.',
            'email_error_message' => "We couldn't send your download link right now. Please try again later.",
            'consent_label' => 'I agree to receive my download link by email.',
        );
        foreach ($old_defaults as $key => $old_value) {
            if (isset($existing[$key]) && $existing[$key] === $old_value) {
                $existing[$key] = $defaults[$key];
            }
        }
        update_option('cdm_settings', array_merge($defaults, $existing));
        update_option('cdm_db_version', CDM_VERSION);

        if (!wp_next_scheduled('cdm_retention_cleanup')) {
            wp_schedule_event(time(), 'daily', 'cdm_retention_cleanup');
        }
    }

    public static function maybe_upgrade() {
        if (get_option('cdm_db_version') !== CDM_VERSION) {
            self::activate();
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('cdm_retention_cleanup');
    }
}

add_action('cdm_retention_cleanup', function () {
    $settings = get_option('cdm_settings', array());
    $days = max(1, absint(isset($settings['retention_days']) ? $settings['retention_days'] : 365));
    global $wpdb;
    $table = $wpdb->prefix . 'cdm_requests';
    $cutoff = gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - $days * DAY_IN_SECONDS);
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE submitted_at < %s", $cutoff));
});
