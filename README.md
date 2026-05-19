# AI Provider for LM Studio

- Contributors: akirk
- Tags: ai, lmstudio, llm, local-ai, connector
- Requires at least: 7.0
- Tested up to: 7.0
- Requires PHP: 7.4
- Stable tag: 1.0.0
- License: GPL-2.0-or-later

LM Studio provider for the WordPress AI Client.

## Description

This plugin integrates [LM Studio](https://lmstudio.ai/) with the WordPress AI Client (WordPress 7.0+). It lets WordPress sites use large language models running locally in LM Studio for text generation, embeddings, and image generation.

Unlike the OpenAI-compatible `/v1/models` endpoint, this provider uses LM Studio's richer **native API** (`/api/v1/models`) to surface full model metadata — type, capabilities, and loaded state — so WordPress sees exactly what your LM Studio instance can do.

This plugin was initially based on [Fueled/ai-provider-for-ollama](https://github.com/Fueled/ai-provider-for-ollama).

**Features:**

- Text generation with any loaded LM Studio model
- Embedding generation support
- Image generation support (for models with `image_generation` capability)
- Vision (multimodal) support for models that declare the `vision` capability
- Function calling and structured output (JSON mode)
- Settings page showing all downloaded models with load/unload controls
- Drag-and-drop model priority ordering — the order controls which model WordPress AI uses first
- Only loaded models are advertised to the WordPress AI client
- Auto-appears as connected on the WordPress Connections page — no API key needed for local use
- Optional API key support for LM Studio instances with authentication enabled
- Host URL configurable via settings or the `LMSTUDIO_HOST` environment variable

**Development of this plugin is done [on GitHub](https://github.com/akirk/ai-provider-for-lmstudio). Pull requests welcome. Please see [issues](https://github.com/akirk/ai-provider-for-lmstudio/issues) reported there before going to the plugin forum.**

## Installation

1. Upload the plugin files to `/wp-content/plugins/ai-provider-for-lmstudio/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Settings > LM Studio** to configure the host URL and manage model loading.

## Frequently Asked Questions

### How do I start the LM Studio server?

Open LM Studio, go to the **Developer** tab, and click **Start Server**. The default port is `1234`.

### Why can't the settings page see my models?

Make sure CORS is enabled in LM Studio. In the **Developer** tab, enable **Allow requests from any origin** (or add your WordPress admin URL to the allowed origins). Without this, your browser will block requests from the WordPress admin to `localhost:1234`.

### Can I use this on a WordPress site that is not running on my local machine?

Yes, but plugins that use the AI client need to make their requests from JavaScript in the browser rather than from PHP on the server. Otherwise the WordPress server would need a network path to your local LM Studio instance, which is typically not available on hosted sites.

### Which model will be used?

You can change the priority order on **Settings > LM Studio** by drag and drop. Also, only loaded models will be used, so make sure to load the model you want to use, either through the LM Studio interface or the plugin settings page.

### How do I change the LM Studio host URL?

By default the plugin connects to `http://localhost:1234`. You can change this in two ways:

1. Set the `LMSTUDIO_HOST` environment variable (takes precedence).
2. Go to **Settings > LM Studio** in the WordPress admin and enter your host URL (without a trailing `/v1`).

### Do I need an API key?

No. For local LM Studio instances with authentication disabled, no API key is needed — the plugin handles this automatically and LM Studio shows as connected on the WordPress Connections page.

If you have enabled authentication in LM Studio's server settings, enter your API key on the WordPress **Connections** page (`/wp-admin/options-connectors.php`).

### Does this support embedding models?

Yes. LM Studio marks embedding models with `type: "embedding"` in its native API. The plugin registers them with the `embeddingGeneration` capability automatically.

### Does this support image generation models?

Yes, for models that LM Studio reports with `capabilities.image_generation: true`. They are registered as a separate entry with the `imageGeneration` capability.

## Changelog

### 1.0.0
- Initial release
- Text generation, embedding generation, and image generation support via LM Studio's native API
- Settings page with load/unload controls and drag-and-drop model priority ordering
- Auto-connected state on the WordPress Connections page
- Optional API key support for authenticated LM Studio instances
