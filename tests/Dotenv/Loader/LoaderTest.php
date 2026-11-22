<?php

declare(strict_types=1);

namespace Dotenv\Tests\Loader;

use Dotenv\Exception\InvalidFileException;
use Dotenv\Loader\Loader;
use Dotenv\Loader\LoaderInterface;
use Dotenv\Parser\Parser;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\Adapter\EnvConstAdapter;
use Dotenv\Repository\Adapter\ServerConstAdapter;
use Dotenv\Repository\RepositoryBuilder;
use PHPUnit\Framework\TestCase;

final class LoaderTest extends TestCase
{
    public function testParserInstanceOf()
    {
        self::assertInstanceOf(LoaderInterface::class, new Loader());
    }

    public function testLoaderWithNoReaders()
    {
        $repository = RepositoryBuilder::createWithNoAdapters()->addWriter(ArrayAdapter::class)->make();
        $loader = new Loader();

        $content = "NVAR1=\"Hello\"\nNVAR2=\"World!\"\nNVAR3=\"{\$NVAR1} {\$NVAR2}\"\nNVAR4=\"\${NVAR1} \${NVAR2}\"";
        $expected = ['NVAR1' => 'Hello', 'NVAR2' => 'World!', 'NVAR3' => '{$NVAR1} {$NVAR2}', 'NVAR4' => '${NVAR1} ${NVAR2}'];

        self::assertSame($expected, $loader->load($repository, (new Parser())->parse($content)));
    }

    public function testLoaderWithAllowList()
    {
        $adapter = ArrayAdapter::create()->get();
        $repository = RepositoryBuilder::createWithNoAdapters()->addReader($adapter)->addWriter($adapter)->allowList(['FOO'])->make();
        $loader = new Loader();

        self::assertSame(['FOO' => 'Hello'], $loader->load($repository, (new Parser())->parse("FOO=\"Hello\"\nBAR=\"World!\"\n")));
        self::assertTrue($adapter->read('FOO')->isDefined());
        self::assertSame('Hello', $adapter->read('FOO')->get());
        self::assertFalse($adapter->read('BAR')->isDefined());
    }

    public function testLoaderWithGarbage()
    {
        $adapter = ArrayAdapter::create()->get();
        $repository = RepositoryBuilder::createWithNoAdapters()->make();
        $loader = new Loader();

        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('Failed to parse dotenv file. Encountered unexpected whitespace at ["""].');

        $loader->load($repository, (new Parser())->parse('FOO="""'));
    }

    public function testLoaderResolvesVariablesCompletedByNestedVariables()
    {
        $adapter = ArrayAdapter::create()->get();
        $repository = RepositoryBuilder::createWithNoAdapters()->addReader($adapter)->addWriter($adapter)->make();
        $loader = new Loader();

        $content = "A=FOO\nB=BAR\nFOOBAR=baz\nX_FOO=qux\nOPEN={A}!\nJOINED=\"\${\${A}\${B}}\"\nPREFIXED=\"\${X_\${A}}\"\nOPENED=\"\$\${OPEN}\"";
        $expected = ['A' => 'FOO', 'B' => 'BAR', 'FOOBAR' => 'baz', 'X_FOO' => 'qux', 'OPEN' => '{A}!', 'JOINED' => 'baz', 'PREFIXED' => 'qux', 'OPENED' => 'FOO!'];

        self::assertSame($expected, $loader->load($repository, (new Parser())->parse($content)));
    }

    public function testLoaderResolvesVariablesAmongMultibyteCharacters()
    {
        $adapter = ArrayAdapter::create()->get();
        $repository = RepositoryBuilder::createWithNoAdapters()->addReader($adapter)->addWriter($adapter)->make();
        $loader = new Loader();

        $content = "A=x\nRESULT=\"€€\${A}😀\${A}é\"";
        $expected = ['A' => 'x', 'RESULT' => '€€x😀xé'];

        self::assertSame($expected, $loader->load($repository, (new Parser())->parse($content)));
    }

    public function testLoaderPreservesInvalidUtf8BytesFromRepositoryValues()
    {
        $adapter = ArrayAdapter::create()->get();
        $repository = RepositoryBuilder::createWithNoAdapters()->addReader($adapter)->addWriter($adapter)->make();
        $loader = new Loader();

        $content = "LEFT=left\nBAD=\xC3\nRESULT=\"\${LEFT}\${BAD}\"";
        $expected = ['LEFT' => 'left', 'BAD' => "\xC3", 'RESULT' => "left\xC3"];

        self::assertSame($expected, $loader->load($repository, (new Parser())->parse($content)));
    }

    public function testLoaderPreservesInvalidUtf8BytesAroundVariables()
    {
        $adapter = ArrayAdapter::create()->get();
        $repository = RepositoryBuilder::createWithNoAdapters()->addReader($adapter)->addWriter($adapter)->make();
        $loader = new Loader();

        $content = "LEFT=left\nRESULT=\"\xC3 \${LEFT}\xC3\"";
        $expected = ['LEFT' => 'left', 'RESULT' => "\xC3 left\xC3"];

        self::assertSame($expected, $loader->load($repository, (new Parser())->parse($content)));
    }

    /**
     * @return array<int,\Dotenv\Repository\Adapter\AdapterInterface|string>[]
     */
    public static function providesAdapters()
    {
        return [
            [ArrayAdapter::create()->get()],
            [EnvConstAdapter::class],
            [ServerConstAdapter::class],
        ];
    }

    /**
     * @dataProvider providesAdapters
     *
     * @param \Dotenv\Repository\Adapter\AdapterInterface|string $adapter
     */
    public function testLoaderWithSpecificAdapter($adapter)
    {
        $repository = RepositoryBuilder::createWithNoAdapters()->addReader($adapter)->addWriter($adapter)->make();
        $loader = new Loader();

        $content = "NVAR1=\"Hello\"\nNVAR2=\"World!\"\nNVAR3=\"{\$NVAR1} {\$NVAR2}\"\nNVAR4=\"\${NVAR1} \${NVAR2}\"";
        $expected = ['NVAR1' => 'Hello', 'NVAR2' => 'World!', 'NVAR3' => '{$NVAR1} {$NVAR2}', 'NVAR4' => 'Hello World!'];

        self::assertSame($expected, $loader->load($repository, (new Parser())->parse($content)));
    }
}
