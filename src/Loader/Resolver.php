<?php

declare(strict_types=1);

namespace Dotenv\Loader;

use Dotenv\Parser\Value;
use Dotenv\Repository\RepositoryInterface;
use Dotenv\Util\Regex;
use Dotenv\Util\Str;
use PhpOption\Option;

final class Resolver
{
    /**
     * The characters allowed in the name of a nested variable.
     */
    private const NAME_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.';

    /**
     * This class is a singleton.
     *
     * @codeCoverageIgnore
     *
     * @return void
     */
    private function __construct()
    {
        //
    }

    /**
     * Resolve the nested variables in the given value.
     *
     * Replaces ${varname} patterns in the allowed positions in the variable
     * value by an existing environment variable.
     *
     * @param \Dotenv\Repository\RepositoryInterface $repository
     * @param \Dotenv\Parser\Value                   $value
     *
     * @return string
     */
    public static function resolve(RepositoryInterface $repository, Value $value)
    {
        $chars = $value->getChars();
        $vars = $value->getVars();

        if ($vars === []) {
            return $chars;
        }

        $tail = [];
        $used = [];
        $cut = \strlen($chars);

        foreach (self::getOffsets($chars, $vars) as $i) {
            $head = \substr($chars, $i, $cut - $i);
            $cut = $i;

            // A variable can run on into the text resolved after it.
            $need = self::getNeeded($head);

            while ($need !== null && $tail !== []) {
                $top = \count($tail) - 1;
                $piece = $tail[$top];
                $from = $used[$top];
                $to = $need === '' ? $from + \strspn($piece, self::NAME_CHARS, $from) : $from;

                if (isset($piece[$to])) {
                    $need = $need !== '' && $piece[$to] === $need[0] ? \substr($need, 1) : null;
                    $to++;
                }

                $head .= \substr($piece, $from, $to - $from);

                if (isset($piece[$to])) {
                    $used[$top] = $to;
                } else {
                    \array_pop($tail);
                    \array_pop($used);
                }
            }

            $head = self::resolveVariable($repository, $head);

            if ($head !== '') {
                $tail[] = $head;
                $used[] = 0;
            }
        }

        $resolved = \substr($chars, 0, $cut);

        for ($top = \count($tail) - 1; $top >= 0; $top--) {
            $resolved .= \substr($tail[$top], $used[$top]);
        }

        return $resolved;
    }

    /**
     * Convert the character offsets of the variables into byte offsets.
     *
     * @param string $chars
     * @param int[]  $vars
     *
     * @return int[]
     */
    private static function getOffsets(string $chars, array $vars)
    {
        $valid = \preg_match('//u', $chars) === 1;
        $offsets = [];
        $char = 0;
        $byte = 0;

        foreach (\array_reverse($vars) as $var) {
            $byte = $valid ? self::skipChars($chars, $byte, $var - $char) : self::skipCountedChars($chars, $byte, $var - $char);
            $char = $var;
            $offsets[] = $byte;
        }

        return \array_reverse($offsets);
    }

    /**
     * Skip the given number of characters in valid UTF-8.
     *
     * @param string $chars
     * @param int    $byte
     * @param int    $count
     *
     * @return int
     */
    private static function skipChars(string $chars, int $byte, int $count)
    {
        // Every byte but a continuation byte starts a character.
        $starts = $count + 1;

        while ($starts > 0 && isset($chars[$byte])) {
            $slice = \substr($chars, $byte, $starts);
            $byte += \strlen($slice);
            $starts -= \strlen($slice) - (int) \preg_match_all('/[\x80-\xBF]/', $slice);
        }

        return $starts > 0 ? $byte : $byte - 1;
    }

    /**
     * Skip the given number of characters, counted as the parser counted them.
     *
     * @param string $chars
     * @param int    $byte
     * @param int    $count
     *
     * @return int
     */
    private static function skipCountedChars(string $chars, int $byte, int $count)
    {
        $rest = \strlen($chars) - $byte;
        $low = \min($count, $rest);

        // Find the shortest length that holds one character too many.
        for ($high = \min($count + 1, $rest); $high > $low; $high = \min(2 * $high, $rest)) {
            if (Str::len(\substr($chars, $byte, $high)) > $count) {
                while ($high - $low > 1) {
                    $middle = $low + \intdiv($high - $low, 2);

                    if (Str::len(\substr($chars, $byte, $middle)) > $count) {
                        $high = $middle;
                    } else {
                        $low = $middle;
                    }
                }

                return $byte + $low;
            }

            if ($high === $rest) {
                break;
            }

            $low = $high;
        }

        return $byte + $rest;
    }

    /**
     * Determine what must follow the given string for it to open a variable.
     *
     * That is, the rest of the opening, an empty string once only the name is
     * unfinished, or null if it cannot open one.
     *
     * @param string $str
     *
     * @return string|null
     */
    private static function getNeeded(string $str)
    {
        if ($str === '') {
            return '${';
        }

        if ($str === '$') {
            return '{';
        }

        if (\strncmp($str, '${', 2) === 0 && \strspn($str, self::NAME_CHARS, 2) === \strlen($str) - 2) {
            return '';
        }

        return null;
    }

    /**
     * Resolve a single nested variable.
     *
     * @param \Dotenv\Repository\RepositoryInterface $repository
     * @param string                                 $str
     *
     * @return string
     */
    private static function resolveVariable(RepositoryInterface $repository, string $str)
    {
        return Regex::replaceCallback(
            '/\A\${([a-zA-Z0-9_.]+)}/',
            static function (array $matches) use ($repository) {
                /** @var string */
                return Option::fromValue($repository->get($matches[1]))->getOrElse($matches[0]);
            },
            $str,
            1
        )->success()->getOrElse($str);
    }
}
