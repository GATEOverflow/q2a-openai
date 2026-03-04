<?php
/**
 * Standalone OpenAI API integration for Q2A.
 *
 * Provides the global `openai_call($message, $configid)` function used by
 * other plugins, and an admin UI to manage the API key and prompt configs.
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

// Register admin module (DB setup + settings)
qa_register_plugin_module('module', 'qa-openai-admin.php', 'qa_openai_admin', 'OpenAI Integration');

// Register admin page for managing configs
qa_register_plugin_module('page', 'qa-openai-configs-page.php', 'qa_openai_configs_page', 'OpenAI Configs Page');

// Register AJAX page handler for AI answer generation & summary
qa_register_plugin_module('page', 'qa-openai-ajax.php', 'qa_openai_ajax_page', 'OpenAI AJAX Handler');

// Register theme layer for question page UI (Generate Answer button + AI Summary)
qa_register_plugin_layer('qa-openai-layer.php', 'OpenAI Layer');

// Load the core function so it's available globally
require_once dirname(__FILE__) . '/qa-openai-core.php';
