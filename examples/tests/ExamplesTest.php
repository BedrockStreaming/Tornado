<?php

declare(strict_types=1);

namespace M6WebExamplesTests\Tornado;

use PHPUnit\Framework\TestCase;

class ExamplesTest extends TestCase
{
    private const EXAMPLES_DIR = __DIR__.'/../';

    public static function examplesProvider(): \Generator
    {
        $iterator = new \FilesystemIterator(
            self::EXAMPLES_DIR,
            \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::KEY_AS_FILENAME | \FilesystemIterator::SKIP_DOTS
        );

        foreach ($iterator as $name => $fileinfo) {
            if ($fileinfo->isDir()) {
                continue;
            }

            foreach (self::extractExampleCode($fileinfo->getRealPath()) as $eventloopName => $code) {
                yield "$name $eventloopName" => [$fileinfo->getRealPath(), $eventloopName, $code];
            }
        }
    }

    /**
     * @dataProvider examplesProvider
     */
    public function testExampleShouldRun(string $exampleFile, string $eventloopName, string $exampleCode): void
    {
        // Sanitize loop name to create a relevant temporary filename
        $eventLoopFileId = preg_replace('/[^a-z0-9]+/', '', strtolower($eventloopName));
        $tmpFilePath = tempnam(self::EXAMPLES_DIR, basename($exampleFile, '.php')."-$eventLoopFileId-");

        try {
            file_put_contents($tmpFilePath, $exampleCode);

            $output = [];
            $code = null;
            exec("php $tmpFilePath", $output, $code);

            $this->assertSame(0, $code);
            $this->assertStringStartsWith("Let's start!", reset($output));
            $this->assertStringEndsWith('Finished!', end($output));
        } finally {
            unlink($tmpFilePath);
        }
    }

    private static function extractExampleCode(string $exampleFile): iterable
    {
        $originalContent = file($exampleFile);

        foreach (self::selectEventLoop($originalContent) as $nameEL => $contentEL) {
            $exampleUseHttpClient = false;

            foreach (self::selectHttpClient($contentEL) as $nameHC => $contentELHC) {
                $exampleUseHttpClient = true;
                yield "$nameEL - $nameHC" => implode('', $contentELHC);
            }

            if (!$exampleUseHttpClient) {
                yield $nameEL => implode('', $contentEL);
            }
        }
    }

    /**
     * Very naive approach to iterate over various eventLoop implementations.
     */
    private static function selectEventLoop(array $originalContent): iterable
    {
        foreach ($originalContent as &$line) {
            if (!str_contains((string) $line, '$eventLoop = new ')) {
                continue;
            }

            // Extract relevant name
            $name = strstr(strstr((string) $line, '(', true), 'Adapter\\');

            // Enable current eventLoop
            $line = ltrim((string) $line, '/');

            yield $name => $originalContent;

            // Disable this eventLoop
            $line = "//$line";
        }
    }

    /**
     * Very naive approach to iterate over various httpClient implementations.
     */
    private static function selectHttpClient(array $originalContent): iterable
    {
        foreach ($originalContent as &$line) {
            if (!str_contains((string) $line, '$httpClient = new ')) {
                continue;
            }

            // Extract relevant name
            $name = strstr(strstr((string) $line, '(', true), 'Adapter\\');

            // Enable current eventLoop
            $line = ltrim((string) $line, '/');

            yield $name => $originalContent;

            // Disable this eventLoop
            $line = "//$line";
        }
    }
}
