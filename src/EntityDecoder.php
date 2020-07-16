<?php
/** 
 * This class decode style entities from Telegram bot messages (bold, italic, etc.) in text with inline entities that duplicate (when possible) the
 * exact style the message has originally when was sended to the bot.
 * All this work is necessary because Telegram returns offset and length of the entities in UTF-16 code units that they've been hard to decode correctly in PHP
 * 
 * Inspired By: https://github.com/php-telegram-bot/core/issues/544#issuecomment-564950430
 * Conversion to Unicode Code Points from: https://stackoverflow.com/questions/1805802/php-convert-unicode-codepoint-to-utf-8
 * Emoji detection (with some customizations) from: https://github.com/aaronpk/emoji-detector-php
 * 
 * Example usage:
 * $entity_decoder = new EntityDecoder('HTML', 'API_KEY_STRING');
 * $decoded_text = $entity_decoder->decode($message);
 * 
 * @author LucaDevelop
 * @access public
 * @see https://github.com/LucaDevelop/telegram-entities-decoder
 */

class EntityDecoder
{
    private $entities;
    private $style;
    private $baseRegex = '';

     /**
     * @param string $style       Either 'HTML', 'Markdown' or 'MarkdownV2'.
     * @param string $api_key     API Key from https://emoji-api.com/. It's free and always up-to-date with Unicode Consortium.
     */
    public function __construct(string $style = 'HTML', string $api_key = '')
    {
        $this->style       = $style;
        $this->baseRegex   = json_decode($this->GenerateRegEx($api_key));
    }

	/**
     * Decode entities and return decoded text
     * 
     * @param StdClass $message       Message object to reconstruct Entities from (json decoded without assoc).
     */
    public function decode(StdClass $message): string
    {
		$this->entities = $message->entities;
        $prevencoding = mb_internal_encoding();
        mb_internal_encoding('UTF-8');
        if (empty($this->entities)) {
            return $message->text;
        }
        //split text in char array with UTF-16 code units length
        $arrayText = $this->splitCharAndLength($message->text);
        $finalText = "";

        $openedEntities = [];
        $currenPosition = 0;
        //Cycle characters one by one to calculate begins and ends of entities and escape special chars
        for($i = 0; $i < count($arrayText); $i++) {
            $offsetAndLength = $currenPosition + $arrayText[$i]['length'];
            $entityCheckStart = $this->checkForEntityStart($currenPosition);
            $entityCheckStop = $this->checkForEntityStop($offsetAndLength);
            if($entityCheckStart !== false)
            {
				foreach($entityCheckStart as $stEntity)
				{
					$startChar = $this->getEntityStartString($stEntity);
					$openedEntities[] = $stEntity;
					$finalText .= $startChar;
				}
                $finalText .= $this->escapeSpecialChars($arrayText[$i]['char'], true, $openedEntities);
            }
            if($entityCheckStop !== false)
            {                
                if($entityCheckStart === false)
                    $finalText .= $this->escapeSpecialChars($arrayText[$i]['char'], true, $openedEntities);
				if($this->style == 'MarkdownV2' && $this->checkMarkdownV2AmbiguousEntities($entityCheckStop))
				{
					$stopChar = "_\r__";
					$finalText .= $stopChar;
					array_pop($openedEntities);
					array_pop($openedEntities);
				}
				foreach($entityCheckStop as $stEntity)
				{
					$stopChar = $this->getEntityStopString($stEntity);
					$finalText .= $stopChar;
					array_pop($openedEntities);
				}
            }
            if($entityCheckStart === false && $entityCheckStop === false)
            {
				$isEntityOpen = count($openedEntities) > 0;
                $finalText .= $this->escapeSpecialChars($arrayText[$i]['char'], $isEntityOpen, $openedEntities);
            }			
            $currenPosition = $offsetAndLength;
        }
        if(count($openedEntities) > 0)
        {
			$openedEntities = array_reverse($openedEntities);
			foreach($openedEntities as $oe)
			{
				$finalText .= $this->getEntityStopString($oe);
			}
        }
        if($prevencoding)
            mb_internal_encoding($prevencoding);

        return $finalText;
    }

