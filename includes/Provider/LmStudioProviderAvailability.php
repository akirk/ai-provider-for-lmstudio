<?php

declare( strict_types=1 );

namespace AiProviderForLmStudio\Provider;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

/**
 * Provider availability based on whether PHP can reach LM Studio.
 *
 * LM Studio runs on the user's machine and does not require an API key, so
 * "configured" cannot mean "has a valid key". Instead it means "the server
 * running WordPress can reach the LM Studio API". This is what the AI client
 * needs to know: when WordPress is hosted remotely, PHP cannot use LM Studio at
 * all, and claiming otherwise only leads to failed requests.
 *
 * Browser-side consumers (see `build/browser-status.js`) perform their own
 * check from the user's browser, which can reach a local LM Studio even when
 * the server cannot.
 *
 * The probe uses a very short timeout and its result is cached in a transient
 * so that a remote host does not hit a dead localhost port on every request.
 *
 * @since 1.0.0
 */
class LmStudioProviderAvailability implements ProviderAvailabilityInterface {

	/**
	 * Transient key prefix. The host is appended so a changed host is re-probed.
	 *
	 * @since 1.1.0
	 */
	private const TRANSIENT_PREFIX = 'ai_provider_for_lmstudio_reachable_';

	/**
	 * How long a probe result is cached, in seconds.
	 *
	 * @since 1.1.0
	 */
	private const CACHE_TTL = 60;

	/**
	 * Probe timeout in seconds. A local port that is not listening refuses the
	 * connection immediately; this bounds the case where the host is remote.
	 *
	 * @since 1.1.0
	 */
	private const PROBE_TIMEOUT = 1;

	/**
	 * {@inheritDoc}
	 *
	 * Returns true when LM Studio is reachable from PHP.
	 *
	 * @since 1.0.0
	 */
	public function isConfigured(): bool {
		return self::is_reachable();
	}

	/**
	 * Checks whether the LM Studio API is reachable from the server.
	 *
	 * The result is cached for {@see self::CACHE_TTL} seconds.
	 *
	 * @since 1.1.0
	 *
	 * @return bool Whether LM Studio responded to the probe.
	 */
	public static function is_reachable(): bool {
		$url = LmStudioProvider::url( 'api/v1/models' );
		$key = self::TRANSIENT_PREFIX . md5( $url );

		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return '1' === $cached;
		}

		$response  = wp_remote_get(
			$url,
			array( 'timeout' => self::PROBE_TIMEOUT )
		);
		$reachable = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );

		set_transient( $key, $reachable ? '1' : '0', self::CACHE_TTL );

		return $reachable;
	}

	/**
	 * Forgets the cached probe result so the next check hits the network.
	 *
	 * @since 1.1.0
	 */
	public static function flush_cache(): void {
		delete_transient( self::TRANSIENT_PREFIX . md5( LmStudioProvider::url( 'api/v1/models' ) ) );
	}
}
