<?php
/**
 * Router test double.
 *
 * @package Clicklet
 */

namespace Clicklet\Tests\Doubles;

use Clicklet\Links\Link;
use Clicklet\Redirect\Router;

/**
 * Router double: records the redirect instead of sending it and exiting.
 */
class RecordingRouter extends Router {

	/**
	 * Redirected link, if any.
	 *
	 * @var Link|null
	 */
	public $redirected = null;

	/**
	 * Record instead of redirecting.
	 *
	 * @param Link $link Link.
	 */
	public function redirect( Link $link ) {
		$this->redirected = $link;
	}
}
