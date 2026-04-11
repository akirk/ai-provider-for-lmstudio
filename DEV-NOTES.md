# Developer Notes

## WordPress AI Client — Browser-Side Provider Investigation

### Background

LM Studio runs locally on the user's machine. When WordPress is on a remote server, PHP cannot reach `localhost:1234`. This plugin's settings page works around this by fetching from LM Studio directly in the browser. But `wp.aiClient.prompt().generateText()` always routes server-side via `POST /wp-ai/v1/generate`, which means it will fail if PHP cannot reach LM Studio.

### ProviderType.CLIENT — The Right Long-Term Fix

Both the PHP and JS sides of the WordPress AI Client define a `client` provider type that is currently unused:

- PHP: `ProviderTypeEnum::CLIENT` in `wp-includes/php-ai-client/src/Providers/Enums/ProviderTypeEnum.php`
- JS: `ProviderType.CLIENT` in the `wp-ai-client` JavaScript library

This is clearly an intended extension point for browser-side provider implementations. When implemented, a `CLIENT`-type provider would handle `wp.aiClient` requests directly in the browser instead of proxying through PHP.

**LM Studio should eventually register as `ProviderTypeEnum::client()` instead of `ProviderTypeEnum::server()`.** This would allow `wp.aiClient.prompt().generateText()` to route directly to `localhost:1234` from the browser.

### Current State

There is no hook or override mechanism in the current `wp-ai-client` JS library. The only available seam is `apiFetch` middleware interception, which is functional but not a clean approach.

### What This Means for Plugin Authors

Plugins that use `wp.aiClient` for text generation will fail silently when WordPress is hosted remotely and the user's LM Studio is on their local machine. Until `ProviderType.CLIENT` is implemented upstream, plugins targeting local model use should make their AI requests from JavaScript rather than PHP.

### Model Management

Load/unload/list-all-models is LM Studio-native functionality not covered by the WordPress AI Client contract. It is handled in this plugin's settings page JS (`build/admin/settings.js`) via direct browser fetch to the LM Studio native API (`/api/v1/models`).
