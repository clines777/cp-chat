<?php

namespace App\Lib;

class CaesarCipher
{

    /**
     * 加.
     *
     * @param  string  $str
     * @param  int     $shift
     *
     * @return string
     */
    public static function enc(string $str, int $shift = 3)
    {
        $result = '';
        $shift  = $shift % 26 + 26;
        for ($i = 0, $iMax = strlen($str); $i < $iMax; $i++) {
            $c = $str[$i];
            if (ctype_alpha($c)) {
                $code = ord($c);
                if ($code >= ord('a') && $code <= ord('z')) {
                    $c = chr((($code - ord('a') + $shift) % 26) + ord('a'));
                } elseif ($code >= ord('A') && $code <= ord('Z')) {
                    $c = chr((($code - ord('A') + $shift) % 26) + ord('A'));
                }
            }
            // Non-alphabetic characters remain unchanged
            $result .= $c;
        }

        return $result;
    }

    /**
     * 解.
     *
     * @param  string  $str
     * @param  int     $shift
     *
     * @return string
     */
    public static function dec(string $str, int $shift = 3): string
    {
        $result = '';
        $shift  %= 26;  // Normalize the shift value
        for ($i = 0, $iMax = strlen($str); $i < $iMax; $i++) {
            $c = $str[$i];
            if (ctype_alpha($c)) {
                $code = ord($c);
                if ($code >= ord('a') && $code <= ord('z')) {
                    // Properly handle wrap-around using +26
                    $c = chr((($code - ord('a') - $shift + 26) % 26) + ord('a'));
                } elseif ($code >= ord('A') && $code <= ord('Z')) {
                    $c = chr((($code - ord('A') - $shift + 26) % 26) + ord('A'));
                }
            }
            // Non-alphabetic characters remain unchanged
            $result .= $c;
        }

        return $result;
    }

}