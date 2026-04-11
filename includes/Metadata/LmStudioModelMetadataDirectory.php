<?php

declare( strict_types=1 );

namespace AiProviderForLmStudio\Metadata;

use AiProviderForLmStudio\Http\NoOpRequestAuthentication;
use AiProviderForLmStudio\Provider\LmStudioProvider;
use AiProviderForLmStudio\Settings\LmStudioSettings;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Class for the LM Studio model metadata directory.
 *
 * Uses the LM Studio native /api/v1/models endpoint for richer model data,
 * including type and capability detection. Only loaded models are registered
 * with the AI client.
 *
 * @since 1.0.0
 *
 * @phpstan-type LmStudioCapabilities array{
 *     vision?: bool,
 *     trained_for_tool_use?: bool,
 *     image_generation?: bool,
 * }
 * @phpstan-type LmStudioLoadedInstance array{
 *     instance_id: string,
 * }
 * @phpstan-type LmStudioModelEntry array{
 *     key: string,
 *     type: string,
 *     display_name?: string,
 *     publisher?: string,
 *     capabilities?: LmStudioCapabilities,
 *     loaded_instances: list<LmStudioLoadedInstance>,
 * }
 * @phpstan-type NativeModelsResponse array{
 *     models: list<LmStudioModelEntry>
 * }
 */
class LmStudioModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory {

	/**
	 * {@inheritDoc}
	 *
	 * Returns a no-op implementation when no authentication is configured,
	 * allowing unauthenticated requests to a local LM Studio instance.
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
	 * Only loaded models are registered. Capabilities are derived from the LM Studio
	 * native API response plus any per-model overrides stored in settings.
	 *
	 * @since 1.0.0
	 */
	protected function sendListModelsRequest(): array {
		$settings    = LmStudioSettings::get_settings();
		$model_order = isset( $settings['model_order'] ) ? (array) $settings['model_order'] : array();

		$models_map = array();

		foreach ( $this->fetchModels() as $model ) {
			if ( empty( $model['loaded_instances'] ) ) {
				continue;
			}

			$instance_id = $this->getInstanceId( $model );
			$type        = $model['type'] ?? 'llm';
			$caps        = $model['capabilities'] ?? array();

			if ( 'embedding' === $type ) {
				$models_map[ $instance_id ] = $this->buildEmbeddingModelMetadata( $instance_id );
				continue;
			}

			$has_vision    = ! empty( $caps['vision'] );
			$has_image_gen = ! empty( $caps['image_generation'] );

			$models_map[ $instance_id ] = $this->buildTextModelMetadata( $instance_id, $has_vision );

			if ( $has_image_gen ) {
				$image_id                = $instance_id . '__image';
				$models_map[ $image_id ] = $this->buildImageModelMetadata( $image_id );
			}
		}

		// Apply user-defined priority order. Each __image variant follows its base model.
		if ( ! empty( $model_order ) ) {
			$ordered   = array();
			$remaining = $models_map;

			foreach ( $model_order as $id ) {
				foreach ( array( $id, $id . '__image' ) as $key ) {
					if ( array_key_exists( $key, $remaining ) ) {
						$ordered[ $key ] = $remaining[ $key ];
						unset( $remaining[ $key ] );
					}
				}
			}

			$models_map = array_merge( $ordered, $remaining );
		}

		return $models_map;
	}

