<?php

/**
 * Test: Tracy\Dumper::toText() specials
 */

declare(strict_types=1);

use Tester\Assert;
use Tracy\Dumper;


require __DIR__ . '/../bootstrap.php';


// resource
Assert::match("stream resource @%d%\n   %S%%A%", Dumper::toText(fopen(__FILE__, 'r')));


// closure
Assert::match(<<<'XX'
Closure #%d%
   file: '%a%'
   line: %i%
   variables: array ()
   parameters: ''
XX
, Dumper::toText(function () {}));


// new class
Assert::match('class@anonymous #%d%', Dumper::toText(new class {
}));


// SplFileInfo
Assert::match("SplFileInfo #%d%
   path: '%a%'
", Dumper::toText(new SplFileInfo(__FILE__)));


// SplObjectStorage
$objStorage = new SplObjectStorage;
$objStorage->attach($o1 = new stdClass);
$objStorage[$o1] = 'o1';
$objStorage->attach($o2 = (object) ['foo' => 'bar']);
$objStorage[$o2] = 'o2';

$objStorage->next();
$key = $objStorage->key();

Assert::match(<<<'XX'
SplObjectStorage #%d%
   0: array (2)
   |  object => stdClass #%d%
   |  data => 'o1'
   1: array (2)
   |  object => stdClass #%d%
   |  |  foo: 'bar'
   |  data => 'o2'
XX
, Dumper::toText($objStorage));

Assert::same($key, $objStorage->key());


// ArrayObject
$obj = new ArrayObject(['a' => 1, 'b' => 2]);
Assert::match(<<<'XX'
ArrayObject #%d%
   storage: array (2)
   |  a => 1
   |  b => 2
XX
, Dumper::toText($obj));

class ArrayObjectChild extends ArrayObject
{
	public $prop = 123;
}

$obj = new ArrayObjectChild(['a' => 1, 'b' => 2]);
Assert::match(<<<'XX'
ArrayObjectChild #%d%
   prop: 123
   storage: array (2)
   |  a => 1
   |  b => 2
XX
, Dumper::toText($obj));
