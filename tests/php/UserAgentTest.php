<?php
/**
 * User agent classification.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Tests;

use FastUrlShortener\Tracking\UserAgent;

/**
 * @covers \FastUrlShortener\Tracking\UserAgent
 */
class UserAgentTest extends TestCase {

	/**
	 * Real browsers.
	 *
	 * @return array
	 */
	public function browsers() {
		return [
			'iPhone Safari'   => [ 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1', UserAgent::DEVICE_MOBILE, UserAgent::OS_IOS, UserAgent::BROWSER_SAFARI ],
			'iPad Safari'     => [ 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1', UserAgent::DEVICE_TABLET, UserAgent::OS_IOS, UserAgent::BROWSER_SAFARI ],
			'Android Chrome'  => [ 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36', UserAgent::DEVICE_MOBILE, UserAgent::OS_ANDROID, UserAgent::BROWSER_CHROME ],
			'Android tablet'  => [ 'Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', UserAgent::DEVICE_TABLET, UserAgent::OS_ANDROID, UserAgent::BROWSER_CHROME ],
			'Samsung'         => [ 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36', UserAgent::DEVICE_MOBILE, UserAgent::OS_ANDROID, UserAgent::BROWSER_SAMSUNG ],
			'Windows Edge'    => [ 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0', UserAgent::DEVICE_DESKTOP, UserAgent::OS_WINDOWS, UserAgent::BROWSER_EDGE ],
			'macOS Firefox'   => [ 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.0; rv:131.0) Gecko/20100101 Firefox/131.0', UserAgent::DEVICE_DESKTOP, UserAgent::OS_MACOS, UserAgent::BROWSER_FIREFOX ],
			'Linux Opera'     => [ 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 OPR/115.0', UserAgent::DEVICE_DESKTOP, UserAgent::OS_LINUX, UserAgent::BROWSER_OPERA ],
			'ChromeOS Chrome' => [ 'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', UserAgent::DEVICE_DESKTOP, UserAgent::OS_CHROMEOS, UserAgent::BROWSER_CHROME ],
		];
	}

	/**
	 * @dataProvider browsers
	 *
	 * @param string $ua      User agent.
	 * @param int    $device  Expected device.
	 * @param int    $os      Expected OS.
	 * @param int    $browser Expected browser.
	 */
	public function test_classifies_browsers( $ua, $device, $os, $browser ) {
		$this->assertSame(
			[
				'is_bot'  => false,
				'device'  => $device,
				'os'      => $os,
				'browser' => $browser,
			],
			UserAgent::parse( $ua )
		);
	}

	/**
	 * Crawlers, link previews, monitors and HTTP clients are bots.
	 *
	 * @return array
	 */
	public function bots() {
		return [
			[ 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ],
			[ 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)' ],
			[ 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)' ],
			[ 'WhatsApp/2.23.20.0 A' ],
			[ 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)' ],
			[ 'TelegramBot (like TwitterBot)' ],
			[ 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36' ],
			[ 'curl/8.4.0' ],
			[ 'python-requests/2.31.0' ],
			[ 'Go-http-client/1.1' ],
			[ '' ],
		];
	}

	/**
	 * @dataProvider bots
	 *
	 * @param string $ua User agent.
	 */
	public function test_detects_bots( $ua ) {
		$this->assertTrue( UserAgent::parse( $ua )['is_bot'] );
	}

	/**
	 * Every stored value has a CSV key and a label.
	 */
	public function test_keys_and_labels_cover_all_values() {
		$labels = UserAgent::labels();

		foreach ( UserAgent::KEYS as $dimension => $keys ) {
			$this->assertSame( array_keys( $keys ), array_keys( $labels[ $dimension ] ), $dimension );
			$this->assertSame( count( $keys ), count( array_unique( $keys ) ), "$dimension keys are unique" );
		}
	}
}
