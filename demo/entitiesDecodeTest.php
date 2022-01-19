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
        "text": "Hi,\nthis is bold \ud83d\ude0e\nthis is italic \ud83d\udc4d\nthis is mono \ud83d\ude0d",
        "entities": [
            {
                "offset": 4,
                "length": 16,
                "type": "bold"
            },
            {
                "offset": 20,
                "length": 18,
                "type": "italic"
            },
            {
                "offset": 38,
                "length": 15,
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