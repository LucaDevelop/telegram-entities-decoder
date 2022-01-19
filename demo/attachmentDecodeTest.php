<?php

include '../src/EntityDecoder.php';

use lucadevelop\TelegramEntitiesDecoder\EntityDecoder;

$telegramUpdateExample = '
{
    "update_id": 123456789,
    "message": {
      "message_id": 1234,
      "from": {
        "id": 123456789,
        "is_bot": false,
        "first_name": "First Name",
        "username": "UserName",
        "language_code": "en"
      },
      "chat": {
        "id": 123456789,
        "first_name": "First Name",
        "username": "UserName",
        "type": "private"
      },
      "date": 1594740863,
      "photo": [
        {
          "file_id": "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
          "file_unique_id": "AAAAAAAA",
          "file_size": 1004,
          "width": 90,
          "height": 71
        },
        {
          "file_id": "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
          "file_unique_id": "AAAAAAAA",
          "file_size": 13572,
          "width": 320,
          "height": 254
        },
        {
          "file_id": "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
          "file_unique_id": "AAAAAAAA",
          "file_size": 64349,
          "width": 800,
          "height": 636
        },
        {
          "file_id": "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
          "file_unique_id": "AAAAAAAA",
          "file_size": 78531,
          "width": 959,
          "height": 762
        }
      ],
      "caption": "Hi,\nThis is bold\nthis is italic\nthis is mono",
      "caption_entities": [
        {
          "offset": 4,
          "length": 13,
          "type": "bold"
        },
        {
          "offset": 17,
          "length": 15,
          "type": "italic"
        },
        {
          "offset": 32,
          "length": 12,
          "type": "code"
        }
      ]
    }
  }';

$updateObj = json_decode($telegramUpdateExample);

$entity_decoder = new EntityDecoder('HTML');
$decoded_text = $entity_decoder->decode($updateObj->message);

echo $decoded_text;
?>