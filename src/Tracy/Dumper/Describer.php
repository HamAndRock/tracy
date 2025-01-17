<?php

/**
 * This file is part of the Tracy (https://tracy.nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Tracy\Dumper;

use Tracy\Helpers;


/**
 * Converts PHP values to internal representation.
 * @internal
 */
final class Describer
{
	public const HIDDEN_VALUE = '*****';

	/** @var int */
	public $maxDepth = 7;

	/** @var int */
	public $maxLength = 150;

	/** @var int */
	public $maxItems = 100;

	/** @var Structure[] */
	public $snapshot = [];

	/** @var bool */
	public $debugInfo = false;

	/** @var array */
	public $keysToHide = [];

	/** @var callable[] */
	public $resourceExposers;

	/** @var array<string,callable> */
	public $objectExposers;

	/** @var (int|\stdClass)[] */
	private $references = [];


	public function describe($var): \stdClass
	{
		$this->references = [];
		uksort($this->objectExposers, function ($a, $b): int {
			return $b === '' || (class_exists($a, false) && is_subclass_of($a, $b)) ? -1 : 1;
		});
		return (object) [
			'value' => $this->describeVar($var),
			'snapshot' => $this->snapshot,
			'location' => self::findLocation(),
		];
	}


	/**
	 * @return mixed
	 */
	private function describeVar($var, int $depth = 0, int $refId = null)
	{
		switch (true) {
			case $var === null:
			case is_bool($var):
			case is_int($var):
				return $var;
			default:
				$m = 'describe' . explode(' ', gettype($var))[0];
				return $this->$m($var, $depth, $refId);
		}
	}


	/**
	 * @return Value|float
	 */
	private function describeDouble(float $num)
	{
		if (!is_finite($num)) {
			return new Value(Value::TYPE_NUMBER, (string) $num);
		}
		$js = json_encode($num);
		return strpos($js, '.')
			? $num
			: new Value(Value::TYPE_NUMBER, "$js.0"); // to distinct int and float in JS
	}


	/**
	 * @return Value|string
	 */
	private function describeString(string $s)
	{
		$res = Helpers::encodeString($s, $this->maxLength, $utf);
		if ($res === $s) {
			return $res;
		} elseif ($utf) { // is UTF-8
			return new Value(Value::TYPE_STRING, $res, strlen(utf8_decode($s)));
		} else {
			return new Value(Value::TYPE_BINARY, $res, strlen($s));
		}
	}


	/**
	 * @return Value|array
	 */
	private function describeArray(array $arr, int $depth = 0, int $refId = null)
	{
		if ($refId || count($arr) > $this->maxItems) {
			$id = $refId ? 'a' . $refId : 'A' . count($this->snapshot);
			$res = new Value(Value::TYPE_ARRAY, $id);

			$struct = &$this->snapshot[$id];
			if (!$struct) {
				$struct = new Structure(null, $depth, $arr);
			} elseif ($struct->depth <= $depth) {
				return $res;
			}
			if (count($arr) > $this->maxItems || $depth >= $this->maxDepth) {
				$struct->length = count($arr);
				if ($depth >= $this->maxDepth) {
					return $res;
				}
				$arr = array_slice($arr, 0, $this->maxItems, true);
			}
			$struct->depth = $depth;
			$items = &$struct->items;

		} elseif ($arr && $depth >= $this->maxDepth) {
			return new Value(Value::TYPE_STOP, count($arr));
		}

		$items = [];
		foreach ($arr as $k => $v) {
			$refId = $this->getReferenceId($arr, $k);
			$items[] = [
				$this->describeVar($k, $depth + 1),
				is_string($k) && isset($this->keysToHide[strtolower($k)])
					? new Value(Value::TYPE_TEXT, self::hideValue($v))
					: $this->describeVar($v, $depth + 1, $refId),
			] + ($refId ? [2 => $refId] : []);
		}

		return $res ?? $items;
	}


	private function describeObject(object $obj, int $depth = 0): Value
	{
		$id = spl_object_id($obj);
		$struct = &$this->snapshot[$id];
		if (!$struct) {
			$struct = new Structure(Helpers::getClass($obj), $depth, $obj);
			$rc = $obj instanceof \Closure ? new \ReflectionFunction($obj) : new \ReflectionClass($obj);
			if ($rc->getFileName() && ($editor = Helpers::editorUri($rc->getFileName(), $rc->getStartLine()))) {
				$struct->editor = (object) ['file' => $rc->getFileName(), 'line' => $rc->getStartLine(), 'url' => $editor];
			}
		} elseif ($struct->depth <= $depth) {
			return new Value(Value::TYPE_OBJECT, $id);
		}

		if ($depth < $this->maxDepth) {
			$struct->depth = $depth;
			$struct->items = [];
			$struct->length = null;

			$props = $this->exposeObject($obj, $struct);
			foreach ($props ?? [] as $k => $v) {
				$this->addProperty($struct, $k, $v, Exposer::PROP_VIRTUAL, $this->getReferenceId($props, $k));
			}
		}
		return new Value(Value::TYPE_OBJECT, $id);
	}


