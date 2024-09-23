<?php

declare(strict_types=1);

namespace DynCom\Phpmigrator\test;

use Haeckel\PhpParserIssueReproduction\Visitor;
use PhpParser\{PrettyPrinter, ParserFactory, NodeTraverser, NodeVisitor};
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
class InternalFnExceptionsTest extends TestCase
{
    public function testInternalFnExceptions(): void
    {
        $traverser = new NodeTraverser(new Visitor());
        $printer = new PrettyPrinter\Standard();
        $parser = (new ParserFactory())->createForHostVersion();
        $cloningPreProcessTraverser = new NodeTraverser(new NodeVisitor\CloningVisitor());
        $tmpFile = \tempnam(\sys_get_temp_dir(), 'test');
        $testFileContents = \file_get_contents(__DIR__ . '/resources/internalFnExceptions/test.php');
        $ok = \file_put_contents(
            $tmpFile,
            $testFileContents
        );
        if ($ok === false) {
            $err = \error_get_last();
            throw new \ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']);
        }
        $oldStmts = $parser->parse($testFileContents);
        $oldTokens = $parser->getTokens();
        // Run CloningVisitor before making changes to the AST.
        $newStmts = $cloningPreProcessTraverser->traverse($oldStmts);
        $newStmts = $traverser->traverse($newStmts);

        $ok = \file_put_contents(
            $tmpFile,
            $printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens)
        );
        if ($ok === false) {
            $fsErrs[] = \error_get_last()['message'];
        }
        $this->assertStringEqualsFile(
            __DIR__ . '/resources/internalFnExceptions/expected.php',
            \file_get_contents($tmpFile)
        );

        \unlink($tmpFile);
    }
}
