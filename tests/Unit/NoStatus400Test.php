<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use Macrolab\Http\HttpException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The application answers invalid input with 422, never 400: on the TU Delft
 * hosting a 400 answer to a form submission is turned into a 403 by
 * ModSecurity rule 243420 and counts towards an IP ban. See
 * HttpException::unprocessable().
 */
final class NoStatus400Test extends TestCase
{
    public function testInvalidInputIsAnswered422(): void
    {
        self::assertSame(422, HttpException::unprocessable()->status);
    }

    public function testNothingInTheApplicationSendsA400(): void
    {
        $offenders = [];
        $source = dirname(__DIR__, 2) . '/app/src';

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                // A status argument or assignment of 400: "? 400 :", "(400,", "= 400;", "400)".
                if (preg_match('/(\?\s*400\s*:|\(\s*400\s*,|=\s*400\s*;|,\s*400\s*\))/', $line)) {
                    $offenders[] = substr($file->getPathname(), strlen($source) + 1) . ':' . ($number + 1);
                }
            }
        }

        self::assertSame([], $offenders, 'answer invalid input with 422 instead');
    }
}
