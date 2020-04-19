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

	/** @var int|null */
	public $maxDepth = 4;

	/** @var int|null */
	public $maxLength = 150;

	/** @var int|null */
	public $maxItems = 50;

	/** @var bool */
	public $location = false;

	/** @var \stdClass[] */
	public $snapshot = [];

	/** @var bool */
	public $debugInfo = false;

	/** @var array */
	public $keysToHide = [];

	/** @var callable[] */
	public $resourceExposers;

	/** @var callable[] */
	public $objectExposers;

	/** @var int[] */
	private $references = [];

	/** @var int[] */
	private $parentArrays = [];


	/**
	 * @return mixed
	 */
	public function describe($var)
	{
		$this->references = $this->parentArrays = [];
		uksort($this->objectExposers, function ($a, $b): int {
			return $b === '' || (class_exists($a, false) && is_subclass_of($a, $b)) ? -1 : 1;
		});
		return $this->describeVar($var);
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
				$m = 'describe' . gettype($var);
				return $this->$m($var, $depth, $refId);
		}
	}


	/**
	 * @return Model|float
	 */
	private function describeDouble(float $num)
	{
		if (!is_finite($num)) {
			return new Model(['number' => (string) $num]);
		}
		$tmp = json_encode($num);
		return strpos($tmp, '.') ? $num : new Model(['number' => "$tmp.0"]);
	}


	/**
	 * @return Model|string
	 */
	private function describeString(string $s)
	{
		$res = Helpers::encodeString($s, $this->maxLength);
		if ($res === $s) {
			return $res;
		}
		return new Model(['string' => $res, 'length' => strlen($s)]);
	}


	/**
	 * @return Model|array
	 */
	private function describeArray(array $arr, int $depth = 0, int $refId = null)
	{
		if ($refId && in_array($refId, $this->parentArrays, true)) {
			return new Model(['array' => [], 'cut' => 'r', 'length' => count($arr)]);
		} elseif (count($arr) && $depth >= $this->maxDepth) {
			return new Model(['array' => [], 'cut' => 'd', 'length' => count($arr)]);

		} elseif ($this->maxItems && count($arr) > $this->maxItems) {
			$res = new Model(['array' => [], 'length' => count($arr), 'cut' => 'i']);
			$items = &$res->array;
			$arr = array_slice($arr, 0, $this->maxItems, true);
		}

		$this->parentArrays[] = $refId;
		$items = [];

		foreach ($arr as $k => $v) {
			$refId = $this->getReferenceId($arr, $k);
			$items[] = [
				$this->encodeKey($k),
				is_string($k) && isset($this->keysToHide[strtolower($k)])
					? new Model(['text' => self::hideValue($v)])
					: $this->describeVar($v, $depth + 1, $refId),
			] + ($refId ? [2 => $refId] : []);
		}

		array_pop($this->parentArrays);
		return $res ?? $items;
	}


	private function describeObject(object $obj, int $depth = 0): Model
	{
		$id = spl_object_id($obj);
		$shot = &$this->snapshot[$id];
		if ($shot && $shot->depth <= $depth) {
			return new Model(['object' => $id]);
		}

		$shot = $shot ?: (object) [
			'name' => Helpers::getClass($obj),
			'depth' => $depth,
			'object' => $obj, // to be not released by garbage collector
		];
		if (empty($shot->editor) && $this->location) {
			$rc = $obj instanceof \Closure ? new \ReflectionFunction($obj) : new \ReflectionClass($obj);
			if ($editor = $rc->getFileName() ? Helpers::editorUri($rc->getFileName(), $rc->getStartLine()) : null) {
				$shot->editor = (object) ['file' => $rc->getFileName(), 'line' => $rc->getStartLine(), 'url' => $editor];
			}
		}

		if ($depth < $this->maxDepth || !$this->maxDepth) {
			$shot->depth = $depth;
			$shot->items = [];

			$props = $this->exposeObject($obj);
			if ($this->maxItems && count($props) > $this->maxItems) {
				$shot->cut = true;
				$props = array_slice($props, 0, $this->maxItems, true);
			}

			foreach ($props as $info) {
				[$k, $v, $type] = $info;
				$refId = $this->getReferenceId($info, 1);
				$k = (string) $k;
				$v = isset($this->keysToHide[strtolower($k)])
					? new Model(['text' => self::hideValue($v)])
					: $this->describeVar($v, $depth + 1, $refId);
				$shot->items[] = [$this->encodeKey($k), $v, $type] + ($refId ? [3 => $refId] : []);
			}
		}
		return new Model(['object' => $id]);
	}


	/**
	 * @param  resource  $resource
	 */
	private function describeResource($resource, int $depth = 0): Model
	{
		$id = 'r' . (int) $resource;
		$shot = &$this->snapshot[$id];
		if (!$shot) {
			$type = get_resource_type($resource);
			$shot = (object) ['name' => $type . ' resource'];
			if (isset($this->resourceExposers[$type])) {
				foreach (($this->resourceExposers[$type])($resource) as $k => $v) {
					$shot->items[] = [$k, $this->describeVar($v, $depth + 1)];
				}
			}
		}
		return new Model(['resource' => $id]);
	}


	/**
	 * @param  int|string  $key
	 * @return int|string
	 */
	private function encodeKey($key)
	{
		return is_int($key) || (preg_match('#^[\w!\#$%&*+./;<>?@^{|}~-]{1,50}$#D', $key) && !preg_match('#^true|false|null$#iD', $key))
			? $key
			: "'" . Helpers::encodeString($key, $this->maxLength) . "'";
	}


	private function exposeObject(object $obj): array
	{
		foreach ($this->objectExposers as $type => $dumper) {
			if (!$type || $obj instanceof $type) {
				$info = $dumper($obj);
				return isset($info[0][0])
					? array_map(function ($x) { $x[2] = $x[2] ?? Exposer::PROP_VIRTUAL; return $x; }, $info)
					: Exposer::convert($info);
			}
		}

		if ($this->debugInfo && method_exists($obj, '__debugInfo')) {
			return Exposer::convert($obj->__debugInfo());
		}

		return Exposer::exposeObject($obj);
	}


	private static function hideValue($var): string
	{
		return self::HIDDEN_VALUE . ' (' . (is_object($var) ? Helpers::getClass($var) : gettype($var)) . ')';
	}


	private function getReferenceId($arr, $key): ?int
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
}
