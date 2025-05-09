<?php

declare(strict_types=1);

namespace Diseltoofast\UeParser;

use Diseltoofast\PhpNinja\Endian;
use Diseltoofast\PhpNinja\Stream;
use Diseltoofast\UeParser\Entities\Locres;
use Diseltoofast\UeParser\Entities\LocresString;
use RuntimeException;

/**
 * LocresReader - A class for reading data from a .locres file.
 *
 * This class provides functionality to parse and extract localized strings from a .locres file used by Unreal Engine.
 * It supports reading namespaces, keys, and values, as well as exporting data to JSON or CSV formats.
 *
 * @property Stream $stream The stream used for reading data from the file.
 * @property Locres $data The parsed data structure containing strings, namespaces, and metadata.
 * @property bool $fullInfo Whether to include full metadata (hashes, counts) or just the string values.
 */
class LocresReader
{
    private Stream $stream;
    private Locres $data;
    private bool $fullInfo;

    /**
     * Initializes the reader with the specified file path and optional full metadata mode.
     *
     * @param string $filePath The path to the .locres file to read.
     * @param bool $fullInfo If true, includes full metadata (hashes, counts); otherwise, only string values are included.
     * @throws RuntimeException If the file does not exist.
     */
    public function __construct(string $filePath, bool $fullInfo = false)
    {
        if (!is_file($filePath)) {
            throw new RuntimeException(sprintf('File "%s" could not be found.', $filePath));
        }

        $this->stream = new Stream(fopen($filePath, 'rb')); // Open the file in read mode (binary)
        $this->stream->setEndian(Endian::ENDIAN_LITTLE); // Set byte order to Little Endian
        $this->fullInfo = $fullInfo;

        $this->start();
    }

    /**
     * Starts parsing the .locres file.
     *
     * Reads the file's header, namespaces, keys, and string data. Strings are stored in an internal buffer,
     * and duplicate strings are excluded based on their indices.
     */
    private function start(): void
    {
        $strings = [];
        $reader = $this->stream;

        $this->data = new Locres();

        $magicBytes = bin2hex($reader->readString(16));
        $this->data->version = $reader->readInt8();

        if (!($this->data->version === Locres::FILE_VERSION && strtolower($magicBytes) === strtolower(Locres::MAGIC))) {
            throw new RuntimeException("Unsupported Locres version");
        }

        $offset = $reader->readUInt64(); // Offset to the start of string data
        $this->data->stringsCount = $reader->readUInt32(); // Total number of strings
        $offsetCurrent = $reader->getPosition(); // Save current position

        $reader->setPosition($offset); // Move to the start of string data

        $this->data->stringsWithoutDoubles = $reader->readUInt32(); // Number of unique strings
        for ($i = 0; $i < $this->data->stringsWithoutDoubles; $i++) {
            $strLength = $reader->readInt32(); // String length

            // If UTF-16
            if ($strLength < 0) {
                $text = $reader->readStringUTF16($strLength * -2, 'UTF-8'); // Read string in UTF-16
            } else {
                $text = $reader->readString($strLength); // Read string in ASCII
            }

            $text = rtrim($text, "\0"); // Remove the trailing null byte
            $strCount = $reader->readUInt32(); // Count of string occurrences

            $strings[$i] = ['text' => $text, 'count' => $strCount]; // Store strings in the buffer
        }

        $reader->setPosition($offsetCurrent); // Return to the saved position

        $namespaceCount = $reader->readUInt32(); // Number of namespaces
        for ($i = 0; $i < $namespaceCount; $i++) {
            $namespaceHash = $reader->readUInt32(); // Namespace hash
            $namespaceLength = $reader->readUInt32(); // Namespace length
            $namespaceTitle = $this->readTrimmedString($namespaceLength); // Namespace itself

            $keyCount = $reader->readUInt32(); // Number of keys
            for ($n = 0; $n < $keyCount; $n++) {
                $keyHash = $reader->readUInt32(); // Key hash
                $keyLength = $reader->readUInt32(); // Key length
                $keyTitle = $this->readTrimmedString($keyLength); // Key itself
                $strHash = $reader->readUInt32(); // String hash
                $strIndex = $reader->readUInt32(); // String index
                $strText = $strings[$strIndex] ?? ''; // Get string from the buffer by index

                if ($strText) {
                    if ($this->fullInfo) {
                        $this->data->strings[$namespaceTitle][$keyTitle] = new LocresString($namespaceTitle, $namespaceHash, $keyTitle, $keyHash, $strText['text'], $strHash, $strText['count']);
                    } else {
                        $this->data->strings[$namespaceTitle][$keyTitle] = $strText['text'];
                    }
                }
            }
        }
    }

