Unreal Engine Parser
==============

This is a PHP library for parsing Unreal Engine files

## Installation

Requirements
- PHP 8.0 or higher
- ext-mbstring
- ext-gmp

Install via Composer:

```bash
composer require diseltoofast/ue-parser
```

## Locres (Reading)

```php
$filePath = '/some/dir/Game.locres';
// Initialize a LocresReader instance to parse data from a .locres file
$parser = new \Diseltoofast\UeParser\LocresReader($filePath);
// Alternatively, enable additional fields by passing `true` as the second argument
$parser = new \Diseltoofast\UeParser\LocresReader($filePath, true); // includes extra metadata
// Retrieve all information from the .locres file as an object
$data = $parser->getData();
// Retrieve all information from the .locres file in JSON format
$json = $parser->getJSON();
// Retrieve only the namespaces from the .locres file as an array
$namespaces = $parser->getNamespaces();
// Retrieve only the strings from the .locres file as an array
$strings = $parser->getStrings();
// Save all data to a JSON file
$parser->saveJSON();
// Save all data to a CSV file; line-break characters are replaced:
// "\r\n" → "<crlf>", "\r" → "<cr>", "\n" → "<lf>"
$parser->saveCSV();
```

## Locres (Writing)

```php
$filePath = '/some/dir/Game.locres';
// Initialize a LocresWriter instance to save data to a .locres file
$parser = new \Diseltoofast\UeParser\LocresWriter($filePath);
// Initialize LocresString instances and add strings in bulk
$writer->setStrings([
    new \Diseltoofast\UeParser\Entities\LocresString(
        "ST_AltarStaticTexts",                       // Namespace
        3755353534,                                  // Namespace hash (CityHash64)
        "LOC_AD_Character_BodyText_LevelingHint",    // Key
        2370108482,                                  // Key hash (CityHash64)
        "You must use a bed to sleep and meditate.", // Value
        2087340891                                   // Value hash (CRC)
    ),
    new \Diseltoofast\UeParser\Entities\LocresString(
        "LOC_AD_Help_Title_Tutorials", 
        3755353534, 
        "LOC_AD_Help_Title_Tutorials", 
        3927305278, 
        "Tutorials", 
        2210848957
    ),
    new \Diseltoofast\UeParser\Entities\LocresString(
        "ST_Descriptions", 
        1300630429, 
        "LOC_DE_ABAT", 
        851339799, 
        "This is the big description for Absorb Attribute.", 
        2146950682
    ),
]);
// Alternatively, add strings one by one
$writer->setString(new \Diseltoofast\UeParser\Entities\LocresString(
    "ST_AltarStaticTexts", 
    3755353534, 
    "LOC_AD_Character_BodyText_LevelingHint", 
    2370108482, 
    "You must use a bed to sleep and meditate.", 
    2087340891
));
$writer->setString(new \Diseltoofast\UeParser\Entities\LocresString(
    "LOC_AD_Help_Title_Tutorials", 
    3755353534, 
    "LOC_AD_Help_Title_Tutorials", 
    3927305278, 
    "Tutorials", 
    2210848957
));
// If you don't know the hash values for the namespace, key, or value of the strings, you can specify 0 for these fields. The library will automatically generate the required hashes.
$writer->setString(new \Diseltoofast\UeParser\Entities\LocresString(
    "LOC_AD_Help_Title_Tutorials",
    0,                             // set to 0 for auto-generation
    "LOC_AD_Help_Title_Tutorials",
    0,                             // set to 0 for auto-generation
    "Tutorials",
    0                              // set to 0 for auto-generation
));
// Save to a .locres file
$writer->save();
```

## Uasset (Reading)

It is specifically for 'StringTable' type files.

```php
$filePath = '/some/dir/Localization.uasset';
// Initialize a UassetReader instance to parse data from a .uasset file
$parser = new \Diseltoofast\UeParser\UassetReader($filePath);
// Retrieve all information from the .uasset file as an object
$data = $parser->getData();
// Retrieve all information from the .uasset file in JSON format
$json = $parser->getJSON();
// Retrieve only the namespace from the .uasset file as a string
$namespace = $parser->getNamespace();
// Retrieve only the strings from the .uasset file as an array
$strings = $parser->getStrings();
// Retrieve only the comments from the .uasset file as an array
$strings = $parser->getComments();
// Save all data to a JSON file
$parser->saveJSON();
```

## Methods to hash strings 
Available algorithms: CityHash64, CRC.

```php
echo \Diseltoofast\UeParser\Hash\Crc::hash('Hello world!');
echo \Diseltoofast\UeParser\Hash\CityHash64::hash('Hello world!'); // returns a 32-bit value
```
