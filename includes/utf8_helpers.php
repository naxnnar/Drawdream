<?php
// includes/utf8_helpers.php — ความยาว/ตัดสตริง UTF-8 โดยใช้ mbstring ถ้ามี ไม่งั้นใช้ iconv/pcre

if (!function_exists('drawdream_utf8_strlen')) {
    function drawdream_utf8_strlen(string $str): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($str, 'UTF-8');
        }
        if (function_exists('iconv_strlen')) {
            $len = @iconv_strlen($str, 'UTF-8');
            if ($len !== false) {
                return (int) $len;
            }
        }
        if (preg_match_all('/./us', $str, $m) !== false) {
            return count($m[0]);
        }

        return strlen($str);
    }
}

if (!function_exists('drawdream_utf8_substr')) {
    function drawdream_utf8_substr(string $str, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null
                ? mb_substr($str, $start, null, 'UTF-8')
                : mb_substr($str, $start, $length, 'UTF-8');
        }
        if (function_exists('iconv_substr')) {
            if ($length === null) {
                $out = @iconv_substr($str, $start, PHP_INT_MAX, 'UTF-8');
            } else {
                $out = @iconv_substr($str, $start, $length, 'UTF-8');
            }

            return $out !== false ? $out : '';
        }
        if (preg_match_all('/./us', $str, $m) !== false) {
            $chars = $m[0];
            $slice = $length === null
                ? array_slice($chars, $start)
                : array_slice($chars, $start, $length);

            return implode('', $slice);
        }

        return $length === null ? substr($str, $start) : substr($str, $start, $length);
    }
}