    /**
     * Split message text in chars array with lengthes
     */
    protected function splitCharAndLength($string)
    {
      //Split with regexp because one emoji must be one char
      $str_split_unicode = preg_split('/(' . $this->baseRegex . ')|(.)/us', $string, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
      $data = [];
      foreach($str_split_unicode as $s)
      {
         $data[] = ["char" => $s, "length" => $this->getUTF16CodePointsLength($s)];
      }
      
      return $data;
    }

    /**
     * Apply Telegram escape rules for the choosen style
     */
    protected function escapeSpecialChars($char, $isEntityOpen, $entities) {
        if($this->style == 'Markdown')
        {			
            if($isEntityOpen)
            {
				$entity = $entities[0];
                if($char == '*' || $char == '_')
                {
                    if($char == $this->getEntityStartString($entity))
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
                if($char == '*' || $char == '_' || $char == '[' || $char == '`')
                {
                    return "\\".$char;
                }
                else
                {
                    return $char;
                }
            }
        }
        else if($this->style == 'HTML')
        {
            return ($char == '<' ? '&lt;' : ($char == '>' ? '&gt;' : ($char == '&' ? '&amp;' : $char)));
        }
        else if($this->style == 'MarkdownV2')
        {
            return (in_array($char, array('_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!', '\\')) ? '\\'.$char : $char);
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
        if($this->style == 'Markdown')
        {
            switch($entity->type)
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
                    if(isset($entity->language))
                    {
                        $startString .= $entity->language;
                    }
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
        else if($this->style == 'HTML')
        {
            switch($entity->type)
			{
				case 'bold':
				{
					$startString .= '<b>';
					break;
				}
				case 'italic':
				{
					$startString .= '<i>';
					break;
				}
				case 'underline':
				{
					$startString .= '<u>';
					break;
				}
				case 'strikethrough':
				{
					$startString .= '<s>';
					break;
				}
				case 'code':
				{
					$startString .= '<code>';
					break;
				}
				case 'pre':
				{
					$startString .= '<pre>';
					if(isset($entity->language))
					{
						$startString .= '<code class="language-'.$entity->language.'">';
					}
					break;
				}
				case 'text_mention':
				{
					$startString .= '<a href="tg://user?id='.$entity->user->id.'">';
					break;
				}
				case 'text_link':
				{
					$startString .= '<a href="'.$entity->url.'">';
					break;
				}
			}
        }
        else if($this->style == 'MarkdownV2')
        {
            switch($entity->type)
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
                    if(isset($entity->language))
                    {
                        $startString .= $entity->language;
                    }
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
            }
        }
        else
        {
            //Exception
        }
        return $startString;
    }

    /**
     * Check if there are entities that start at the given position and return them
     */
    protected function checkForEntityStart($pos)
    {
		$entities = [];
        foreach($this->entities as $entity)
        {
            if($entity->offset == $pos)
            {
				if(in_array($entity->type, array('bold', 'italic', 'code', 'pre', 'text_mention', 'text_link', 'strikethrough', 'underline')))
                {
                   $entities[] = $entity;
                }
            }
        }
		if(count($entities) > 0)
			return $entities;
		else
			return false;
    }

    /**
     * Get the end string of the entity  for the choosen style
     */
    protected function getEntityStopString($entity)
    {
        $stopString = '';
        if($this->style == 'Markdown')
        {
            switch($entity->type)
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
                    $stopString = '```';
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
        else if($this->style == 'HTML')
        {
			switch($entity->type)
			{
				case 'bold':
				{
					$stopString .= '</b>';
					break;
				}
				case 'italic':
				{
					$stopString .= '</i>';
					break;
				}
				case 'underline':
				{
					$stopString .= '</u>';
					break;
				}
				case 'strikethrough':
				{
					$stopString .= '</s>';
					break;
				}
				case 'code':
				{
					$stopString .= '</code>';
					break;
				}
				case 'pre':
				{
					if(isset($entity->language))
					{
						$stopString .= '</code>';
					}
					$stopString .= '</pre>';
					break;
				}
				case 'text_mention':
				case 'text_link':
				{
					$stopString .= '</a>';
					break;
				}
			}
        }
        else if($this->style == 'MarkdownV2')
        {
            switch($entity->type)
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
                    $stopString = '```';
                    break;
                }
				case 'underline':
				{
					$stopString .= '__';
					break;
				}
				case 'strikethrough':
				{
					$stopString .= '~';
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
        else
        {
            //Exception
        }
        return $stopString;
    }

    /**
     * Check if there are entities that end at the given position and return them (reversed because they are nested)
     */
    protected function checkForEntityStop($pos)
    {
		$entities = [];
        foreach($this->entities as $entity)
        {
            if($entity->offset + $entity->length == $pos)
            {
				if(in_array($entity->type, array('bold', 'italic', 'code', 'pre', 'text_mention', 'text_link', 'strikethrough', 'underline')))
                {
                    $entities[] = $entity;
                }
            }
        }
		if(count($entities) > 0)
			return array_reverse($entities);
		else
			return false;
    }
    
    /**
     * Check for ambiguous entities in MarkdownV2 style (see Telegram docs)
     */
	protected function checkMarkdownV2AmbiguousEntities(&$entitiesToCheck)
	{
		$result = false;
		$newEntities = [];
		$foundIndex = 0;
		foreach($entitiesToCheck as $ec)
		{
			if($ec->type == 'italic' || $ec->type == 'underline')
			{
				$foundIndex++;
			}
		}
		if($foundIndex == 2)
		{
			$result = true;
			foreach($entitiesToCheck as $ec)
			{
				if($ec->type != 'italic' && $ec->type != 'underline')
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
    
    /**
     * Emoji regexp generation
     */
	protected function GenerateRegEx($api_key) {
		$url = "https://emoji-api.com/emojis?access_key=".$api_key;
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$Output = curl_exec($ch);
		curl_close($ch);
		$basearr = [];
		$obj = json_decode($Output, true);
		foreach($obj as $o)
		{
			$chunks = str_split(bin2hex(mb_convert_encoding($o['character'], 'UTF-32')), 8);
			$newchunks = [];
			foreach($chunks as $chunk)
			{
				$newchunks[] = strtoupper(ltrim($chunk, '0'));
			}
			$basearr[] = $newchunks;
			if(isset($o['variants']))
			{
				foreach($o['variants'] as $v)
				{
					$chunks = str_split(bin2hex(mb_convert_encoding($v['character'], 'UTF-32')), 8);
					$newchunks = [];
					foreach($chunks as $chunk)
					{
						$newchunks[] = strtoupper(ltrim($chunk, '0'));
					}
					$basearr[] = $newchunks;
				}
			}
		}
		usort($basearr, array(get_class(), 'cmpbase'));
		$regexp = '"';
		foreach($basearr as $b)
		{
			$regexp .= '\\\\x{'.join('}\\\\x{', $b).'}|';
		}
		$regexp = substr($regexp, 0 ,strlen($regexp) - 1).'"';
		return $regexp;
	}
	
	private static function cmpbase($a, $b)
	{
		return (count($a)<count($b) ? 1 : -1);
	}
}
?>