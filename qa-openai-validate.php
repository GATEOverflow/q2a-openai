<?php
/**
 * LLM Answer Validation Page for OpenAI Integration plugin.
 *
 * Given a tag, lists questions with answer keys and allows LLM-based
 * answer generation + cross-validation using two models.
 *
 * URL: /admin/openai-validate
 * Requires: qa_ec_answers table
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

class qa_openai_validate_page
{
    private $page_size = 100;

    public function suggest_requests()
    {
        return [
            [
                'title'   => 'LLM Answer Validation',
                'request' => 'admin/openai-validate',
                'nav'     => null,
            ],
        ];
    }

    public function match_request($request)
    {
        return $request === 'admin/openai-validate';
    }

    public function process_request($request)
    {
        if (qa_get_logged_in_level() < QA_USER_LEVEL_ADMIN) {
            return include QA_INCLUDE_DIR . 'qa-page-not-found.php';
        }

        // Check if qa_ec_answers table exists
        if (!$this->table_exists('ec_answers')) {
            $qa_content = qa_content_prepare();
            $qa_content['title'] = 'LLM Answer Validation';
            $qa_content['custom'] = '<p style="color:#c62828;">The <code>qa_ec_answers</code> table does not exist. This feature requires answer keys to be available.</p>';
            return $qa_content;
        }

        // Handle AJAX requests
        if (qa_post_text('ajax_action')) {
            return $this->handle_ajax();
        }

        $qa_content = qa_content_prepare();
        $qa_content['title'] = 'LLM Answer Validation';

        $tag = trim(qa_get('tag') ?? '');
        $start = max(0, (int) qa_get('start'));

        // Get available models from configs
        $models = $this->get_available_models();

        // Build the page
        $html = $this->render_tag_form($tag);

        if (!empty($tag)) {
            $questions = $this->get_questions_for_tag($tag, $start, $this->page_size);
            $total = $this->count_questions_for_tag($tag);

            if (empty($questions)) {
                $html .= '<p style="margin-top:15px; color:#888;">No questions with answer keys found for tag: <strong>' . qa_html($tag) . '</strong></p>';
            } else {
                $html .= $this->render_model_selectors($models);
                $html .= $this->render_controls($total);
                $html .= '<div id="oai-summary" style="display:none; margin:15px 0; padding:15px; background:#e8f5e9; border-radius:8px;"></div>';
                $html .= $this->render_table($questions);
                $html .= $this->render_pagination($tag, $start, $total);
            }
        }

        $html .= $this->get_mathjax_script();
        $html .= $this->get_js($tag);

        $qa_content['custom'] = $html;
        return $qa_content;
    }

    // ─── AJAX handler ───────────────────────────────────────────────────

    private function handle_ajax()
    {
        header('Content-Type: application/json; charset=utf-8');

        if (qa_get_logged_in_level() < QA_USER_LEVEL_ADMIN) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            qa_exit();
        }

        $action = qa_post_text('ajax_action');
        $postid = (int) qa_post_text('postid');
        $model = trim(qa_post_text('model'));
        $question_title = qa_post_text('question_title');
        $question_content = qa_post_text('question_content');
        $question_type = qa_post_text('question_type');
        $answer_key = qa_post_text('answer_key');

        if ($action === 'tag_suggest') {
            $term = trim(qa_post_text('term'));
            $results = $this->suggest_tags($term);
            echo json_encode(['success' => true, 'tags' => $results]);
        } elseif ($action === 'user_suggest') {
            $term = trim(qa_post_text('term'));
            $results = $this->suggest_users($term);
            echo json_encode(['success' => true, 'users' => $results]);
        } elseif ($action === 'fetch_style') {
            $handle = trim(qa_post_text('handle'));
            $result = $this->fetch_user_style($handle);
            echo json_encode($result);
        } elseif ($action === 'generate') {
            $style_examples = qa_post_text('style_examples') ?? '';
            $result = $this->llm_generate_answer($model, $question_title, $question_content, $question_type, $style_examples, $postid);
            echo json_encode($result);
        } elseif ($action === 'validate') {
            $llm_answer = qa_post_text('llm_answer');
            $llm_key = qa_post_text('llm_key');
            $result = $this->llm_validate($model, $question_title, $question_content, $question_type, $answer_key, $llm_answer, $llm_key, $postid);
            echo json_encode($result);
        } elseif ($action === 'add_answer') {
            $result = $this->add_answer_to_qa($postid, qa_post_text('answer_text'), qa_post_text('agent_key'), qa_post_text('handle'));
            echo json_encode($result);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }

        qa_exit();
    }

    // ─── Image helpers ────────────────────────────────────────────────

    private function extract_images_from_html($html)
    {
        $images = [];
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
                $images[] = $url;
            }
        }
        return $images;
    }

    private function download_image_base64($url)
    {
        // Make relative URLs absolute
        if (strpos($url, '//') === false) {
            $url = qa_opt('site_url') . ltrim($url, '/');
        }
        // Fix protocol-relative URLs
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $data = curl_exec($ch);
        if ($data === false) {
            curl_close($ch);
            return null;
        }
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200 || empty($data)) {
            return null;
        }

        // Determine MIME type
        $mime = 'image/png';
        if ($content_type && strpos($content_type, 'image/') === 0) {
            $mime = explode(';', $content_type)[0];
        } elseif (preg_match('/\.(jpe?g)$/i', $url)) {
            $mime = 'image/jpeg';
        } elseif (preg_match('/\.(gif)$/i', $url)) {
            $mime = 'image/gif';
        } elseif (preg_match('/\.(webp)$/i', $url)) {
            $mime = 'image/webp';
        }

        return [
            'base64' => base64_encode($data),
            'mime'   => $mime,
            'url'    => $url,
        ];
    }

    // ─── LLM calls ─────────────────────────────────────────────────────

    private function llm_generate_answer($model, $title, $content, $qtype, $style_examples = '', $postid = 0)
    {
        // Fetch raw HTML from DB for image extraction
        $raw_html = '';
        if ($postid > 0) {
            $raw_html = (string) qa_db_read_one_value(qa_db_query_sub(
                'SELECT content FROM ^posts WHERE postid=#', $postid
            ), true);
        }

        $content_text = strip_tags($raw_html ?: $content);
        $image_urls = $this->extract_images_from_html($raw_html);
        $images = [];
        foreach ($image_urls as $url) {
            $img = $this->download_image_base64($url);
            if ($img) $images[] = $img;
        }

        $type_instruction = 'This is a multiple choice question (MCQ) with options A, B, C, D. Select exactly one correct option.';
        if ($qtype === 'MSQ') {
            $type_instruction = 'This is a multiple select question (MSQ). One or more options may be correct. List all correct options separated by semicolons (e.g. A;C).';
        } elseif ($qtype === 'Numerical') {
            $type_instruction = 'This is a numerical answer type question. Provide the exact numerical answer.';
        }

        $style_section = '';
        if (!empty($style_examples)) {
            $style_section = "\n\n--- STYLE REFERENCE ---\n"
                . "Below are example answers written by a specific user. Mimic their writing style, tone, level of detail, formatting, and explanation approach when writing your answer.\n\n"
                . $style_examples
                . "\n--- END STYLE REFERENCE ---\n";
        }

        $image_note = !empty($images) ? "\nIMPORTANT: This question includes " . count($images) . " image(s) attached below. Carefully examine all images as they contain essential information (diagrams, circuits, graphs, tables, etc.) needed to answer the question.\n" : '';

        $prompt = "You are an expert in Computer Science, answering exam questions.\n\n"
            . $type_instruction . "\n"
            . $style_section
            . $image_note . "\n"
            . "Question Title: " . $title . "\n"
            . "Question:\n" . $content_text . "\n\n"
            . "Instructions:\n"
            . "1. First provide a clear, step-by-step explanation/solution.\n"
            . "2. Then on a NEW LINE at the very end, output EXACTLY: ANSWER_KEY: <your answer>\n"
            . "   For MCQ: ANSWER_KEY: A (or B, C, D - single uppercase letter)\n"
            . "   For MSQ: ANSWER_KEY: A;C (uppercase letters separated by semicolons, sorted)\n"
            . "   For Numerical: ANSWER_KEY: 42 (the number)\n"
            . "3. The ANSWER_KEY line must be the LAST line of your response.";

        $result = $this->call_llm_direct($model, $prompt, $images);

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        $response_text = $result['content'];

        // Extract answer key from response
        $extracted_key = $this->extract_answer_key($response_text);

        return [
            'success' => true,
            'answer' => $response_text,
            'extracted_key' => $extracted_key,
        ];
    }

    private function llm_validate($model, $title, $content, $qtype, $correct_key, $llm_answer, $llm_key, $postid = 0)
    {
        // Fetch raw HTML from DB for image extraction
        $raw_html = '';
        if ($postid > 0) {
            $raw_html = (string) qa_db_read_one_value(qa_db_query_sub(
                'SELECT content FROM ^posts WHERE postid=#', $postid
            ), true);
        }

        $content_text = strip_tags($raw_html ?: $content);
        $image_urls = $this->extract_images_from_html($raw_html);
        $images = [];
        foreach ($image_urls as $url) {
            $img = $this->download_image_base64($url);
            if ($img) $images[] = $img;
        }

        $prompt = "You are an expert examiner validating an AI-generated answer to an exam question.\n\n"
            . "Question Title: " . $title . "\n"
            . "Question Type: " . $qtype . "\n"
            . "Question:\n" . $content_text . "\n\n"
            . "AI-Generated Answer Key: " . $llm_key . "\n"
            . "AI-Generated Explanation:\n" . $llm_answer . "\n\n"
            . "Please validate:\n"
            . "1. Solve the question yourself independently.\n"
            . "2. Is the AI's answer key correct based on your own solution?\n"
            . "3. Is the AI's explanation/reasoning correct?\n"
            . "4. Also output your own answer key.\n\n"
            . "On the LAST line, output EXACTLY in this format:\n"
            . "VALIDATION: CORRECT | ANSWER_KEY: <your answer>  - if the AI answer is correct and reasoning is sound\n"
            . "VALIDATION: INCORRECT | ANSWER_KEY: <your answer> - if the AI answer is wrong or reasoning is flawed\n"
            . "VALIDATION: PARTIAL | ANSWER_KEY: <your answer> - if partially correct";

        $result = $this->call_llm_direct($model, $prompt, $images);

        if (isset($result['error'])) {
            return ['success' => false, 'error' => $result['error']];
        }

        $response_text = $result['content'];

        // Extract validation result and validator's own answer key
        $validation = 'UNKNOWN';
        $validator_key = '';
        if (preg_match('/VALIDATION:\s*(CORRECT|INCORRECT|PARTIAL)/i', $response_text, $m)) {
            $validation = strtoupper(trim($m[1]));
        }
        $validator_key = $this->extract_answer_key($response_text);

        return [
            'success' => true,
            'validation_text' => $response_text,
            'validation_result' => $validation,
            'validator_key' => $validator_key,
        ];
    }

    private function extract_answer_key($text)
    {
        // Try ANSWER_KEY: format first (most explicit)
        if (preg_match('/ANSWER_KEY:\s*(.+)$/mi', $text, $m)) {
            return strtoupper(trim(preg_replace('/[\*`]+/', '', $m[1])));
        }

        // Fallback: \boxed{...} (Gemini often uses this) - handle nested braces and $\boxed{X}$
        if (preg_match_all('/\\\\boxed\{((?:[^{}]|\{[^{}]*\})*)\}/', $text, $matches)) {
            $val = trim(end($matches[1]));
            // Clean up LaTeX formatting like \text{}, \mathrm{} etc.
            $val = preg_replace('/\\\\(?:text|mathrm|mathbf)\{([^}]*)\}/', '$1', $val);
            // If it's a simple letter/number, return directly
            if (preg_match('/^[A-Da-d](?:\s*[;,]\s*[A-Da-d])*$/', $val)) {
                return strtoupper(preg_replace('/[\s,]+/', ';', $val));
            }
            // For plain numbers
            if (preg_match('/^[+-]?\d+(?:\.\d+)?$/', $val)) {
                return $val;
            }
            // For numerical/LaTeX values, return as-is (uppercased)
            return strtoupper($val);
        }

        // Fallback: "answer is $\boxed{X}$" or "answer is \boxed{X}"
        if (preg_match('/answer\s+is\s*\$?\\\\boxed\{((?:[^{}]|\{[^{}]*\})*)\}\$?\s*\.?\s*$/mi', $text, $m)) {
            $val = trim($m[1]);
            $val = preg_replace('/\\\\(?:text|mathrm|mathbf)\{([^}]*)\}/', '$1', $val);
            if (preg_match('/^[A-Da-d](?:\s*[;,]\s*[A-Da-d])*$/', $val)) {
                return strtoupper(preg_replace('/[\s,]+/', ';', $val));
            }
            if (preg_match('/^[+-]?\d+(?:\.\d+)?$/', $val)) {
                return $val;
            }
            return strtoupper($val);
        }

        // Fallback: "Answer: X" or "**Answer: X**" or "Answer is X"
        if (preg_match('/(?:correct\s+)?(?:final\s+)?answer\s*(?:is|:)\s*[:\s]*\**([A-Da-d](?:\s*[;,]\s*[A-Da-d])*)\**\s*\.?\s*$/mi', $text, $m)) {
            return strtoupper(preg_replace('/[\s,]+/', ';', trim($m[1])));
        }

        // Fallback: "The answer is <number>" for numerical types
        if (preg_match('/(?:the\s+)?(?:correct\s+)?(?:final\s+)?answer\s+is\s*[:\s]*\**([+-]?\d+(?:\.\d+)?)\**\s*\.?\s*$/mi', $text, $m)) {
            return trim($m[1]);
        }

        // Fallback: "Option (B)" or "Option B" at end
        if (preg_match('/(?:correct\s+)?option\s*(?:is\s*)?\(?([A-Da-d])\)?\s*\.?\s*$/mi', $text, $m)) {
            return strtoupper(trim($m[1]));
        }

        // Fallback: Standalone bold answer at very end like "**B**" or "**(B)**"
        if (preg_match('/\*\*\(?([A-Da-d])\)?\*\*\s*\.?\s*$/m', $text, $m)) {
            return strtoupper(trim($m[1]));
        }

        // Fallback: Last line contains just a single letter answer
        $lines = array_filter(array_map('trim', explode("\n", $text)));
        if (!empty($lines)) {
            $lastLine = end($lines);
            $lastLine = preg_replace('/[\*`]+/', '', $lastLine);
            if (preg_match('/^[\(]?([A-Da-d])[\)]?\.?$/', trim($lastLine), $m)) {
                return strtoupper($m[1]);
            }
        }

        return '';
    }

    /**
     * Call LLM directly using model name (bypasses config system).
     * Detects provider from model name prefix.
     */
    private function call_llm_direct($model, $prompt, $images = [])
    {
        if (stripos($model, 'gemini') === 0) {
            return $this->call_gemini_direct($model, $prompt, $images);
        }
        if (stripos($model, 'claude') === 0) {
            return $this->call_claude_direct($model, $prompt, $images);
        }
        return $this->call_openai_direct($model, $prompt, $images);
    }

    private function call_openai_direct($model, $prompt, $images = [])
    {
        $apiKey = qa_opt('openai_api_key');
        if (empty($apiKey)) {
            return ['error' => 'OpenAI API key not configured.'];
        }

        // Build content: text + images for vision
        if (!empty($images)) {
            $content_parts = [['type' => 'text', 'text' => $prompt]];
            foreach ($images as $img) {
                $content_parts[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => 'data:' . $img['mime'] . ';base64,' . $img['base64'],
                    ],
                ];
            }
            $messages = [['role' => 'user', 'content' => $content_parts]];
        } else {
            $messages = [['role' => 'user', 'content' => $prompt]];
        }

        $data = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => 16000,
            'temperature' => 0.3,
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT    => 300,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['error' => 'cURL error: ' . $err];
        }
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);
        if ($http_code !== 200) {
            $msg = $decoded['error']['message'] ?? ('HTTP ' . $http_code);
            return ['error' => 'OpenAI API error: ' . $msg];
        }

        if (!isset($decoded['choices'][0]['message']['content'])) {
            return ['error' => 'Unexpected OpenAI response'];
        }

        return ['content' => $decoded['choices'][0]['message']['content']];
    }

    private function call_gemini_direct($model, $prompt, $images = [])
    {
        $apiKey = qa_opt('openai_gemini_api_key');
        if (empty($apiKey)) {
            return ['error' => 'Gemini API key not configured.'];
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . urlencode($apiKey);

        // Build parts: text + images
        $parts = [['text' => $prompt]];
        foreach ($images as $img) {
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $img['mime'],
                    'data'     => $img['base64'],
                ],
            ];
        }

        $data = [
            'contents' => [
                ['role' => 'user', 'parts' => $parts],
            ],
            'generationConfig' => [
                'temperature'     => 0.3,
                'maxOutputTokens' => 65536,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_TIMEOUT        => 300,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['error' => 'cURL error: ' . $err];
        }
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);
        if ($http_code !== 200) {
            $msg = $decoded['error']['message'] ?? ('HTTP ' . $http_code);
            return ['error' => 'Gemini API error: ' . $msg];
        }

        if (!isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
            // Provide more detail about what went wrong
            if (isset($decoded['promptFeedback']['blockReason'])) {
                return ['error' => 'Gemini blocked: ' . $decoded['promptFeedback']['blockReason']];
            }
            if (isset($decoded['candidates'][0]['finishReason']) && $decoded['candidates'][0]['finishReason'] !== 'STOP') {
                return ['error' => 'Gemini finish reason: ' . $decoded['candidates'][0]['finishReason']];
            }
            return ['error' => 'Unexpected Gemini response: ' . substr($response, 0, 500)];
        }

        return ['content' => $decoded['candidates'][0]['content']['parts'][0]['text']];
    }

    private function call_claude_direct($model, $prompt, $images = [])
    {
        $apiKey = qa_opt('openai_claude_api_key');
        if (empty($apiKey)) {
            return ['error' => 'Claude (Anthropic) API key not configured.'];
        }

        // Build content: text + images for vision
        if (!empty($images)) {
            $content_parts = [];
            foreach ($images as $img) {
                $content_parts[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $img['mime'],
                        'data' => $img['base64'],
                    ],
                ];
            }
            $content_parts[] = ['type' => 'text', 'text' => $prompt];
        } else {
            $content_parts = $prompt;
        }

        $data = [
            'model'      => $model,
            'max_tokens' => 16000,
            'messages'   => [
                ['role' => 'user', 'content' => $content_parts],
            ],
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT    => 300,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['error' => 'cURL error: ' . $err];
        }
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);
        if ($http_code !== 200) {
            $msg = $decoded['error']['message'] ?? ('HTTP ' . $http_code);
            return ['error' => 'Claude API error: ' . $msg];
        }

        if (!isset($decoded['content'][0]['text'])) {
            $stop = $decoded['stop_reason'] ?? 'unknown';
            return ['error' => 'Unexpected Claude response (stop_reason: ' . $stop . '): ' . substr($response, 0, 500)];
        }

        return ['content' => $decoded['content'][0]['text']];
    }

    // ─── Data queries ───────────────────────────────────────────────────

    private function get_questions_for_tag($tag, $start, $limit)
    {
        return qa_db_read_all_assoc(qa_db_query_sub(
            'SELECT p.postid, p.title, p.content, p.tags, p.selchildid, a.answer_str
             FROM ^posts p
             JOIN ^ec_answers a ON a.postid = p.postid
             WHERE p.type = $ AND FIND_IN_SET($, p.tags)
             ORDER BY p.postid ASC
             LIMIT #,#',
            'Q', $tag, $start, $limit
        ));
    }

    private function count_questions_for_tag($tag)
    {
        return (int) qa_db_read_one_value(qa_db_query_sub(
            'SELECT COUNT(*)
             FROM ^posts p
             JOIN ^ec_answers a ON a.postid = p.postid
             WHERE p.type = $ AND FIND_IN_SET($, p.tags)',
            'Q', $tag
        ));
    }

    private function get_available_models()
    {
        $models = ['o3-mini', 'o3', 'o4-mini', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4.1-nano', 'gpt-4o', 'gpt-4o-mini'];

        if (!empty(qa_opt('openai_gemini_api_key'))) {
            $models = array_merge($models, [
                'gemini-2.5-pro', 'gemini-2.5-flash',
                'gemini-2.0-flash', 'gemini-1.5-pro', 'gemini-1.5-flash',
            ]);
        }

        if (!empty(qa_opt('openai_claude_api_key'))) {
            $models = array_merge($models, [
                'claude-sonnet-4-20250514', 'claude-opus-4-20250514',
                'claude-3-7-sonnet-20250219', 'claude-3-5-haiku-20241022', 'claude-3-5-sonnet-20241022',
            ]);
        }

        return $models;
    }

    private function get_question_type($tags)
    {
        $tag_list = explode(',', $tags);
        foreach ($tag_list as $t) {
            $t = trim($t);
            if ($t === 'multiple-selects') return 'MSQ';
            if ($t === 'numerical-answers') return 'Numerical';
        }
        return 'MCQ';
    }

    private function table_exists($table_name)
    {
        $full = qa_db_add_table_prefix($table_name);
        $result = qa_db_read_all_values(qa_db_query_sub(
            'SHOW TABLES LIKE $', $full
        ));
        return !empty($result);
    }

    private function suggest_tags($term)
    {
        if (strlen($term) < 2) return [];
        return qa_db_read_all_values(qa_db_query_sub(
            'SELECT word FROM ^words WHERE word LIKE $ AND tagcount > 0 ORDER BY tagcount DESC LIMIT 15',
            $term . '%'
        ));
    }

    private function suggest_users($term)
    {
        if (strlen($term) < 1) return [];
        return qa_db_read_all_values(qa_db_query_sub(
            'SELECT handle FROM ^users WHERE handle LIKE $ ORDER BY handle ASC LIMIT 15',
            $term . '%'
        ));
    }

    private function fetch_user_style($handle)
    {
        if (empty($handle)) {
            return ['success' => false, 'error' => 'No handle provided'];
        }

        $userid = qa_db_read_one_value(qa_db_query_sub(
            'SELECT userid FROM ^users WHERE handle = $ LIMIT 1', $handle
        ), true);

        if ($userid === null) {
            return ['success' => false, 'error' => 'User not found: ' . $handle];
        }

        // Fetch top 10 voted answers by this user
        $answers = qa_db_read_all_assoc(qa_db_query_sub(
            'SELECT a.content, q.title
             FROM ^posts a
             JOIN ^posts q ON q.postid = a.parentid
             WHERE a.type = $ AND a.userid = # AND a.netvotes > 0
             ORDER BY a.netvotes DESC
             LIMIT 10',
            'A', $userid
        ));

        if (empty($answers)) {
            return ['success' => false, 'error' => 'No upvoted answers found for user: ' . $handle];
        }

        $examples = '';
        foreach ($answers as $i => $ans) {
            $num = $i + 1;
            $examples .= "=== Example Answer {$num} (Q: " . strip_tags($ans['title']) . ") ===\n";
            $examples .= strip_tags($ans['content']) . "\n\n";
        }

        return [
            'success' => true,
            'style_examples' => $examples,
            'count' => count($answers),
        ];
    }

    private function markdown_to_html($text)
    {
        // Remove ANSWER_KEY line from the output
        $text = preg_replace('/^ANSWER_KEY:\s*.+$/mi', '', $text);
        $text = trim($text);

        // Protect LaTeX/MathJax expressions from Markdown processing
        // Replace $...$ and $$...$$ with placeholders
        $placeholders = [];
        $idx = 0;

        // Block math $$...$$
        $text = preg_replace_callback('/\$\$(.+?)\$\$/s', function($m) use (&$placeholders, &$idx) {
            $key = "\x00MATH" . $idx++ . "\x00";
            $placeholders[$key] = '$$' . $m[1] . '$$';
            return $key;
        }, $text);

        // Inline math $...$  (not $$)
        $text = preg_replace_callback('/\$([^\$\n]+?)\$/', function($m) use (&$placeholders, &$idx) {
            $key = "\x00MATH" . $idx++ . "\x00";
            $placeholders[$key] = '$' . $m[1] . '$';
            return $key;
        }, $text);

        // \boxed{...}
        $text = preg_replace_callback('/\\\\boxed\{((?:[^{}]|\{[^{}]*\})*)\}/', function($m) use (&$placeholders, &$idx) {
            $key = "\x00MATH" . $idx++ . "\x00";
            $placeholders[$key] = '\\boxed{' . $m[1] . '}';
            return $key;
        }, $text);

        // Convert Markdown to HTML

        // Code blocks ```...```
        $text = preg_replace_callback('/```(\w*)\n(.*?)\n```/s', function($m) {
            return '<pre><code>' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '</code></pre>';
        }, $text);

        // Inline code `...`
        $text = preg_replace_callback('/`([^`]+)`/', function($m) {
            return '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
        }, $text);

        // Bold **...**
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);

        // Italic *...*
        $text = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $text);

        // Headers ### -> <h4>, ## -> <h3>, # -> <h2>
        $text = preg_replace('/^###\s+(.+)$/m', '<h4>$1</h4>', $text);
        $text = preg_replace('/^##\s+(.+)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^#\s+(.+)$/m', '<h2>$1</h2>', $text);

        // Ordered lists: lines starting with "1. ", "2. " etc.
        $text = preg_replace_callback('/(?:^|\n)((?:\d+\.\s+.+\n?)+)/', function($m) {
            $items = preg_split('/\n(?=\d+\.\s)/', trim($m[1]));
            $li = '';
            foreach ($items as $item) {
                $item = preg_replace('/^\d+\.\s+/', '', $item);
                $li .= '<li>' . trim($item) . '</li>';
            }
            return '<ol>' . $li . '</ol>';
        }, $text);

        // Unordered lists: lines starting with "- " or "* "
        $text = preg_replace_callback('/(?:^|\n)((?:[\-\*]\s+.+\n?)+)/', function($m) {
            $items = preg_split('/\n(?=[\-\*]\s)/', trim($m[1]));
            $li = '';
            foreach ($items as $item) {
                $item = preg_replace('/^[\-\*]\s+/', '', $item);
                $li .= '<li>' . trim($item) . '</li>';
            }
            return '<ul>' . $li . '</ul>';
        }, $text);

        // Paragraphs: double newlines
        $text = preg_replace('/\n{2,}/', '</p><p>', $text);

        // Single newlines to <br>
        $text = preg_replace('/\n/', '<br>', $text);

        // Wrap in <p> tags
        $text = '<p>' . $text . '</p>';

        // Clean up empty paragraphs
        $text = preg_replace('/<p>\s*<\/p>/', '', $text);

        // Restore LaTeX placeholders
        foreach ($placeholders as $key => $val) {
            $text = str_replace($key, $val, $text);
        }

        return $text;
    }

    private function add_answer_to_qa($postid, $answer_text, $agent_key, $handle)
    {
        if (empty($postid) || empty($answer_text)) {
            return ['success' => false, 'error' => 'Missing postid or answer text'];
        }

        require_once QA_INCLUDE_DIR . 'app/post-create.php';
        require_once QA_INCLUDE_DIR . 'app/posts.php';
        require_once QA_INCLUDE_DIR . 'app/users.php';

        // Resolve user
        $userid = null;
        $cookieid = qa_cookie_get();
        if (!empty($handle)) {
            $userid = qa_handle_to_userid($handle);
        }
        if ($userid === null) {
            $userid = qa_get_logged_in_userid();
            $handle = qa_get_logged_in_handle();
        }

        // Get question
        $question = qa_post_get_full($postid);
        if (!$question) {
            return ['success' => false, 'error' => 'Question not found: ' . $postid];
        }

        // Convert Markdown to HTML for Q2A
        $html_content = $this->markdown_to_html($answer_text);

        // Create the answer
        $answerid = qa_answer_create($userid, $handle, $cookieid, $html_content, 'html', strip_tags($html_content), false, null, $question, false, null);

        if (!$answerid) {
            return ['success' => false, 'error' => 'Failed to create answer'];
        }

        // Fill ec_answers if key provided and not already present
        $ec_filled = false;
        if (!empty($agent_key)) {
            $existing = qa_db_read_one_value(qa_db_query_sub(
                'SELECT answer_str FROM ^ec_answers WHERE postid = # LIMIT 1', $postid
            ), true);

            if ($existing === null || $existing === '') {
                // Insert new row
                qa_db_query_sub(
                    'INSERT INTO ^ec_answers (postid, answer_str, userid, created) VALUES (#, $, #, NOW())
                     ON DUPLICATE KEY UPDATE answer_str = $, editedby = #, edited = NOW()',
                    $postid, $agent_key, $userid, $agent_key, $userid
                );
                $ec_filled = true;
            }
        }

        return [
            'success' => true,
            'answerid' => $answerid,
            'ec_filled' => $ec_filled,
        ];
    }

    // ─── Rendering ──────────────────────────────────────────────────────

    private function render_tag_form($tag)
    {
        $url = qa_path('admin/openai-validate', null, qa_opt('site_url'));
        $ajax_url = qa_path('admin/openai-validate', null, qa_opt('site_url'));
        $html = '<form id="oai-tag-form" method="get" action="' . qa_html($url) . '" style="margin-bottom:20px; position:relative;">';
        $html .= '<label style="font-weight:bold; margin-right:8px;">Tag:</label>';
        $html .= '<input type="text" id="oai-tag-input" name="tag" value="' . qa_html($tag) . '" autocomplete="off" style="padding:6px 12px; width:300px; font-size:14px;" placeholder="e.g. gatecse-2024">';
        $html .= '<div id="oai-tag-dropdown" style="display:none; position:absolute; left:42px; top:36px; width:300px; max-height:250px; overflow-y:auto; background:#fff; border:1px solid #ccc; border-radius:4px; box-shadow:0 4px 12px rgba(0,0,0,0.15); z-index:1000;"></div>';
        $html .= ' <button type="submit" style="padding:6px 16px; font-size:14px;">Load Questions</button>';
        $html .= '</form>';

        // Tag autocomplete JS
        $html .= '<script>
(function() {
    var input = document.getElementById("oai-tag-input");
    var dropdown = document.getElementById("oai-tag-dropdown");
    var ajaxUrl = ' . json_encode($ajax_url) . ';
    var debounceTimer = null;

    input.addEventListener("input", function() {
        clearTimeout(debounceTimer);
        var val = this.value.trim();
        if (val.length < 2) { dropdown.style.display = "none"; return; }
        debounceTimer = setTimeout(function() {
            var fd = new FormData();
            fd.append("ajax_action", "tag_suggest");
            fd.append("term", val);
            fetch(ajaxUrl, { method: "POST", body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success || !data.tags.length) { dropdown.style.display = "none"; return; }
                    var h = "";
                    data.tags.forEach(function(t) {
                        h += "<div style=\"padding:6px 12px; cursor:pointer; border-bottom:1px solid #eee;\" ";
                        h += "onmouseover=\"this.style.background=\'#e3f2fd\'\" ";
                        h += "onmouseout=\"this.style.background=\'#fff\'\" ";
                        h += "onclick=\"document.getElementById(\'oai-tag-input\').value=\'" + t + "\'; document.getElementById(\'oai-tag-dropdown\').style.display=\'none\'; document.getElementById(\'oai-tag-form\').submit();\"";
                        h += ">" + t + "</div>";
                    });
                    dropdown.innerHTML = h;
                    dropdown.style.display = "block";
                });
        }, 250);
    });

    document.addEventListener("click", function(e) {
        if (!dropdown.contains(e.target) && e.target !== input) dropdown.style.display = "none";
    });
})();
</script>';

        return $html;
    }

    private function render_model_selectors($models)
    {
        $options_html = '';
        foreach ($models as $m) {
            $options_html .= '<option value="' . qa_html($m) . '">' . qa_html($m) . '</option>';
        }

        // For model B, try to default to a different model
        $options_html_b = '';
        foreach ($models as $i => $m) {
            $sel = ($i === 1 || ($i === 0 && count($models) === 1)) ? ' selected' : '';
            $options_html_b .= '<option value="' . qa_html($m) . '"' . $sel . '>' . qa_html($m) . '</option>';
        }

        $html = '<div style="margin-bottom:15px; padding:12px; background:#f5f5f5; border-radius:8px; display:flex; gap:30px; align-items:center; flex-wrap:wrap;">';
        $html .= '<div><label style="font-weight:bold;">Model A (Generate₁ + Validate₂):</label><br>';
        $html .= '<select id="oai-model-a" style="padding:5px; min-width:200px;">' . $options_html . '</select></div>';
        $html .= '<div><label style="font-weight:bold;">Model B (Validate₁ + Generate₂):</label><br>';
        $html .= '<select id="oai-model-b" style="padding:5px; min-width:200px;">' . $options_html_b . '</select></div>';

        // User style selector
        $current_handle = qa_get_logged_in_handle();
        $html .= '<div style="position:relative;"><label style="font-weight:bold;">Mimic User Style:</label><br>';
        $html .= '<input type="text" id="oai-user-input" value="' . qa_html($current_handle) . '" autocomplete="off" style="padding:5px; min-width:180px;" placeholder="Username">';
        $html .= '<div id="oai-user-dropdown" style="display:none; position:absolute; left:0; top:52px; width:180px; max-height:200px; overflow-y:auto; background:#fff; border:1px solid #ccc; border-radius:4px; box-shadow:0 4px 12px rgba(0,0,0,0.15); z-index:1000;"></div>';
        $html .= ' <button type="button" id="oai-load-style" onclick="oaiLoadStyle()" style="padding:5px 12px;">Load Style</button>';
        $html .= '<span id="oai-style-status" style="margin-left:6px; font-size:12px; color:#888;"></span>';
        $html .= '</div>';

        $html .= '</div>';
        return $html;
    }

    private function render_controls($total)
    {
        $html = '<div style="margin-bottom:10px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">';
        $html .= '<strong>Total: ' . number_format($total) . ' questions</strong>';
        $html .= '<button id="oai-btn-complete" onclick="oaiCompleteRun()" style="padding:6px 16px; background:#2e7d32; color:#fff; font-weight:bold;">&#9654; Complete Run</button>';
        $html .= '<button id="oai-btn-generate-all" onclick="oaiRunAll(\'generate\')" style="padding:6px 16px;">1. Generate All (Model A)</button>';
        $html .= '<button id="oai-btn-validate1-all" onclick="oaiRunAll(\'validate1\')" style="padding:6px 16px;" disabled>2. Validate All (Model B)</button>';
        $html .= '<button id="oai-btn-validate2-all" onclick="oaiRunAll(\'validate2\')" style="padding:6px 16px;" disabled>3. Generate All (Model B)</button>';
        $html .= '<button id="oai-btn-validate2v-all" onclick="oaiRunAll(\'validate2v\')" style="padding:6px 16px;" disabled>4. Validate Swapped (Model A)</button>';
        $html .= '<button id="oai-btn-stop" onclick="oaiStop()" style="padding:6px 16px; background:#c62828; color:#fff; display:none;">Stop</button>';
        $html .= '<button id="oai-btn-add-all" onclick="oaiAddAll()" style="padding:6px 16px; background:#1565c0; color:#fff;" disabled>5. Add All Answers</button>';
        $html .= '<button id="oai-btn-vp" onclick="oaiValidatePopulate()" style="padding:6px 16px; background:#6a1b9a; color:#fff; font-weight:bold;">&#9889; Validate &amp; Populate (A)</button>';
        $html .= '<span id="oai-progress" style="color:#666;"></span>';
        $html .= '</div>';
        return $html;
    }

    private function render_table($questions)
    {
        $html = '<div style="overflow-x:auto;">';
        $html .= '<table id="oai-table" style="width:100%; border-collapse:collapse; font-size:13px;" border="1" cellpadding="6">';
        $html .= '<thead><tr style="background:#e3f2fd;">';
        $html .= '<th style="min-width:40px;">#</th>';
        $html .= '<th style="min-width:200px;">Question Title</th>';
        $html .= '<th style="min-width:200px;">Question Text</th>';
        $html .= '<th style="min-width:50px;">Type</th>';
        $html .= '<th style="min-width:50px;">Official Key</th>';
        $html .= '<th style="min-width:200px;">Answer (A)</th>';
        $html .= '<th style="min-width:50px;">Key (A)</th>';
        $html .= '<th style="min-width:80px;">Valid₁ (B validates A)</th>';
        $html .= '<th style="min-width:200px;">Answer (B)</th>';
        $html .= '<th style="min-width:50px;">Key (B)</th>';
        $html .= '<th style="min-width:80px;">Valid₂ (A validates B)</th>';
        $html .= '<th style="min-width:80px;">Agent Answer</th>';
        $html .= '<th style="min-width:80px;">Actions</th>';
        $html .= '<th style="min-width:80px;">Best Ans</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($questions as $i => $q) {
            $qtype = $this->get_question_type($q['tags']);
            $content_preview = mb_substr(strip_tags($q['content']), 0, 200);
            if (mb_strlen(strip_tags($q['content'])) > 200) $content_preview .= '…';

            $question_url = qa_path_html(qa_q_request((int)$q['postid'], $q['title']), null, qa_opt('site_url'));

            $full_content = strip_tags($q['content']);

            $html .= '<tr id="oai-row-' . (int)$q['postid'] . '" '
                . 'data-postid="' . (int)$q['postid'] . '" '
                . 'data-qtype="' . qa_html($qtype) . '" '
                . 'data-answer-key="' . qa_html($q['answer_str']) . '" '
                . 'data-title="' . qa_html($q['title']) . '" '
                . 'data-content="' . qa_html($full_content) . '">';
            $html .= '<td>' . ($i + 1) . '</td>';
            $html .= '<td><a href="' . $question_url . '" target="_blank">' . qa_html($q['title']) . '</a></td>';
            $html .= '<td class="oai-qtext" style="max-width:300px; overflow:hidden; font-size:12px;">' . qa_html($content_preview) . '</td>';
            $html .= '<td><span class="oai-qtype-badge oai-qtype-' . strtolower($qtype) . '">' . $qtype . '</span></td>';
            $html .= '<td style="font-weight:bold; text-align:center;">' . qa_html(strtoupper($q['answer_str'])) . '</td>';
            $html .= '<td class="oai-col-answer" style="font-size:12px;"></td>';
            $html .= '<td class="oai-col-llmkey" style="text-align:center; font-weight:bold;"></td>';
            $html .= '<td class="oai-col-val1" style="text-align:center;"></td>';
            $html .= '<td class="oai-col-answer2" style="font-size:12px;"></td>';
            $html .= '<td class="oai-col-llmkey2" style="text-align:center; font-weight:bold;"></td>';
            $html .= '<td class="oai-col-val2" style="text-align:center;"></td>';
            $html .= '<td class="oai-col-agent" style="text-align:center; font-weight:bold;"></td>';
            $html .= '<td class="oai-col-actions" style="text-align:center;"></td>';

            // Best answer link
            if (!empty($q['selchildid'])) {
                $best_url = qa_path_html(qa_q_request((int)$q['postid'], $q['title']), null, qa_opt('site_url')) . '#a' . (int)$q['selchildid'];
                $html .= '<td style="text-align:center;"><a href="' . $best_url . '" target="_blank" title="Selected answer" style="color:#2e7d32;">&#9733;</a></td>';
            } else {
                $html .= '<td style="text-align:center; color:#ccc;">-</td>';
            }

            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        return $html;
    }

    private function render_pagination($tag, $start, $total)
    {
        if ($total <= $this->page_size) return '';

        $html = '<div style="margin-top:15px; display:flex; gap:10px;">';
        $base = qa_path('admin/openai-validate', ['tag' => $tag], qa_opt('site_url'));

        if ($start > 0) {
            $prev = max(0, $start - $this->page_size);
            $html .= '<a href="' . qa_html($base) . '&start=' . $prev . '" style="padding:6px 12px; border:1px solid #ddd; border-radius:4px;">&laquo; Previous</a>';
        }

        $page_num = floor($start / $this->page_size) + 1;
        $total_pages = ceil($total / $this->page_size);
        $html .= '<span style="padding:6px 12px;">Page ' . $page_num . ' of ' . $total_pages . '</span>';

        if ($start + $this->page_size < $total) {
            $next = $start + $this->page_size;
            $html .= '<a href="' . qa_html($base) . '&start=' . $next . '" style="padding:6px 12px; border:1px solid #ddd; border-radius:4px;">Next &raquo;</a>';
        }

        $html .= '</div>';
        return $html;
    }

    private function get_mathjax_script()
    {
        return '<script>
MathJax = {
  tex: { inlineMath: [["$","$"],["\\\\(","\\\\)"]], processEscapes: true },
  svg: { fontCache: "global" }
};
</script>
<script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-svg.js"></script>';
    }

    private function get_js($tag)
    {
        if (empty($tag)) return '';

        $ajax_url = qa_path('admin/openai-validate', null, qa_opt('site_url'));

        return <<<JSEOF
<style>
.oai-qtype-mcq { background:#e3f2fd; padding:2px 8px; border-radius:4px; font-size:11px; }
.oai-qtype-msq { background:#fff3e0; padding:2px 8px; border-radius:4px; font-size:11px; }
.oai-qtype-numerical { background:#f3e5f5; padding:2px 8px; border-radius:4px; font-size:11px; }
.oai-correct { background:#c8e6c9; color:#2e7d32; font-weight:bold; }
.oai-incorrect { background:#ffcdd2; color:#c62828; font-weight:bold; }
.oai-partial { background:#fff9c4; color:#f57f17; font-weight:bold; }
.oai-match { color:#2e7d32; }
.oai-mismatch { color:#c62828; }
.oai-agent-match { background:#c8e6c9; color:#2e7d32; padding:2px 6px; border-radius:4px; }
.oai-agent-mismatch { background:#ffcdd2; color:#c62828; padding:2px 6px; border-radius:4px; }
#oai-table td { vertical-align:top; }
.oai-add-btn { padding:3px 10px; font-size:11px; cursor:pointer; background:#1565c0; color:#fff; border:none; border-radius:4px; }
.oai-add-btn:hover { background:#0d47a1; }
.oai-add-btn:disabled { background:#bbb; cursor:default; }
.oai-added { color:#2e7d32; font-size:11px; }
</style>
<script>
var OAI_AJAX_URL = '{$ajax_url}';
var oaiQueue = [];
var oaiRunning = false;
var oaiStyleExamples = '';

function oaiTypeset(el) {
    if (typeof MathJax !== "undefined" && typeof MathJax.typesetPromise === "function") {
        MathJax.typesetPromise(el ? [el] : undefined).catch(function(e){});
    }
}
document.addEventListener('DOMContentLoaded', function() { oaiTypeset(); });

// ─── User autocomplete ─────────────────────────────────────────────
(function() {
    document.addEventListener('DOMContentLoaded', function() {
        var input = document.getElementById('oai-user-input');
        var dropdown = document.getElementById('oai-user-dropdown');
        if (!input || !dropdown) return;
        var timer = null;
        input.addEventListener('input', function() {
            clearTimeout(timer);
            var val = this.value.trim();
            if (val.length < 1) { dropdown.style.display = 'none'; return; }
            timer = setTimeout(function() {
                var fd = new FormData();
                fd.append('ajax_action', 'user_suggest');
                fd.append('term', val);
                fetch(OAI_AJAX_URL, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data.success || !data.users.length) { dropdown.style.display = 'none'; return; }
                        var h = '';
                        data.users.forEach(function(u) {
                            h += '<div style="padding:5px 10px; cursor:pointer; border-bottom:1px solid #eee;" ';
                            h += 'onmouseover="this.style.background=\'#e3f2fd\'" ';
                            h += 'onmouseout="this.style.background=\'#fff\'" ';
                            h += 'onclick="document.getElementById(\'oai-user-input\').value=\'' + u + '\'; document.getElementById(\'oai-user-dropdown\').style.display=\'none\';"';
                            h += '>' + u + '</div>';
                        });
                        dropdown.innerHTML = h;
                        dropdown.style.display = 'block';
                    });
            }, 200);
        });
        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target) && e.target !== input) dropdown.style.display = 'none';
        });
    });
})();

function oaiLoadStyle() {
    var handle = document.getElementById('oai-user-input').value.trim();
    var status = document.getElementById('oai-style-status');
    if (!handle) { status.textContent = 'Enter a username'; status.style.color = '#c62828'; return; }
    status.textContent = 'Loading...';
    status.style.color = '#888';
    var fd = new FormData();
    fd.append('ajax_action', 'fetch_style');
    fd.append('handle', handle);
    fetch(OAI_AJAX_URL, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                oaiStyleExamples = data.style_examples;
                status.textContent = 'Loaded ' + data.count + ' examples from ' + handle;
                status.style.color = '#2e7d32';
            } else {
                oaiStyleExamples = '';
                status.textContent = data.error || 'Error loading style';
                status.style.color = '#c62828';
            }
        })
        .catch(function(e) {
            status.textContent = 'Error: ' + e.message;
            status.style.color = '#c62828';
        });
}

// ─── Core functions ─────────────────────────────────────────────────

function oaiGetRows() {
    return document.querySelectorAll('#oai-table tbody tr');
}

function oaiProgress(msg) {
    document.getElementById('oai-progress').textContent = msg;
}

function oaiStop() {
    oaiRunning = false;
    oaiCompleteMode = false;
    oaiQueue = [];
    document.getElementById('oai-btn-stop').style.display = 'none';
    oaiProgress('Stopped.');
    oaiUpdateSummary();
}

function oaiRunAll(step) {
    var rows = oaiGetRows();
    oaiQueue = [];
    for (var i = 0; i < rows.length; i++) {
        oaiQueue.push({ row: rows[i], step: step, index: i });
    }
    oaiRunning = true;
    document.getElementById('oai-btn-stop').style.display = 'inline-block';
    oaiProcessNext(0, rows.length, step);
}

function oaiCompleteRun() {
    oaiCompleteMode = true;
    oaiRunAll('generate');
}

function oaiProcessNext(done, total, step) {
    if (!oaiRunning || oaiQueue.length === 0) {
        oaiRunning = false;
        document.getElementById('oai-btn-stop').style.display = 'none';
        oaiProgress('Done! ' + done + '/' + total + ' (' + step + ')');
        oaiUpdateSummary();
        if (step === 'generate') {
            document.getElementById('oai-btn-validate1-all').disabled = false;
        } else if (step === 'validate1') {
            document.getElementById('oai-btn-validate2-all').disabled = false;
        } else if (step === 'validate2') {
            document.getElementById('oai-btn-validate2v-all').disabled = false;
        } else if (step === 'validate2v') {
            document.getElementById('oai-btn-add-all').disabled = false;
        }
        // Auto-chain next step in complete mode
        if (oaiCompleteMode) {
            var nextStep = null;
            if (step === 'generate') nextStep = 'validate1';
            else if (step === 'validate1') nextStep = 'validate2';
            else if (step === 'validate2') nextStep = 'validate2v';
            else { oaiCompleteMode = false; }
            if (nextStep) {
                oaiProgress('Starting ' + nextStep + '...');
                setTimeout(function() { oaiRunAll(nextStep); }, 500);
            }
        }
        return;
    }

    var item = oaiQueue.shift();
    done++;
    oaiProgress(step + ': ' + done + '/' + total + '...');

    if (step === 'generate') {
        oaiGenerate(item.row, function() { oaiProcessNext(done, total, step); });
    } else if (step === 'validate1') {
        oaiValidate(item.row, 1, function() { oaiProcessNext(done, total, step); });
    } else if (step === 'validate2') {
        oaiGenerateB(item.row, function() { oaiProcessNext(done, total, step); });
    } else if (step === 'validate2v') {
        oaiValidateB(item.row, function() { oaiProcessNext(done, total, step); });
    }
}

function oaiGenerate(row, callback) {
    var postid = row.dataset.postid;
    var qtype = row.dataset.qtype;
    var title = row.dataset.title;
    var qtext = row.dataset.content;
    var answerKey = row.dataset.answerKey;
    var model = document.getElementById('oai-model-a').value;

    var ansCol = row.querySelector('.oai-col-answer');
    var keyCol = row.querySelector('.oai-col-llmkey');
    ansCol.textContent = 'Generating...';

    var fd = new FormData();
    fd.append('ajax_action', 'generate');
    fd.append('postid', postid);
    fd.append('model', model);
    fd.append('question_title', title);
    fd.append('question_content', qtext);
    fd.append('question_type', qtype);
    fd.append('answer_key', answerKey);
    if (oaiStyleExamples) fd.append('style_examples', oaiStyleExamples);

    fetch(OAI_AJAX_URL, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                ansCol.innerHTML = '<details><summary>View (' + model + ')</summary><pre style="white-space:pre-wrap;font-size:11px;max-height:300px;overflow:auto;">' + oaiEscape(data.answer) + '</pre></details>';
                keyCol.textContent = data.extracted_key || '?';
                var match = oaiKeysMatch(answerKey.toUpperCase(), data.extracted_key);
                keyCol.className = 'oai-col-llmkey ' + (match ? 'oai-match' : 'oai-mismatch');
                row.dataset.llmAnswer = data.answer;
                row.dataset.llmKey = data.extracted_key;
                row.dataset.generateModel = model;
                oaiTypeset(ansCol);
            } else {
                ansCol.textContent = 'Error: ' + (data.error || 'Unknown');
                keyCol.textContent = '-';
            }
            if (callback) callback();
        })
        .catch(function(e) {
            ansCol.textContent = 'Error: ' + e.message;
            if (callback) callback();
        });
}

function oaiValidate(row, colNum, callback) {
    var postid = row.dataset.postid;
    var llmAnswer = colNum === 1 ? row.dataset.llmAnswer : row.dataset.llmAnswer2;
    var llmKey = colNum === 1 ? row.dataset.llmKey : row.dataset.llmKey2;
    if (!llmAnswer) { if (callback) callback(); return; }

    var model = colNum === 1 ? document.getElementById('oai-model-b').value : document.getElementById('oai-model-a').value;
    var valCol = row.querySelector('.oai-col-val' + colNum);
    valCol.textContent = 'Validating...';

    var fd = new FormData();
    fd.append('ajax_action', 'validate');
    fd.append('postid', postid);
    fd.append('model', model);
    fd.append('question_title', row.dataset.title);
    fd.append('question_content', row.dataset.content);
    fd.append('question_type', row.dataset.qtype);
    fd.append('answer_key', row.dataset.answerKey);
    fd.append('llm_answer', llmAnswer);
    fd.append('llm_key', llmKey);

    fetch(OAI_AJAX_URL, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var badge = oaiValidationBadge(data.validation_result);
                var vKey = data.validator_key ? ' Key:' + data.validator_key : '';
                valCol.innerHTML = '<details><summary>' + badge + vKey + '</summary><pre style="white-space:pre-wrap;font-size:11px;max-height:200px;overflow:auto;">' + oaiEscape(data.validation_text) + '</pre></details>';
                row.dataset['val' + colNum] = data.validation_result;
                row.dataset['val' + colNum + 'Key'] = data.validator_key || '';
                oaiTypeset(valCol);
                oaiUpdateAgentAnswer(row);
            } else {
                valCol.textContent = 'Error: ' + (data.error || 'Unknown');
            }
            if (callback) callback();
        })
        .catch(function(e) {
            valCol.textContent = 'Error: ' + e.message;
            if (callback) callback();
        });
}

function oaiGenerateB(row, callback) {
    var postid = row.dataset.postid;
    var qtype = row.dataset.qtype;
    var title = row.dataset.title;
    var qtext = row.dataset.content;
    var answerKey = row.dataset.answerKey;
    var modelB = document.getElementById('oai-model-b').value;

    var ansCol = row.querySelector('.oai-col-answer2');
    var keyCol = row.querySelector('.oai-col-llmkey2');
    ansCol.textContent = 'Generating (B)...';

    var fd = new FormData();
    fd.append('ajax_action', 'generate');
    fd.append('postid', postid);
    fd.append('model', modelB);
    fd.append('question_title', title);
    fd.append('question_content', qtext);
    fd.append('question_type', qtype);
    fd.append('answer_key', answerKey);
    if (oaiStyleExamples) fd.append('style_examples', oaiStyleExamples);

    fetch(OAI_AJAX_URL, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                ansCol.innerHTML = '<details><summary>View (' + modelB + ')</summary><pre style="white-space:pre-wrap;font-size:11px;max-height:300px;overflow:auto;">' + oaiEscape(data.answer) + '</pre></details>';
                keyCol.textContent = data.extracted_key || '?';
                var match = oaiKeysMatch(answerKey.toUpperCase(), data.extracted_key);
                keyCol.className = 'oai-col-llmkey2 ' + (match ? 'oai-match' : 'oai-mismatch');
                row.dataset.llmAnswer2 = data.answer;
                row.dataset.llmKey2 = data.extracted_key;
                oaiTypeset(ansCol);
            } else {
                ansCol.textContent = 'Error: ' + (data.error || 'Unknown');
                keyCol.textContent = '-';
            }
            if (callback) callback();
        })
        .catch(function(e) {
            ansCol.textContent = 'Error: ' + e.message;
            if (callback) callback();
        });
}

function oaiValidateB(row, callback) {
    oaiValidate(row, 2, callback);
}

function oaiUpdateAgentAnswer(row) {
    var agentCol = row.querySelector('.oai-col-agent');
    var val1 = row.dataset.val1;
    var val2 = row.dataset.val2;
    var keyA = row.dataset.llmKey;
    var keyB = row.dataset.llmKey2;
    var val1Key = row.dataset.val1Key;
    var val2Key = row.dataset.val2Key;
    var officialKey = row.dataset.answerKey;

    if (!val1 && !val2) return;

    var agentKey = '';

    // Both validations done
    if (val1 && val2) {
        if (val1 === 'CORRECT' && val2 === 'CORRECT') {
            agentKey = keyA;
        } else if (val1 === 'CORRECT' && val2 !== 'CORRECT') {
            agentKey = keyA;
        } else if (val1 !== 'CORRECT' && val2 === 'CORRECT') {
            agentKey = keyB;
        } else {
            if (val1Key && val2Key && oaiKeysMatch(val1Key, val2Key)) {
                agentKey = val1Key;
            } else {
                agentKey = '?';
            }
        }
    } else if (val1) {
        agentKey = (val1 === 'CORRECT') ? keyA : (val1Key || '?');
    } else if (val2) {
        agentKey = (val2 === 'CORRECT') ? keyB : (val2Key || '?');
    }

    if (agentKey && agentKey !== '?') {
        var match = oaiKeysMatch(officialKey.toUpperCase(), agentKey);
        agentCol.innerHTML = '<span class="' + (match ? 'oai-agent-match' : 'oai-agent-mismatch') + '">' + oaiEscape(agentKey) + '</span>';
    } else if (agentKey === '?') {
        agentCol.innerHTML = '<span style="color:#999;">?</span>';
    }
    row.dataset.agentKey = agentKey;

    // Show Add Answer button in actions column
    var actCol = row.querySelector('.oai-col-actions');
    if (agentKey && agentKey !== '?' && !row.dataset.answerAdded) {
        // Determine which answer text to use (from the validated correct model)
        var answerText = '';
        if (val1 === 'CORRECT') {
            answerText = row.dataset.llmAnswer || '';
        } else if (val2 === 'CORRECT') {
            answerText = row.dataset.llmAnswer2 || '';
        } else {
            answerText = row.dataset.llmAnswer || row.dataset.llmAnswer2 || '';
        }
        row.dataset.agentAnswer = answerText;
        actCol.innerHTML = '<button class="oai-add-btn" onclick="oaiAddAnswer(this.closest(\'tr\'))">Add Answer</button>';
    }
}

// ─── Add Answer to Q2A ──────────────────────────────────────────────

function oaiAddAnswer(row) {
    var postid = row.dataset.postid;
    var answerText = row.dataset.agentAnswer || '';
    var agentKey = row.dataset.agentKey || '';
    var handle = document.getElementById('oai-user-input').value.trim();
    var actCol = row.querySelector('.oai-col-actions');

    if (!answerText) { actCol.textContent = 'No answer'; return; }

    var btn = actCol.querySelector('.oai-add-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Adding...'; }

    var fd = new FormData();
    fd.append('ajax_action', 'add_answer');
    fd.append('postid', postid);
    fd.append('answer_text', answerText);
    fd.append('agent_key', agentKey);
    fd.append('handle', handle);

    fetch(OAI_AJAX_URL, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                row.dataset.answerAdded = '1';
                actCol.innerHTML = '<span class="oai-added">Added #' + data.answerid + (data.ec_filled ? ' +key' : '') + '</span>';
            } else {
                actCol.innerHTML = '<span style="color:#c62828;font-size:11px;">' + oaiEscape(data.error || 'Error') + '</span> <button class="oai-add-btn" onclick="oaiAddAnswer(this.closest(\'tr\'))">Retry</button>';
            }
        })
        .catch(function(e) {
            actCol.innerHTML = '<span style="color:#c62828;font-size:11px;">' + e.message + '</span>';
        });
}

