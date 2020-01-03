<?php

namespace M6Web\Tornado;

use Psr\Http\Message\RequestInterface;

interface HttpClient
{
    /**
     * Sends a http request and returns a promise that will be resolved with a
     * Psr\Http\Message\ResponseInterface.
     * When cancelled, related network jobs will be cancelled as soon as possible.
     */
    public function sendRequest(RequestInterface $request): Promise;
}
