<?php
/**
 * User agent parsing.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Tracking;

/**
 * Small, dependency-free user agent classifier. It only answers what the
 * reports need: bot or not, device type, OS family and browser family.
 * Values are stored as small integers; labels() maps them back.
 */
class UserAgent {

	const UNKNOWN = 0;

	const DEVICE_DESKTOP = 1;
	const DEVICE_MOBILE  = 2;
	const DEVICE_TABLET  = 3;

	const OS_WINDOWS  = 1;
	const OS_MACOS    = 2;
	const OS_IOS      = 3;
	const OS_ANDROID  = 4;
	const OS_LINUX    = 5;
	const OS_CHROMEOS = 6;

	const BROWSER_CHROME  = 1;
	const BROWSER_SAFARI  = 2;
	const BROWSER_FIREFOX = 3;
	const BROWSER_EDGE    = 4;
	const BROWSER_OPERA   = 5;
	const BROWSER_SAMSUNG = 6;

	/**
	 * Crawlers, link unfurlers (chat apps fetch links to build previews),
	 * monitoring tools and HTTP libraries.
	 */
	const BOT_PATTERN = '/bot\b|bot\/|crawl|spider|slurp|mediapartners|facebookexternalhit|facebot|embedly|preview|outbrain|pinterest|vkshare|w3c_validator|whatsapp|bitly|headlesschrome|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|curl|wget|python|java\/|go-http-client|okhttp|axios|node-fetch|httpclient|libwww|scrapy|feedfetcher/i';

	/**
	 * Classify a user agent string.
	 *
	 * @param string $user_agent User agent.
	 *
	 * @return array{is_bot: bool, device: int, os: int, browser: int}
	 */
	public static function parse( $user_agent ) {
		$user_agent = (string) $user_agent;

		return [
			'is_bot'  => '' === trim( $user_agent ) || (bool) preg_match( self::BOT_PATTERN, $user_agent ),
			'device'  => self::device( $user_agent ),
			'os'      => self::os( $user_agent ),
			'browser' => self::browser( $user_agent ),
		];
	}

	/**
	 * Device type.
	 *
	 * @param string $ua User agent.
	 *
	 * @return int
	 */
	protected static function device( $ua ) {
		if ( preg_match( '/ipad|tablet|kindle|silk|playbook|android(?!.*mobile)/i', $ua ) ) {
			return self::DEVICE_TABLET;
		}

		if ( preg_match( '/mobi|iphone|ipod|android|blackberry|opera mini|iemobile/i', $ua ) ) {
			return self::DEVICE_MOBILE;
		}

		return '' === $ua ? self::UNKNOWN : self::DEVICE_DESKTOP;
	}

	/**
	 * Operating system family. Order matters: iOS and Android UAs also
	 * mention macOS and Linux.
	 *
	 * @param string $ua User agent.
	 *
	 * @return int
	 */
	protected static function os( $ua ) {
		$map = [
			'/iphone|ipad|ipod/i'   => self::OS_IOS,
			'/android/i'            => self::OS_ANDROID,
			'/cros/i'               => self::OS_CHROMEOS,
			'/windows/i'            => self::OS_WINDOWS,
			'/mac os x|macintosh/i' => self::OS_MACOS,
			'/linux/i'              => self::OS_LINUX,
		];

		return self::first_match( $ua, $map );
	}

	/**
	 * Browser family. Order matters: most browsers also claim to be Chrome
	 * and Safari.
	 *
	 * @param string $ua User agent.
	 *
	 * @return int
	 */
	protected static function browser( $ua ) {
		$map = [
			'/edg(e|a|ios)?\//i' => self::BROWSER_EDGE,
			'/opr\/|opera/i'     => self::BROWSER_OPERA,
			'/samsungbrowser/i'  => self::BROWSER_SAMSUNG,
			'/firefox|fxios/i'   => self::BROWSER_FIREFOX,
			'/chrome|crios/i'    => self::BROWSER_CHROME,
			'/safari/i'          => self::BROWSER_SAFARI,
		];

		return self::first_match( $ua, $map );
	}

	/**
	 * Value of the first matching pattern.
	 *
	 * @param string $ua  User agent.
	 * @param array  $map Pattern => value.
	 *
	 * @return int
	 */
	protected static function first_match( $ua, array $map ) {
		foreach ( $map as $pattern => $value ) {
			if ( preg_match( $pattern, $ua ) ) {
				return $value;
			}
		}

		return self::UNKNOWN;
	}

	/**
	 * Human-readable labels for stored values.
	 *
	 * @return array{device: array, os: array, browser: array}
	 */
	public static function labels() {
		$unknown = __( 'Unknown', 'fast-url-shortener' );

		return [
			'device'  => [
				self::UNKNOWN        => $unknown,
				self::DEVICE_DESKTOP => __( 'Desktop', 'fast-url-shortener' ),
				self::DEVICE_MOBILE  => __( 'Mobile', 'fast-url-shortener' ),
				self::DEVICE_TABLET  => __( 'Tablet', 'fast-url-shortener' ),
			],
			'os'      => [
				self::UNKNOWN     => $unknown,
				self::OS_WINDOWS  => 'Windows',
				self::OS_MACOS    => 'macOS',
				self::OS_IOS      => 'iOS',
				self::OS_ANDROID  => 'Android',
				self::OS_LINUX    => 'Linux',
				self::OS_CHROMEOS => 'ChromeOS',
			],
			'browser' => [
				self::UNKNOWN         => $unknown,
				self::BROWSER_CHROME  => 'Chrome',
				self::BROWSER_SAFARI  => 'Safari',
				self::BROWSER_FIREFOX => 'Firefox',
				self::BROWSER_EDGE    => 'Edge',
				self::BROWSER_OPERA   => 'Opera',
				self::BROWSER_SAMSUNG => 'Samsung Internet',
			],
		];
	}
}
