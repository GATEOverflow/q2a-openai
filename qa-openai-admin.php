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

            // Seed the 7 original configs
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
        }

        return empty($queries) ? null : $queries;
    }

    /**
     * Admin settings form – just the API key for now; configs are on a separate page.
     */
    public function admin_form(&$qa_content)
    {
        $saved = false;

        if (qa_clicked('openai_save')) {
            qa_opt('openai_api_key', qa_post_text('openai_api_key'));
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
        ];
    }
}
