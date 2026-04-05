<?php
/**
 * AJAX page handler for OpenAI Integration plugin.
 *
 * Handles two actions via POST at URL: openai-ajax
 *   - generate_answer : Generate an AI answer for a question (admin only)
 *   - generate_summary: Generate an AI summary of a question thread (all users)
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

class qa_openai_ajax_page
{
    public function suggest_requests()
    {
        return [];
    }

    public function match_request($request)
    {
        return $request === 'openai-ajax';
    }

    public function process_request($request)
    {
        header('Content-Type: application/json; charset=utf-8');

        $action = qa_post_text('action');

        switch ($action) {
            case 'generate_answer':
                $this->handle_generate_answer();
                break;

            case 'generate_summary':
                $this->handle_generate_summary();
                break;

            default:
                echo json_encode(['success' => false, 'error' => 'Invalid action.']);
                break;
        }

        qa_exit();
    }

    /**
     * Generate an AI answer for a given question. Admin only.
     */
    private function handle_generate_answer()
    {
        // Check user level access
        $user_level = qa_get_logged_in_level();
        $min_level = (int) qa_opt('openai_generate_min_level');
        if ($min_level <= 0) {
            $min_level = QA_USER_LEVEL_ADMIN;
        }
        if ($user_level < $min_level) {
            echo json_encode(['success' => false, 'error' => 'Insufficient permission to generate answers.']);
            return;
        }

        $postid = (int) qa_post_text('postid');
        if ($postid <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid question ID.']);
            return;
        }

        // Load the question
        require_once QA_INCLUDE_DIR . 'app/posts.php';
        $question = qa_post_get_full($postid, 'Q');

        if (empty($question)) {
            echo json_encode(['success' => false, 'error' => 'Question not found.']);
            return;
        }

        // Build the message to send to OpenAI
        $title = isset($question['title']) ? $question['title'] : '';
        $content = isset($question['content']) ? $question['content'] : '';

        // Extract image URLs before stripping HTML
        $image_urls = array();
        if (preg_match_all('/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                $image_urls[] = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
            }
        }

        // Strip HTML tags for cleaner input
        $content_text = strip_tags($content);

        $message = "Question Title: " . $title . "\n\n" . "Question Content:\n" . $content_text;

        if (!empty($image_urls)) {
            $message .= "\n\n--- Images in Question ---\n";
            foreach ($image_urls as $i => $url) {
                $message .= "Image " . ($i + 1) . ": " . $url . "\n";
            }
        }

        // Also include existing answers for context (if any)
        $answers = qa_db_read_all_assoc(
            qa_db_query_sub(
                'SELECT content, format FROM ^posts WHERE parentid=# AND type=$ ORDER BY created ASC LIMIT 10',
                $postid, 'A'
            )
        );

        if (!empty($answers)) {
            $message .= "\n\n--- Existing Answers ---\n";
            foreach ($answers as $i => $ans) {
                $ans_text = strip_tags($ans['content']);
                $message .= "\nAnswer " . ($i + 1) . ":\n" . $ans_text . "\n";
            }
            $message .= "\nPlease provide a comprehensive answer that adds value beyond the existing answers.";
        }

        // Get the config ID for answer generation
        $config_id = (int) qa_opt('openai_answer_config_id');
        if ($config_id <= 0) {
            // Fall back to looking up by label
            $config_id = self::get_config_id_by_label('Answer generation');
        }
        if ($config_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Answer generation config not found. Please set it in Admin > Plugins > OpenAI Integration.']);
            return;
        }

        // Call OpenAI
        if (!function_exists('openai_call')) {
            echo json_encode(['success' => false, 'error' => 'OpenAI core not loaded.']);
            return;
        }

        $result = openai_call($message, $config_id, $image_urls);

        if (strpos($result, 'Error:') === 0 || strpos($result, 'cURL error:') === 0 || strpos($result, 'OpenAI API error:') === 0) {
            echo json_encode(['success' => false, 'error' => $result]);
            return;
        }

        echo json_encode(['success' => true, 'answer' => $result]);
    }

    /**
     * Generate an AI summary of the question thread (all logged-in users).
     */
    private function handle_generate_summary()
    {
        $userid = qa_get_logged_in_userid();
        if (empty($userid)) {
            echo json_encode(['success' => false, 'error' => 'Please log in to use AI summary.']);
            return;
        }

        $postid = (int) qa_post_text('postid');
        if ($postid <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid question ID.']);
            return;
        }

        // Check cache first (valid for 24 hours)
        $cached = self::get_cached_summary($postid);
        if ($cached !== null) {
            echo json_encode(['success' => true, 'summary' => $cached, 'cached' => true]);
            return;
        }

        // Load the question
        require_once QA_INCLUDE_DIR . 'app/posts.php';
        $question = qa_post_get_full($postid, 'Q');

        if (empty($question)) {
            echo json_encode(['success' => false, 'error' => 'Question not found.']);
            return;
        }

        // Build the full thread text
        $title = isset($question['title']) ? $question['title'] : '';
        $content = strip_tags(isset($question['content']) ? $question['content'] : '');

        $thread_text = "QUESTION: " . $title . "\n" . $content;

        // Get question comments
        $q_comments = qa_db_read_all_assoc(
            qa_db_query_sub(
                'SELECT content, format FROM ^posts WHERE parentid=# AND type=$ ORDER BY created ASC',
                $postid, 'C'
            )
        );

        if (!empty($q_comments)) {
            $thread_text .= "\n\nCOMMENTS ON QUESTION:\n";
            foreach ($q_comments as $i => $c) {
                $thread_text .= "- " . strip_tags($c['content']) . "\n";
            }
        }

        // Get answers and their comments
        $answers = qa_db_read_all_assoc(
            qa_db_query_sub(
                'SELECT postid, content, format, netvotes FROM ^posts WHERE parentid=# AND type=$ ORDER BY netvotes DESC, created ASC',
                $postid, 'A'
            )
        );

        if (!empty($answers)) {
            foreach ($answers as $i => $ans) {
                $vote_info = (int) $ans['netvotes'] > 0 ? " (+" . $ans['netvotes'] . " votes)" : "";
                $thread_text .= "\n\nANSWER " . ($i + 1) . $vote_info . ":\n" . strip_tags($ans['content']);

                // Get comments on this answer
                $a_comments = qa_db_read_all_assoc(
                    qa_db_query_sub(
                        'SELECT content, format FROM ^posts WHERE parentid=# AND type=$ ORDER BY created ASC',
                        (int) $ans['postid'], 'C'
                    )
                );

                if (!empty($a_comments)) {
                    $thread_text .= "\n  Comments on this answer:\n";
                    foreach ($a_comments as $c) {
                        $thread_text .= "  - " . strip_tags($c['content']) . "\n";
                    }
                }
            }
        }

        // Truncate if too long (to stay within token limits)
        if (mb_strlen($thread_text) > 15000) {
            $thread_text = mb_substr($thread_text, 0, 15000) . "\n\n[Thread truncated due to length...]";
        }

        // Get the config ID for summary generation
        $config_id = (int) qa_opt('openai_summary_config_id');
        if ($config_id <= 0) {
            // Fall back to looking up by label
            $config_id = self::get_config_id_by_label('Thread summary');
        }
        if ($config_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Thread summary config not found. Please set it in Admin > Plugins > OpenAI Integration.']);
            return;
        }

        // Call OpenAI
        if (!function_exists('openai_call')) {
            echo json_encode(['success' => false, 'error' => 'OpenAI core not loaded.']);
            return;
        }

        $result = openai_call($thread_text, $config_id);

        if (strpos($result, 'Error:') === 0 || strpos($result, 'cURL error:') === 0 || strpos($result, 'OpenAI API error:') === 0) {
            echo json_encode(['success' => false, 'error' => $result]);
            return;
        }

        // Send raw text — the client-side JS handles safe rendering and MathJax typesetting
        // Cache the result before returning
        self::set_cached_summary($postid, $result);

        echo json_encode(['success' => true, 'summary' => $result]);
    }

    /**
     * Look up config ID by label name.
     */
    private static function get_config_id_by_label($label)
    {
        $row = qa_db_read_one_assoc(
            qa_db_query_sub('SELECT id FROM ^openai_configs WHERE label = $ LIMIT 1', $label),
            true
        );
        return $row ? (int) $row['id'] : 0;
    }

    /**
     * Get cached summary for a question (returns null if expired or missing).
     */
    private static function get_cached_summary($postid)
    {
        $cache_hours = 24;
        $row = qa_db_read_one_assoc(
            qa_db_query_sub(
                'SELECT content, created FROM ^openai_cache WHERE postid=# AND cache_type=$ AND created > NOW() - INTERVAL # HOUR',
                $postid, 'summary', $cache_hours
            ),
            true
        );
        return $row ? $row['content'] : null;
    }

    /**
     * Store a cached summary for a question.
     */
    private static function set_cached_summary($postid, $content)
    {
        qa_db_query_sub(
            'INSERT INTO ^openai_cache (postid, cache_type, content, created) VALUES (#, $, $, NOW()) '
            . 'ON DUPLICATE KEY UPDATE content=$, created=NOW()',
            $postid, 'summary', $content, $content
        );
    }
}
