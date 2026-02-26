<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Razy Standalone App</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .container { text-align: center; max-width: 600px; padding: 2rem; }
        h1 { font-size: 2.5rem; margin-bottom: 0.5rem; color: #2c3e50; }
        .badge { display: inline-block; background: #3498db; color: #fff; padding: 0.25rem 0.75rem; border-radius: 1rem; font-size: 0.85rem; margin-bottom: 1.5rem; }
        p { font-size: 1.1rem; line-height: 1.6; color: #555; margin-bottom: 1rem; }
        code { background: #e8e8e8; padding: 0.15rem 0.4rem; border-radius: 3px; font-size: 0.9rem; }
        .hint { font-size: 0.9rem; color: #888; margin-top: 2rem; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Razy</h1>
        <span class="badge">Standalone Mode</span>
        <p>Your standalone application is running. Edit <code>standalone/controller/app.php</code> to add routes and <code>standalone/view/</code> for templates.</p>
        <p class="hint">To enable multisite mode, create a <code>sites.inc.php</code> file in the project root.</p>
    </div>
</body>
</html>
