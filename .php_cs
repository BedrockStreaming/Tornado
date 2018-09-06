<?php
$config = new M6Web\CS\Config\Php71();
$config->getFinder()
    ->in([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/examples',
    ])->exclude([
    ]);

return $config;
