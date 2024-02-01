<?php
/**
 * This class decode style entities from Telegram bot messages (bold, italic, etc.) in text with inline entities that duplicate (when possible) the
 * exact style the message has originally when was sended to the bot.
 * All this work is necessary because Telegram returns offset and length of the entities in UTF-16 code units that they've been hard to decode correctly in PHP
 *
 * Inspired By: https://github.com/php-telegram-bot/core/issues/544#issuecomment-564950430
 *
 * Example usage:
 * $entity_decoder = new EntityDecoder('HTML');
 * $decoded_text = $entity_decoder->decode($message);
 *
 * @author LucaDevelop
 * @access public
 * @see https://github.com/LucaDevelop/telegram-entities-decoder
 */

namespace lucadevelop\TelegramEntitiesDecoder;

class EntityDecoder
{
    private $entitiesToParse = ['bold', 'italic', 'code', 'pre', 'text_mention', 'text_link', 'strikethrough', 'underline', 'spoiler', 'blockquote', 'custom_emoji'];
    private $entities = [];
    private $style;

      /**
       * @param string $style       Either 'HTML', 'Markdown' or 'MarkdownV2'.
       *
       * @throws InvalidArgumentException if the provided style name in invalid.
       */
    public function __construct(string $style = 'HTML')
    {
        if (in_array($style, ["HTML", "MarkdownV2", "Markdown"]))
        {
            $this->style = $style;
        }
        else
        {
            throw new \InvalidArgumentException("Wrong style name");
        }
    }

    /**
     * Decode entities and return decoded text
     *
     * @param object $message       message object to reconstruct Entities from (json decoded without assoc).
     * @return string
     */
    public function decode($message): string
    {
        if (!is_object($message))
        {
            throw new \Exception('message must be an object');
        }
        //Get available entities (for text or for attachment like photo, document, etc.)
        if (!empty($message->entities))
        {
            $this->entities = $message->entities;
        }
        if (!empty($message->caption_entities))
        {
            $this->entities = $message->caption_entities;
        }
        //Get internal encoding
        $prevencoding = mb_internal_encoding();
        //Set encoding to UTF-8
        mb_internal_encoding('UTF-8');
        //Get available text (text message or caption for attachment)
        $textToDecode = (!empty($message->text) ? $message->text : (!empty($message->caption) ? $message->caption : ""));
        //if the message has no entities or no text return the original text
        if (empty($this->entities) || $textToDecode == "") {
            if ($prevencoding)
            {
                mb_internal_encoding($prevencoding);
            }
            return $textToDecode;
        }
        //split text in char array with UTF-16 code units length
        $arrayText = $this->splitCharAndLength($textToDecode);
        $finalText = "";

        $openedEntities = [];
        $currenPosition = 0;
        //Cycle characters one by one to calculate begins and ends of entities and escape special chars
        for ($i = 0, $c = count($arrayText); $i < $c; $i++) {
            $offsetAndLength = $currenPosition + $arrayText[$i]['length'];
            $entityCheckStart = $this->checkForEntityStart($currenPosition);
            $entityCheckStop = $this->checkForEntityStop($offsetAndLength);
            if ($entityCheckStart !== false)
            {
                foreach ($entityCheckStart as $stEntity)
                {
                    $startChar = $this->getEntityStartString($stEntity);
                    $openedEntities[] = $stEntity;
                    $finalText .= $startChar;
                }
                $finalText .= $this->escapeSpecialChars($arrayText[$i]['char'], true, $openedEntities);
            }
            if ($entityCheckStop !== false)
            {
                if ($entityCheckStart === false)
                {
                    $finalText .= $this->escapeSpecialChars($arrayText[$i]['char'], true, $openedEntities);
                }
                if ($this->style == 'MarkdownV2' && $this->checkMarkdownV2AmbiguousEntities($entityCheckStop))
                {
                    $stopChar = "_\r__";
                    $finalText .= $stopChar;
                    array_pop($openedEntities);
                    array_pop($openedEntities);
                }
                foreach ($entityCheckStop as $stEntity)
                {
                    $stopChar = $this->getEntityStopString($stEntity);
                    $finalText .= $stopChar;
                    array_pop($openedEntities);
                }
            }
            if ($entityCheckStart === false && $entityCheckStop === false)
            {
                $isEntityOpen = !empty($openedEntities);
                $finalText .= $this->escapeSpecialChars($arrayText[$i]['char'], $isEntityOpen, $openedEntities);
            }
            $currenPosition = $offsetAndLength;
        }
        if (!empty($openedEntities))
        {
            $openedEntities = array_reverse($openedEntities);
            foreach ($openedEntities as $oe)
            {
                $finalText .= $this->getEntityStopString($oe);
            }
        }
        if ($prevencoding)
        {
            mb_internal_encoding($prevencoding);
        }

        return $finalText;
    }

