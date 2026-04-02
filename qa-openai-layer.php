<?php
/**
 * Theme layer for OpenAI Integration plugin.
 *
 * — Adds "Generate AI Answer" button for admin users on question pages.
 * — Adds "AI Summary" section for threads with 5+ answers or 5+ comments.
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

class qa_html_theme_layer extends qa_html_theme_base
{
    /**
     * Inject CSS styles in the head.
     */
    public function head_css()
    {
        qa_html_theme_base::head_css();

        if ($this->template !== 'question') {
            return;
        }

        $this->output('<style>
/* OpenAI plugin styles */
.qa-openai-generate-btn {
    display: inline-block;
    margin: 10px 0;
    padding: 8px 18px;
    background: #10a37f;
    color: #fff;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
    font-weight: bold;
}
.qa-openai-generate-btn:hover {
    background: #0d8c6d;
}
.qa-openai-generate-btn:disabled {
    background: #999;
    cursor: wait;
}
.qa-openai-generate-wrap {
    margin: 12px 0;
    padding: 10px 14px;
    background: #f0faf6;
    border: 1px solid #b2dfdb;
    border-radius: 6px;
}
.qa-openai-summary-wrap {
    margin: 16px 0;
    padding: 14px 18px;
    background: #f5f5ff;
    border: 1px solid #c5cae9;
    border-radius: 6px;
}
.qa-openai-summary-wrap h3 {
    margin: 0 0 8px 0;
    font-size: 15px;
    color: #3949ab;
}
.qa-openai-summary-toggle {
    display: inline-block;
    padding: 6px 14px;
    background: #3949ab;
    color: #fff;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    margin-bottom: 8px;
}
.qa-openai-summary-toggle:hover {
    background: #303f9f;
}
.qa-openai-summary-toggle:disabled {
    background: #999;
    cursor: wait;
}
.qa-openai-summary-content {
    display: none;
    margin-top: 8px;
    padding: 10px;
    background: #fff;
    border-radius: 4px;
    line-height: 1.6;
    white-space: pre-wrap;
    word-wrap: break-word;
}
.qa-openai-summary-content.loaded {
    display: block;
}
.qa-openai-error {
    color: #c62828;
    font-weight: bold;
}
/* Dark mode support: Polaris uses [data-theme="dark"], MayroPro uses body.dark-theme / prefers-color-scheme */
[data-theme="dark"] .qa-openai-summary-wrap,
body.dark-theme .qa-openai-summary-wrap {
    background: #1e1e2f;
    border-color: #3949ab;
}
[data-theme="dark"] .qa-openai-summary-content,
body.dark-theme .qa-openai-summary-content {
    background: #2a2a3c;
    color: #e0e0e0;
}
[data-theme="dark"] .qa-openai-summary-wrap h3,
body.dark-theme .qa-openai-summary-wrap h3 {
    color: #7986cb;
}
[data-theme="dark"] .qa-openai-generate-wrap,
body.dark-theme .qa-openai-generate-wrap {
    background: #1e2e2a;
    border-color: #388e6c;
}
@media (prefers-color-scheme: dark) {
    body:not(.light-theme) .qa-openai-summary-wrap {
        background: #1e1e2f;
        border-color: #3949ab;
    }
    body:not(.light-theme) .qa-openai-summary-content {
        background: #2a2a3c;
        color: #e0e0e0;
    }
    body:not(.light-theme) .qa-openai-summary-wrap h3 {
        color: #7986cb;
    }
    body:not(.light-theme) .qa-openai-generate-wrap {
        background: #1e2e2a;
        border-color: #388e6c;
    }
}
.qa-openai-spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid #ccc;
    border-top-color: #333;
    border-radius: 50%;
    animation: qa-openai-spin 0.6s linear infinite;
    vertical-align: middle;
    margin-left: 6px;
}
@keyframes qa-openai-spin {
    to { transform: rotate(360deg); }
}
</style>');
    }

    /**
     * Override a_form to inject the "Generate AI Answer" button for admins.
     */
    public function a_form($a_form)
    {
        $user_level = qa_get_logged_in_level();
        $min_level = (int) qa_opt('openai_generate_min_level');
        if ($min_level <= 0) {
            $min_level = QA_USER_LEVEL_ADMIN;
        }
        $can_generate = ($user_level >= $min_level);

        if ($can_generate && $this->template === 'question' && isset($this->content['q_view']['raw']['postid'])) {
            $postid = (int) $this->content['q_view']['raw']['postid'];
            $this->output('<div class="qa-openai-generate-wrap">');
            $this->output('<button type="button" class="qa-openai-generate-btn" id="qa-openai-generate-answer" data-postid="' . $postid . '">');
            $this->output('&#x2728; Generate AI Answer');
            $this->output('</button>');
            $this->output('<span id="qa-openai-generate-status"></span>');
            $this->output('</div>');
        }

        qa_html_theme_base::a_form($a_form);
    }

    /**
     * Override q_view to inject AI Summary section after question when thread is long.
     */
    public function q_view($q_view)
    {
        qa_html_theme_base::q_view($q_view);

        if ($this->template !== 'question' || empty($q_view)) {
            return;
        }

        // Count answers
        $answer_count = 0;
        if (isset($this->content['a_list']['as'])) {
            $answer_count = count($this->content['a_list']['as']);
        }

        // Count comments on the question
        $comment_count = 0;
        if (isset($q_view['c_list']['cs'])) {
            $comment_count = count($q_view['c_list']['cs']);
        }

        // Also count comments on answers
        if (isset($this->content['a_list']['as'])) {
            foreach ($this->content['a_list']['as'] as $a_item) {
                if (isset($a_item['c_list']['cs'])) {
                    $comment_count += count($a_item['c_list']['cs']);
                }
            }
        }

        $threshold = (int) qa_opt('openai_summary_threshold');
        if ($threshold <= 0) {
            $threshold = 5;
        }

        if ($answer_count >= $threshold || $comment_count >= $threshold) {
            $postid = isset($q_view['raw']['postid']) ? (int) $q_view['raw']['postid'] : 0;
            if ($postid > 0) {
                $this->output('<div class="qa-openai-summary-wrap">');
                $this->output('<button type="button" class="qa-openai-summary-toggle" id="qa-openai-summary-btn" data-postid="' . $postid . '">');
                $this->output('&#x1F4DD; Show AI Summary');
                $this->output('</button>');
                $this->output('<div class="qa-openai-summary-content" id="qa-openai-summary-content"></div>');
                $this->output('</div>');
            }
        }
    }

    /**
     * Inject JavaScript at the end of the body.
     */
    public function body_suffix()
    {
        qa_html_theme_base::body_suffix();

        if ($this->template !== 'question') {
            return;
        }

        $ajax_url = qa_path('openai-ajax', null, qa_opt('site_url'));

        $this->output('<script>
(function() {
    "use strict";

    var ajaxUrl = ' . json_encode($ajax_url) . ';

    // ── Generate AI Answer (admin only) ──
    var genBtn = document.getElementById("qa-openai-generate-answer");
    if (genBtn) {
        genBtn.addEventListener("click", function() {
            var postid = genBtn.getAttribute("data-postid");
            genBtn.disabled = true;
            var statusEl = document.getElementById("qa-openai-generate-status");
            statusEl.innerHTML = " Generating\u2026 <span class=\"qa-openai-spinner\"></span>";

            var xhr = new XMLHttpRequest();
            xhr.open("POST", ajaxUrl, true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    genBtn.disabled = false;
                    statusEl.innerHTML = "";
                    if (xhr.status === 200) {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.success && resp.answer) {
                                fillAnswerEditor(resp.answer);
                            } else {
                                statusEl.innerHTML = "<span class=\"qa-openai-error\">" + escHtml(resp.error || "Unknown error") + "</span>";
                            }
                        } catch(e) {
                            statusEl.innerHTML = "<span class=\"qa-openai-error\">Invalid response from server.</span>";
                        }
                    } else {
                        statusEl.innerHTML = "<span class=\"qa-openai-error\">Request failed (HTTP " + xhr.status + ").</span>";
                    }
                }
            };
            xhr.send("action=generate_answer&postid=" + encodeURIComponent(postid));
        });
    }

    // ── AI Summary button ──
    var summaryBtn = document.getElementById("qa-openai-summary-btn");
    if (summaryBtn) {
        var summaryLoaded = false;
        summaryBtn.addEventListener("click", function() {
            var contentEl = document.getElementById("qa-openai-summary-content");

            if (summaryLoaded) {
                // Toggle visibility
                contentEl.classList.toggle("loaded");
                summaryBtn.textContent = contentEl.classList.contains("loaded")
                    ? "\uD83D\uDCDD Hide AI Summary"
                    : "\uD83D\uDCDD Show AI Summary";
                return;
            }

            var postid = summaryBtn.getAttribute("data-postid");
            summaryBtn.disabled = true;
            summaryBtn.innerHTML = "Loading\u2026 <span class=\"qa-openai-spinner\"></span>";

            var xhr = new XMLHttpRequest();
            xhr.open("POST", ajaxUrl, true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    summaryBtn.disabled = false;
                    if (xhr.status === 200) {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.success && resp.summary) {
                                contentEl.innerHTML = safeHtmlWithNewlines(resp.summary);
                                contentEl.classList.add("loaded");
                                typesetMathJax(contentEl);
                                summaryBtn.textContent = "\uD83D\uDCDD Hide AI Summary";
                                summaryLoaded = true;
                            } else {
                                contentEl.innerHTML = "<span class=\"qa-openai-error\">" + escHtml(resp.error || "Unknown error") + "</span>";
                                contentEl.classList.add("loaded");
                                summaryBtn.textContent = "\uD83D\uDCDD Show AI Summary";
                            }
                        } catch(e) {
                            contentEl.innerHTML = "<span class=\"qa-openai-error\">Invalid response from server.</span>";
                            contentEl.classList.add("loaded");
                            summaryBtn.textContent = "\uD83D\uDCDD Show AI Summary";
                        }
                    } else {
                        contentEl.innerHTML = "<span class=\"qa-openai-error\">Request failed.</span>";
                        contentEl.classList.add("loaded");
                        summaryBtn.textContent = "\uD83D\uDCDD Show AI Summary";
                    }
                }
            };
            xhr.send("action=generate_summary&postid=" + encodeURIComponent(postid));
        });
    }

    // ── Helper: fill the Q2A answer editor ──
    function fillAnswerEditor(text) {
        var anewEl = document.getElementById("anew");
        var formVisible = anewEl && anewEl.offsetParent !== null;

        // Function to set data into the editor once ready
        function setEditorData() {
            var filled = false;

            // CKEditor (used by gpt-wysiwyg-editor and wysiwyg8)
            if (typeof CKEDITOR !== "undefined" && CKEDITOR.instances) {
                var inst = CKEDITOR.instances["a_content"];
                if (!inst) {
                    for (var name in CKEDITOR.instances) {
                        if (name.indexOf("a_content") !== -1) {
                            inst = CKEDITOR.instances[name];
                            break;
                        }
                    }
                }
                if (inst) {
                    if (inst.status === "ready") {
                        inst.setData(text);
                        filled = true;
                    } else {
                        inst.on("instanceReady", function() {
                            inst.setData(text);
                        });
                        filled = true;
                    }
                }
            }

            // Also set the hidden CKEditor data fields
            var hiddenData = document.getElementById("a_content_ckeditor_data");
            if (hiddenData) hiddenData.value = text;

            // SCEditor / TinyMCE / plain textarea fallback
            if (!filled) {
                var ta = document.querySelector("textarea[name=\"a_content\"]");
                if (!ta) ta = document.querySelector(".qa-a-form textarea");
                if (ta) {
                    if (typeof jQuery !== "undefined" && jQuery(ta).data("sceditor")) {
                        jQuery(ta).sceditor("instance").val(text);
                    } else if (typeof tinymce !== "undefined" && tinymce.activeEditor) {
                        tinymce.activeEditor.setContent(text);
                    } else {
                        ta.value = text;
                        ta.dispatchEvent(new Event("input", {bubbles: true}));
                    }
                }
            }

            // Scroll to answer form
            if (anewEl) {
                anewEl.scrollIntoView({behavior: "smooth", block: "center"});
            }
        }

        if (!formVisible && typeof qa_toggle_element === "function") {
            // Form is collapsed - use Q2A toggle which calls qa_load (inits CKEditor)
            // Then wait for CKEditor to be ready
            if (typeof CKEDITOR !== "undefined") {
                var readyHandler = function(evt) {
                    if (evt.editor && evt.editor.name && evt.editor.name.indexOf("a_content") !== -1) {
                        CKEDITOR.removeListener("instanceReady", readyHandler);
                        // Small delay to let CKEditor fully render
                        setTimeout(function() { setEditorData(); }, 100);
                    }
                };
                CKEDITOR.on("instanceReady", readyHandler);
            }
            qa_toggle_element("anew");
            // Fallback: if CKEditor not available, just wait for form animation
            if (typeof CKEDITOR === "undefined") {
                setTimeout(setEditorData, 500);
            }
        } else {
            // Form is already visible — editor should be loaded
            setEditorData();
        }
    }

    function typesetMathJax(el) {
        // KaTeX: use global typeset() exposed by mathjax plugin layer
        if (typeof typeset === "function") {
            typeset(function() { return [el]; });
        } else if (typeof MathJax !== "undefined") {
            if (typeof MathJax.typesetPromise === "function") {
                MathJax.typesetPromise([el]).catch(function(err) {
                    console.warn("MathJax typeset error:", err);
                });
            } else if (MathJax.Hub && typeof MathJax.Hub.Queue === "function") {
                MathJax.Hub.Queue(["Typeset", MathJax.Hub, el]);
            }
        }
    }

    // Escape HTML entities but preserve newlines as <br>, keeping MathJax delimiters intact
    function safeHtmlWithNewlines(s) {
        return escHtml(s).replace(/\n/g, "<br>");
    }

    function escHtml(s) {
        var div = document.createElement("div");
        div.appendChild(document.createTextNode(s));
        return div.innerHTML;
    }
})();
</script>');
    }
}
