<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sanitizes the custom CSS an administrator can add for the form and the email.
 *
 * Any selector that targets a plugin class (it must contain "cdm-") is allowed, together with
 * @media / @supports blocks and form @keyframes. Anything that could run script or load
 * unexpected resources is removed.
 */
class CDM_Style_Manager {
    public static function sanitize_custom_css($css, $context = 'form') {
        if (!is_string($css) || trim($css) === '') {
            return '';
        }
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        $css = str_replace(array('<', "\0"), '', $css);
        $css = preg_replace('/@(import|charset|namespace)[^;{]*;?/i', '', $css);

        return implode("\n\n", self::parse_blocks($css, $context, false));
    }

    private static function parse_blocks($css, $context, $keyframes) {
        $rules = array();
        $len = strlen($css);
        $i = 0;

        while ($i < $len) {
            $open = strpos($css, '{', $i);
            if ($open === false) {
                break;
            }
            $prelude = trim(substr($css, $i, $open - $i));

            $level = 1;
            $j = $open + 1;
            while ($j < $len && $level > 0) {
                if ($css[$j] === '{') {
                    $level++;
                } elseif ($css[$j] === '}') {
                    $level--;
                }
                $j++;
            }
            $inner = substr($css, $open + 1, max(0, $j - $open - 2));
            $i = $j;

            if ($prelude === '') {
                continue;
            }

            if ($prelude[0] === '@') {
                if ($keyframes) {
                    continue;
                }
                if (preg_match('/^@(media|supports)\s+[a-z0-9\s\(\):,.\-%\/]+$/i', $prelude)) {
                    $nested = self::parse_blocks($inner, $context, false);
                    if ($nested) {
                        $indented = array();
                        foreach ($nested as $rule) {
                            $indented[] = '  ' . str_replace("\n", "\n  ", $rule);
                        }
                        $rules[] = preg_replace('/\s+/', ' ', $prelude) . " {\n" . implode("\n", $indented) . "\n}";
                    }
                } elseif ($context === 'form' && preg_match('/^@(-webkit-)?keyframes\s+[a-z0-9_-]+$/i', $prelude)) {
                    $frames = self::parse_blocks($inner, $context, true);
                    if ($frames) {
                        $rules[] = preg_replace('/\s+/', ' ', $prelude) . " {\n" . implode("\n", $frames) . "\n}";
                    }
                }
                continue;
            }

            $valid = array();
            foreach (array_map('trim', explode(',', $prelude)) as $selector) {
                if ($selector === '') {
                    continue;
                }
                if ($keyframes) {
                    if (preg_match('/^(from|to|\d{1,3}(\.\d+)?%)$/i', $selector)) {
                        $valid[] = $selector;
                    }
                    continue;
                }
                if (strpos($selector, 'cdm-') !== false &&
                    preg_match('/^[a-z0-9\s\.\#_\-:>+~*\[\]="\'\(\)%^$|]+$/i', $selector)) {
                    $valid[] = $selector;
                }
            }
            if (!$valid) {
                continue;
            }

            $declarations = self::clean_declarations($inner, $context);
            if ($declarations === '') {
                continue;
            }
            $rules[] = implode(",\n", $valid) . " {\n" . $declarations . "\n}";
        }

        return $rules;
    }

    private static function clean_declarations($block, $context) {
        $out = array();
        // Split on semicolons that are not inside parentheses (keeps data: URLs intact).
        $parts = preg_split('/;(?![^(]*\))/', $block);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || strpos($part, ':') === false) {
                continue;
            }
            $pieces = explode(':', $part, 2);
            $prop = trim($pieces[0]);
            $value = trim($pieces[1]);

            if (!preg_match('/^(--[a-z0-9_-]+|[a-z-]+)$/i', $prop) || $value === '') {
                continue;
            }
            if (preg_match('/expression\s*\(|javascript:|vbscript:|behaviou?r\s*:|-moz-binding|@import|>/i', $value)) {
                continue;
            }

            $bad_url = false;
            $value = preg_replace_callback('/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/i', function ($m) use (&$bad_url) {
                $url = trim($m[2]);
                $ok = preg_match('#^(https?:)?//#i', $url)
                    || preg_match('#^/[^/]#', $url)
                    || preg_match('#^data:image/(png|jpe?g|gif|webp|svg\+xml)[;,]#i', $url);
                if (!$ok) {
                    $bad_url = true;
                    return '';
                }
                return 'url("' . str_replace('"', '', $url) . '")';
            }, $value);
            if ($bad_url) {
                continue;
            }

            if ($context === 'email' && stripos($value, '!important') === false) {
                $value .= ' !important';
            }
            $out[] = '  ' . $prop . ': ' . $value . ';';
        }

        return implode("\n", $out);
    }
}
