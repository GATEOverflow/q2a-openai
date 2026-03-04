<?php
/**
 * Admin module for OpenAI Integration plugin.
 *
 * – Creates the ^openai_configs table
 * – Seeds the 7 default configs from the old YAML files
 * – Provides the API key settings form
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

class qa_openai_admin
{
    public function option_default($option)
    {
        switch ($option) {
            case 'openai_api_key':
                return '';
            case 'openai_answer_config_id':
                return 7;
            case 'openai_summary_config_id':
                return 8;
            case 'openai_summary_threshold':
                return 5;
            case 'openai_generate_min_level':
                return QA_USER_LEVEL_ADMIN;
            default:
                return null;
        }
    }

    public function allow_template($template)
    {
        return ($template != 'admin');
    }

    /**
     * Create the configs table and seed defaults on first run.
     */
    public function init_queries($tableslc)
    {
        $tablename = qa_db_add_table_prefix('openai_configs');
        $queries = [];

        if (!in_array($tablename, $tableslc)) {
            $queries[] = "CREATE TABLE IF NOT EXISTS $tablename (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                label        VARCHAR(100)  NOT NULL DEFAULT '' COMMENT 'Human-readable name',
                model        VARCHAR(50)   NOT NULL DEFAULT 'gpt-4o',
                system_prompt TEXT         NOT NULL,
                user_prompt   TEXT         NOT NULL,
                max_tokens   INT UNSIGNED  NOT NULL DEFAULT 2000,
                temperature  DECIMAL(3,2)  NOT NULL DEFAULT 0.70,
                updated      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_label (label)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            // Seed all configs (including answer generation and thread summary)
            $seeds = self::get_seed_configs();
            foreach ($seeds as $seed) {
                $queries[] = "INSERT INTO $tablename (id, label, model, system_prompt, user_prompt, max_tokens, temperature)
                    VALUES (" . (int) $seed['id'] . ", "
                    . "'" . addslashes($seed['label']) . "', "
                    . "'" . addslashes($seed['model']) . "', "
                    . "'" . addslashes($seed['system_prompt']) . "', "
                    . "'" . addslashes($seed['user_prompt']) . "', "
                    . (int) $seed['max_tokens'] . ", "
                    . (float) $seed['temperature'] . ")";
            }
        } else {
            // Table exists — seed any configs that are missing by label
            $new_labels = ['Answer generation', 'Thread summary'];
            $seeds = self::get_seed_configs();
            foreach ($seeds as $seed) {
                if (in_array($seed['label'], $new_labels)) {
                    $queries[] = "INSERT INTO $tablename (label, model, system_prompt, user_prompt, max_tokens, temperature)
                        SELECT "
                        . "'" . addslashes($seed['label']) . "', "
                        . "'" . addslashes($seed['model']) . "', "
                        . "'" . addslashes($seed['system_prompt']) . "', "
                        . "'" . addslashes($seed['user_prompt']) . "', "
                        . (int) $seed['max_tokens'] . ", "
                        . (float) $seed['temperature']
                        . " FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM $tablename WHERE label = '" . addslashes($seed['label']) . "')";
                }
            }
        }

        // Ensure the cache table exists
        self::ensure_cache_table($queries, $tableslc);

        return empty($queries) ? null : $queries;
    }

    /**
     * Ensure the cache table exists. Called from init_queries.
     */
    private static function ensure_cache_table(&$queries, $tableslc)
    {
        $cache_table = qa_db_add_table_prefix('openai_cache');
        if (!in_array($cache_table, $tableslc)) {
            $queries[] = "CREATE TABLE IF NOT EXISTS $cache_table (
                postid       INT UNSIGNED NOT NULL,
                cache_type   VARCHAR(30)  NOT NULL DEFAULT 'summary',
                content      MEDIUMTEXT   NOT NULL,
                created      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (postid, cache_type),
                INDEX idx_created (created)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }
    }

    /**
     * Admin settings form – just the API key for now; configs are on a separate page.
     */
    public function admin_form(&$qa_content)
    {
        $saved = false;

        if (qa_clicked('openai_save')) {
            qa_opt('openai_api_key', qa_post_text('openai_api_key'));
            qa_opt('openai_answer_config_id', (int) qa_post_text('openai_answer_config_id'));
            qa_opt('openai_summary_config_id', (int) qa_post_text('openai_summary_config_id'));
            qa_opt('openai_summary_threshold', (int) qa_post_text('openai_summary_threshold'));
            qa_opt('openai_generate_min_level', (int) qa_post_text('openai_generate_min_level'));
            $saved = true;
        }

        $config_url = qa_path('admin/openai-configs');

        return [
            'ok'     => $saved ? 'Settings saved.' : null,
            'fields' => [
                [
                    'label' => 'OpenAI API Key:',
                    'type'  => 'text',
                    'tags'  => 'name="openai_api_key" style="width:500px;"',
                    'value' => qa_opt('openai_api_key'),
                ],
                [
                    'label' => 'Answer Generation Config ID:',
                    'type'  => 'number',
                    'tags'  => 'name="openai_answer_config_id"',
                    'value' => qa_opt('openai_answer_config_id'),
                    'note'  => 'Config ID from OpenAI Configs used for generating answers (default: 7)',
                ],
                [
                    'label' => 'Thread Summary Config ID:',
                    'type'  => 'number',
                    'tags'  => 'name="openai_summary_config_id"',
                    'value' => qa_opt('openai_summary_config_id'),
                    'note'  => 'Config ID from OpenAI Configs used for thread summaries (default: 8)',
                ],
                [
                    'label' => 'Summary Threshold:',
                    'type'  => 'number',
                    'tags'  => 'name="openai_summary_threshold"',
                    'value' => qa_opt('openai_summary_threshold'),
                    'note'  => 'Minimum number of answers or comments to show AI Summary button (default: 5)',
                ],
                [
                    'label' => 'Generate Answer – Minimum User Level:',
                    'type'  => 'select',
                    'tags'  => 'name="openai_generate_min_level"',
                    'options' => [
                        QA_USER_LEVEL_EXPERT     => 'Expert',
                        QA_USER_LEVEL_EDITOR     => 'Editor',
                        QA_USER_LEVEL_MODERATOR  => 'Moderator',
                        QA_USER_LEVEL_ADMIN      => 'Admin',
                        QA_USER_LEVEL_SUPER      => 'Super Admin',
                    ],
                    'value' => (int) qa_opt('openai_generate_min_level'),
                    'match_by' => 'key',
                    'note'  => 'Minimum user level to see the "Generate AI Answer" button (default: Admin)',
                ],
                [
                    'type' => 'static',
                    'label' => 'Prompt Configs:',
                    'value' => '<a href="' . qa_html($config_url) . '">Manage OpenAI prompt configurations &rarr;</a>',
                ],
            ],
            'buttons' => [
                [
                    'label' => 'Save',
                    'tags'  => 'name="openai_save"',
                ],
            ],
        ];
    }

    /**
     * Default seed data matching the 7 YAML configs that previously lived in publish-to-email/.
     */
    private static function get_seed_configs()
    {
        return [
            [
                'id' => 1,
                'label' => 'Blog summary',
                'model' => 'gpt-4o',
                'system_prompt' => 'You are tasked with summarising a blog post to generate a short summary for link preview. First, carefully read the post content enclosed within <mypost></mypost>',
                'user_prompt' => '<mypost>{{ MESSAGE }}</mypost>',
                'max_tokens' => 2000,
                'temperature' => 0.7,
            ],
            [
                'id' => 2,
                'label' => 'Exam summary',
                'model' => 'gpt-4o',
                'system_prompt' => 'You are tasked with creating a standard summary for an exam link given the title enclosed within <mypost></mypost>. Please keep your reply short and precise without much exaggeration.',
                'user_prompt' => '<mypost>{{ MESSAGE }}</mypost>',
                'max_tokens' => 150,
                'temperature' => 0.7,
            ],
            [
                'id' => 3,
                'label' => 'Today in history (CS)',
                'model' => 'gpt-4o',
                'system_prompt' => 'consider todays exact date and reply to the user content. Include appropriate title and hashtags',
                'user_prompt' => 'can you please make a post to highlight a historical event which happened on this same date and month as today, targetting computer science and engineering students from India? Put your reply between <<<< and >>>>. Please double check to make sure the event indeed happened on today\'s date',
                'max_tokens' => 800,
                'temperature' => 0.2,
            ],
            [
                'id' => 4,
                'label' => 'Today in history (Exam)',
                'model' => 'gpt-4o',
                'system_prompt' => 'consider todays exact date and reply to the user content.',
                'user_prompt' => 'can you please make a post to highlight a historical event of importance which happened on the same date and month as {{ MESSAGE }}? The response must be targetting exam aspirants in india. Put your reply between <<<< and >>>>. Please double check to ensure the event indeed happened on the said date',
                'max_tokens' => 2000,
                'temperature' => 0.7,
            ],
            [
                'id' => 5,
                'label' => 'GATE motivational quote',
                'model' => 'gpt-4o',
                'system_prompt' => 'Our audience is Engineering students preparing for GATE examination in February 2027.',
                'user_prompt' => 'Todays date is exactly {{ MESSAGE }}. Can you please make a motivational quote to encourage them? Put your reply between <<<< and >>>> and include sensible hashtabs and headings',
                'max_tokens' => 2000,
                'temperature' => 0.7,
            ],
            [
                'id' => 6,
                'label' => 'Spam detection',
                'model' => 'gpt-4o',
                'system_prompt' => 'You are a spam detection assistant for a Q&A website. Analyze the given post and determine whether it is spam or not. Your response MUST begin with exactly one of these two words: "SPAM" or "NOT SPAM", followed by a brief explanation. Spam includes: promotional content, irrelevant advertising, link farming, gibberish, or off-topic solicitations. Legitimate posts include: genuine questions, answers, or discussions related to academic or technical topics, even if poorly written.',
                'user_prompt' => 'Please analyze this post for spam:\n\n{{ MESSAGE }}',
                'max_tokens' => 300,
                'temperature' => 0.3,
            ],
            [
                'id' => 7,
                'label' => 'Answer generation',
                'model' => 'gpt-4o',
                'system_prompt' => 'You are a knowledgeable assistant for a Q&A website focused on computer science, GATE exam preparation, and engineering topics. Generate a clear, accurate, and well-structured answer to the given question. Use proper formatting with paragraphs. If the question involves code, include relevant code snippets. If there are existing answers, provide additional value or a better explanation. Be precise and educational.',
                'user_prompt' => '{{ MESSAGE }}',
                'max_tokens' => 3000,
                'temperature' => 0.5,
            ],
            [
                'id' => 8,
                'label' => 'Thread summary',
                'model' => 'gpt-4o',
                'system_prompt' => 'You are a concise summarizer for a Q&A discussion thread. Summarize the key points from the question, answers, and comments. Highlight the most important conclusions, areas of agreement, points of disagreement, and the best answer if one is apparent. Keep the summary informative but concise (3-8 sentences). Use clear, simple language.',
                'user_prompt' => 'Please summarize this Q&A thread:\n\n{{ MESSAGE }}',
                'max_tokens' => 1000,
                'temperature' => 0.3,
            ],
        ];
    }
}
