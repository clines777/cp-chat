<?php

namespace App\Lib;

/**
 * 彩票端对接加密元件
 */
class CpEncrypt
{

    public const Expire = 30;

    /**
     * @param  string  $string
     * @param  string  $operation
     * @param  int     $expiry
     *
     * @return string
     */
    public static function authCode(string $string, string $operation = 'DECODE', int $expiry = 0, string $key = ''): string
    {
        if ($operation == 'DECODE') {
            $string = str_replace('[a]', '+', $string);
            $string = str_replace('[b]', '&', $string);
            $string = str_replace('[c]', '/', $string);
            $string = str_replace('[d]', '=', $string);
        }
        $ckey_length   = 4;
        $key           = md5($key ? $key : 'livcmsencryption ');
        $keya          = md5(substr($key, 0, 16));
        $keyb          = md5(substr($key, 16, 16));
        $keyc          = $ckey_length ? ($operation == 'DECODE' ? substr($string, 0, $ckey_length) : substr(md5(microtime()), -$ckey_length)) : '';
        $cryptkey      = $keya.md5($keya.$keyc);
        $key_length    = strlen($cryptkey);
        $string        = $operation == 'DECODE' ? base64_decode(substr($string, $ckey_length))
            : sprintf('%010d', $expiry ? $expiry + time() : 0).substr(md5($string.$keyb), 0, 16).$string;
        $string_length = strlen($string);
        $result        = '';
        $box           = range(0, 255);
        $rndkey        = [];
        for ($i = 0; $i <= 255; $i++) {
            $rndkey[$i] = ord($cryptkey[$i % $key_length]);
        }
        for ($j = $i = 0; $i < 256; $i++) {
            $j       = ($j + $box[$i] + $rndkey[$i]) % 256;
            $tmp     = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }
        for ($a = $j = $i = 0; $i < $string_length; $i++) {
            $a       = ($a + 1) % 256;
            $j       = ($j + $box[$a]) % 256;
            $tmp     = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $tmp;
            $result  .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
        }
        if ($operation == 'DECODE') {
            if (((int)substr($result, 0, 10) == 0 || (int)substr($result, 0, 10) - time() > 0) && substr($result, 10, 16) == substr(md5(substr($result, 26).$keyb), 0, 16)) {
                return substr($result, 26);
            } else {
                return '';
            }
        } else {
            $ustr = $keyc.str_replace('=', '', base64_encode($result));
            $ustr = str_replace('+', '[a]', $ustr);
            $ustr = str_replace('&', '[b]', $ustr);
            $ustr = str_replace('/', '[c]', $ustr);
            $ustr = str_replace('=', '[d]', $ustr);

            return $ustr;
        }
    }

}