    /**
     * Split message text in chars array with lengthes
     */
    protected function splitCharAndLength($string)
    {
        //Split string in individual unicode points
        $str_split_unicode = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        $new_string_split = [];
        $joiner = false;
        for ($i = 0, $c = count($str_split_unicode); $i < $c; $i++)
        {
            //loop the array
            $codepoint = bin2hex(mb_convert_encoding($str_split_unicode[$i], 'UTF-16')); //Get the string rappresentation of the unicode char
            if ($codepoint == "fe0f" || $codepoint == "1f3fb" || $codepoint == "1f3fc" || $codepoint == "1f3fd" || $codepoint == "1f3fe" || $codepoint == "1f3ff")
            {
                //Manage the modifiers
                $new_string_split[count($new_string_split) - 1] .= $str_split_unicode[$i]; //Apppend the modifier to the previous char
            }
            else
            {
                if ($codepoint == "200d")
                {
                    //Manage the Zero Width Joiner
                    $new_string_split[count($new_string_split) - 1] .= $str_split_unicode[$i]; //Apppend the ZWJ to the previous char
                    $joiner = true;
                }
                else
                {
                    if ($joiner)
                    {
                        //If previous one was a ZWJ
                        $new_string_split[count($new_string_split) - 1] .= $str_split_unicode[$i]; //Apppend to the previous char
                        $joiner = false;
                    }
                    else
                    {
                        $new_string_split[] = $str_split_unicode[$i]; //New char
                    }
                }
            }
        }
        $data = [];
        foreach ($new_string_split as $s)
        {
          $data[] = ["char" => $s, "length" => $this->getUTF16CodePointsLength($s)];
        }
        return $data;
    }

