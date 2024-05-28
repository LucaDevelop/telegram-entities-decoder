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
        "text": "Hi,\nthis is bold, this is bold and italic,\nthis is mono",
        "entities": [
            {
                "offset": 4,
                "length": 13,
                "type": "bold"
            },
            {
                "offset": 17,
                "length": 25,
                "type": "bold"
            },
            {
                "offset": 17,
                "length": 24,
                "type": "italic"
            },
            {
                "offset": 43,
                "length": 12,
                "type": "code"
            }
        ]
    }
}';

$updateObj = json_decode($telegramUpdateExample);

$entity_decoder = new EntityDecoder('HTML');
$decoded_entities = $entity_decoder->extractAllEntities($updateObj->message);

echo json_encode($decoded_entities, JSON_PRETTY_PRINT);
?>