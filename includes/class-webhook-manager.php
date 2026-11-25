<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sends form submissions to an external webhook (Zapier, Make, n8n, your own endpoint...).
 * This replaces the old email delivery.
 */
class CDM_Webhook_Manager {
    /** Last error message, if any. */
    public static $last_error = '';

    public static function settings() {
        return CDM_Form_Manager::settings();
    }

    public static function is_configured() {
        $settings = self::settings();
        return self::valid_url($settings['webhook_url']);
    }

    public static function valid_url($url) {
        $url = (string) $url;
        if ($url === '' || !wp_http_validate_url($url)) {
            return false;
        }
        return in_array(wp_parse_url($url, PHP_URL_SCHEME), array('http', 'https'), true);
    }

    /**
     * Builds the JSON payload for one submission.
     *
     * @param array  $values   name, email, phone, company, message, consent.
     * @param object $resource Resource row (name, slug, download_url, ...).
     */
    public static function build_payload($event, $values, $resource, $request_id = 0, $ip = '') {
        return array(
            'event' => $event,
            'request_id' => (int) $request_id,
            'submitted_at' => current_time('mysql'),
            'site_url' => home_url('/'),
            'resource' => array(
                'id' => isset($resource->slug) ? $resource->slug : '',
                'name' => isset($resource->name) ? $resource->name : '',
                'download_url' => isset($resource->download_url) ? $resource->download_url : '',
                'etsy_url' => isset($resource->etsy_url) ? $resource->etsy_url : '',
                'snapshot_id' => isset($resource->page_id) ? (int) $resource->page_id : 0,
            ),
            'name' => isset($values['name']) ? $values['name'] : '',
            'email' => isset($values['email']) ? $values['email'] : '',
            'phone' => isset($values['phone']) ? $values['phone'] : '',
            'company' => isset($values['company']) ? $values['company'] : '',
            'message' => isset($values['message']) ? $values['message'] : '',
            'consent' => !empty($values['consent']),
            'ip_address' => $ip,
        );
    }

    /**
     * POSTs the payload as JSON. Returns true when the endpoint answers with a 2xx status.
     */
    public static function send($payload) {
        self::$last_error = '';
        $settings = self::settings();
        $url = (string) $settings['webhook_url'];

        if (!self::valid_url($url)) {
            self::$last_error = 'Webhook URL is not configured.';
            return false;
        }

        $body = wp_json_encode($payload);
        $headers = array(
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent' => 'CustomDownloadManager/' . CDM_VERSION . '; ' . home_url('/'),
            'X-CDM-Event' => isset($payload['event']) ? $payload['event'] : 'download_request',
        );
        $secret = (string) $settings['webhook_secret'];
        if ($secret !== '') {
            // Receivers can verify the body with: hash_hmac('sha256', $rawBody, $secret).
            $headers['X-CDM-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }

        $response = wp_remote_post($url, array(
            'timeout' => min(30, max(3, absint($settings['webhook_timeout']))),
            'redirection' => 2,
            'headers' => $headers,
            'body' => $body,
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            self::$last_error = $response->get_error_message();
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code > 299) {
            self::$last_error = 'Webhook responded with HTTP ' . $code . '.';
            return false;
        }
        return true;
    }
}
