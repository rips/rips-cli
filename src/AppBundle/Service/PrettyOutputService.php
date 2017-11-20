<?php

namespace AppBundle\Service;

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
}
