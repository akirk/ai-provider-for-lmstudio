<?php

declare( strict_types=1 );

namespace AiProviderForLmStudio\Http;

use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;

/**
 * No-op authentication for providers that do not require credentials.
 *
 * @since 1.0.0
 */
class NoOpRequestAuthentication implements RequestAuthenticationInterface {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	public function authenticateRequest( Request $request ): Request {
		return $request;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	public static function getJsonSchema(): array {
		return array( 'type' => 'object', 'properties' => array() );
	}
}