	/**
	 * Returns all downloaded models with full metadata for display in the settings UI.
	 *
	 * Unlike listModelMetadata(), this returns every downloaded model regardless of
	 * loaded state, so the settings table can show the full picture and offer
	 * load/unload controls.
	 *
	 * @since 1.0.0
	 *
	 * @return list<array{key: string, instance_id: string, display_name: string, type: string, capabilities: LmStudioCapabilities, is_loaded: bool, image_generation_override: bool}>
	 * @throws \Throwable On HTTP or parse failure.
	 */
	public function getAvailableModels(): array {
		$settings    = LmStudioSettings::get_settings();
		$model_order = isset( $settings['model_order'] ) ? (array) $settings['model_order'] : array();

		$result = array();

		foreach ( $this->fetchModels() as $model ) {
			$instance_id = $this->getInstanceId( $model );

			$result[] = array(
				'key'          => $model['key'],
				'instance_id'  => $instance_id,
				'display_name' => $model['display_name'] ?? $model['key'],
				'type'         => $model['type'] ?? 'llm',
				'capabilities' => $model['capabilities'] ?? array(),
				'is_loaded'    => ! empty( $model['loaded_instances'] ),
			);
		}

		if ( ! empty( $model_order ) ) {
			usort(
				$result,
				static function ( array $a, array $b ) use ( $model_order ): int {
					$pos_a = array_search( $a['instance_id'], $model_order, true );
					$pos_b = array_search( $b['instance_id'], $model_order, true );
					$pos_a = false === $pos_a ? PHP_INT_MAX : $pos_a;
					$pos_b = false === $pos_b ? PHP_INT_MAX : $pos_b;
					return $pos_a <=> $pos_b;
				}
			);
		}

		return $result;
	}

	/**
	 * Loads a model into LM Studio memory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $instance_id The model identifier (publisher/key format).
	 * @throws \Throwable On HTTP or API failure.
	 */
	public function loadModel( string $instance_id ): void {
		$request  = $this->createRequest(
			HttpMethodEnum::POST(),
			'api/v1/models/load',
			array( 'Content-Type' => 'application/json' ),
			array( 'model' => $instance_id )
		);
		$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = $this->getHttpTransporter()->send( $request );
		ResponseUtil::throwIfNotSuccessful( $response );
		$this->invalidateCaches();
	}

	/**
	 * Unloads a model from LM Studio memory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $instance_id The instance ID returned by LM Studio for the loaded model.
	 * @throws \Throwable On HTTP or API failure.
	 */
	public function unloadModel( string $instance_id ): void {
		$request  = $this->createRequest(
			HttpMethodEnum::POST(),
			'api/v1/models/unload',
			array( 'Content-Type' => 'application/json' ),
			array( 'instance_id' => $instance_id )
		);
		$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = $this->getHttpTransporter()->send( $request );
		ResponseUtil::throwIfNotSuccessful( $response );
		$this->invalidateCaches();
	}

	/**
	 * Derives the instance ID used in API calls from a model entry.
	 *
	 * Prefers the instance_id from a loaded instance (authoritative), then falls
	 * back to constructing publisher/key, then bare key.
	 *
	 * @since 1.0.0
	 *
	 * @param LmStudioModelEntry $model
	 * @return string
	 */
	private function getInstanceId( array $model ): string {
		if ( ! empty( $model['loaded_instances'][0]['instance_id'] ) ) {
			return $model['loaded_instances'][0]['instance_id'];
		}
		return $model['key'];
	}

	/**
	 * The option name used to cache the model list synced from the browser.
	 *
	 * Because LM Studio typically runs on the user's local machine while
	 * WordPress may be hosted remotely, the PHP server often cannot reach
	 * the LM Studio API directly. Instead, the browser (which *can* reach
	 * localhost) fetches the model list and stores it here via AJAX.
	 *
	 * @since 1.0.0
	 */
	public const MODELS_CACHE_OPTION = 'ai_provider_for_lmstudio_models_cache';

	/**
	 * Fetches all downloaded models, preferring the browser-synced cache.
	 *
	 * Falls back to a direct HTTP request when no cache exists (e.g. when
	 * LM Studio runs on the same host as WordPress).
	 *
	 * @since 1.0.0
	 *
	 * @return list<LmStudioModelEntry>
	 * @throws \Throwable On HTTP or parse failure when no cache is available.
	 */
	private function fetchModels(): array {
		$cached = get_option( self::MODELS_CACHE_OPTION, array() );
		if ( ! empty( $cached ) && is_array( $cached ) ) {
			return $cached;
		}

		return $this->fetchModelsViaHttp();
	}

