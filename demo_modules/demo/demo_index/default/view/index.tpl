{#
 * @llm Demo Index Template
 * 
 * Variables:
 * - $site_url: Base URL of the site
 * - $demo_count: Number of registered demos
 * - $category_count: Number of categories with demos
 * - $styles: CSS styles string
 * 
 * Blocks:
 * - category: Loop for each category
 * - demo: Loop for each demo in a category
 * - empty: Shown when no demos registered
#}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Razy Framework - Demo Index</title>
    <style>{$styles}</style>
    <style>
        .hero {
            background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
            color: white;
            padding: 60px 0;
            text-align: center;
            margin-bottom: 40px;
        }
        .hero h1 { font-size: 2.5rem; margin-bottom: 16px; }
        .hero p { font-size: 1.125rem; opacity: 0.9; max-width: 600px; margin: 0 auto; }
        .demo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .demo-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .demo-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.15);
        }
        .demo-card h3 {
            color: #2563eb;
            margin-bottom: 8px;
            font-size: 1.125rem;
        }
        .demo-card p {
            color: #64748b;
            font-size: 0.875rem;
            margin-bottom: 12px;
        }
        .demo-card .routes {
            font-size: 0.75rem;
            color: #94a3b8;
        }
        .category { margin-bottom: 40px; }
        .category h2 {
            font-size: 1.5rem;
            color: #1e293b;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        .empty-state h2 { color: #94a3b8; margin-bottom: 16px; }
        .stats {
            display: flex;
            justify-content: center;
            gap: 40px;
            margin-top: 30px;
        }
        .stat { text-align: center; }
        .stat-value { font-size: 2rem; font-weight: 700; }
        .stat-label { font-size: 0.875rem; opacity: 0.8; }
    </style>
</head>
<body>
    <div class="hero">
        <div class="container">
            <h1>🚀 Razy Framework Demos</h1>
            <p>Explore interactive demonstrations of Razy Framework features including routing, events, templating, database operations, and more.</p>
            <div class="stats">
                <div class="stat">
                    <div class="stat-value">{$demo_count}</div>
                    <div class="stat-label">Demo Modules</div>
                </div>
                <div class="stat">
                    <div class="stat-value">{$category_count}</div>
                    <div class="stat-label">Categories</div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="container">
<!-- START BLOCK: category -->
<div class="category">
    <h2>{$category_name}</h2>
    <div class="demo-grid">
<!-- START BLOCK: demo -->
        <a href="{$site_url}{$demo_url}" class="demo-card">
            <h3>{$demo_icon} {$demo_name}</h3>
            <p>{$demo_description}</p>
            <div class="routes">{$demo_routes}</div>
        </a>
<!-- END BLOCK: demo -->
    </div>
</div>
<!-- END BLOCK: category -->

<!-- START BLOCK: empty -->
        <div class="empty-state">
            <h2>No Demos Registered</h2>
            <p>Demo modules need to listen to the <code>demo/demo_index:register_demo</code> event to appear here.</p>
            <pre>// In your module's __onInit():
$agent->listen('demo/demo_index:register_demo', function($data) {
    return [
        'name' => 'My Demo',
        'description' => 'Demo description',
        'url' => '/my_demo/',
        'category' => 'Core Features',
    ];
});</pre>
        </div>
<!-- END BLOCK: empty -->
    </div>
    
    <footer class="footer">
        <p>Razy Framework v0.5.4 · Built with ❤️ for developers</p>
    </footer>
</body>
</html>
