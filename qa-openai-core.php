<?php
/**
 * Core OpenAI API call function.
 *
 * Replaces the old openai_call() that lived in publish-to-email/post.php.
 * Configs are now loaded from the ^openai_configs DB table.
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

if (!function_exists('openai_call')) {

    /**
     * Call the OpenAI Chat Completions API.
     *
     * @param  string $message   The user content (replaces {{ MESSAGE }} in the prompt template)
     * @param  int    $configid  The config row id from ^openai_configs
     * @return string            The assistant's reply text, or an error string
     */
    function openai_call($message, $configid = 1, $image_urls = array())
    {
        // Load config from DB
        $config = qa_db_read_one_assoc(
            qa_db_query_sub(
                'SELECT * FROM ^openai_configs WHERE id = #',
                (int) $configid
            ),
            true // return null on no rows
        );

        if (empty($config)) {
            return "Error: OpenAI config #$configid not found.";
        }

        // Build the request payload
        $model       = !empty($config['model']) ? $config['model'] : 'gpt-4o';
        $max_tokens  = (int) ($config['max_tokens'] ?: 2000);
        $temperature = (float) ($config['temperature'] ?: 0.7);

        $system_prompt = $config['system_prompt'];
        $user_template = $config['user_prompt'];

        // Replace {{ MESSAGE }} placeholder
        $user_content = str_replace('{{ MESSAGE }}', $message, $user_template);

        // Route to the appropriate provider based on model name
        if (stripos($model, 'gemini') === 0) {
            return _openai_call_gemini($model, $system_prompt, $user_content, $max_tokens, $temperature, $image_urls);
        }

        return _openai_call_openai($model, $system_prompt, $user_content, $max_tokens, $temperature, $image_urls);
    }

    /**
     * Call OpenAI Chat Completions API.
     */
    function _openai_call_openai($model, $system_prompt, $user_content, $max_tokens, $temperature, $image_urls = array())
    {
        $apiKey = qa_opt('openai_api_key');
        if (empty($apiKey)) {
            return 'Error: OpenAI API key not configured.';
        }

        $url = 'https://api.openai.com/v1/chat/completions';

        // Build user message content — multimodal if images present
        if (!empty($image_urls)) {
            $content_parts = [['type' => 'text', 'text' => $user_content]];
            foreach ($image_urls as $img_url) {
                $content_parts[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => $img_url],
                ];
            }
            $user_message = ['role' => 'user', 'content' => $content_parts];
        } else {
            $user_message = ['role' => 'user', 'content' => $user_content];
        }

        $data = [
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $system_prompt],
                $user_message,
            ],
            'max_tokens'  => $max_tokens,
            'temperature' => $temperature,
        ];

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($curl);

        if ($response === false) {
            $error = curl_error($curl);
            curl_close($curl);
            return "cURL error: $error";
        }

        curl_close($curl);

        $decoded = json_decode($response, true);

        if (isset($decoded['choices'][0]['message']['content'])) {
            return $decoded['choices'][0]['message']['content'];
        }

        if (isset($decoded['error']['message'])) {
            return 'OpenAI API error: ' . $decoded['error']['message'];
        }

        return 'OpenAI: unexpected response – ' . mb_substr($response, 0, 500);
    }

    /**
     * Call Google Gemini generateContent API.
     */
    function _openai_call_gemini($model, $system_prompt, $user_content, $max_tokens, $temperature, $image_urls = array())
    {
        $apiKey = qa_opt('openai_gemini_api_key');
        if (empty($apiKey)) {
            return 'Error: Gemini API key not configured. Set it in Admin > Plugins > OpenAI Integration.';
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . urlencode($apiKey);

        // Build user parts — text + images
        $user_parts = [['text' => $user_content]];

        if (!empty($image_urls)) {
            foreach ($image_urls as $img_url) {
                $image_data = _openai_fetch_image_base64($img_url);
                if ($image_data) {
                    $user_parts[] = [
                        'inlineData' => [
                            'mimeType' => $image_data['mime'],
                            'data'     => $image_data['base64'],
                        ],
                    ];
                }
            }
        }

        $data = [
            'systemInstruction' => [
                'parts' => [['text' => $system_prompt]],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => $user_parts,
                ],
            ],
            'generationConfig' => [
                'temperature'    => $temperature,
                'maxOutputTokens' => $max_tokens,
            ],
        ];

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($curl);

        if ($response === false) {
            $error = curl_error($curl);
            curl_close($curl);
            return "cURL error: $error";
        }

        curl_close($curl);

        $decoded = json_decode($response, true);

        if (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
            return $decoded['candidates'][0]['content']['parts'][0]['text'];
        }

        if (isset($decoded['error']['message'])) {
            return 'Gemini API error: ' . $decoded['error']['message'];
        }

        return 'Gemini: unexpected response – ' . mb_substr($response, 0, 500);
    }

    /**
     * Fetch an image from a URL and return base64-encoded data with MIME type.
     * Returns array with 'mime' and 'base64' keys, or null on failure.
     */
    function _openai_fetch_image_base64($url)
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 15);
        curl_setopt($curl, CURLOPT_MAXREDIRS, 3);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0');

        $data = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $content_type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);

        if ($data === false || $http_code !== 200 || empty($data)) {
            return null;
        }

        // Determine MIME type
        $mime = 'image/png';
        if ($content_type && strpos($content_type, 'image/') === 0) {
            $mime = explode(';', $content_type)[0];
        } else {
            // Detect from data
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->buffer($data);
            if ($detected && strpos($detected, 'image/') === 0) {
                $mime = $detected;
            }
        }

        // Limit size to 10MB
        if (strlen($data) > 10 * 1024 * 1024) {
            return null;
        }

        return [
            'mime'   => $mime,
            'base64' => base64_encode($data),
        ];
    }
}
