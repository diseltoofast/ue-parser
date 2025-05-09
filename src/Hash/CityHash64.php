<?php

declare(strict_types=1);

namespace Diseltoofast\UeParser\Hash;

use GMP;

/**
 * CityHash64 - A class for hashing strings using the CityHash64 algorithm.
 *
 * This class provides methods to hash strings using the CityHash64 algorithm. It supports hashing strings of various lengths
 * and includes optimizations for 32-bit and 64-bit operations. The implementation uses GMP functions for handling large integers.
 */
class CityHash64
{
    private const MASK_32 = '4294967295';           // 0xFFFFFFFF
    private const MASK_64 = '18446744073709551615'; // 0xFFFFFFFFFFFFFFFF
    private const K0      = '14097894508562428199'; // 0xC3A5C85C97CB3127
    private const K1      = '13011662864482103923'; // 0xB492B66FBE98F273
    private const K2      = '11160318154034397263'; // 0x9AE16A3B2F90404F
    private const KMUL    = '11376068507788127593'; // 0x9DDFEA08EB382D69

    /**
     * Hashes a string using the CityHash64 algorithm.
     *
     * @param string $string The input string to hash.
     * @return int The resulting 32-bit hash value.
     */
    public static function hash(string $string): int
    {
        if ($string === '') {
            return 0;
        }

        $hash = self::cityHash64(mb_convert_encoding($string, 'UTF-16LE', 'UTF-8'));

        return self::hashOptimize($hash);
    }

    /**
     * Limits the result to 32 bits.
     *
     * @param string $hash The hash value to optimize.
     * @return int The optimized 32-bit hash value.
     */
    private static function hashOptimize(string $hash): int
    {
        if ($hash) {
            $low = self::mask($hash, self::MASK_32);
            $high = self::mask(self::shiftRight($hash, 32), self::MASK_32);
            $hash = self::mask(self::add($low, self::mul($high, '23')), self::MASK_32);

            // Convert to integer
            return (int)$hash;
        }

        return 0;
    }

    /**
     * Main hashing function.
     *
     * @param string $string The input string to hash.
     * @return string The resulting 64-bit hash value.
     */
    private static function cityHash64(string $string): string
    {
        $len = strlen($string);

        if ($len <= 16) {
            return self::hashLen0to16($string, $len);
        }

        if ($len <= 32) {
            return self::hashLen17to32($string, $len);
        }

        if ($len <= 64) {
            return self::hashLen33to64($string, $len);
        }

        $x = self::fetch64($string, -40);
        $y = self::add(self::fetch64($string, -16), self::fetch64($string, -56));
        $z = self::hashLen16(self::add(self::fetch64($string, -48), (string)$len), self::fetch64($string, -24), self::KMUL);
        [$v_lo, $v_hi] = self::weakHashLen32WithSeeds(substr($string, -64), (string)$len, $z);
        [$w_lo, $w_hi] = self::weakHashLen32WithSeeds(substr($string, -32), self::add($y, self::K1), $x);
        $x = self::add(self::mul(self::K1, $x), self::fetch64($string));

        $len = ($len - 1) & (~63);
        $bytes = substr($string, 0, $len);

        while ($bytes !== '') {
            $x = self::mul(self::rotate(self::add($x, self::add($y, self::add($v_lo, self::fetch64($bytes, 8)))), 37), self::K1);
            $y = self::mul(self::rotate(self::add($y, self::add($v_hi, self::fetch64($bytes, 48))), 42), self::K1);
            $x = self::xor($x, $w_hi);
            $y = self::add($y, self::add($v_lo, self::fetch64($bytes, 40)));
            $z = self::mul(self::rotate(self::add($z, $w_lo), 33), self::K1);
            [$v_lo, $v_hi] = self::weakHashLen32WithSeeds($bytes, self::mul($v_hi, self::K1), self::add($x, $w_lo));
            [$w_lo, $w_hi] = self::weakHashLen32WithSeeds(substr($bytes, 32), self::add($z, $w_hi), self::add($y, self::fetch64($bytes, 16)));
            [$z, $x] = [$x, $z];
            $bytes = substr($bytes, 64);
        }

        return self::hashLen16(
            self::add(self::hashLen16($v_lo, $w_lo, self::KMUL), self::add(self::mul(self::shiftMix($y), self::K1), $z)),
            self::add(self::hashLen16($v_hi, $w_hi, self::KMUL), $x),
            self::KMUL
        );
    }