    /**
     * Reads a string of the specified length from the stream and trims null bytes (\0) from the end.
     *
     * This helper method reads a string from the file stream using the provided length, then removes any trailing
     * null bytes to ensure clean data. It is useful for reading null-terminated strings commonly found in binary files.
     *
     * @param int $length The length of the string to read from the stream.
     * @return string The trimmed string with null bytes removed.
     */
    private function readTrimmedString(int $length): string
    {
        return rtrim($this->stream->readString($length), "\0");
    }

    /**
     * Retrieves all parsed data from the .locres file.
     *
     * @return Locres The parsed data structure containing strings, namespaces, and metadata.
     */
    public function getData(): Locres
    {
        return $this->data;
    }

    /**
     * Retrieves all strings from the .locres file.
     *
     * @return array An array of strings organized by namespaces and keys.
     */
    public function getStrings(): array
    {
        return $this->data->strings;
    }

    /**
     * Retrieves all namespaces from the .locres file.
     *
     * @return array An array of namespaces with their corresponding hashes.
     */
    public function getNamespaces(): array
    {
        $namespaces = [];
        if ($strings = $this->getStrings()) {
            /** @var LocresString $string */
            foreach ($strings as $string) {
                $namespaces[$string->namespaceHash] = $string->namespace;
            }
        }
        return $namespaces;
    }

    /**
     * Converts the parsed data into a JSON string.
     *
     * @return string The JSON representation of the parsed data.
     */
    public function getJSON(): string
    {
        if ($data = $this->getData()) {
            return json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        return '';
    }

    /**
     * Saves the parsed data to a file in JSON format.
     *
     * @param string $filePath The path to the output JSON file.
     * @throws RuntimeException If no data is available to save.
     */
    public function saveJSON(string $filePath): void
    {
        if (!$data = $this->getStrings()) {
            throw new RuntimeException("No data");
        }

        file_put_contents($filePath, json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    /**
     * Saves the parsed data to a file in CSV format.
     *
     * @param string $filePath The path to the output CSV file.
     * @throws RuntimeException If no data is available to save.
     */
    public function saveCSV(string $filePath): void
    {
        if (!$data = $this->getStrings()) {
            throw new RuntimeException("No data");
        }

        $fp = fopen($filePath, 'wb');

        if ($this->fullInfo) {
            fputcsv($fp, ['namespace', 'namespaceHash', 'key', 'keyHash', 'value', 'valueHash', 'count']);
        } else {
            fputcsv($fp, ['namespace', 'key', 'value']);
        }

        foreach ($data as $namespace => $namespaces) {
            foreach ($namespaces as $keyString => $string) {
                if ($this->fullInfo) {
                    /** @var LocresString $string */
                    fputcsv($fp, [
                        $string->namespace,
                        $string->namespaceHash,
                        $string->key,
                        $string->keyHash,
                        self::replaceBreaklines($string->value),
                        $string->valueHash,
                        $string->count
                    ]);
                } else {
                    fputcsv($fp, [
                        $namespace,
                        $keyString,
                        self::replaceBreaklines($string)
                    ]);
                }
            }
        }

        fclose($fp);
    }

    /**
     * Replaces line breaks in a string with special markers.
     *
     * @param string $string The input string.
     * @return string The modified string with line breaks replaced by markers (<crlf>, <cr>, <lf>).
     */
    public static function replaceBreaklines(string $string): string
    {
        return str_replace(["\r\n", "\r", "\n"], ['<crlf>', '<cr>', '<lf>'], $string);
    }
}
