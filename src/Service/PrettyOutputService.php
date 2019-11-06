<?php

namespace App\Service;

class PrettyOutputService
{
    /**
     * @param string $input
     * @param int $maxChars
     * @return string
     */
    public function shortenString($input, $maxChars)
    {
        if (strlen($input) > $maxChars) {
            return '...' . substr($input, strlen($input) - $maxChars + 3);
        } else {
            return $input;
        }
    }

    /**
     * @param mixed $input
     * @return string
     */
    public function toString($input)
    {
        if ($input instanceof \DateTime) {
            return $input->format(DATE_RFC822);
        } elseif (is_bool($input)) {
            return ($input ? 'true' : 'false');
        } elseif (is_array($input)) {
            return implode(', ', $input);
        } else {
            return (string)$input;
        }
    }
}