    /**
     * Hashes strings with a length between 0 and 16 bytes.
     *
     * @param string $string The input string to hash.
     * @param int $len The length of the input string.
     * @return string The resulting 64-bit hash value.
     */
    private static function hashLen0to16(string $string, int $len): string
    {
        if ($len >= 8) {
            $mul = self::add(self::K2, (string)(2 * $len));
            $a = self::add(self::fetch64($string), self::K2);
            $b = self::fetch64($string, -8);
            $c = self::add(self::mul(self::rotate($b, 37), $mul), $a);
            $d = self::mul(self::add(self::rotate($a, 25), $b), $mul);

            return self::hashLen16($c, $d, $mul);
        }

        if ($len >= 4) {
            $mul = self::add(self::K2, (string)(2 * $len));
            $a = self::fetch32($string);

            return self::hashLen16(
                self::add((string)$len, self::shiftLeft($a, 3)),
                self::fetch32($string, -4),
                $mul
            );
        }

        if ($len > 0) {
            $a = (string)ord($string[0]);
            $b = (string)ord($string[$len >> 1]);
            $c = (string)ord($string[-1]);
            $y = self::mask(self::add($a, self::shiftLeft($b, 8)), self::MASK_32);
            $z = self::mask(self::add((string)$len, self::shiftLeft($c, 2)), self::MASK_32);

            return self::mask(
                self::mul(self::shiftMix(self::xor(self::mul($y, self::K2), self::mul($z, self::K0))), self::K2),
                self::MASK_64
            );
        }

        return self::K2;
    }

    /**
     * Hashes strings with a length between 17 and 32 bytes.
     *
     * @param string $string The input string to hash.
     * @param int $len The length of the input string.
     * @return string The resulting 64-bit hash value.
     */
    private static function hashLen17to32(string $string, int $len): string
    {
        $mul = self::add(self::K2, (string)(2 * $len));
        $a = self::mul(self::fetch64($string), self::K1);
        $b = self::fetch64($string, 8);
        $c = self::mul(self::fetch64($string, -8), $mul);
        $d = self::mul(self::fetch64($string, -16), self::K2);

        return self::mask(
            self::hashLen16(
                self::add(self::rotate(self::add($a, $b), 43), self::add(self::rotate($c, 30), $d)),
                self::add($a, self::add(self::rotate(self::add($b, self::K2), 18), $c)),
                $mul
            ),
            self::MASK_64
        );
    }

    /**
     * Hashes strings with a length between 33 and 64 bytes.
     *
     * @param string $string The input string to hash.
     * @param int $len The length of the input string.
     * @return string The resulting 64-bit hash value.
     */
    private static function hashLen33to64(string $string, int $len): string
    {
        $mul = self::add(self::K2, (string)(2 * $len));
        $a = self::mul(self::fetch64($string), self::K2);
        $b = self::fetch64($string, 8);
        $c = self::fetch64($string, -24);
        $d = self::fetch64($string, -32);
        $e = self::mul(self::fetch64($string, 16), self::K2);
        $f = self::mul(self::fetch64($string, 24), '9');
        $g = self::fetch64($string, -8);
        $h = self::mul(self::fetch64($string, -16), $mul);
        $u = self::add(self::rotate(self::add($a, $g), 43), self::mul((self::add(self::rotate($b, 30), $c)), '9'));
        $v = self::add(self::add(self::xor(self::add($a, $g), $d), $f), '1');
        $w = self::add(self::byteSwap(self::mul(self::add($u, $v), $mul)), $h);
        $x = self::add(self::rotate(self::add($e, $f), 42), $c);
        $y = self::mul(self::add(self::byteSwap(self::mul(self::add($v, $w), $mul)), $g), $mul);
        $z = self::add(self::add($e, $f), $c);

        // y = (bswap_64((v + w) * mul) + g) * mul
        echo self::byteSwap(self::mul(self::add($v, $w), $mul)) . PHP_EOL;


        $a = self::add(self::byteSwap(self::add(self::mul(self::add($x, $z), $mul), $y)), $b);
        $b = self::mul(self::shiftMix(self::add(self::add(self::mul(self::add($z, $a), $mul), $d), $h)), $mul);

        return self::mask(self::add($b, $x), self::MASK_64);
    }

