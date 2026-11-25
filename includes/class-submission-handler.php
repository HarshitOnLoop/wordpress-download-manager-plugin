<?php
if (!defined('ABSPATH')) {
    exit;
}

class CDM_Submission_Handler {
    public static function init() {
        add_action('wp_ajax_cdm_submit', array(__CLASS__, 'handle'));
        add_action('wp_ajax_nopriv_cdm_submit', array(__CLASS__, 'handle'));
        add_action('wp_ajax_cdm_check', array(__CLASS__, 'check'));
        add_action('wp_ajax_nopriv_cdm_check', array(__CLASS__, 'check'));
    }

    private static function request_table() {
        global $wpdb;
        return $wpdb->prefix . 'cdm_requests';
    }

    private static function ip_address() {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $settings = CDM_Form_Manager::settings();
        $trusted = preg_split('/[\s,]+/', (string) $settings['trusted_proxy_ips'], -1, PREG_SPLIT_NO_EMPTY);
        if ($remote && in_array($remote, $trusted, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return substr($first, 0, 45);
            }
        }
        return substr(filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0', 0, 45);
    }

    private static function release_locks($lock_names) {
        global $wpdb;
        foreach ($lock_names as $lock_name) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * True when the current site time is inside the configured opening hours.
     * Supports overnight ranges (e.g. 22:00 - 06:00). Equal start and end means "always open".
     */
    private static function within_active_hours($settings) {
        $to_minutes = function ($time, $fallback) {
            if (!is_string($time) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
                $time = $fallback;
                preg_match('/^(\d\d):(\d\d)$/', $time, $m);
            }
            return ((int) $m[1]) * 60 + (int) $m[2];
        };
        $start = $to_minutes($settings['active_start'], '09:00');
        $end = $to_minutes($settings['active_end'], '18:00');
        if ($start === $end) {
            return true;
        }
        $now = new DateTimeImmutable('now', wp_timezone());
        $current = ((int) $now->format('G')) * 60 + (int) $now->format('i');
        if ($start < $end) {
            return $current >= $start && $current < $end;
        }
        return $current >= $start || $current < $end;
    }

    /**
     * Start / end of the period in which downloads are counted, plus the window in hours (0 = calendar day).
     */
    private static function limit_window($settings) {
        $now = new DateTimeImmutable('now', wp_timezone());
        $hours = min(720, absint($settings['limit_window_hours']));
        if ($hours > 0) {
            return array($now->modify('-' . $hours . ' hours')->format('Y-m-d H:i:s'), $now->modify('+1 minute')->format('Y-m-d H:i:s'), $hours);
        }
        $day = new DateTimeImmutable('today', wp_timezone());
        return array($day->format('Y-m-d H:i:s'), $day->modify('+1 day')->format('Y-m-d H:i:s'), 0);
    }

    /**
     * Quick check run when the visitor clicks the button, BEFORE the progress bar is shown.
     * If the visitor is over the limit (or outside the allowed hours) they get the message straight away
     * and no progress bar. Nothing is sent to the webhook here; the real submit checks everything again.
     */
    public static function check() {
        $settings = CDM_Form_Manager::settings();
        $slug = sanitize_title(wp_unslash(isset($_POST['resource']) ? $_POST['resource'] : ''));
        $raw_email = (isset($_POST['email']) && !is_array($_POST['email'])) ? wp_unslash($_POST['email']) : '';
        $email = strtolower(sanitize_email($raw_email));
        $name = (isset($_POST['name']) && !is_array($_POST['name'])) ? self::cut(sanitize_text_field(wp_unslash($_POST['name'])), 190) : '';

        $resource = $slug !== '' ? CDM_Resource_Manager::get_by_slug($slug) : null;
        if (!$resource || $resource->status !== 'active') {
            wp_send_json_error(array('message' => $settings['inactive_message']));
        }
        if (!is_email($email)) {
            wp_send_json_success(array('allowed' => true));
        }

        global $wpdb;
        $table = self::request_table();
        $ip = self::ip_address();
        $exemptions = preg_split('/[\s,]+/', (string) $settings['ip_exemptions'], -1, PREG_SPLIT_NO_EMPTY);
        $ip_exempt = in_array($ip, $exemptions, true);

        if (!empty($settings['active_hours_enabled']) && !$ip_exempt && !self::within_active_hours($settings)) {
            wp_send_json_error(array('message' => str_replace(
                array('{start}', '{end}'),
                array((string) $settings['active_start'], (string) $settings['active_end']),
                $settings['inactive_hours_message']
            )));
        }

        list($start, $next, $window_hours) = self::limit_window($settings);
        $limit = max(1, absint($settings['daily_limit']));
        $email_count = empty($settings['email_restriction']) ? 0 : (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE email = %s AND request_status = 'approved' AND submitted_at >= %s AND submitted_at < %s",
            $email,
            $start,
            $next
        ));
        $ip_count = (empty($settings['ip_restriction']) || $ip_exempt) ? 0 : (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE ip_address = %s AND request_status = 'approved' AND submitted_at >= %s AND submitted_at < %s",
            $ip,
            $start,
            $next
        ));
        if ((!empty($settings['email_restriction']) && $email_count >= $limit) ||
            (!empty($settings['ip_restriction']) && !$ip_exempt && $ip_count >= $limit)) {
            self::insert_request(array('name' => $name, 'email' => $email), $ip, $slug, 'blocked', 'skipped', null);
            wp_send_json_error(array(
                'message' => str_replace(
                    array('{limit}', '{hours}'),
                    array((string) $limit, (string) ($window_hours > 0 ? $window_hours : 24)),
                    $settings['limit_message']
                ),
                'etsy_url' => !empty($resource->etsy_url) ? esc_url_raw($resource->etsy_url) : '',
            ));
        }

        wp_send_json_success(array('allowed' => true));
    }

