<?php

declare( strict_types=1 );

namespace AiProviderForLmStudio\Provider;

use AiProviderForLmStudio\Metadata\LmStudioModelMetadataDirectory;
use AiProviderForLmStudio\Models\LmStudioImageGenerationModel;
use AiProviderForLmStudio\Models\LmStudioTextGenerationModel;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Class for the LM Studio provider.
 *
 * @since 1.0.0
 */
class LmStudioProvider extends AbstractApiProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function baseUrl(): string {
		$host = getenv( 'LMSTUDIO_HOST' );
		if ( false !== $host && '' !== $host ) {
			return rtrim( $host, '/' );
		}

		return 'http://localhost:1234';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createModel(
		ModelMetadata $model_metadata,
		ProviderMetadata $provider_metadata
	): ModelInterface {
		$capabilities = $model_metadata->getSupportedCapabilities();
		foreach ( $capabilities as $capability ) {
			if ( $capability->isImageGeneration() ) {
				return new LmStudioImageGenerationModel( $model_metadata, $provider_metadata );
			}
			if ( $capability->isTextGeneration() ) {
				return new LmStudioTextGenerationModel( $model_metadata, $provider_metadata );
			}
		}

		throw new RuntimeException(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
			'Unsupported model capabilities: ' . implode( ', ', $capabilities )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		$provider_meta = array(
			'lmstudio',
			'LM Studio',
			ProviderTypeEnum::server(),
			null,
			RequestAuthenticationMethod::apiKey(),
		);

		// Provider description support was added in 1.2.0.
		if ( version_compare( AiClient::VERSION, '1.2.0', '>=' ) ) {
			if ( function_exists( '__' ) ) {
				$provider_meta[] = __( 'Text generation with LM Studio running locally.', 'ai-provider-for-lmstudio' );
			} else {
				$provider_meta[] = 'Text generation with LM Studio running locally.';
			}
		}

		// Provider logo path support was added in 1.3.0.
		if ( version_compare( AiClient::VERSION, '1.3.0', '>=' ) ) {
			$provider_meta[] = __DIR__ . '/logo.svg';
		}

		return new ProviderMetadata( ...$provider_meta );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new LmStudioProviderAvailability();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new LmStudioModelMetadataDirectory();
	}
}
