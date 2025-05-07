<?php

declare(strict_types=1);

namespace Diseltoofast\UeParser;

use Diseltoofast\PhpNinja\Endian;
use Diseltoofast\PhpNinja\Stream;
use Diseltoofast\UeParser\Entities\Uasset;
use RuntimeException;

/**
 * UassetReader - A class for reading data from a .uasset file.
 *
 * This class provides functionality to parse and extract data from a .uasset file used by Unreal Engine.
 * It is specifically for 'StringTable' type files.
 * It supports reading the file signature, namespace, strings, and comments, and organizes the data into a structured format.
 *
 * @property Stream $stream The stream used for reading data from the file.
 * @property Uasset $data The parsed data structure containing namespaces, strings, and comments.
 */
class UassetReader
{
    private Stream $stream;
    private Uasset $data;

    /**
     * Initializes the reader with the specified file path and sets up the stream for reading.
     *
     * @param string $filePath The path to the .uasset file to read.
     * @throws RuntimeException If the file does not exist.
     */
    public function __construct(string $filePath)
    {
        if (!is_file($filePath)) {
            throw new RuntimeException(sprintf('File "%s" could not be found.', $filePath));
        }

        $this->stream = new Stream(fopen($filePath, 'rb')); // Open the file in read mode (binary)
        $this->stream->setEndian(Endian::ENDIAN_LITTLE); // Set byte order to Little Endian

        $this->start();
    }

    /**
     * Starts parsing the .uasset file.
     *
     * Reads the file's signature, namespace, strings, and comments. Strings and comments are stored in an internal data structure.
     */
    private function start(): void
    {
        $reader = $this->stream;

        $this->data = new Uasset();

        // Read the signature
        $reader->setPosition($reader->getSize() - 4); // Read the last 4 bytes of the file
        $signature = bin2hex($reader->readString(4));
        if (strtolower($signature) !== strtolower(Uasset::SIGNATURE)) {
            throw new RuntimeException("Invalid Uasset file signature.");
        }

        $reader->setPosition(4); // Skip the first bytes

        $totalHeaderSize = $reader->readUInt32(); // Header size
        $fileType = $reader->readUInt32(); // TODO type or flags
        $reader->readUInt32(); // unknown
        $reader->readUInt32(); // unknown
        $reader->readUInt32(); // unknown
        $importOffset = $reader->readUInt32();
        $importOffset2 = $reader->readUInt32(); // TODO ?
        $exportOffset = $reader->readUInt32();
        $reader->readUInt32(); // unknown
        $reader->readUInt32(); // unknown
        $reader->readUInt32(); // unknown
        $reader->readUInt32(); // unknown
        $nameCount = $reader->readUInt32();
        $nameLength = $reader->readUInt32();

        // TODO only for StringTable
        if (!in_array($fileType, [1, 2], true)) {
            throw new RuntimeException("Unsupported Uasset file type.");
        }

        $reader->setPosition($totalHeaderSize); // Move to the start of string data
        $reader->skipPosition(6); // Skip bytes

        $namespaceLength = $reader->readUInt32(); // Namespace length
        $this->data->namespace = $this->readTrimmedString($namespaceLength); // Namespace itself

        $stringsCount = $reader->readUInt32(); // Number of strings
        for ($i = 0; $i < $stringsCount; $i++) {
            $keyLength = $reader->readInt32(); // Key length
            $keyTitle = $this->readTrimmedString($keyLength); // Key itself
            $strLength = $reader->readUInt32(); // String length
            $strTitle = $this->readTrimmedString($strLength); // String itself

            $this->data->strings[$this->data->namespace][$keyTitle] = $strTitle;
        }

        $commentsCount = $reader->readUInt32(); // Number of comments
        for ($i = 0; $i < $commentsCount; $i++) {
            $keyLength = $reader->readInt32(); // Key length
            $keyTitle = $this->readTrimmedString($keyLength); // Key itself
            $reader->skipPosition(16); // Skip bytes

            // TODO
            $this->data->comments[$this->data->namespace][$keyTitle] = '';
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
     * Retrieves all parsed data from the .uasset file.
     *
     * @return Uasset The parsed data structure containing namespaces, strings, and comments.
     */
    public function getData(): Uasset
    {
        return $this->data;
    }

    /**
     * Retrieves all strings from the .uasset file.
     *
     * @return array An array of strings organized by namespaces.
     */
    public function getStrings(): array
    {
        return $this->data->strings;
    }

    /**
     * Retrieves all comments from the .uasset file.
     *
     * @return array An array of strings organized by namespaces.
     */
    public function getComments(): array
    {
        return $this->data->comments;
    }

    /**
     * Retrieves the namespace from the parsed .uasset file data.
     *
     * This method returns the namespace extracted from the .uasset file during the parsing process.
     * The namespace typically represents a logical grouping or categorization of the data within the file.
     *
     * @return string The namespace as a string.
     */
    public function getNamespace(): string
    {
        return $this->data->namespace;
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
        if (!$data = $this->getData()) {
            throw new RuntimeException("No data");
        }

        file_put_contents($filePath, json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
