<?php
/**
 * Header API - Returns shared header HTML
 * 
 * @llm API Command: header
 * Returns HTML header with navigation and optional back button.
 * 
 * Parameters:
 * - $title: Page title (required)
 * - $subtitle: Optional subtitle text
 * - $showBack: Whether to show back to index button (default: true)
 */

use Razy\Controller;

return function (string $title, string $subtitle = '', bool $showBack = true): string {
    /** @var Controller $this */
    
    $styles = $this->getStyles();
    $siteUrl = rtrim($this->getSiteURL(), '/');
    $backBtn = $showBack ? "<a href=\"{$siteUrl}/\" class=\"back-btn\">← Back to Index</a>" : '';
    $subtitleHtml = $subtitle ? "<div class=\"subtitle\">{$subtitle}</div>" : '';
    
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} - Razy Demos</title>
    <style>{$styles}</style>
</head>
<body>
    <header class="header">
        <div class="container">
            <div>
                <h1>{$title}</h1>
                {$subtitleHtml}
            </div>
            {$backBtn}
        </div>
    </header>
    <div class="container">
HTML;
};
