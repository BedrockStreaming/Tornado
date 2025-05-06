<?php

declare(strict_types=1);

namespace M6Web\Tornado;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface HttpClient
{
    /**
     * Sends a http request and returns a promise that will be resolved with a
     * Psr\Http\Message\ResponseInterface
     *
     * @return Promise<ResponseInterface>
     */
    public function sendRequest(RequestInterface $request): Promise;
}
