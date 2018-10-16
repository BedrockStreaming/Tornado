<?php

namespace M6WebExamplesTest\Tornado;

use PHPUnit\Framework\TestCase;

class ExamplesTest extends TestCase
{
    public function examplesProvider()
    {
        $iterator = new \FilesystemIterator(
            __DIR__.'/../',
            \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::KEY_AS_FILENAME | \FilesystemIterator::SKIP_DOTS
        );

        foreach ($iterator as $name => $fileinfo) {
            if ($fileinfo->isDir()) {
                continue;
            }

            yield $name => [$fileinfo->getRealPath()];
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
