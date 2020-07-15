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
	private $longest_emoji = 0;

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
        if($this->is_single_emoji($s))  //If char is an emoji
        {
          $eLen = 0;
          for($i=0; $i<mb_strlen($s); $i++) { //Cycle emoji elements
            switch(strtoupper(dechex($this->uniord(mb_substr($s, $i, 1))))) //Code points conversion
            {
              case '200D':  //Zero-width joiner has length 1
              {
                $eLen += 1;
                break;
              }
              case 'FE0F':  //Variation Selector has length 0
              {
                //Add 0 length
                break;
              }
              default:  //Any other code point has length 2
              {
                $eLen += 2;
                break;
              }
            }
          }
          $data[] = ["char" => $s, "length" => $eLen];
        }
        else
        {
          $data[] = ["char" => $s, "length" => mb_strlen($s)];
        }
      }
      
      return $data;
    }

    /**
     * Code units convertion
     */
    protected function uniord($c) {
        $ord0 = ord($c[0]); if ($ord0>=0   && $ord0<=127) return $ord0;
        $ord1 = ord($c[1]); if ($ord0>=192 && $ord0<=223) return ($ord0-192)*64 + ($ord1-128);
        $ord2 = ord($c[2]); if ($ord0>=224 && $ord0<=239) return ($ord0-224)*4096 + ($ord1-128)*64 + ($ord2-128);
        $ord3 = ord($c[3]); if ($ord0>=240 && $ord0<=247) return ($ord0-240)*262144 + ($ord1-128)*4096 + ($ord2-128)*64 + ($ord3-128);
        return false;
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
     * Single emoji detection
     */
    protected function is_single_emoji($string) {
      // If the string is longer than the longest emoji, it's not a single emoji
      if(mb_strlen($string) >= $this->longest_emoji) return false;
    
      $all_emoji = $this->detect_emoji($string);
    
      $emoji = false;
    
      // If there are more than one or none, return false immediately
      if(count($all_emoji) == 1) {
        $emoji = $all_emoji[0];
    
        // Check if there are any other characters in the string
    
        // Remove the emoji found
        $string = str_replace($emoji, '', $string);
    
        // If there are any characters left, then the string is not a single emoji
        if(strlen($string) > 0)
          $emoji = false;
      }
    
      return $emoji;
    }

    /**
     * Emoji detection with regexp
     */
    protected function detect_emoji($string) {

        $data = array();

        if(preg_match_all('/(?:' . $this->baseRegex. ')/u', $string, $matches)) {
          foreach($matches[0] as $ch) {
            $data[] = $ch;
          }
        }

        return $data;
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
			if($this->longest_emoji == 0)
				$this->longest_emoji = count($b);
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