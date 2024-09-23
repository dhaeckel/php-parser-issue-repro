<?php

declare(strict_types=1);

namespace Haeckel\PhpParserIssueReproduction;

use PhpParser\{Node, NodeVisitorAbstract};

final class Visitor extends NodeVisitorAbstract
{
    public function leaveNode(Node $node)
    {
        if (! $node instanceof Node\Expr\FuncCall) {
            return;
        }

        $name = $node->name;
        if (
            ! \in_array(
                $name,
                [
                    'array_column',
                    'array_filter',
                    'array_key_exists',
                    'array_key_first',
                    'array_merge',
                    'array_values',
                    'current',
                    'reset',
                    'next',
                    'usort',
                    'count',
                    'mysqli_num_rows',
                    'mysqli_data_seek',
                    'strtolower',
                    'str_ireplace',
                    'sort'
                ]
            )
        ) {
            return;
        }

        return new Node\Expr\StaticCall(
            new Node\Name\FullyQualified('Compat'),
            $name,
            $node->args
        );
    }
}
