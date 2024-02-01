# telegram-entities-decoder
[![Build Status](https://scrutinizer-ci.com/g/LucaDevelop/telegram-entities-decoder/badges/build.png?b=master)](https://scrutinizer-ci.com/g/LucaDevelop/telegram-entities-decoder/build-status/master) [![Latest Stable Version](https://img.shields.io/github/v/release/lucadevelop/telegram-entities-decoder?display_name=tag&label=stable)](https://packagist.org/packages/lucadevelop/telegram-entities-decoder) [![Total Downloads](http://poser.pugx.org/lucadevelop/telegram-entities-decoder/downloads)](https://packagist.org/packages/lucadevelop/telegram-entities-decoder) [![Latest Unstable Version](http://poser.pugx.org/lucadevelop/telegram-entities-decoder/v/unstable)](https://packagist.org/packages/lucadevelop/telegram-entities-decoder) [![License](http://poser.pugx.org/lucadevelop/telegram-entities-decoder/license)](https://packagist.org/packages/lucadevelop/telegram-entities-decoder) [![PHP Version Require](http://poser.pugx.org/lucadevelop/telegram-entities-decoder/require/php)](https://packagist.org/packages/lucadevelop/telegram-entities-decoder)

![EntityDecoder](https://user-images.githubusercontent.com/68305127/164949030-622a200e-8c18-4480-b8e2-08476801bb90.PNG)

This class decode style entities from Telegram bot messages (bold, italic, etc.) in text with inline entities that duplicate (when possible) the
exact style the message had originally when was sended to the bot.
All this work is necessary because Telegram returns offset and length of the entities in UTF-16 code units that they've been hard to decode correctly in PHP 

## Compatibility
PHP >= 7.0

## Features
- Decode entities from text messages and attachments caption.
- Supports all Telegram parse modes (Markdown, HTML and MarkdownV2). HTML has more entropy but it's easily the best and it's recommended.
- Supports emoji in the text field
- Easy to use

_NOTE: Markdown parse mode is deprecated and no longer up-to-date so it doesn't support all entities. Use MarkdownV2 or HTML._

## Example usage
```
$entity_decoder = new EntityDecoder('HTML');
$decoded_text = $entity_decoder->decode($message);
```
_See demo folder for full example_

## Composer
```
composer require lucadevelop/telegram-entities-decoder
```
Usage:
```
require 'vendor/autoload.php';
use lucadevelop\TelegramEntitiesDecoder\EntityDecoder;
[...]
$entity_decoder = new EntityDecoder('HTML');
$decoded_text = $entity_decoder->decode($message);
```

## Credits
- Telegram docs: https://core.telegram.org/bots/api#formatting-options
- Inspired By: https://github.com/php-telegram-bot/core/issues/544#issuecomment-564950430

## Contacts
![Telegram](https://telegram.org/favicon.ico) [@LucaDevelop](https://t.me/LucaDevelop)
