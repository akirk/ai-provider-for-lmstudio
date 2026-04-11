<?php

declare( strict_types=1 );

namespace AiProviderForLmStudio\Models;

use AiProviderForLmStudio\Http\NoOpRequestAuthentication;
use AiProviderForLmStudio\Provider\LmStudioProvider;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;

/**
 * Class for an LM Studio image generation model using the OpenAI-compatible images API.
 *
 * @since 1.0.0
 */
class LmStudioImageGenerationModel extends AbstractOpenAiCompatibleImageGenerationModel {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	public function getRequestAuthentication(): RequestAuthenticationInterface {
		try {
			return parent::getRequestAuthentication();
		} catch ( \RuntimeException $e ) {
			return new NoOpRequestAuthentication();
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * Strips the `__image` suffix from the model ID before sending to LM Studio,
	 * since that suffix is internal bookkeeping for capability routing.
	 *
	 * @since 1.0.0
	 */
	protected function prepareGenerateImageParams( array $prompt ): array {
		$params = parent::prepareGenerateImageParams( $prompt );
		if ( isset( $params['model'] ) && str_ends_with( (string) $params['model'], '__image' ) ) {
			$params['model'] = substr( (string) $params['model'], 0, -7 );
		}
		return $params;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected function createRequest(
		HttpMethodEnum $method,
		string $path,
		array $headers = array(),
		$data = null
	): Request {
		$path = ltrim( (string) preg_replace( '#^v1/?#', '', ltrim( $path, '/' ) ), '/' );
		$path = '/v1/' . $path;

		return new Request(
			$method,
			LmStudioProvider::url( $path ),
			$headers,
			$data,
			$this->getRequestOptions()
		);
	}
}