    /**
     * Masks a value to fit within the specified number of bits.
     *
     * @param GMP|string $value The value to mask.
     * @param string $mask The bitmask to apply.
     * @return string The masked value.
     */
    private static function mask(GMP|string $value, string $mask): string
    {
        return gmp_strval(gmp_and($value, $mask));
    }

    /**
     * Adds two 64-bit numbers.
     *
     * @param GMP|string $a The first number.
     * @param GMP|string $b The second number.
     * @return string The result of the addition.
     */
    private static function add(GMP|string $a, GMP|string $b): string
    {
        return gmp_strval(gmp_add($a, $b));
    }

    /**
     * Multiplies two 64-bit numbers.
     *
     * @param GMP|string $a The first number.
     * @param GMP|string $b The second number.
     * @return string The result of the multiplication.
     */
    private static function mul(GMP|string $a, GMP|string $b): string
    {
        return gmp_strval(gmp_mul($a, $b));
    }

    /**
     * Performs a bitwise XOR operation on two numbers.
     *
     * @param GMP|string $a The first number.
     * @param GMP|string $b The second number.
     * @return string The result of the XOR operation.
     */
    private static function xor(GMP|string $a, GMP|string $b): string
    {
        return gmp_strval(gmp_xor($a, $b));
    }

    /**
     * Сдвиг влево для 64-битных чисел
     */
    private static function shiftLeft(GMP|string $value, int $shift): string
    {
        return gmp_strval(gmp_mul($value, gmp_pow(2, $shift)));
    }

    /**
     * Shifts a 64-bit number to the left.
     *
     * @param GMP|string $value The value to shift.
     * @param int $shift The number of bits to shift.
     * @return string The result of the left shift.
     */
    private static function shiftRight(GMP|string $value, int $shift): string
    {
        return gmp_strval(gmp_div($value, gmp_pow(2, $shift)));
    }

    /**
     * Смешивание битов
     */
    private static function shiftMix(GMP|string $value): string
    {
        $value = self::mask($value, self::MASK_64);
        $value = self::xor($value, self::shiftRight($value, 47));

        return self::mask($value, self::MASK_64);
    }

    /**
     * Shifts a 64-bit number to the right.
     *
     * @param GMP|string $value The value to shift.
     * @param int $shift The number of bits to shift.
     * @return string The result of the right shift.
     */
    private static function rotate(GMP|string $value, int $shift): string
    {
        if ($shift === 0) {
            return $value;
        }

        $value = self::mask($value, self::MASK_64);
        $shiftRight = self::shiftRight($value, $shift);
        $shiftLeft = self::shiftLeft($value, 64 - $shift);
        $result = gmp_or($shiftRight, $shiftLeft);

        return self::mask($result, self::MASK_64);
    }

    /**
     * Swaps the byte order of a 64-bit number.
     *
     * @param string $value The input value as a string representing a 64-bit integer.
     * @return string The byte-swapped 64-bit integer as a string.
     */
    private static function byteSwap(string $value): string
    {
        $value = self::mask($value, self::MASK_64);

        $result = gmp_or(
            self::shiftLeft(gmp_and($value, '0xFF'), 56),          // Байт 0 → Байт 7
            gmp_or(
                self::shiftLeft(gmp_and($value, '0xFF00'), 40),    // Байт 1 → Байт 6
                gmp_or(
                    self::shiftLeft(gmp_and($value, '0xFF0000'), 24), // Байт 2 → Байт 5
                    gmp_or(
                        self::shiftLeft(gmp_and($value, '0xFF000000'), 8), // Байт 3 → Байт 4
                        gmp_or(
                            self::shiftRight(gmp_and($value, '0xFF00000000'), 8), // Байт 4 → Байт 3
                            gmp_or(
                                self::shiftRight(gmp_and($value, '0xFF0000000000'), 24), // Байт 5 → Байт 2
                                gmp_or(
                                    self::shiftRight(gmp_and($value, '0xFF000000000000'), 40), // Байт 6 → Байт 1
                                    gmp_and(self::shiftRight($value, 56), '0xFF')             // Байт 7 → Байт 0
                                )
                            )
                        )
                    )
                )
            )
        );

        return gmp_strval($result);
    }

