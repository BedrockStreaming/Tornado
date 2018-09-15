<?php

namespace M6WebTest\Tornado;

use PHPUnit\Framework\TestCase;

class ExamplesTest extends TestCase
{
    public function examplesProvider()
    {
        $iterator = new \FilesystemIterator(
            __DIR__.'/../examples',
            \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::KEY_AS_FILENAME | \FilesystemIterator::SKIP_DOTS
        );

        foreach ($iterator as $name => $path) {
            yield $name => [$path];
        }
    }

    /**
     * @dataProvider examplesProvider
     */
    public function testExampleShouldRun($exampleFile)
    {
        $output = [];
        $code = null;
        exec($exampleFile, $output, $code);

        $this->assertSame(0, $code);
        $this->assertStringStartsWith("Let's start!", reset($output));
        $this->assertStringEndsWith('Finished!', end($output));
    }
}
