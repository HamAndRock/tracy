<?php

/**
 * This file is part of the Tracy (https://tracy.nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Tracy\Dumper;


/**
 * @internal
 */
final class Model
{
	public function __construct(array $props)
	{
		foreach ($props as $k => $v) {
			$this->$k = $v;
		}
	}
}
