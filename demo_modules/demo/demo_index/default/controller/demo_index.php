<?php
/**
 * Demo Index Controller
 * 
 * @llm Main controller providing index page, shared styles, and demo registration.
 * 
 * Routes:
 * - addRoute('/', 'index') - Main index page
 * 
 * API Commands:
 * - header($title, $showBack) - Get shared header HTML
 * - styles() - Get shared CSS styles
 * 
 * Events:
 * - Fires 'register_demo' to collect demo info from all modules
 */

namespace Razy\Module\demo_index;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    /**
     * Registered demos (populated by event responses)
     */
    private array $demos = [];
    
    /**
     * Initialize routes and API commands
     */
    public function __onInit(Agent $agent): bool
    {
        // Main index page - absolute regex route at site root
        $agent->addRoute('/', 'index');
        
        // API commands for other modules to use
        $agent->addAPICommand('header', 'api/header');
        $agent->addAPICommand('styles', 'api/styles');
        $agent->addAPICommand('footer', 'api/footer');
        
        return true;
    }
    
    /**
     * On module load - collect registered demos via event
     */
    public function __onLoad(Agent $agent): bool
    {
        // Fire event to collect demo registrations
        $emitter = $this->trigger('register_demo');
        $emitter->resolve([
            'index_url' => '/',
        ]);
        
        // Collect all demo registrations
        foreach ($emitter->getAllResponse() as $response) {
            if (is_array($response) && isset($response['name'])) {
                $this->demos[] = $response;
            }
        }
        
        return true;
    }
    
    /**
     * Get all registered demos
     */
    public function getDemos(): array
    {
        return $this->demos;
    }
    
    /**
     * Get shared CSS styles
     */
    public function getStyles(): string
    {
        return <<<CSS
:root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --secondary: #64748b;
    --success: #22c55e;
    --warning: #f59e0b;
    --danger: #ef4444;
    --bg: #f8fafc;
    --card-bg: #ffffff;
    --text: #1e293b;
    --text-muted: #64748b;
    --border: #e2e8f0;
    --shadow: 0 1px 3px rgba(0,0,0,0.1);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
    --radius: 8px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.6;
    min-height: 100vh;
}
.container { max-width: 1200px; margin: 0 auto; padding: 20px; }
.header {
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: white;
    padding: 20px 0;
    margin-bottom: 30px;
    box-shadow: var(--shadow-lg);
}
.header .container { display: flex; align-items: center; justify-content: space-between; }
.header h1 { font-size: 1.5rem; font-weight: 600; }
.header .subtitle { opacity: 0.9; font-size: 0.875rem; margin-top: 4px; }
.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(255,255,255,0.15);
    color: white;
    text-decoration: none;
    padding: 8px 16px;
    border-radius: var(--radius);
    font-size: 0.875rem;
    transition: background 0.2s;
}
.back-btn:hover { background: rgba(255,255,255,0.25); }
.card {
    background: var(--card-bg);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 24px;
    margin-bottom: 20px;
}
.card h2 { color: var(--primary); margin-bottom: 16px; font-size: 1.25rem; }
.card h3 { color: var(--text); margin: 16px 0 8px; font-size: 1rem; }
.btn {
    display: inline-block;
    padding: 10px 20px;
    background: var(--primary);
    color: white;
    text-decoration: none;
    border-radius: var(--radius);
    font-size: 0.875rem;
    font-weight: 500;
    transition: background 0.2s, transform 0.1s;
    border: none;
    cursor: pointer;
}
.btn:hover { background: var(--primary-dark); transform: translateY(-1px); }
.btn-secondary { background: var(--secondary); }
.btn-secondary:hover { background: #475569; }
.btn-success { background: var(--success); }
.btn-success:hover { background: #16a34a; }
code {
    background: #f1f5f9;
    padding: 2px 6px;
    border-radius: 4px;
    font-family: 'SF Mono', Monaco, monospace;
    font-size: 0.875em;
    color: #e11d48;
}
pre {
    background: #1e293b;
    color: #e2e8f0;
    padding: 16px;
    border-radius: var(--radius);
    overflow-x: auto;
    font-family: 'SF Mono', Monaco, monospace;
    font-size: 0.875rem;
    line-height: 1.5;
    margin: 12px 0;
}
pre code { background: transparent; color: inherit; padding: 0; }
.grid { display: grid; gap: 20px; }
.grid-2 { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
.grid-3 { grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); }
table { width: 100%; border-collapse: collapse; margin: 12px 0; }
th, td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border); }
th { background: #f8fafc; font-weight: 600; color: var(--text-muted); font-size: 0.875rem; }
.tag {
    display: inline-block;
    padding: 4px 10px;
    background: #dbeafe;
    color: var(--primary);
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 500;
}
.tag-success { background: #dcfce7; color: #166534; }
.tag-warning { background: #fef3c7; color: #92400e; }
ul, ol { padding-left: 24px; margin: 8px 0; }
li { margin: 4px 0; }
.footer { text-align: center; padding: 20px; color: var(--text-muted); font-size: 0.875rem; }
CSS;
    }
};
