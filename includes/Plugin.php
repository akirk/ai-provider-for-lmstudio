<?php

/**
 * Plugin initializer class.
 *
 * @since 1.0.0
 */

declare( strict_types=1 );

namespace AiProviderForLmStudio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AiProviderForLmStudio\Provider\LmStudioProvider;
use AiProviderForLmStudio\Settings\LmStudioSettings;
use WordPress\AiClient\AiClient;
/**
 * Plugin class.
 *
 * @since 1.0.0
 */
class Plugin {

	/**
	 * Initializes the plugin.
	 *
	 * @since 1.0.0
	 */
	/**
	 * The option name used by WordPress core to store the LM Studio connector API key.
	 *
	 * @since 1.0.0
	 */
	private const CONNECTOR_OPTION = 'connectors_ai_lmstudio_api_key';

	/**
	 * Sentinel value stored when no real API key is configured.
	 *
	 * LM Studio does not require a key for local use. This non-empty placeholder
	 * tells the connectors UI that the provider is already "connected", avoiding
	 * a broken save-button state. Requests to LM Studio always succeed regardless
	 * of the bearer token value when authentication is disabled in LM Studio.
	 *
	 * @since 1.0.0
	 */
	private const CONNECTOR_SENTINEL = 'lmstudio-local';

	public function init(): void {
		add_action( 'init', array( $this, 'register_provider' ), 5 );
		add_action( 'init', array( $this, 'initialize_settings' ) );
		add_action( 'wp_ai_provider_browser_status_scripts', array( $this, 'enqueue_browser_status_script' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AI_PROVIDER_FOR_LMSTUDIO_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );
		add_filter( 'http_request_host_is_external', array( $this, 'allow_localhost_requests' ), 10, 3 );
		add_filter( 'http_allowed_safe_ports', array( $this, 'allow_lmstudio_ports' ) );

		// Make LM Studio appear as connected on the connectors page even without an API key.
		add_filter( 'option_' . self::CONNECTOR_OPTION, array( $this, 'fill_connector_sentinel' ), 9 );
		add_filter( 'sanitize_option_' . self::CONNECTOR_OPTION, array( $this, 'fill_connector_sentinel' ), 20 );
	}

	/**
	 * Gets the LM Studio host.
	 *
	 * @since 1.0.0
	 *
	 * @return string The LM Studio host.
	 */
	private function get_lmstudio_host(): string {
		$host = getenv( 'LMSTUDIO_HOST' );
		if ( false !== $host && '' !== $host ) {
			return $host;
		}

		$settings = LmStudioSettings::get_settings();
		if ( isset( $settings['host'] ) && '' !== $settings['host'] ) {
			return $settings['host'];
		}

		return 'http://localhost:1234';
	}

	/**
	 * Sets the LMSTUDIO_HOST environment variable.
	 *
	 * @since 1.0.0
	 */
	private function set_lmstudio_host(): void {
		$host = $this->get_lmstudio_host();

		if ( '' === $host ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Required to set LMSTUDIO_HOST for the provider SDK.
		putenv( 'LMSTUDIO_HOST=' . $host );
	}

	/**
	 * Registers the LM Studio provider with the AI Client.
	 *
	 * @since 1.0.0
	 */
	public function register_provider(): void {
		if ( ! class_exists( AiClient::class ) ) {
			return;
		}

		$this->set_lmstudio_host();

		$registry = AiClient::defaultRegistry();

		if ( $registry->hasProvider( LmStudioProvider::class ) ) {
			return;
		}

		$registry->registerProvider( LmStudioProvider::class );
	}

	/**
	 * Initializes the LM Studio settings.
	 *
	 * @since 1.0.0
	 */
	public function initialize_settings(): void {
		$settings = new LmStudioSettings();
		$settings->init();
	}

	/**
	 * Enqueues the browser-side provider status checker.
	 *
	 * Consumers call the `wp_ai_provider_browser_status_scripts` action on pages
	 * where they need local/server provider status from the current browser.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_browser_status_script(): void {
		$handle = 'ai-provider-for-lmstudio-browser-status';

		if ( wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}

		$plugin_dir = AI_PROVIDER_FOR_LMSTUDIO_PLUGIN_DIR;
		$asset_file = $plugin_dir . 'build/browser-status.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(); // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Asset file path is built from a known constant.

		$dependencies = isset( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$version      = isset( $asset['version'] ) ? $asset['version'] : false;

		wp_enqueue_script(
			$handle,
			plugins_url( 'build/browser-status.js', AI_PROVIDER_FOR_LMSTUDIO_PLUGIN_FILE ),
			$dependencies,
			$version,
			true
		);

		$raw_api_key = get_option( self::CONNECTOR_OPTION, '' );
		$api_key     = preg_replace( '/[^\x00-\xFF]/', '', $raw_api_key );

		wp_localize_script(
			$handle,
			'wpAiLmStudioBrowserStatus',
			array(
				'endpoint' => rtrim( LmStudioProvider::url( '' ), '/' ),
				'apiKey'   => $api_key,
			)
		);
	}

	/**
	 * Adds action links to the plugin list table.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string> $links Existing action links.
	 * @return array<string> Modified action links.
	 */
	public function plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			admin_url( 'options-general.php?page=ai-provider-for-lmstudio' ),
			esc_html__( 'Settings', 'ai-provider-for-lmstudio' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Returns the sentinel value when the stored connector key is empty.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The option value.
	 * @return string The original value, or the sentinel when it is empty.
	 */
	public function fill_connector_sentinel( $value ): string {
		if ( '' === (string) $value ) {
			return self::CONNECTOR_SENTINEL;
		}
		return (string) $value;
	}

	/**
	 * Allows localhost requests to the LM Studio host.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $external Whether the request is external.
	 * @param string $host The host of the request.
	 * @param string $url The URL of the request.
	 * @return bool Whether the request is allowed.
	 */
	public function allow_localhost_requests( $external, $host, $url ): bool {
		if ( strpos( $url, $this->get_lmstudio_host() ) !== false ) {
			return true;
		}

		return $external;
	}

	/**
	 * Allows LM Studio ports.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int> $ports The ports.
	 * @return array<int> The allowed ports.
	 */
	public function allow_lmstudio_ports( $ports ): array {
		$lmstudio_host = $this->get_lmstudio_host();
		$lmstudio_port = wp_parse_url( $lmstudio_host, PHP_URL_PORT );

		if ( ! $lmstudio_port ) {
			return $ports;
		}

		return array_merge( $ports, array( $lmstudio_port ) );
	}
}
