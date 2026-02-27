<?php
/**
 * Footer API - Returns shared footer HTML
 * 
 * @llm API Command: footer
 * Returns closing HTML tags with footer.
 */

use Razy\Controller;

return function (): string {
    /** @var Controller $this */
    
    $siteUrl = rtrim($this->getSiteURL(), '/');
    
    return <<<HTML
    </div>
    <footer class="footer">
        <p>Razy Framework v1.0.1-beta · <a href="{$siteUrl}/">Back to Demo Index</a></p>
    </footer>
</body>
</html>
HTML;
};
