<?php

declare( strict_types=1 );

namespace AiProviderForLmStudio\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AiProviderForLmStudio\Metadata\LmStudioModelMetadataDirectory;
use AiProviderForLmStudio\Provider\LmStudioProvider;
use WordPress\AiClient\AiClient;

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
	private const AJAX_ACTION            = 'ai_provider_for_lmstudio_list_models';
	private const AJAX_LOAD_ACTION       = 'ai_provider_for_lmstudio_load_model';
	private const AJAX_UNLOAD_ACTION     = 'ai_provider_for_lmstudio_unload_model';
	private const AJAX_SAVE_ORDER_ACTION = 'ai_provider_for_lmstudio_save_order';
	private const NONCE_ACTION           = 'ai_provider_for_lmstudio_nonce';

	/**
	 * Initializes the settings.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_screen' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_script' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_list_models' ) );
		add_action( 'wp_ajax_' . self::AJAX_LOAD_ACTION, array( $this, 'ajax_load_model' ) );
		add_action( 'wp_ajax_' . self::AJAX_UNLOAD_ACTION, array( $this, 'ajax_unload_model' ) );
		add_action( 'wp_ajax_' . self::AJAX_SAVE_ORDER_ACTION, array( $this, 'ajax_save_order' ) );
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
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_settings_script( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
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

		$nonce    = wp_create_nonce( self::NONCE_ACTION );
		$ajax_url = admin_url( 'admin-ajax.php' ) . '?_wpnonce=' . $nonce;

		wp_localize_script(
			'ai-provider-for-lmstudio-settings',
			'aiProviderForLmStudioSettings',
			array(
				'listModelsUrl'  => esc_url( $ajax_url . '&action=' . self::AJAX_ACTION ),
				'loadModelUrl'   => esc_url( $ajax_url . '&action=' . self::AJAX_LOAD_ACTION ),
				'unloadModelUrl' => esc_url( $ajax_url . '&action=' . self::AJAX_UNLOAD_ACTION ),
				'saveOrderUrl'   => esc_url( $ajax_url . '&action=' . self::AJAX_SAVE_ORDER_ACTION ),
			)
		);
	}

	/**
	 * Handles the AJAX request to list available LM Studio models.
	 *
	 * @since 1.0.0
	 */
	public function ajax_list_models(): void {
		check_ajax_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'ai-provider-for-lmstudio' ), 403 );
		}

		$directory = $this->get_model_directory();
		if ( is_wp_error( $directory ) ) {
			wp_send_json_error( $directory->get_error_message(), 404 );
		}

		try {
			wp_send_json_success( $directory->getAvailableModels() );
		} catch ( \Throwable $e ) {
			/* translators: %s: Error message. */
			wp_send_json_error( sprintf( __( 'Could not list models — is LM Studio running? Error: %s', 'ai-provider-for-lmstudio' ), $e->getMessage() ), 500 );
		}
	}

	/**
	 * Handles the AJAX request to load a model in LM Studio.
	 *
	 * Makes a blocking request to LM Studio and returns success once the model
	 * is fully loaded.
	 *
	 * @since 1.0.0
	 */
	public function ajax_load_model(): void {
		check_ajax_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'ai-provider-for-lmstudio' ), 403 );
		}

		$instance_id = isset( $_POST['instance_id'] ) ? sanitize_text_field( wp_unslash( $_POST['instance_id'] ) ) : '';
		if ( '' === $instance_id ) {
			wp_send_json_error( __( 'Missing instance ID.', 'ai-provider-for-lmstudio' ), 400 );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentionally extending execution time for model loading.
		@set_time_limit( 300 );

		$response = wp_remote_post(
			LmStudioProvider::url( 'api/v1/models/load' ),
			array(
				'blocking' => true,
				'timeout'  => 300,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'model' => $instance_id ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message(), 500 );
		}

		wp_send_json_success();
	}

	/**
	 * Handles the AJAX request to unload a model from LM Studio.
	 *
	 * @since 1.0.0
	 */
	public function ajax_unload_model(): void {
		check_ajax_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'ai-provider-for-lmstudio' ), 403 );
		}

		$instance_id = isset( $_POST['instance_id'] ) ? sanitize_text_field( wp_unslash( $_POST['instance_id'] ) ) : '';
		if ( '' === $instance_id ) {
			wp_send_json_error( __( 'Missing instance ID.', 'ai-provider-for-lmstudio' ), 400 );
		}

		$directory = $this->get_model_directory();
		if ( is_wp_error( $directory ) ) {
			wp_send_json_error( $directory->get_error_message(), 404 );
		}

		try {
			$directory->unloadModel( $instance_id );
			wp_send_json_success();
		} catch ( \Throwable $e ) {
			/* translators: %s: Error message. */
			wp_send_json_error( sprintf( __( 'Could not unload model. Error: %s', 'ai-provider-for-lmstudio' ), $e->getMessage() ), 500 );
		}
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
	 * Gets the settings from the WordPress option.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> The settings.
	 */
	public static function get_settings(): array {
		return (array) get_option( self::OPTION_NAME, array() );
	}

	/**
	 * Returns the model metadata directory, or a WP_Error if unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @return LmStudioModelMetadataDirectory|\WP_Error
	 */
	private function get_model_directory() {
		$provider_id = 'lmstudio';
		$registry    = AiClient::defaultRegistry();

		if ( ! $registry->hasProvider( $provider_id ) ) {
			return new \WP_Error( 'provider_not_found', __( 'AI provider not found.', 'ai-provider-for-lmstudio' ) );
		}

		$provider_classname = $registry->getProviderClassName( $provider_id );

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort
		$directory = $provider_classname::modelMetadataDirectory();

		if ( ! $directory instanceof LmStudioModelMetadataDirectory ) {
			return new \WP_Error( 'unexpected_directory', __( 'Unexpected model directory type.', 'ai-provider-for-lmstudio' ) );
		}

		return $directory;
	}
}