    private static function cut($value, $length) {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    private static function insert_request($values, $ip, $slug, $status, $email_status, $extra_json) {
        global $wpdb;
        $wpdb->insert(self::request_table(), array(
            'name' => $values['name'],
            'email' => $values['email'],
            'ip_address' => $ip,
            'resource_id' => $slug,
            'submitted_at' => current_time('mysql'),
            'request_status' => $status,
            'email_status' => $email_status,
            'email_error' => '',
            'extra_data' => $extra_json,
        ), array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));
        return $wpdb->insert_id;
    }

    public static function handle() {
        // No nonce on purpose: public forms sit on cached pages where nonces expire and break submissions.
        // Spam is controlled by the honeypot, per-email/IP limits and the burst limit below.
        $settings = CDM_Form_Manager::settings();
        $resource_slug = sanitize_title(wp_unslash(isset($_POST['resource']) ? $_POST['resource'] : ''));
        $honeypot = isset($_POST['website']) && !is_array($_POST['website']) ? sanitize_text_field(wp_unslash($_POST['website'])) : '';

        if ($honeypot !== '') {
            wp_send_json_error(array('message' => 'Submission rejected.'));
        }
        if ($resource_slug === '') {
            wp_send_json_error(array('message' => $settings['missing_fields_message']));
        }

        // Read and validate the configured fields.
        $values = array();
        $missing = false;
        foreach (CDM_Form_Manager::fields() as $key => $config) {
            $values[$key] = '';
            if (!CDM_Form_Manager::field_enabled($key, $settings)) {
                continue;
            }
            $raw = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
            if (is_array($raw)) {
                $raw = '';
            }
            switch ($config['type']) {
                case 'email':
                    $value = strtolower(sanitize_email($raw));
                    break;
                case 'textarea':
                    $value = self::cut(sanitize_textarea_field($raw), 2000);
                    break;
                case 'checkbox':
                    $value = $raw ? '1' : '';
                    break;
                default:
                    $value = self::cut(sanitize_text_field($raw), 190);
            }
            if ($value === '' && CDM_Form_Manager::field_required($key, $settings)) {
                $missing = true;
            }
            $values[$key] = $value;
        }
        if ($missing) {
            wp_send_json_error(array('message' => $settings['missing_fields_message']));
        }
        if (!is_email($values['email'])) {
            wp_send_json_error(array('message' => $settings['invalid_email_message']));
        }

        $resource = CDM_Resource_Manager::get_by_slug($resource_slug);
        if (!$resource) {
            wp_send_json_error(array('message' => $settings['inactive_message']));
        }
        if ($resource->status !== 'active') {
            wp_send_json_error(array('message' => $settings['inactive_message']));
        }

        $extra = array();
        foreach (array('phone', 'company', 'message', 'consent') as $extra_key) {
            if ($values[$extra_key] !== '') {
                $extra[$extra_key] = $values[$extra_key];
            }
        }
        $extra_json = $extra ? wp_json_encode($extra) : null;

        global $wpdb;
        $table = self::request_table();
        $ip = self::ip_address();
        $email = $values['email'];

        $exemptions = preg_split('/[\s,]+/', (string) $settings['ip_exemptions'], -1, PREG_SPLIT_NO_EMPTY);
        $ip_exempt = in_array($ip, $exemptions, true);
        if (!empty($settings['active_hours_enabled']) && !$ip_exempt && !self::within_active_hours($settings)) {
            wp_send_json_error(array('message' => str_replace(
                array('{start}', '{end}'),
                array((string) $settings['active_start'], (string) $settings['active_end']),
                $settings['inactive_hours_message']
            )));
        }

        $limit = max(1, absint($settings['daily_limit']));
        $day_start = new DateTimeImmutable('today', wp_timezone());
        $start = $day_start->format('Y-m-d H:i:s');
        $next = $day_start->modify('+1 day')->format('Y-m-d H:i:s');
        $now = new DateTimeImmutable('now', wp_timezone());
        $ten_seconds_ago = $now->modify('-10 seconds')->format('Y-m-d H:i:s');
        $ten_minutes_ago = $now->modify('-10 minutes')->format('Y-m-d H:i:s');

        // Limit window: a rolling number of hours instead of the calendar day.
        $window_hours = min(720, absint($settings['limit_window_hours']));
        if ($window_hours > 0) {
            $start = $now->modify('-' . $window_hours . ' hours')->format('Y-m-d H:i:s');
            $next = $now->modify('+1 minute')->format('Y-m-d H:i:s');
        }

        $lock_names = array(
            'cdm:' . substr(hash('sha256', 'email:' . $email), 0, 56),
            'cdm:' . substr(hash('sha256', 'ip:' . $ip), 0, 56),
        );
        sort($lock_names, SORT_STRING);
        $acquired_locks = array();
        foreach ($lock_names as $lock_name) {
            $lock_result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock_name));
            if ((int) $lock_result !== 1) {
                self::release_locks($acquired_locks);
                wp_send_json_error(array('message' => 'The request could not be processed right now. Please try again.'));
            }
            $acquired_locks[] = $lock_name;
        }

        $recent = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE email = %s AND resource_id = %s AND submitted_at >= %s LIMIT 1",
            $email,
            $resource_slug,
            $ten_seconds_ago
        ));
        if ($recent) {
            self::release_locks($acquired_locks);
            wp_send_json_error(array('message' => 'Please wait before submitting another request.'));
        }

        if (!$ip_exempt) {
            $burst = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE ip_address = %s AND submitted_at >= %s",
                $ip,
                $ten_minutes_ago
            ));
            if ($burst >= 15) {
                self::release_locks($acquired_locks);
                wp_send_json_error(array('message' => 'Too many requests. Please try again in a few minutes.'));
            }
        }

        $email_count = empty($settings['email_restriction']) ? 0 : (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE email = %s AND request_status = 'approved' AND submitted_at >= %s AND submitted_at < %s",
            $email,
            $start,
            $next
        ));
        $ip_count = (empty($settings['ip_restriction']) || $ip_exempt) ? 0 : (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE ip_address = %s AND request_status = 'approved' AND submitted_at >= %s AND submitted_at < %s",
            $ip,
            $start,
            $next
        ));
        if ((!empty($settings['email_restriction']) && $email_count >= $limit) ||
            (!empty($settings['ip_restriction']) && !$ip_exempt && $ip_count >= $limit)) {
            self::insert_request($values, $ip, $resource_slug, 'blocked', 'skipped', $extra_json);
            self::release_locks($acquired_locks);
            $message = str_replace(
                array('{limit}', '{hours}'),
                array((string) $limit, (string) ($window_hours > 0 ? $window_hours : 24)),
                $settings['limit_message']
            );
            wp_send_json_error(array(
                'message' => $message,
                'etsy_url' => !empty($resource->etsy_url) ? esc_url_raw($resource->etsy_url) : '',
            ));
        }

        $request_id = self::insert_request($values, $ip, $resource_slug, 'pending', 'pending', $extra_json);

        // Deliver to the webhook (no email is sent). email_status / email_error hold the webhook result.
        $payload = CDM_Webhook_Manager::build_payload('download_request', $values, $resource, $request_id, $ip);
        $sent = CDM_Webhook_Manager::send($payload);
        if ($sent) {
            $wpdb->update($table, array('request_status' => 'approved', 'email_status' => 'sent', 'email_error' => ''), array('id' => $request_id), array('%s', '%s', '%s'), array('%d'));
            self::release_locks($acquired_locks);
            wp_send_json_success(array('message' => $settings['success_message']));
        }

        $error = CDM_Webhook_Manager::$last_error !== '' ? CDM_Webhook_Manager::$last_error : 'Webhook delivery failed.';
        $wpdb->update($table, array('request_status' => 'failed', 'email_status' => 'failed', 'email_error' => self::cut($error, 500)), array('id' => $request_id), array('%s', '%s', '%s'), array('%d'));
        self::release_locks($acquired_locks);
        wp_send_json_error(array('message' => $settings['email_error_message']));
    }
}