	/**
	 * @param  resource  $resource
	 */
	private function describeResource($resource, int $depth = 0): Value
	{
		$id = 'r' . (int) $resource;
		$struct = &$this->snapshot[$id];
		if (!$struct) {
			$type = is_resource($resource) ? get_resource_type($resource) : 'closed';
			$struct = new Structure($type . ' resource');
			$struct->items = [];
			if (isset($this->resourceExposers[$type])) {
				foreach (($this->resourceExposers[$type])($resource) as $k => $v) {
					$struct->items[] = [htmlspecialchars($k), $this->describeVar($v, $depth + 1)];
				}
			}
		}
		return new Value(Value::TYPE_RESOURCE, $id);
	}


	private function describeKey(string $key): string
	{
		return preg_match('#^[\w!\#$%&*+./;<>?@^{|}~-]{1,50}$#D', $key) && !preg_match('#^true|false|null$#iD', $key)
			? htmlspecialchars($key)
			: "'" . Helpers::encodeString($key, $this->maxLength) . "'";
	}


	public function addProperty(Structure $struct, $k, $v, $type, $refId = null)
	{
		if (count($struct->items ?? []) >= $this->maxItems) {
			$struct->length = ($struct->length ?? count($struct->items)) + 1;
			return;
		}
		$k = (string) $k;
		$v = isset($this->keysToHide[strtolower($k)])
			? new Value(Value::TYPE_TEXT, self::hideValue($v))
			: $this->describeVar($v, $struct->depth + 1, $refId);
		$struct->items[] = [$this->describeKey($k), $v, $type] + ($refId ? [3 => $refId] : []);
	}


	private function exposeObject(object $obj, Structure $struct): ?array
	{
		foreach ($this->objectExposers as $type => $dumper) {
			if (!$type || $obj instanceof $type) {
				return $dumper($obj, $struct, $this);
			}
		}

		if ($this->debugInfo && method_exists($obj, '__debugInfo')) {
			return $obj->__debugInfo();
		}

		Exposer::exposeObject($obj, $struct, $this);
		return null;
	}


	private static function hideValue($var): string
	{
		return self::HIDDEN_VALUE . ' (' . (is_object($var) ? Helpers::getClass($var) : gettype($var)) . ')';
	}


	public function getReferenceId($arr, $key): ?int
	{
		if (PHP_VERSION_ID >= 70400) {
			if ((!$rr = \ReflectionReference::fromArrayElement($arr, $key))) {
				return null;
			}
			$tmp = &$this->references[$rr->getId()];
			if ($tmp === null) {
				return $tmp = count($this->references);
			}
			return $tmp;
		}
		$uniq = new \stdClass;
		$copy = $arr;
		$orig = $copy[$key];
		$copy[$key] = $uniq;
		if ($arr[$key] !== $uniq) {
			return null;
		}
		$res = array_search($uniq, $this->references, true);
		$copy[$key] = $orig;
		if ($res === false) {
			$this->references[] = &$arr[$key];
			return count($this->references);
		}
		return $res + 1;
	}


	/**
	 * Finds the location where dump was called. Returns [file, line, code]
	 */
	private static function findLocation(): ?array
	{
		foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $item) {
			if (isset($item['class']) && ($item['class'] === self::class || $item['class'] === \Tracy\Dumper::class)) {
				$location = $item;
				continue;
			} elseif (isset($item['function'])) {
				try {
					$reflection = isset($item['class'])
						? new \ReflectionMethod($item['class'], $item['function'])
						: new \ReflectionFunction($item['function']);
					if ($reflection->isInternal() || preg_match('#\s@tracySkipLocation\s#', (string) $reflection->getDocComment())) {
						$location = $item;
						continue;
					}
				} catch (\ReflectionException $e) {
				}
			}
			break;
		}

		if (isset($location['file'], $location['line']) && is_file($location['file'])) {
			$lines = file($location['file']);
			$line = $lines[$location['line'] - 1];
			return [
				$location['file'],
				$location['line'],
				trim(preg_match('#\w*dump(er::\w+)?\(.*\)#i', $line, $m) ? $m[0] : $line),
			];
		}
		return null;
	}
}
