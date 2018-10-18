# Tornado 🌪🐎
[![Build Status](https://travis-ci.com/M6Web/Tornado.svg?branch=master)](https://travis-ci.com/M6Web/Tornado)

A library for asynchronous programming in [Php](https://secure.php.net/).

<img src="assets/Tornado-Logo.png?raw=true" width="250" align="right" alt="Tornado Logo">

*Tornado* is composed of several interfaces to write asynchronous programs using [generators](https://secure.php.net/manual/en/language.generators.php).
This library provides adapters for popular asynchronous frameworks ([ReactPhp](https://reactphp.org/), [Amp](https://amphp.org/))
and built-in adapters to understand how to write your own.


## Installation

You can install it using [Composer](https://getcomposer.org/):
```bash
composer require m6web/tornado
```

You will also have to install additional dependencies related to the adapter you choose for your [`EventLoop`](src/EventLoop.php)
you may check our suggestions using [Composer](https://getcomposer.org/):
```bash
composer suggests --by-package
```

ℹ️ *Tornado* includes its own [`EventLoop`](src/EventLoop.php) adapter to ease quick testing, and to show how you could write
 your own [`EventLoop`](src/EventLoop.php) optimized for your use case, but keep in mind that ⚠️***Tornado* adapters are not
 yet production ready**⚠️.



## How to use it
You can find ready-to-use examples in [`examples`](https://github.com/M6Web/Tornado/tree/master/examples) directory,
but here some detailed explanations about asynchronous programing, and *Tornado* principles.

### Dealing with promises
The [`EventLoop`](src/EventLoop.php) is the engine in charge of executing all asynchronous functions.
If one of those functions is *waiting* an asynchronous result (a [`Promise`](src/Promise.php))
the [`EventLoop`](src/EventLoop.php) is able to pause this function and to resume an other one ready to be executed.

When you get a [`Promise`](src/Promise.php), the only way to retrieve its concrete value is to [`yield`](https://secure.php.net/manual/en/language.generators.syntax.php#control-structures.yield) it,
letting the [`EventLoop`](src/EventLoop.php) deal internally with 
[Php Generators](https://secure.php.net/manual/en/language.generators.overview.php). 
```php
/**
 * Sends a HTTP request a returns its body as a Json array.
 */
function getJsonResponseAsync(Tornado\HttpClient $httpClient, RequestInterface $request): \Generator
{
    /** @var ResponseInterface $response */
    $response = yield $httpClient->sendRequest($request);

    return json_decode((string) $response->getBody(), true);
}
```
⚠️ Remember that the return type can **NOT** be `array` here,
even if we expect that `json_decode` will return an `array`.
Since we are creating a [`Generator`](https://secure.php.net/manual/en/language.generators.overview.php),
the return type is by definition `\Generator`.
 
### Asynchronous functions
As soon as your function needs to wait a [`Promise`](src/Promise.php), it becomes by definition an asynchronous function.
To execute it, you need to use [`EventLoop::async`](src/EventLoop.php) method.
The returned [`Promise`](src/Promise.php) will be resolved with the value returned by your function.
```php
/**
 * Returns a Promise that will be resolved with a Json array.
 */
function requestJsonContent(Tornado\EventLoop $eventLoop, Tornado\HttpClient $httpClient): Tornado\Promise
{
    $request = new Psr7\Request(
        'GET',
        'http://httpbin.org/json',
        ['accept' => 'application/json']
    );

    return $eventLoop->async(getJsonResponseAsync($httpClient, $request));
}
```
⚠️ Keep in mind that's a bad practice to expose publicly a [`Generator`](https://secure.php.net/manual/en/language.generators.overview.php).
Your asynchronous functions should return a [`Promise`](src/Promise.php)
and keep its [`Generator`](https://secure.php.net/manual/en/language.generators.overview.php)
as an implementation detail, you could choose to return a [`Promise`](src/Promise.php)
in an other manner (see [dedicated examples](#resolving-your-own-promises)).

### Running the event loop
Now, you know that you have to create a generator to wait a [`Promise`](src/Promise.php),
and then call [`EventLoop::async`](src/EventLoop.php) to execute the generator and obtain a new [`Promise`](src/Promise.php)…
But how can we wait the **first** [`Promise`](src/Promise.php)?
Actually, there is a second way to wait a [`Promise`](src/Promise.php), a **synchronous** one:
the [`EventLoop::wait`](src/EventLoop.php) method.
It means that **you should use it only once**, to wait synchronously the resolution of a predefined goal.
Internally, this function will run a loop to handle all *events* until your goal is reached
(or an error occurred, see [dedicated chapter](#error-management)).
```php
function waitResponseSynchronously(Tornado\EventLoop $eventLoop, Tornado\HttpClient $httpClient)
{
    /** @var array $jsonArray */
    $jsonArray = $eventLoop->wait(requestJsonContent($eventLoop, $httpClient));
    echo '>>> '.json_encode($jsonArray).PHP_EOL;
}
```
Like with the `yield` keyword,
the [`EventLoop::wait`](src/EventLoop.php) method will return the resolved value of the input [`Promise`](src/Promise.php),
but remember that you should use it only once during execution.

### Concurrency
To reveal the true power of asynchronous programming, we have to introduce *concurrency* in our program.
If our goal is to send only one HTTP request and wait for it,
there is no gain to deal with an asynchronous request.
However, as soon as you have at least two goals to reach,
asynchronous functions will improve your performances thanks to concurrency.
To resolve several independent [`Promises`](src/Promise.php),
use [`EventLoop::promiseAll`](src/EventLoop.php) method to create a new [`Promise`](src/Promise.php)
that will be resolved when all others are resolved.
```php
function waitManyResponsesSynchronously(Tornado\EventLoop $eventLoop, Tornado\HttpClient $httpClient)
{
    $allJsonArrays = $eventLoop->wait(
        $eventLoop->promiseAll(
            requestJsonContent($eventLoop, $httpClient),
            requestJsonContent($eventLoop, $httpClient),
            requestJsonContent($eventLoop, $httpClient),
            requestJsonContent($eventLoop, $httpClient)
        )
    );

    foreach ($allJsonArrays as $index => $jsonArray) {
        echo "[$index]>>> ".json_encode($jsonArray).PHP_EOL;
    }
}
```

It's important to note that 
it will be more efficient to use [`EventLoop::promiseAll`](src/EventLoop.php)
instead of waiting each input [`Promise`](src/Promise.php) consecutively,
because of concurrency.
Each time that you have several promises to resolve,
ask yourself if you could wait them concurrently, especially when you deal with `foreach` loops. 

### Resolving your own promises
By design, you cannot resolve a promise by yourself, you will need a [`Deferred`](src/Deferred.php).
It allows you to create a [`Promise`](src/Promise.php) and to resolve (or reject) it
while not exposing these advanced controls.
```php
function promiseWaiter(Tornado\Promise $promise): \Generator
{
    echo "I'm waiting a promise…\n";
    $result = yield $promise;
    echo "I received [$result]!\n";
}

function deferredResolver(Tornado\EventLoop $eventLoop, Tornado\Deferred $deferred): \Generator
{
    yield $eventLoop->delay(1000);
    $deferred->resolve('Hello World!');
}

function waitDeferredSynchronously(Tornado\EventLoop $eventLoop)
{
    $deferred = $eventLoop->deferred();
    $eventLoop->wait($eventLoop->promiseAll(
        $eventLoop->async(deferredResolver($eventLoop, $deferred)),
        $eventLoop->async(promiseWaiter($deferred->getPromise()))
    ));
}
```

### Error management
A [`Promise`](src/Promise.php) is *resolved* in case of success,
but it will be *rejected* with a [`Throwable`](https://secure.php.net/manual/fr/class.throwable.php)
in case of error.
While waiting a [`Promise`](src/Promise.php) with `yield` or [`EventLoop::wait`](src/EventLoop.php) an exception may be thrown,
it's up to you to catch it or to let it propagate to the upper level.
If you throw an exception in an asynchronous function, this will reject the associated [`Promise`](src/Promise.php). 
```php
function failingAsynchronousFunction(Tornado\EventLoop $eventLoop): \Generator
{
    yield $eventLoop->idle();

    throw new \Exception('This is an exception!');
}

function waitException(Tornado\EventLoop $eventLoop)
{
    try {
        $eventLoop->wait($eventLoop->async(failingAsynchronousFunction($eventLoop)));
    } catch (\Throwable $throwable) {
        echo $throwable->getMessage().PHP_EOL;
    }
}
```


## FAQ

#### Is *Tornado* related to the [*Tornado* Python library](http://www.tornadoweb.org)?
No, even if these two libraries deal with asynchronous programming,
they are absolutely not related.
The name *Tornado* has been chosen in reference to the [horse ridden by Zorro](https://en.wikipedia.org/wiki/Tornado_%28horse%29).

#### I :heart: your logo, who did it?
The *Tornado* logo has been designed by [Cécile Moret](https://cecilemoret.com/).

## Contributing

Running unit tests:
```bash
composer tests-unit
```

Running examples:
```bash
composer tests-examples
```

Running PhpStan (static analysis):
```bash
composer static-analysis
```

Check code style:
```bash
composer code-style-check
```

Fix code style:
```bash
composer code-style-fix
```
