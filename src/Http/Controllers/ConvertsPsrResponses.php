<?php

namespace Mpge\OpenIdProvider\Http\Controllers;

use Illuminate\Http\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Converts PSR-7 responses from league/oauth2-server into Laravel responses.
 *
 * @internal
 */
trait ConvertsPsrResponses
{
    protected function convertResponse(ResponseInterface $psrResponse): Response
    {
        return new Response(
            (string) $psrResponse->getBody(),
            $psrResponse->getStatusCode(),
            $psrResponse->getHeaders(),
        );
    }
}
