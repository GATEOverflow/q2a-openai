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
    function openai_call($message, $configid = 1)
    {
        $apiKey = qa_opt('openai_api_key');
        if (empty($apiKey)) {
            return 'Error: OpenAI API key not configured.';
        }

        $url = 'https://api.openai.com/v1/chat/completions';

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

        $data = [
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user',   'content' => $user_content],
            ],
            'max_tokens'  => $max_tokens,
            'temperature' => $temperature,
        ];

        // cURL request
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

        // Return raw error if the API returned something unexpected
        if (isset($decoded['error']['message'])) {
            return 'OpenAI API error: ' . $decoded['error']['message'];
        }

        return 'OpenAI: unexpected response – ' . mb_substr($response, 0, 500);
    }
}