    /**
     * Apply Telegram escape rules for the choosen style
     */
    protected function escapeSpecialChars($char, $isEntityOpen, $entities) {
        if ($this->style == 'Markdown')
        {
            if ($isEntityOpen)
            {
                $entity = $entities[0];
                if ($char == '*' || $char == '_')
                {
                    if ($char == $this->getEntityStartString($entity))
                    {
                        return $char."\\".$char.$char;
                    }
                    else
                    {
                        return $char;
                    }
                }
                else
                {
                    return $char;
                }
            }
            else
            {
                if ($char == '*' || $char == '_' || $char == '[' || $char == '`')
                {
                    return "\\".$char;
                }
                else
                {
                    return $char;
                }
            }
        }
        else if ($this->style == 'HTML')
        {
            return ($char == '<' ? '&lt;' : ($char == '>' ? '&gt;' : ($char == '&' ? '&amp;' : $char)));
        }
        else if ($this->style == 'MarkdownV2')
        {
            $isBlockquoteOpen = false;
            foreach ($entities as $entity) {
                if ($entity->type === 'blockquote') {
                    $isBlockquoteOpen = true;
                    break;
                }
            }
            if($isBlockquoteOpen && $char == "\n")
            {
                return $char.'>';
            }
            else
            {
                return (in_array($char, ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!', '\\']) ? '\\'.$char : $char);
            }
        }
        else
        {
            return $char;
        }
    }

    /**
     * Get the begin string of the entity  for the choosen style
     */
    protected function getEntityStartString($entity)
    {
        $startString = '';
        if ($this->style == 'Markdown')
        {
            switch ($entity->type)
            {
                case 'bold':
                {
                    $startString = '*';
                    break;
                }
                case 'italic':
                {
                    $startString = '_';
                    break;
                }
                case 'code':
                {
                    $startString = '`';
                    break;
                }
                case 'pre':
                {
                    $startString = '```';
                    if (isset($entity->language))
                    {
                        $startString .= $entity->language;
                    }
                    $startString .= "\n";
                    break;
                }
                case 'text_mention':
                case 'text_link':
                {
                    $startString = '[';
                    break;
                }
            }
        }
        else if ($this->style == 'HTML')
        {
            switch ($entity->type)
            {
                case 'bold':
                {
                    $startString = '<b>';
                    break;
                }
                case 'italic':
                {
                    $startString = '<i>';
                    break;
                }
                case 'underline':
                {
                    $startString = '<u>';
                    break;
                }
                case 'strikethrough':
                {
                    $startString = '<s>';
                    break;
                }
                case 'spoiler':
                {
                    $startString = '<span class="tg-spoiler">';
                    break;
                }
                case 'code':
                {
                    $startString = '<code>';
                    break;
                }
                case 'pre':
                {
                    $startString = '<pre>';
                    if (isset($entity->language))
                    {
                        $startString .= '<code class="language-'.$entity->language.'">';
                    }
                    break;
                }
                case 'text_mention':
                {
                    $startString = '<a href="tg://user?id='.$entity->user->id.'">';
                    break;
                }
                case 'text_link':
                {
                    $startString = '<a href="'.$entity->url.'">';
                    break;
                }
                case 'custom_emoji':
                {
                    $startString = '<tg-emoji emoji-id="'.$entity->custom_emoji_id.'">';
                    break;
                }
                case 'blockquote':
                {
                    $startString = '<blockquote>';
                    break;
                }
            }
        }
        else if ($this->style == 'MarkdownV2')
        {
            switch ($entity->type)
            {
                case 'bold':
                {
                    $startString = '*';
                    break;
                }
                case 'italic':
                {
                    $startString = '_';
                    break;
                }
                case 'spoiler':
                {
                    $startString = '||';
                    break;
                }
                case 'code':
                {
                    $startString = '`';
                    break;
                }
                case 'pre':
                {
                    $startString = '```';
                    if (isset($entity->language))
                    {
                        $startString .= $entity->language;
                    }
                    $startString .= "\n";
                    break;
                }
                case 'underline':
                {
                    $startString .= '__';
                    break;
                }
                case 'strikethrough':
                {
                    $startString .= '~';
                    break;
                }
                case 'text_mention':
                case 'text_link':
                {
                    $startString = '[';
                    break;
                }
                case 'custom_emoji':
                {
                    $startString = '![';
                    break;
                }
                case 'blockquote':
                {
                    $startString = '>';
                    break;
                }
            }
        }
        return $startString;
    }

    /**
     * Check if there are entities that start at the given position and return them
     */
    protected function checkForEntityStart($pos)
    {
        $entities = [];
        foreach ($this->entities as $entity)
        {
            if ($entity->offset == $pos)
            {
                if (in_array($entity->type, $this->entitiesToParse))
                {
                    $entities[] = $entity;
                }
            }
        }
        if (!empty($entities)) {
            return $entities;
        } else {
            return false;
        }
    }

    /**
     * Get the end string of the entity  for the choosen style
     */
    protected function getEntityStopString($entity)
    {
        $stopString = '';
        if ($this->style == 'Markdown')
        {
            switch ($entity->type)
            {
                case 'bold':
                {
                    $stopString = '*';
                    break;
                }
                case 'italic':
                {
                    $stopString = '_';
                    break;
                }
                case 'code':
                {
                    $stopString = '`';
                    break;
                }
                case 'pre':
                {
                    $stopString = "\n".'```';
                    break;
                }
                case 'text_mention':
                {
                    $stopString = '](tg://user?id='.$entity->user->id.')';
                    break;
                }
                case 'text_link':
                {
                    $stopString = ']('.$entity->url.')';
                    break;
                }
            }
        }
        else if ($this->style == 'HTML')
        {
            switch ($entity->type)
            {
                case 'bold':
                {
                    $stopString = '</b>';
                    break;
                }
                case 'italic':
                {
                    $stopString = '</i>';
                    break;
                }
                case 'underline':
                {
                    $stopString = '</u>';
                    break;
                }
                case 'strikethrough':
                {
                    $stopString = '</s>';
                    break;
                }
                case 'spoiler':
                {
                    $stopString = '</span>';
                    break;
                }
                case 'code':
                {
                    $stopString = '</code>';
                    break;
                }
                case 'pre':
                {
                    if (isset($entity->language))
                    {
                        $stopString = '</code>';
                    }
                    $stopString .= '</pre>';
                    break;
                }
                case 'text_mention':
                case 'text_link':
                {
                    $stopString = '</a>';
                    break;
                }
                case 'custom_emoji':
                {
                    $stopString = '</tg-emoji>';
                    break;
                }
                case 'blockquote':
                {
                    $stopString = '</blockquote>';
                    break;
                }
            }
        }
        else if ($this->style == 'MarkdownV2')
        {
            switch ($entity->type)
            {
                case 'bold':
                {
                    $stopString = '*';
                    break;
                }
                case 'italic':
                {
                    $stopString = '_';
                    break;
                }
                case 'spoiler':
                {
                    $stopString = '||';
                    break;
                }
                case 'code':
                {
                    $stopString = '`';
                    break;
                }
                case 'pre':
                {
                    $stopString = "\n".'```';
                    break;
                }
                case 'underline':
                {
                    $stopString = '__';
                    break;
                }
                case 'strikethrough':
                {
                    $stopString = '~';
                    break;
                }
                case 'text_mention':
                {
                    $stopString = '](tg://user?id='.$entity->user->id.')';
                    break;
                }
                case 'text_link':
                {
                    $stopString = ']('.$entity->url.')';
                    break;
                }
                case 'custom_emoji':
                {
                    $stopString = '](tg://emoji?id='.$entity->custom_emoji_id.')';
                    break;
                }
            }
        }
        return $stopString;
    }

    /**
     * Check if there are entities that end at the given position and return them (reversed because they are nested)
     */
    protected function checkForEntityStop($pos)
    {
        $entities = [];
        foreach ($this->entities as $entity)
        {
            if ($entity->offset + $entity->length == $pos)
            {
                if (in_array($entity->type, $this->entitiesToParse))
                {
                    $entities[] = $entity;
                }
            }
        }
        if (!empty($entities)) {
            return array_reverse($entities);
        } else {
            return false;
        }
    }

    /**
     * Check for ambiguous entities in MarkdownV2 style (see Telegram docs)
     */
    protected function checkMarkdownV2AmbiguousEntities(&$entitiesToCheck)
    {
        $result = false;
        $newEntities = [];
        $foundIndex = 0;
        foreach ($entitiesToCheck as $ec)
        {
            if ($ec->type == 'italic' || $ec->type == 'underline')
            {
                $foundIndex++;
            }
        }
        if ($foundIndex == 2)
        {
            $result = true;
            foreach ($entitiesToCheck as $ec)
            {
                if ($ec->type != 'italic' && $ec->type != 'underline')
                {
                    $newEntities[] = $ec;
                }
            }
            $entitiesToCheck = $newEntities;
        }
        return $result;
    }

    /**
     * Count UTF-16 code units of the char passed
     */
    protected function getUTF16CodePointsLength($char) {
        $chunks = str_split(bin2hex(mb_convert_encoding($char, 'UTF-16')), 4);
        return count($chunks);
    }
}
