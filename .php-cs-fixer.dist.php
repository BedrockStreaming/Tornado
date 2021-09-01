<?php

$finder = PhpCsFixer\Finder::create();
$finder->in(
    [
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/examples',
    ]
);

$config = new M6Web\CS\Config\BedrockStreaming();
$config->setFinder($finder);

return $config;
