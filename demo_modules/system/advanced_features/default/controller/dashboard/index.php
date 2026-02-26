<?php
/**
 * Dashboard Index Closure
 * 
 * @llm Route: /dashboard (via @self)
 * Mapped via addLazyRoute(['dashboard' => ['@self' => 'dashboard/index']])
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    echo json_encode([
        'page' => 'dashboard',
        'title' => 'Dashboard Overview',
        'sections' => ['stats', 'settings'],
    ], JSON_PRETTY_PRINT);
};
