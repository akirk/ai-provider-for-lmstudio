<?php

declare( strict_types=1 );

namespace AiProviderForLmStudio\Provider;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

/**
 * Provider availability that always reports as configured.
 *
 * LM Studio runs locally and does not require an API key. The WordPress
 * connectors UI validates provider credentials by calling isConfigured();
 * returning true unconditionally lets the sentinel key pass validation so
 * that the provider appears as connected on the connectors page.
 *
 * @since 1.0.0
 */
class LmStudioProviderAvailability implements ProviderAvailabilityInterface {

	/**
	 * {@inheritDoc}
	 *
	 * Always returns true because LM Studio does not require authentication
	 * and the provider is considered configured as soon as the plugin is active.
	 *
	 * @since 1.0.0
	 */
	public function isConfigured(): bool {
		return true;
	}
}
