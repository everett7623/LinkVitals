<?php
/**
 * AI Assistant class
 *
 * Integrates OpenAI (GPT) and Anthropic (Claude) APIs to provide
 * intelligent suggestions for broken links, redirects, and SEO issues.
 *
 * Features:
 * - Analyze broken links and suggest replacement URLs
 * - Batch analyze multiple broken links at once
 * - Suggest anchor text improvements
 * - Explain why a link might be broken
 *
 * API keys are stored encrypted in WordPress options.
 * All requests go through server-side PHP - no keys exposed to browser.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_AI {

    /** Available AI providers */
    const PROVIDER_OPENAI    = 'openai';
    const PROVIDER_CLAUDE    = 'claude';

    /** OpenAI API endpoint */
    const OPENAI_API_URL     = 'https://api.openai.com/v1/chat/completions';

    /** Anthropic API endpoint */
    const CLAUDE_API_URL     = 'https://api.anthropic.com/v1/messages';

    /** Anthropic API version header */
    const CLAUDE_API_VERSION = '2023-06-01';

    /** Default models */
    const OPENAI_DEFAULT_MODEL = 'gpt-4o-mini';
    const CLAUDE_DEFAULT_MODEL = 'claude-3-5-haiku-20241022';

    /**
     * Get the active AI provider from settings
     */
    public static function get_provider(): string {
        $settings = get_option( 'lha_settings', array() );
        return $settings['ai_provider'] ?? '';
    }

    /**
     * Check if AI features are configured and available
     */
    public static function is_available(): bool {
        $provider = self::get_provider();
        if ( empty( $provider ) ) {
            return false;
        }
        $key = self::get_api_key( $provider );
        return ! empty( $key );
    }

    /**
     * Retrieve and decrypt API key for a provider
     * Supports a temporary override filter for connection testing
     */
    public static function get_api_key( string $provider ): string {
        // Allow temporary override (used during connection test before saving)
        $override = apply_filters( 'lha_api_key_override', '' );
        if ( ! empty( $override ) ) {
            return $override;
        }

        $settings  = get_option( 'lha_settings', array() );
        $encrypted = $settings[ 'ai_key_' . $provider ] ?? '';
        if ( empty( $encrypted ) ) {
            return '';
        }
        return self::decrypt( $encrypted );
    }

    /**
     * Encrypt an API key before storing
     */
    public static function encrypt( string $value ): string {
        if ( empty( $value ) ) {
            return '';
        }
        // Use WordPress AUTH_KEY as encryption salt
        $salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'lha_fallback_salt_32chars_here!';
        $key  = hash( 'sha256', $salt, true );
        $iv   = random_bytes( 16 );
        $enc  = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $enc );
    }

    /**
     * Decrypt a stored API key
     */
    public static function decrypt( string $encrypted ): string {
        if ( empty( $encrypted ) ) {
            return '';
        }
        try {
            $salt    = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'lha_fallback_salt_32chars_here!';
            $key     = hash( 'sha256', $salt, true );
            $decoded = base64_decode( $encrypted, true );
            if ( $decoded === false || strlen( $decoded ) < 17 ) {
                return '';
            }
            $iv  = substr( $decoded, 0, 16 );
            $enc = substr( $decoded, 16 );
            $dec = openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
            return $dec !== false ? $dec : '';
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    /**
     * Analyze a broken link and get AI suggestions
     *
     * @param array $link  Link record from DB
     * @param array $occurrences  Occurrence records
     * @return array{success: bool, suggestion: string, explanation: string, error?: string}
     */
    public function analyze_link( array $link, array $occurrences = array() ): array {
        if ( ! self::is_available() ) {
            return array( 'success' => false, 'error' => __( 'AI not configured. Please set an API key in Settings.', 'linkvitals' ) );
        }

        $context = $this->build_link_context( $link, $occurrences );
        $prompt  = $this->build_analysis_prompt( $context );

        return $this->call_api( $prompt );
    }

    /**
     * Batch analyze broken links (returns suggestions for multiple links)
     *
     * @param array $links Array of link records
     * @return array Results keyed by link ID
     */
    public function analyze_batch( array $links ): array {
        if ( ! self::is_available() ) {
            return array();
        }

        $results = array();

        // Process in small batches to stay within token limits
        $chunks = array_chunk( $links, 5 );

        foreach ( $chunks as $chunk ) {
            $context = $this->build_batch_context( $chunk );
            $prompt  = $this->build_batch_prompt( $context );
            $result  = $this->call_api( $prompt );

            if ( $result['success'] ) {
                // Parse JSON response from AI
                $parsed = json_decode( $result['raw'] ?? '', true );
                if ( is_array( $parsed ) ) {
                    foreach ( $parsed as $link_id => $suggestion ) {
                        $results[ (int) $link_id ] = $suggestion;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Build context string for a single link
     */
    private function build_link_context( array $link, array $occurrences ): string {
        $site_url = home_url();
        $context  = "Site: {$site_url}\n";
        $context .= "Broken URL: {$link['url']}\n";
        $context .= "Status: HTTP {$link['http_code']} ({$link['status']})\n";
        $context .= "Link type: {$link['link_type']}\n";

        if ( ! empty( $link['final_url'] ) ) {
            $context .= "Redirects to: {$link['final_url']}\n";
        }

        if ( ! empty( $occurrences ) ) {
            $occ = $occurrences[0];
            $context .= "Found in: {$occ['source_title']}\n";
            $context .= "Anchor text: {$occ['anchor_text']}\n";
        }

        return $context;
    }

    /**
     * Build context for batch analysis
     */
    private function build_batch_context( array $links ): string {
        $context = "Site: " . home_url() . "\n\nBroken links to analyze:\n";
        foreach ( $links as $link ) {
            $occurrences = LHA_DB::get_occurrences( (int) $link['id'] );
            $anchor      = ! empty( $occurrences ) ? $occurrences[0]['anchor_text'] : '';
            $context .= "ID {$link['id']}: {$link['url']} (HTTP {$link['http_code']}, anchor: \"{$anchor}\")\n";
        }
        return $context;
    }

    /**
     * Build prompt for single link analysis
     */
    private function build_analysis_prompt( string $context ): string {
        $lang = $this->get_response_language();
        return <<<PROMPT
You are a WordPress SEO and link health expert. Analyze this broken link and provide actionable suggestions.

{$context}

Please respond in {$lang} with:
1. **Explanation**: Why this link is likely broken (1-2 sentences)
2. **Suggestion**: What action to take (replace with a specific URL, unlink, or update anchor text)
3. **New URL** (if applicable): Suggest a replacement URL or leave blank

Keep your response concise and practical. Format as plain text, no markdown headers.
PROMPT;
    }

    /**
     * Build prompt for batch analysis
     */
    private function build_batch_prompt( string $context ): string {
        $lang = $this->get_response_language();
        return <<<PROMPT
You are a WordPress SEO expert. Analyze these broken links and suggest fixes.

{$context}

Respond ONLY with valid JSON (no markdown, no explanation outside JSON):
{
  "LINK_ID": {
    "explanation": "brief reason why broken",
    "action": "replace|unlink|ignore",
    "new_url": "https://... or empty string"
  }
}

Respond in {$lang}.
PROMPT;
    }

    /**
     * Determine response language based on WordPress locale
     */
    private function get_response_language(): string {
        $locale = get_locale();
        if ( str_starts_with( $locale, 'zh' ) ) {
            return 'Chinese (Simplified)';
        }
        if ( str_starts_with( $locale, 'ja' ) ) {
            return 'Japanese';
        }
        if ( str_starts_with( $locale, 'de' ) ) {
            return 'German';
        }
        if ( str_starts_with( $locale, 'fr' ) ) {
            return 'French';
        }
        return 'English';
    }

    /**
     * Call the configured AI API
     */
    private function call_api( string $prompt ): array {
        $provider = self::get_provider();
        $settings = get_option( 'lha_settings', array() );
        $model    = $settings[ 'ai_model_' . $provider ] ?? '';

        switch ( $provider ) {
            case self::PROVIDER_OPENAI:
                return $this->call_openai( $prompt, $model );
            case self::PROVIDER_CLAUDE:
                return $this->call_claude( $prompt, $model );
            default:
                return array( 'success' => false, 'error' => __( 'Unknown AI provider.', 'linkvitals' ) );
        }
    }

    /**
     * Call OpenAI Chat Completions API
     */
    private function call_openai( string $prompt, string $model = '' ): array {
        $api_key = self::get_api_key( self::PROVIDER_OPENAI );
        if ( empty( $api_key ) ) {
            return array( 'success' => false, 'error' => __( 'OpenAI API key not configured.', 'linkvitals' ) );
        }

        $model = $model ?: self::OPENAI_DEFAULT_MODEL;

        $body = wp_json_encode( array(
            'model'      => $model,
            'messages'   => array(
                array( 'role' => 'user', 'content' => $prompt ),
            ),
            'max_tokens' => 500,
            'temperature' => 0.3,
        ) );

        $response = wp_remote_post( self::OPENAI_API_URL, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => $body,
        ) );

        return $this->parse_openai_response( $response );
    }

    /**
     * Call Anthropic Claude Messages API
     */
    private function call_claude( string $prompt, string $model = '' ): array {
        $api_key = self::get_api_key( self::PROVIDER_CLAUDE );
        if ( empty( $api_key ) ) {
            return array( 'success' => false, 'error' => __( 'Claude API key not configured.', 'linkvitals' ) );
        }

        $model = $model ?: self::CLAUDE_DEFAULT_MODEL;

        $body = wp_json_encode( array(
            'model'      => $model,
            'max_tokens' => 500,
            'messages'   => array(
                array( 'role' => 'user', 'content' => $prompt ),
            ),
        ) );

        $response = wp_remote_post( self::CLAUDE_API_URL, array(
            'timeout' => 30,
            'headers' => array(
                'x-api-key'         => $api_key,
                'anthropic-version' => self::CLAUDE_API_VERSION,
                'Content-Type'      => 'application/json',
            ),
            'body' => $body,
        ) );

        return $this->parse_claude_response( $response );
    }

    /**
     * Parse OpenAI API response
     */
    private function parse_openai_response( $response ): array {
        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'error' => $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code !== 200 ) {
            $msg = $data['error']['message'] ?? "HTTP {$code}";
            return array( 'success' => false, 'error' => $msg );
        }

        $text = $data['choices'][0]['message']['content'] ?? '';

        return array(
            'success'    => true,
            'suggestion' => trim( $text ),
            'raw'        => $text,
            'model'      => $data['model'] ?? '',
            'tokens'     => $data['usage']['total_tokens'] ?? 0,
        );
    }

    /**
     * Parse Anthropic Claude API response
     */
    private function parse_claude_response( $response ): array {
        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'error' => $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code !== 200 ) {
            $msg = $data['error']['message'] ?? "HTTP {$code}";
            return array( 'success' => false, 'error' => $msg );
        }

        $text = $data['content'][0]['text'] ?? '';

        return array(
            'success'    => true,
            'suggestion' => trim( $text ),
            'raw'        => $text,
            'model'      => $data['model'] ?? '',
            'tokens'     => ( $data['usage']['input_tokens'] ?? 0 ) + ( $data['usage']['output_tokens'] ?? 0 ),
        );
    }

    /**
     * Test API connectivity with a simple request
     *
     * @param string $provider 'openai' or 'claude'
     * @return array{success: bool, message: string}
     */
    public function test_connection( string $provider ): array {
        $prompt = 'Reply with exactly: "Connection OK"';

        switch ( $provider ) {
            case self::PROVIDER_OPENAI:
                $result = $this->call_openai( $prompt );
                break;
            case self::PROVIDER_CLAUDE:
                $result = $this->call_claude( $prompt );
                break;
            default:
                return array( 'success' => false, 'message' => __( 'Unknown provider.', 'linkvitals' ) );
        }

        if ( $result['success'] ) {
            return array(
                'success' => true,
                'message' => sprintf(
                    /* translators: %s: AI model name, %d: token count */
                    __( 'Connected! Model: %1$s, Tokens used: %2$d', 'linkvitals' ),
                    $result['model'] ?? $provider,
                    $result['tokens'] ?? 0
                ),
            );
        }

        return array( 'success' => false, 'message' => $result['error'] ?? __( 'Connection failed.', 'linkvitals' ) );
    }
}
