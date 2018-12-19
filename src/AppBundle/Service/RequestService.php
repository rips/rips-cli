<?php

namespace AppBundle\Service;

class RequestService
{
    /**
     * @param $parameters
     * @return array
     */
    public function transformParametersForQuery($parameters)
    {
        $queryParams = [];

        foreach ($parameters as $parameter) {
            $parameterSplit = explode('=', $parameter, 2);

            if (isset($queryParams[$parameterSplit[0]])) {
                throw new \RuntimeException('Query parameter collision of "' . $parameterSplit[0] . '"');
            }

            if (count($parameterSplit) === 1) {
                $queryParams[$parameterSplit[0]] = 1;
            } else {
                $queryParams[$parameterSplit[0]] = $parameterSplit[1];
            }
        }

        return $queryParams;
    }
}
