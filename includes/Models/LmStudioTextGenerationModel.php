<?php

declare( strict_types=1 );

namespace AiProviderForLmStudio\Models;

use AiProviderForLmStudio\Http\NoOpRequestAuthentication;
use AiProviderForLmStudio\Provider\LmStudioProvider;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Class for an LM Studio text generation model using the OpenAI-compatible chat completions API.
 *
 * @since 1.0.0
 */
class LmStudioTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {

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
	 * @since 1.0.0
	 */
	protected function createRequest(
		HttpMethodEnum $method,
		string $path,
		array $headers = array(),
		$data = null
	): Request {
		// LM Studio supports OpenAI-compatible endpoints at /v1/.
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
