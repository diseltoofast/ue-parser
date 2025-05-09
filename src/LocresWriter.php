<?php

declare(strict_types=1);

namespace Diseltoofast\UeParser;

use Diseltoofast\PhpNinja\Endian;
use Diseltoofast\PhpNinja\Stream;
use Diseltoofast\UeParser\Entities\Locres;
use Diseltoofast\UeParser\Entities\LocresString;
use Diseltoofast\UeParser\Hash\Crc;
use Diseltoofast\UeParser\Hash\CityHash64;
use RuntimeException;

/**
 * LocresWriter - A class for writing data to a .locres file.
 *
 * This class provides functionality to write localized strings into a .locres file format used by Unreal Engine.
 * It supports generating hashes for namespaces, keys, and values, excluding duplicate strings, and writing metadata.
 * The class ensures proper formatting of the file, including handling ASCII and UTF-16 encodings for string values.
 *
 * @property Stream $stream The stream used for writing data to the file.
 * @property LocresString[] $strings An array of strings organized by namespaces.
 */
class LocresWriter
{
    private Stream $stream;
    private array $strings;

    /**
     * Initializes a new instance of the LocresWriter class.
     *
     * Opens a file stream in binary write mode and sets the byte order to Little Endian.
     *
     * @param string $filePath The path to the file where data will be written.
     */
    public function __construct(string $filePath)
    {
        $this->stream = new Stream(fopen($filePath, 'wb')); // Open the file in write mode (binary)
        $this->stream->setEndian(Endian::ENDIAN_LITTLE); // Set byte order to Little Endian
    }

    /**
     * Generates a hash for a given value if the current hash is set to 0.
     *
     * This method checks if the current hash is 0. If so, it generates a new hash using the provided hash function.
     * Otherwise, it returns the existing hash value.
     *
     * @param int $currentHash The current hash value (0 indicates that a new hash needs to be generated).
     * @param string $value The input value for which the hash is generated.
     * @param callable $hashFunction A callable hash function used to generate the hash (e.g., CityHash64::hash or Crc::hash).
     * @return int The generated or existing hash value.
     */
    private static function generateHash(int $currentHash, string $value, callable $hashFunction): int
    {
        return $currentHash === 0 ? $hashFunction($value) : $currentHash;
    }

    /**
     * Adds a single string to the writer's internal storage.
     *
     * If the namespace, key, or value hash is set to 0, this method automatically generates the required hashes
     * using appropriate hashing algorithms (e.g., CityHash64 for namespace and key, CRC for value). After generating
     * the hashes, the string is added to the internal storage, organized by namespaces.
     *
     * @param LocresString $string The string object to add.
     */
    public function setString(LocresString $string): void
    {
        // Generate hashes
        $string->namespaceHash = self::generateHash($string->namespaceHash, $string->namespace, [CityHash64::class, 'hash']);
        $string->keyHash = self::generateHash($string->keyHash, $string->key, [CityHash64::class, 'hash']);
        $string->valueHash = self::generateHash($string->valueHash, $string->value, [Crc::class, 'hash']);

        // Add the string to the array, organized by namespaces
        $this->strings[$string->namespace][] = $string;
    }

    /**
     * Adds an array of strings to the writer's internal storage.
     *
     * Iterates through the provided array and calls `setString` for each string object.
     *
     * @param LocresString[] $strings An array of LocresString objects to add.
     */
    public function setStrings(array $strings): void
    {
        foreach ($strings as $string) {
            $this->setString($string);
        }
    }

    /**
     * Saves the accumulated data to the .locres file.
     *
     * This method writes the file header, namespaces, keys, and string values to the file. It excludes duplicate
     * strings, calculates offsets, and handles both ASCII and UTF-16 encodings for string values.
     *
     * @throws RuntimeException If no data is available to write.
     */
    public function save(): void
    {
        if (!$this->strings) {
            throw new RuntimeException("No data");
        }

        $writer = $this->stream;

        // Write the magic number and version
        $writer->writeString(hex2bin(Locres::MAGIC));
        $writer->writeInt8(Locres::FILE_VERSION);

        // Save the current position for later header overwrite
        $offsetCurrent = $writer->getPosition(); // Save current position
        $writer->writeUInt64(0); // Offset to the start of string data (temporarily 0)
        $writer->writeUInt32(0); // Total number of strings (temporarily 0)
        $writer->writeUInt32(count($this->strings)); // Number of namespaces

        $stringsCount = 0; // String counter
        $stringsBuffer = []; // Buffer to exclude duplicates

        // Write data by namespaces
        $n = 0;
        foreach ($this->strings as $strings) {
            $namespaceNext = 0;
            /** @var LocresString $string */
            foreach ($strings as $string) {
                // Exclude duplicate strings
                $genHash = crc32($string->value);
                if (isset($stringsBuffer[$genHash])) {
                    $stringsBuffer[$genHash]['count'] += 1; // Increment duplicate count
                } else {
                    $stringsBuffer[$genHash] = ['index' => $n, 'value' => $string->value, 'count' => 1];
                    $n++;
                }

                // Write the namespace (only for the first string in the namespace)
                if ($namespaceNext === 0) {
                    $string->namespace .= "\0"; // Add null terminator
                    $writer->writeUInt32($string->namespaceHash); // Namespace hash
                    $writer->writeUInt32(mb_strlen($string->namespace, 'UTF-8')); // Namespace length
                    $writer->writeString($string->namespace); // Namespace itself
                    $writer->writeUInt32(count($strings)); // Number of strings in the namespace
                    $namespaceNext = 1;
                }

                // Write string information
                $string->key .= "\0"; // Add null terminator
                $writer->writeUInt32($string->keyHash); // Key hash
                $writer->writeUInt32(mb_strlen($string->key, 'UTF-8')); // Key length
                $writer->writeString($string->key); // Key itself
                $writer->writeUInt32($string->valueHash); // String hash
                $writer->writeUInt32($stringsBuffer[$genHash]['index']); // String index

                $stringsCount++;
            }
        }

        // Write string data
        $stringsOffset = $writer->getPosition(); // Save the offset of the string data start
        $writer->writeUInt32(count($stringsBuffer)); // Number of unique strings

        foreach ($stringsBuffer as $string) {
            $string['value'] .= "\0"; // Add null terminator
            $isAscii = mb_check_encoding($string['value'], 'ASCII'); // Check encoding
            $strLength = mb_strlen($string['value'], 'UTF-8') * ($isAscii ? 1 : -1); // String length (consider UTF-16)

            $writer->writeInt32($strLength); // Write string length
            if ($isAscii) {
                $writer->writeString($string['value']); // Write string in ASCII
            } else {
                $writer->writeStringUTF16($string['value'], 'UTF-8'); // Write string in UTF-16
            }

            $writer->writeUInt32($string['count']); // Write duplicate count
        }

        // Overwrite the header
        $writer->setPosition($offsetCurrent); // Move to the saved position
        $writer->writeUInt64($stringsOffset); // Write offset to the start of string data
        $writer->writeUInt32($stringsCount); // Write total number of strings
    }
}