function oaiAddAll() {
    var rows = oaiGetRows();
    oaiQueue = [];
    for (var i = 0; i < rows.length; i++) {
        if (rows[i].dataset.agentKey && rows[i].dataset.agentKey !== '?' && !rows[i].dataset.answerAdded && rows[i].dataset.agentAnswer) {
            oaiQueue.push({ row: rows[i], step: 'add', index: i });
        }
    }
    if (oaiQueue.length === 0) { oaiProgress('No answers to add.'); return; }
    oaiRunning = true;
    document.getElementById('oai-btn-stop').style.display = 'inline-block';
    oaiAddNext(0, oaiQueue.length);
}

function oaiAddNext(done, total) {
    if (!oaiRunning || oaiQueue.length === 0) {
        oaiRunning = false;
        document.getElementById('oai-btn-stop').style.display = 'none';
        oaiProgress('Added ' + done + '/' + total + ' answers.');
        return;
    }
    var item = oaiQueue.shift();
    done++;
    oaiProgress('Adding answers: ' + done + '/' + total + '...');
    oaiAddAnswer(item.row);
    // Small delay between adds to avoid overwhelming the server
    setTimeout(function() { oaiAddNext(done, total); }, 500);
}

// ─── Validate & Populate (Model A only) ─────────────────────────────

