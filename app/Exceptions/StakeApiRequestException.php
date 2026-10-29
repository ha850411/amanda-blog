<?php

namespace App\Exceptions;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

class StakeApiRequestException extends RequestException
{
    protected function prepareMessage(Response $response): string
    {
        // GraphQL failures can include partial account data in the response body.
        return 'Stake API request failed (HTTP '.$response->status().').';
    }
}
