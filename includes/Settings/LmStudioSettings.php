<?php

declare( strict_types=1 );

namespace AiProviderForLmStudio\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AiProviderForLmStudio\Provider\LmStudioProvider;

/**
 * Class for the LM Studio settings in the WordPress admin.
 *
 * Provides a settings page under Settings > LM Studio for configuring the
 * LM Studio host URL and managing model loading.
 *
 * @since 1.0.0
 */
class LmStudioSettings {

	private const OPTION_GROUP       = 'ai-provider-for-lmstudio-settings';
	private const OPTION_NAME        = 'ai_provider_for_lmstudio_settings';
	private const PAGE_SLUG          = 'ai-provider-for-lmstudio';
	private const SECTION_ID         = 'ai_provider_for_lmstudio_main';
	private const AJAX_SAVE_ORDER_ACTION  = 'ai_provider_for_lmstudio_save_order';
	private const AJAX_SYNC_MODELS_ACTION = 'ai_provider_for_lmstudio_sync_models';
	private const NONCE_ACTION            = 'ai_provider_for_lmstudio_nonce';

	/**
	 * Initializes the settings.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_screen' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_script' ) );
		add_action( 'wp_ajax_' . self::AJAX_SAVE_ORDER_ACTION, array( $this, 'ajax_save_order' ) );
		add_action( 'wp_ajax_' . self::AJAX_SYNC_MODELS_ACTION, array( $this, 'ajax_sync_models' ) );
	}

	/**
	 * Registers the setting and settings fields.
	 *
	 * @since 1.0.0
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		add_settings_section(
			self::SECTION_ID,
			'',
			'__return_empty_string',
			self::PAGE_SLUG
		);

		add_settings_field(
			self::OPTION_NAME . '_host',
			__( 'Host URL', 'ai-provider-for-lmstudio' ),
			array( $this, 'render_host_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID,
			array( 'label_for' => self::OPTION_NAME . '-host' )
		);

		add_settings_field(
			self::OPTION_NAME . '_models',
			__( 'Models', 'ai-provider-for-lmstudio' ),
			array( $this, 'render_models_table_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID
		);
	}

	/**
	 * Registers the settings screen.
	 *
	 * @since 1.0.0
	 */
	public function register_settings_screen(): void {
		add_options_page(
			__( 'LM Studio Settings', 'ai-provider-for-lmstudio' ),
			__( 'LM Studio', 'ai-provider-for-lmstudio' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_screen' )
		);
	}

	/**
	 * Sanitizes the settings array.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The input value.
	 * @return array<string, mixed> The sanitized settings.
	 */
	public function sanitize_settings( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$host = isset( $value['host'] ) ? trim( (string) $value['host'] ) : '';
		if ( '' !== $host ) {
			$host = rtrim( esc_url_raw( $host ), '/' );
		}

		return array(
			'host' => $host,
		);
	}

	/**
	 * Renders the settings screen.
	 *
	 * @since 1.0.0
	 */
	public function render_screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>

		<div class="wrap" style="max-width: 50rem;">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: 1: code tag, 2: closing code tag */
					esc_html__( 'Configure the connection to your LM Studio instance. Make sure the LM Studio local server is running before use.', 'ai-provider-for-lmstudio' ),
					'<code>',
					'</code>'
				);
				?>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: code tag, 2: closing code tag */
					esc_html__( 'Leave the host URL empty to use the default (%1$shttp://localhost:1234%2$s). You can also set the %1$sLMSTUDIO_HOST%2$s environment variable to override this setting.', 'ai-provider-for-lmstudio' ),
					'<code>',
					'</code>'
				);
				?>
			</p>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>

		<?php
	}

	/**
	 * Renders the host URL field.
	 *
	 * @since 1.0.0
	 */
	public function render_host_field(): void {
		$settings = self::get_settings();
		$value    = isset( $settings['host'] ) ? $settings['host'] : '';
		?>

		<input
			type="url"
			id="<?php echo esc_attr( self::OPTION_NAME . '-host' ); ?>"
			name="<?php echo esc_attr( self::OPTION_NAME . '[host]' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="http://localhost:1234"
		/>
		<p class="description">
			<?php
			printf(
				/* translators: 1: code tag, 2: closing code tag */
				esc_html__( 'The base URL of your LM Studio server (without /v1). Example: %1$shttp://localhost:1234%2$s', 'ai-provider-for-lmstudio' ),
				'<code>',
				'</code>'
			);
			?>
		</p>

		<?php
	}

	/**
	 * Renders the models table container.
	 *
	 * The table is populated by JavaScript via AJAX. It shows all downloaded models
	 * with their load status and allows loading, unloading, and toggling image
	 * generation support per model.
	 *
	 * @since 1.0.0
	 */
	public function render_models_table_field(): void {
		?>
		<div id="lmstudio-models-container">
			<p class="description"><?php echo esc_html__( 'Loading…', 'ai-provider-for-lmstudio' ); ?></p>
		</div>
		<p class="description">
			<?php echo esc_html__( 'Only loaded models are made available to the WordPress AI client. Use the Load / Unload buttons to control which models are active.', 'ai-provider-for-lmstudio' ); ?>
		</p>
		<?php
	}

	/**
	 * Enqueues the settings page script.
	 *
	 * On the plugin's own settings page the full UI script is loaded. On all
	 * other admin pages a lightweight inline script silently syncs the LM Studio
	 * model list from the browser so that the PHP-side model directory stays
	 * up-to-date even when the server cannot reach LM Studio directly.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_settings_script( string $hook_suffix ): void {
		// On non-settings admin pages, enqueue a lightweight background sync.
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			$this->enqueue_background_sync_script();
			return;
		}

		$plugin_dir = AI_PROVIDER_FOR_LMSTUDIO_PLUGIN_DIR;
		$asset_file = $plugin_dir . 'build/admin/settings.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(); // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Asset file path is built from a known constant.

		$dependencies = isset( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$version      = isset( $asset['version'] ) ? $asset['version'] : false;

		wp_enqueue_script(
			'ai-provider-for-lmstudio-settings',
			plugins_url( 'build/admin/settings.js', $plugin_dir . 'plugin.php' ),
			$dependencies,
			$version,
			true
		);

		$settings    = self::get_settings();
		$model_order = isset( $settings['model_order'] ) ? $settings['model_order'] : array();
		$api_key     = get_option( 'connectors_ai_lmstudio_api_key', '' );

		$nonce    = wp_create_nonce( self::NONCE_ACTION );
		$ajax_url = admin_url( 'admin-ajax.php' ) . '?_wpnonce=' . $nonce;

		wp_localize_script(
			'ai-provider-for-lmstudio-settings',
			'aiProviderForLmStudioSettings',
			array(
				'lmstudioHost' => rtrim( LmStudioProvider::url( '' ), '/' ),
				'apiKey'       => $api_key,
				'modelOrder'   => $model_order,
				'saveOrderUrl'  => $ajax_url . '&action=' . self::AJAX_SAVE_ORDER_ACTION,
				'syncModelsUrl' => $ajax_url . '&action=' . self::AJAX_SYNC_MODELS_ACTION,
			)
		);
	}

	/**
	 * Enqueues a lightweight inline script that syncs the LM Studio model list
	 * from the browser on any admin page.
	 *
	 * @since 1.0.0
	 */
	private function enqueue_background_sync_script(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$nonce    = wp_create_nonce( self::NONCE_ACTION );
		$ajax_url = admin_url( 'admin-ajax.php' ) . '?_wpnonce=' . $nonce;
		$host     = rtrim( LmStudioProvider::url( '' ), '/' );
		$sync_url = $ajax_url . '&action=' . self::AJAX_SYNC_MODELS_ACTION;

		wp_add_inline_script( 'common', sprintf(
			'(function(){' .
				'fetch(%s+"/api/v1/models").then(function(r){return r.json()}).then(function(d){' .
					'if(!d.models)return;' .
					'var b=new URLSearchParams({models:JSON.stringify(d.models)});' .
					'fetch(%s,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:b})' .
				'}).catch(function(){})' .
			'})();',
			wp_json_encode( $host ),
			wp_json_encode( $sync_url )
		) );
	}

	/**
	 * Handles the AJAX request to save the user-defined model priority order.
	 *
	 * @since 1.0.0
	 */
	public function ajax_save_order(): void {
		check_ajax_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'ai-provider-for-lmstudio' ), 403 );
		}

		$raw   = isset( $_POST['order'] ) ? wp_unslash( $_POST['order'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below after JSON decode
		$order = json_decode( $raw, true );

		if ( ! is_array( $order ) ) {
			wp_send_json_error( __( 'Invalid order data.', 'ai-provider-for-lmstudio' ), 400 );
		}

		$order    = array_values( array_map( 'sanitize_text_field', $order ) );
		$settings = self::get_settings();

		$settings['model_order'] = $order;
		update_option( self::OPTION_NAME, $settings );

		wp_send_json_success();
	}

	/**
	 * Handles the AJAX request to sync the model list from the browser.
	 *
	 * The browser can reach LM Studio on localhost even when the PHP server
	 * cannot. This endpoint receives the model list fetched by JavaScript and
	 * stores it so the PHP model metadata directory can use it.
	 *
	 * @since 1.0.0
	 */
	public function ajax_sync_models(): void {
		check_ajax_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'ai-provider-for-lmstudio' ), 403 );
		}

		$raw    = isset( $_POST['models'] ) ? wp_unslash( $_POST['models'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded and sanitized below.
		$models = json_decode( $raw, true );

		if ( ! is_array( $models ) ) {
			wp_send_json_error( __( 'Invalid model data.', 'ai-provider-for-lmstudio' ), 400 );
		}

		// Sanitize each model entry to only keep known keys.
		$clean = array();
		foreach ( $models as $model ) {
			if ( ! is_array( $model ) || empty( $model['key'] ) ) {
				continue;
			}
			$clean[] = array(
				'key'              => sanitize_text_field( $model['key'] ),
				'type'             => isset( $model['type'] ) ? sanitize_text_field( $model['type'] ) : 'llm',
				'display_name'     => isset( $model['display_name'] ) ? sanitize_text_field( $model['display_name'] ) : $model['key'],
				'publisher'        => isset( $model['publisher'] ) ? sanitize_text_field( $model['publisher'] ) : '',
				'capabilities'     => isset( $model['capabilities'] ) && is_array( $model['capabilities'] ) ? $model['capabilities'] : array(),
				'loaded_instances' => isset( $model['loaded_instances'] ) && is_array( $model['loaded_instances'] ) ? $model['loaded_instances'] : array(),
			);
		}

		update_option( \AiProviderForLmStudio\Metadata\LmStudioModelMetadataDirectory::MODELS_CACHE_OPTION, $clean, false );

		wp_send_json_success( array( 'count' => count( $clean ) ) );
	}

	/**
	 * Gets the settings from the WordPress option.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> The settings.
	 */
	public static function get_settings(): array {
		return (array) get_option( self::OPTION_NAME, array() );
	}

}
