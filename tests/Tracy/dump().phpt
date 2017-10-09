<?php

/**
 * Test: dump() modes
 */

declare(strict_types=1);

use Tester\Assert;
use Tracy\Debugger;


require __DIR__ . '/../bootstrap.php';


test(function () { // html mode
	header('Content-Type: text/html');
	if (headers_list()) {
		ob_start();
		Assert::same(123, dump(123));
		Assert::match(<<<'XX'
<pre class="tracy-dump"><span class="tracy-dump-number">123</span></pre>
XX
, ob_get_clean());
	}
});


test(function () { // terminal mode
	header('Content-Type: text/plain');
	putenv('ConEmuANSI=ON');
	ob_start();
	Assert::same(123, dump(123));
	Assert::match("\e[1;32m123\e[0m", ob_get_clean());
});


test(function () { // text mode
	header('Content-Type: text/plain');
	Tracy\Dumper::$terminalColors = null;
	ob_start();
	Assert::same(123, dump(123));
	Assert::match('123', ob_get_clean());
});


test(function () { // production mode
	Debugger::$productionMode = true;

	ob_start();
	dump('sensitive data');
	Assert::same('', ob_get_clean());
});


test(function () { // development mode
	Debugger::$productionMode = false;

	ob_start();
	dump('sensitive data');
	Assert::match("'sensitive data'", ob_get_clean());
});


test(function () { // returned value
	$obj = new stdClass;
	Assert::same(dump($obj), $obj);
});


test(function () { // multiple value
	$obj = new stdClass;
	ob_start();
	Assert::same(dump($obj, 1, 2), $obj);
	Assert::match('stdClass #%d%
1
2', ob_get_clean());
});