function oaiValidatePopulate() {
    var rows = oaiGetRows();
    var vpQueue = [];
    for (var i = 0; i < rows.length; i++) {
        // Skip rows that already have an answer added
        if (!rows[i].dataset.answerAdded) {
            vpQueue.push(rows[i]);
        }
    }
    if (vpQueue.length === 0) { oaiProgress('No questions to process.'); return; }
    oaiRunning = true;
    document.getElementById('oai-btn-stop').style.display = 'inline-block';
    oaiVPNext(vpQueue, 0, vpQueue.length, 0, 0);
}

function oaiVPNext(queue, done, total, matched, added) {
    if (!oaiRunning || queue.length === 0) {
        oaiRunning = false;
        document.getElementById('oai-btn-stop').style.display = 'none';
        oaiProgress('V&P Done! ' + done + '/' + total + ' processed, ' + matched + ' matched, ' + added + ' added.');
        oaiUpdateSummary();
        return;
    }
    var row = queue.shift();
    done++;
    oaiProgress('V&P: ' + done + '/' + total + ' (matched:' + matched + ' added:' + added + ')...');

    // Step 1: Generate with Model A
    var postid = row.dataset.postid;
    var qtype = row.dataset.qtype;
    var title = row.dataset.title;
    var qtext = row.dataset.content;
    var officialKey = row.dataset.answerKey;
    var model = document.getElementById('oai-model-a').value;
    var handle = document.getElementById('oai-user-input').value.trim();

    var ansCol = row.querySelector('.oai-col-answer');
    var keyCol = row.querySelector('.oai-col-llmkey');
    var agentCol = row.querySelector('.oai-col-agent');
    var actCol = row.querySelector('.oai-col-actions');
    ansCol.textContent = 'V&P Generating...';

    var fd = new FormData();
    fd.append('ajax_action', 'generate');
    fd.append('postid', postid);
    fd.append('model', model);
    fd.append('question_title', title);
    fd.append('question_content', qtext);
    fd.append('question_type', qtype);
    fd.append('answer_key', officialKey);
    if (oaiStyleExamples) fd.append('style_examples', oaiStyleExamples);

    fetch(OAI_AJAX_URL, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                ansCol.innerHTML = '<details><summary>View (' + model + ')</summary><pre style="white-space:pre-wrap;font-size:11px;max-height:300px;overflow:auto;">' + oaiEscape(data.answer) + '</pre></details>';
                keyCol.textContent = data.extracted_key || '?';
                var isMatch = oaiKeysMatch(officialKey.toUpperCase(), data.extracted_key);
                keyCol.className = 'oai-col-llmkey ' + (isMatch ? 'oai-match' : 'oai-mismatch');
                row.dataset.llmAnswer = data.answer;
                row.dataset.llmKey = data.extracted_key;
                row.dataset.generateModel = model;
                oaiTypeset(ansCol);

                if (isMatch) {
                    matched++;
                    agentCol.innerHTML = '<span class="oai-agent-match">' + oaiEscape(data.extracted_key) + '</span>';
                    row.dataset.agentKey = data.extracted_key;
                    row.dataset.agentAnswer = data.answer;

                    // Auto-add answer
                    actCol.textContent = 'Adding...';
                    var fd2 = new FormData();
                    fd2.append('ajax_action', 'add_answer');
                    fd2.append('postid', postid);
                    fd2.append('answer_text', data.answer);
                    fd2.append('agent_key', data.extracted_key);
                    fd2.append('handle', handle);

                    fetch(OAI_AJAX_URL, { method: 'POST', body: fd2 })
                        .then(function(r2) { return r2.json(); })
                        .then(function(d2) {
                            if (d2.success) {
                                added++;
                                row.dataset.answerAdded = '1';
                                actCol.innerHTML = '<span class="oai-added">Added #' + d2.answerid + (d2.ec_filled ? ' +key' : '') + '</span>';
                            } else {
                                actCol.innerHTML = '<span style="color:#c62828;font-size:11px;">' + oaiEscape(d2.error || 'Error') + '</span>';
                            }
                            oaiVPNext(queue, done, total, matched, added);
                        })
                        .catch(function(e) {
                            actCol.innerHTML = '<span style="color:#c62828;font-size:11px;">' + e.message + '</span>';
                            oaiVPNext(queue, done, total, matched, added);
                        });
                    return; // wait for add_answer callback
                } else {
                    agentCol.innerHTML = '<span class="oai-agent-mismatch">' + oaiEscape(data.extracted_key || '?') + '</span>';
                    row.dataset.agentKey = data.extracted_key || '?';
                }
            } else {
                ansCol.textContent = 'Error: ' + (data.error || 'Unknown');
                keyCol.textContent = '-';
            }
            oaiVPNext(queue, done, total, matched, added);
        })
        .catch(function(e) {
            ansCol.textContent = 'Error: ' + e.message;
            oaiVPNext(queue, done, total, matched, added);
        });
}

