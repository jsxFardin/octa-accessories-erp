<?php

declare(strict_types=1);

namespace App\Support\Schema;

use RuntimeException;

/**
 * Splits a MySQL DDL script into executable statements.
 *
 * Deliberately small: docs/02a-schema.sql contains only DDL and SET statements — no stored
 * programs, no DELIMITER blocks, no string literals containing a semicolon. If that ever
 * changes this class must grow a real lexer; a test asserts the statement count, which is
 * what would fail first.
 */
final class SqlScript
{
    /** @var list<string> */
    private array $statements;

    private function __construct(string $sql)
    {
        $this->statements = $this->split($sql);
    }

    public static function fromFile(string $path): self
    {
        $sql = @file_get_contents($path);

        if ($sql === false) {
            throw new RuntimeException("Cannot read SQL script at [{$path}].");
        }

        return new self($sql);
    }

    public static function fromString(string $sql): self
    {
        return new self($sql);
    }

    /** @return list<string> */
    public function statements(): array
    {
        return $this->statements;
    }

    /** @return list<string> Table names in declaration order. */
    public function tables(): array
    {
        return $this->namesMatching('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i');
    }

    /** @return list<string> View names in declaration order. */
    public function views(): array
    {
        return $this->namesMatching('/^CREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+`?(\w+)`?/i');
    }

    /** @return list<string> */
    private function namesMatching(string $pattern): array
    {
        $names = [];

        foreach ($this->statements as $statement) {
            if (preg_match($pattern, $statement, $m) === 1) {
                $names[] = $m[1];
            }
        }

        return $names;
    }

    /** @return list<string> */
    private function split(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $stripped = [];

        foreach ($lines as $line) {
            // Full-line and trailing `--` comments. The schema uses no `#` comments and no
            // `/* */` blocks, and no line contains `--` inside a quoted literal.
            $line = preg_replace('/(^|\s)--\s.*$/', '', $line) ?? $line;

            if (trim($line) !== '') {
                $stripped[] = rtrim($line);
            }
        }

        $statements = [];
        $buffer = '';

        foreach ($stripped as $line) {
            $buffer .= $line."\n";

            if (str_ends_with(rtrim($line), ';')) {
                $statement = trim(rtrim(trim($buffer), ';'));

                if ($statement !== '') {
                    $statements[] = $statement;
                }

                $buffer = '';
            }
        }

        if (trim($buffer) !== '') {
            throw new RuntimeException('SQL script ends with an unterminated statement.');
        }

        return $statements;
    }
}
