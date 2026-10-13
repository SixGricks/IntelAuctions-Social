<?php
/**
 * Intel Auctions Social — Auctions → Social Calendar → Guide.
 *
 * Renders docs/guide.md (shipped in the plugin) with the toolkit's Markdown
 * renderer, so the guide reads the same as the toolkit's own Documentation.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('ias_guide_render')) {
  function ias_guide_render(): void {
    if (!current_user_can('manage_options')) return;
    $path = IAS_PATH . 'docs/guide.md';
    $md   = is_readable($path) ? (string) file_get_contents($path) : '';
    ias_admin_styles();
    ia_ui_header('Social Guide', 'How Intel Auctions Social works. Source: <code>docs/guide.md</code> in the plugin.');
    echo ias_switches_strip();
    if ($md === '') {
      echo '<div class="notice notice-error"><p><strong>docs/guide.md is missing.</strong> Reinstall the plugin from its latest release.</p></div></div>';
      return;
    }
    if (!function_exists('ia_docs_md_to_html')) {
      echo '<pre style="white-space:pre-wrap;max-width:920px">' . esc_html($md) . '</pre></div>';
      return;
    }
    [$html, $toc] = ia_docs_md_to_html($md, 'docs');
    echo '<style>
      .ias-docs{max-width:920px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:8px 32px 32px;line-height:1.65}
      .ias-docs h1{font-size:1.7em;margin:1em 0 .4em}
      .ias-docs h2{font-size:1.35em;margin:1.6em 0 .4em;padding-top:.6em;border-top:1px solid #f0f0f1}
      .ias-docs h3{font-size:1.1em;margin:1.3em 0 .3em}
      .ias-docs table{border-collapse:collapse;margin:.6em 0;width:100%}
      .ias-docs th,.ias-docs td{border:1px solid #dcdcde;padding:6px 10px;text-align:left;vertical-align:top}
      .ias-docs th{background:#f6f7f7}
      .ias-docs code{background:#f0f0f1;padding:1px 5px;border-radius:3px;font-size:.95em}
      .ias-docs pre{background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:12px;overflow-x:auto}
      .ias-docs pre code{background:none;padding:0}
      .ias-docs blockquote{border-left:4px solid #2271b1;margin:.8em 0;padding:.3em 1em;background:#f6f7f7;color:#50575e}
      .ias-docs-toc{background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:12px 18px;margin:16px 0}
      .ias-docs-toc ul{margin:.3em 0 .2em 1.2em}
    </style>';
    echo '<div class="ias-docs">';
    if ($toc) {
      echo '<div class="ias-docs-toc"><strong>Contents</strong><ul>';
      foreach ($toc as $item) echo '<li><a href="#' . esc_attr($item['id']) . '">' . esc_html($item['text']) . '</a></li>';
      echo '</ul></div>';
    }
    echo $html; // built by the toolkit's renderer from the escaped Markdown source
    echo '</div></div>';
  }
}