// ─── Utilities ──────────────────────────────────────────────────────

function oaiKeysMatch(official, llm) {
    if (!official || !llm) return false;
    official = official.trim();
    llm = llm.trim();

    // Numerical range check: "5:7" means any value between 5 and 7 is correct
    if (official.indexOf(':') !== -1) {
        var parts = official.split(':');
        if (parts.length === 2) {
            var lo = parseFloat(parts[0]);
            var hi = parseFloat(parts[1]);
            var val = parseFloat(llm);
            if (!isNaN(lo) && !isNaN(hi) && !isNaN(val)) {
                return val >= lo && val <= hi;
            }
        }
    }

    // Standard comparison (MCQ/MSQ letters)
    var norm = function(s) {
        return s.toUpperCase().split(';').map(function(x){return x.trim();}).sort().join(';');
    };
    return norm(official) === norm(llm);
}

function oaiValidationBadge(result) {
    var cls = 'oai-' + result.toLowerCase();
    return '<span class="' + cls + '">' + result + '</span>';
}

function oaiEscape(str) {
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function oaiUpdateSummary() {
    var rows = oaiGetRows();
    var total = rows.length;
    var stats = {
        generated: 0, keyMatchA: 0,
        generated2: 0, keyMatchB: 0,
        val1Done: 0, val1Correct: 0, val1Incorrect: 0, val1Partial: 0,
        val2Done: 0, val2Correct: 0, val2Incorrect: 0, val2Partial: 0,
        agentDone: 0, agentMatch: 0, added: 0
    };

    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        if (r.dataset.llmKey) {
            stats.generated++;
            if (oaiKeysMatch(r.dataset.answerKey, r.dataset.llmKey)) stats.keyMatchA++;
        }
        if (r.dataset.llmKey2) {
            stats.generated2++;
            if (oaiKeysMatch(r.dataset.answerKey, r.dataset.llmKey2)) stats.keyMatchB++;
        }
        if (r.dataset.val1) {
            stats.val1Done++;
            if (r.dataset.val1 === 'CORRECT') stats.val1Correct++;
            else if (r.dataset.val1 === 'INCORRECT') stats.val1Incorrect++;
            else if (r.dataset.val1 === 'PARTIAL') stats.val1Partial++;
        }
        if (r.dataset.val2) {
            stats.val2Done++;
            if (r.dataset.val2 === 'CORRECT') stats.val2Correct++;
            else if (r.dataset.val2 === 'INCORRECT') stats.val2Incorrect++;
            else if (r.dataset.val2 === 'PARTIAL') stats.val2Partial++;
        }
        if (r.dataset.agentKey && r.dataset.agentKey !== '?') {
            stats.agentDone++;
            if (oaiKeysMatch(r.dataset.answerKey, r.dataset.agentKey)) stats.agentMatch++;
        }
        if (r.dataset.answerAdded) stats.added++;
    }

    var div = document.getElementById('oai-summary');
    if (stats.generated === 0 && stats.generated2 === 0) { div.style.display = 'none'; return; }
    div.style.display = 'block';

    var pct = function(n, d) { return d > 0 ? (n / d * 100).toFixed(1) + '%' : '-'; };

    var modelA = document.getElementById('oai-model-a').value;
    var modelB = document.getElementById('oai-model-b').value;

    var h = '<h3 style="margin-top:0;">Summary</h3>';
    h += '<table border="1" cellpadding="8" style="border-collapse:collapse; font-size:14px;">';
    h += '<tr style="background:#e3f2fd;"><th>Metric</th><th>Count</th><th>Percentage</th></tr>';

    if (stats.generated > 0) {
        h += '<tr><td>Model A (' + oaiEscape(modelA) + ') Key Match vs Official</td><td>' + stats.keyMatchA + ' / ' + stats.generated + '</td><td><strong>' + pct(stats.keyMatchA, stats.generated) + '</strong></td></tr>';
    }
    if (stats.generated2 > 0) {
        h += '<tr><td>Model B (' + oaiEscape(modelB) + ') Key Match vs Official</td><td>' + stats.keyMatchB + ' / ' + stats.generated2 + '</td><td><strong>' + pct(stats.keyMatchB, stats.generated2) + '</strong></td></tr>';
    }

    if (stats.val1Done > 0) {
        h += '<tr style="background:#f5f5f5;"><td colspan="3"><strong>Validation\u2081 (Model B validates Model A)</strong></td></tr>';
        h += '<tr><td style="padding-left:20px;">Correct</td><td>' + stats.val1Correct + ' / ' + stats.val1Done + '</td><td>' + pct(stats.val1Correct, stats.val1Done) + '</td></tr>';
        h += '<tr><td style="padding-left:20px;">Incorrect</td><td>' + stats.val1Incorrect + ' / ' + stats.val1Done + '</td><td>' + pct(stats.val1Incorrect, stats.val1Done) + '</td></tr>';
        h += '<tr><td style="padding-left:20px;">Partial</td><td>' + stats.val1Partial + ' / ' + stats.val1Done + '</td><td>' + pct(stats.val1Partial, stats.val1Done) + '</td></tr>';
    }

    if (stats.val2Done > 0) {
        h += '<tr style="background:#f5f5f5;"><td colspan="3"><strong>Validation\u2082 (Model A validates Model B)</strong></td></tr>';
        h += '<tr><td style="padding-left:20px;">Correct</td><td>' + stats.val2Correct + ' / ' + stats.val2Done + '</td><td>' + pct(stats.val2Correct, stats.val2Done) + '</td></tr>';
        h += '<tr><td style="padding-left:20px;">Incorrect</td><td>' + stats.val2Incorrect + ' / ' + stats.val2Done + '</td><td>' + pct(stats.val2Incorrect, stats.val2Done) + '</td></tr>';
        h += '<tr><td style="padding-left:20px;">Partial</td><td>' + stats.val2Partial + ' / ' + stats.val2Done + '</td><td>' + pct(stats.val2Partial, stats.val2Done) + '</td></tr>';
    }

    if (stats.agentDone > 0) {
        h += '<tr style="background:#e8f5e9;"><td><strong>Agent Answer Match vs Official</strong></td><td><strong>' + stats.agentMatch + ' / ' + stats.agentDone + '</strong></td><td><strong>' + pct(stats.agentMatch, stats.agentDone) + '</strong></td></tr>';
    }

    if (stats.added > 0) {
        h += '<tr style="background:#e3f2fd;"><td><strong>Answers Added to Q2A</strong></td><td><strong>' + stats.added + '</strong></td><td>-</td></tr>';
    }

    h += '</table>';
    div.innerHTML = h;
}
</script>
JSEOF;
    }
}