	/**
	 * Fetches models directly from the LM Studio native API over HTTP.
	 *
	 * @since 1.0.0
	 *
	 * @return list<LmStudioModelEntry>
	 * @throws \Throwable On HTTP or parse failure.
	 */
	private function fetchModelsViaHttp(): array {
		$request  = $this->createRequest( HttpMethodEnum::GET(), 'api/v1/models' );
		$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = $this->getHttpTransporter()->send( $request );

		ResponseUtil::throwIfNotSuccessful( $response );

		/** @var NativeModelsResponse $data */
		$data = $response->getData();
		if ( ! isset( $data['models'] ) ) {
			throw ResponseException::fromMissingData( 'LM Studio', 'models' );
		}

		return $data['models'];
	}

	/**
	 * Builds metadata for a text generation (LLM) model.
	 *
	 * @since 1.0.0
	 *
	 * @param string $model_id   The model instance ID.
	 * @param bool   $has_vision Whether the model accepts image inputs.
	 * @return \WordPress\AiClient\Providers\Models\DTO\ModelMetadata
	 */
	private function buildTextModelMetadata( string $model_id, bool $has_vision = false ): ModelMetadata {
		$input_modalities = array( array( ModalityEnum::text() ) );
		if ( $has_vision ) {
			$input_modalities[] = array( ModalityEnum::text(), ModalityEnum::image() );
		}

		$options = array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::candidateCount() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::topK() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::frequencyPenalty() ),
			new SupportedOption( OptionEnum::presencePenalty() ),
			new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ),
			new SupportedOption( OptionEnum::outputSchema() ),
			new SupportedOption( OptionEnum::functionDeclarations() ),
			new SupportedOption( OptionEnum::customOptions() ),
			new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
			new SupportedOption( OptionEnum::inputModalities(), $input_modalities ),
		);

		return new ModelMetadata(
			$model_id,
			$model_id,
			array(
				CapabilityEnum::textGeneration(),
				CapabilityEnum::chatHistory(),
			),
			$options
		);
	}

	/**
	 * Builds metadata for an embedding model.
	 *
	 * @since 1.0.0
	 *
	 * @param string $model_id The model instance ID.
	 * @return \WordPress\AiClient\Providers\Models\DTO\ModelMetadata
	 */
	private function buildEmbeddingModelMetadata( string $model_id ): ModelMetadata {
		return new ModelMetadata(
			$model_id,
			$model_id,
			array( CapabilityEnum::embeddingGeneration() ),
			array( new SupportedOption( OptionEnum::customOptions() ) )
		);
	}

	/**
	 * Builds metadata for an image generation model.
	 *
	 * @since 1.0.0
	 *
	 * @param string $image_id The model ID with `__image` suffix.
	 * @return \WordPress\AiClient\Providers\Models\DTO\ModelMetadata
	 */
	private function buildImageModelMetadata( string $image_id ): ModelMetadata {
		$options = array(
			new SupportedOption( OptionEnum::candidateCount() ),
			new SupportedOption( OptionEnum::outputMimeType(), array( 'image/png', 'image/jpeg', 'image/webp' ) ),
			new SupportedOption( OptionEnum::outputFileType() ),
			new SupportedOption( OptionEnum::outputMediaOrientation() ),
			new SupportedOption( OptionEnum::outputMediaAspectRatio() ),
			new SupportedOption( OptionEnum::customOptions() ),
			new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
		);

		return new ModelMetadata(
			$image_id,
			$image_id,
			array( CapabilityEnum::imageGeneration() ),
			$options
		);
	}

	/**
	 * Creates a request object for the LM Studio API.
	 *
	 * @since 1.0.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum $method  The HTTP method.
	 * @param string                                                   $path    The API endpoint path.
	 * @param array<string, string|list<string>>                       $headers The request headers.
	 * @param string|array<string, mixed>|null                         $data    The request data.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Request
	 */
	private function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request(
			$method,
			LmStudioProvider::url( $path ),
			$headers,
			$data
		);
	}
}
