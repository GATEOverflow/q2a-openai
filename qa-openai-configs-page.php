<?php
/**
 * Admin page for viewing and editing OpenAI prompt configurations.
 *
 * URL: /admin/openai-configs          – list all configs
 * URL: /admin/openai-configs?edit=N   – edit config N
 * URL: /admin/openai-configs?add=1    – add new config
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

class qa_openai_configs_page
{
    public function suggest_requests()
    {
        return [
            [
                'title'   => 'OpenAI Configs',
                'request' => 'admin/openai-configs',
                'nav'     => null,
            ],
        ];
    }

    public function match_request($request)
    {
        return $request === 'admin/openai-configs';
    }

    public function process_request($request)
    {
        if (qa_get_logged_in_level() < QA_USER_LEVEL_ADMIN) {
            return include QA_INCLUDE_DIR . 'qa-page-not-found.php';
        }

        $qa_content = qa_content_prepare();

        // Route to the appropriate handler
        if (qa_post_text('openai_config_save')) {
            return $this->handle_save($qa_content);
        }
        if (qa_post_text('openai_config_delete')) {
            return $this->handle_delete($qa_content);
        }

        $edit_id = qa_get('edit');
        $add     = qa_get('add');

        if ($add) {
            return $this->render_form($qa_content, null);
        }
        if ($edit_id !== null && $edit_id !== '') {
            return $this->render_form($qa_content, (int) $edit_id);
        }

        return $this->render_list($qa_content);
    }

    // ─── List all configs ───────────────────────────────────────────────

    private function render_list(&$qa_content)
    {
        $qa_content['title'] = 'OpenAI Prompt Configurations';

        $rows = qa_db_read_all_assoc(
            qa_db_query_sub('SELECT * FROM ^openai_configs ORDER BY id')
        );

        $base = qa_path('admin/openai-configs');

        $html = '<p><a href="' . qa_html($base) . '?add=1" style="font-weight:bold;">+ Add New Config</a></p>';

        $html .= '<table style="width:100%; border-collapse:collapse; font-size:14px;">';
        $html .= '<thead><tr style="background:#f5f5f5; border-bottom:2px solid #ddd;">';
        $html .= '<th style="padding:8px; text-align:left;">ID</th>';
        $html .= '<th style="padding:8px; text-align:left;">Label</th>';
        $html .= '<th style="padding:8px; text-align:left;">Model</th>';
        $html .= '<th style="padding:8px; text-align:left;">Max Tokens</th>';
        $html .= '<th style="padding:8px; text-align:left;">Temp</th>';
        $html .= '<th style="padding:8px; text-align:left;">System Prompt</th>';
        $html .= '<th style="padding:8px; text-align:left;">Updated</th>';
        $html .= '<th style="padding:8px; text-align:left;">Actions</th>';
        $html .= '</tr></thead><tbody>';

        if (empty($rows)) {
            $html .= '<tr><td colspan="8" style="padding:20px; text-align:center; color:#888;">No configs found.</td></tr>';
        }

        foreach ($rows as $row) {
            $sys_preview = qa_html(mb_substr($row['system_prompt'], 0, 80));
            if (mb_strlen($row['system_prompt']) > 80) $sys_preview .= '&hellip;';

            $edit_url = $base . '?edit=' . (int) $row['id'];

            $html .= '<tr style="border-bottom:1px solid #eee;">';
            $html .= '<td style="padding:8px;">' . (int) $row['id'] . '</td>';
            $html .= '<td style="padding:8px; font-weight:bold;">' . qa_html($row['label']) . '</td>';
            $html .= '<td style="padding:8px;">' . qa_html($row['model']) . '</td>';
            $html .= '<td style="padding:8px;">' . (int) $row['max_tokens'] . '</td>';
            $html .= '<td style="padding:8px;">' . qa_html($row['temperature']) . '</td>';
            $html .= '<td style="padding:8px; max-width:300px; word-wrap:break-word;">' . $sys_preview . '</td>';
            $html .= '<td style="padding:8px; white-space:nowrap;">' . qa_html($row['updated']) . '</td>';
            $html .= '<td style="padding:8px; white-space:nowrap;"><a href="' . qa_html($edit_url) . '">Edit</a></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        $qa_content['custom'] = $html;
        return $qa_content;
    }

    // ─── Edit / Add form ────────────────────────────────────────────────

    private function render_form(&$qa_content, $id)
    {
        $config = null;
        if ($id !== null) {
            $config = qa_db_read_one_assoc(
                qa_db_query_sub('SELECT * FROM ^openai_configs WHERE id = #', $id),
                true
            );
            if (!$config) {
                $qa_content['title'] = 'Config Not Found';
                $qa_content['custom'] = '<p>Config #' . (int) $id . ' does not exist. <a href="' . qa_html(qa_path('admin/openai-configs')) . '">Back to list</a></p>';
                return $qa_content;
            }
        }

        $is_new = ($config === null);
        $qa_content['title'] = $is_new ? 'Add OpenAI Config' : 'Edit OpenAI Config: ' . qa_html($config['label']);

        $base = qa_path('admin/openai-configs');
        $form_action = $base;

        $val = function ($field, $default = '') use ($config) {
            return $config ? $config[$field] : $default;
        };

        $html  = '<form method="post" action="' . qa_html($form_action) . '">';
        $html .= '<input type="hidden" name="config_id" value="' . ($is_new ? '' : (int) $id) . '">';

        $html .= '<table style="width:100%; max-width:800px; border-collapse:collapse;">';

        $html .= $this->form_row('Label', 'text', 'config_label', $val('label'), 'A short human-readable name');
        $html .= $this->form_row('Model', 'text', 'config_model', $val('model', 'gpt-4o'), 'e.g. gpt-4o, gpt-4o-mini, gemini-2.5-flash, gemini-2.5-pro');
        $html .= $this->form_row('Max Tokens', 'number', 'config_max_tokens', $val('max_tokens', 2000));
        $html .= $this->form_row('Temperature', 'text', 'config_temperature', $val('temperature', '0.70'), '0.0 – 2.0');

        // Textareas
        $html .= '<tr><td style="padding:8px; vertical-align:top; font-weight:bold;">System Prompt</td>';
        $html .= '<td style="padding:8px;"><textarea name="config_system_prompt" rows="6" style="width:100%; font-family:monospace;">' . qa_html($val('system_prompt')) . '</textarea></td></tr>';

        $html .= '<tr><td style="padding:8px; vertical-align:top; font-weight:bold;">User Prompt</td>';
        $html .= '<td style="padding:8px;"><textarea name="config_user_prompt" rows="4" style="width:100%; font-family:monospace;">' . qa_html($val('user_prompt')) . '</textarea>';
        $html .= '<br><small style="color:#888;">Use <code>{{ MESSAGE }}</code> as a placeholder for the input text.</small></td></tr>';

        $html .= '</table>';

        $html .= '<div style="margin-top:15px;">';
        $html .= '<button type="submit" name="openai_config_save" value="1" style="padding:8px 20px; font-size:14px;">Save Config</button>';

        if (!$is_new) {
            $html .= ' &nbsp; <button type="submit" name="openai_config_delete" value="1" onclick="return confirm(\'Delete this config permanently?\');" style="padding:8px 20px; font-size:14px; color:#c62828;">Delete</button>';
        }

        $html .= ' &nbsp; <a href="' . qa_html($base) . '">Cancel</a>';
        $html .= '</div>';
        $html .= '</form>';

        $qa_content['custom'] = $html;
        return $qa_content;
    }

    private function form_row($label, $type, $name, $value, $hint = '')
    {
        $html  = '<tr><td style="padding:8px; font-weight:bold;">' . qa_html($label) . '</td>';
        $html .= '<td style="padding:8px;"><input type="' . $type . '" name="' . $name . '" value="' . qa_html($value) . '" style="width:100%;">';
        if ($hint) $html .= '<br><small style="color:#888;">' . qa_html($hint) . '</small>';
        $html .= '</td></tr>';
        return $html;
    }

    // ─── Save handler ───────────────────────────────────────────────────

    private function handle_save(&$qa_content)
    {
        $id            = qa_post_text('config_id');
        $label         = trim(qa_post_text('config_label'));
        $model         = trim(qa_post_text('config_model')) ?: 'gpt-4o';
        $max_tokens    = (int) qa_post_text('config_max_tokens') ?: 2000;
        $temperature   = (float) qa_post_text('config_temperature');
        $system_prompt = qa_post_text('config_system_prompt');
        $user_prompt   = qa_post_text('config_user_prompt');

        if ($temperature < 0) $temperature = 0;
        if ($temperature > 2) $temperature = 2;

        if ($id !== '' && $id !== null) {
            // Update existing
            qa_db_query_sub(
                'UPDATE ^openai_configs SET label=$, model=$, system_prompt=$, user_prompt=$, max_tokens=#, temperature=# WHERE id=#',
                $label, $model, $system_prompt, $user_prompt, $max_tokens, $temperature, (int) $id
            );
        } else {
            // Insert new
            qa_db_query_sub(
                'INSERT INTO ^openai_configs (label, model, system_prompt, user_prompt, max_tokens, temperature) VALUES ($, $, $, $, #, #)',
                $label, $model, $system_prompt, $user_prompt, $max_tokens, $temperature
            );
        }

        // Redirect back to list
        header('Location: ' . qa_path('admin/openai-configs', null, qa_opt('site_url')));
        exit;
    }

    // ─── Delete handler ─────────────────────────────────────────────────

    private function handle_delete(&$qa_content)
    {
        $id = (int) qa_post_text('config_id');
        if ($id > 0) {
            qa_db_query_sub('DELETE FROM ^openai_configs WHERE id = #', $id);
        }

        header('Location: ' . qa_path('admin/openai-configs', null, qa_opt('site_url')));
        exit;
    }
}
