<?php

$finder = PhpCsFixer\Finder::create();
$finder->in(
    [
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/examples',
    ]
);

$baseConfig = new M6Web\CS\Config\BedrockStreaming();

$config = new PhpCsFixer\Config('Bedrock Streaming');
$config->setFinder($finder);

$override_rules = array_merge(
    $baseConfig->getRules(),
    [
        // Adding strict_types should be part of another PR
        'declare_strict_types' => false,
    ]
);

$config->setRules($override_rules);

return $config;