    /**
     * Reads a 64-bit integer from a string at the specified offset.
     *
     * @param string $value The input string containing binary data.
     * @param int $offset The offset in bytes from which to read the 64-bit integer.
     * @return string The 64-bit integer as a string.
     */
    private static function fetch64(string $value, int $offset = 0): string
    {
        $data = substr($value, $offset, 8);
        $data = str_pad($data, 8, "\0"); // Pad data to 8 bytes with zeros if necessary
        $unpacked = unpack('P', $data);
        return (string)($unpacked[1] ?? '');
    }

    /**
     * Reads a 32-bit integer from a string at the specified offset.
     *
     * @param string $value The input string containing binary data.
     * @param int $offset The offset in bytes from which to read the 32-bit integer.
     * @return string The 32-bit integer as a string.
     */
    private static function fetch32(string $value, int $offset = 0): string
    {
        $data = substr($value, $offset, 4);
        $data = str_pad($data, 4, "\0"); // Pad data to 4 bytes with zeros if necessary
        $unpacked = unpack('V', $data);
        return (string)($unpacked[1] ?? '');
    }

    /**
     * Computes a hash for two 64-bit numbers using a multiplier.
     *
     * @param string $a The first 64-bit number as a string.
     * @param string $b The second 64-bit number as a string.
     * @param string $mul The multiplier as a string.
     * @return string The resulting hash as a 64-bit integer string.
     */
    private static function hashLen16(string $a, string $b, string $mul): string
    {
        $a = self::mask(self::mul(self::xor($a, $b), $mul), self::MASK_64);
        $a = self::xor($a, self::shiftRight($a, 47));
        $b = self::mask(self::mul(self::xor($b, $a), $mul), self::MASK_64);
        $b = self::xor($b, self::shiftRight($b, 47));
        return self::mask(self::mul($b, $mul), self::MASK_64);
    }

    /**
     * Computes a weak hash for a 32-byte block using seed values.
     *
     * @param string $string The 32-byte input string.
     * @param string $a The first seed value as a 64-bit integer string.
     * @param string $b The second seed value as a 64-bit integer string.
     * @return array An array containing two 64-bit integers as strings.
     */
    private static function weakHashLen32WithSeeds(string $string, string $a, string $b): array
    {
        return self::weakHashLen32WithSeedsInternal(
            self::fetch64($string),
            self::fetch64(substr($string, 8)),
            self::fetch64(substr($string, 16)),
            self::fetch64(substr($string, 24)),
            $a,
            $b
        );
    }

    /**
     * Computes a weak hash for a 32-byte block using seed values (internal helper method).
     *
     * @param string $w The first 64-bit chunk as a string.
     * @param string $x The second 64-bit chunk as a string.
     * @param string $y The third 64-bit chunk as a string.
     * @param string $z The fourth 64-bit chunk as a string.
     * @param string $a The first seed value as a 64-bit integer string.
     * @param string $b The second seed value as a 64-bit integer string.
     * @return array An array containing two 64-bit integers as strings.
     */
    private static function weakHashLen32WithSeedsInternal(string $w, string $x, string $y, string $z, string $a, string $b): array
    {
        $a = self::add($a, $w);
        $b = self::rotate(self::add($b, self::add($a, $z)), 21);
        $c = $a;
        $a = self::add($a, $x);
        $a = self::add($a, $y);
        $b = self::add($b, self::rotate($a, 44));
        return [self::mask(self::add($a, $z), self::MASK_64), self::mask(self::add($b, $c), self::MASK_64)];
    }
}
