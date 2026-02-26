<?php
/**
 * Mailer Demo Controller
 * 
 * @llm Razy Mailer provides SMTP email functionality.
 * 
 * ## Constructor
 * 
 * ```php
 * new Mailer(
 *     string $hostname,       // SMTP server
 *     int $port = 25,         // Port (25, 587, 465)
 *     int $connectionTimeout = 30,
 *     int $responseTimeout = 5,
 *     string $origin = ''     // HELO/EHLO hostname
 * )
 * ```
 * 
 * ## Security Protocols
 * 
 * - `SECURE_NONE` - No encryption
 * - `SECURE_TLS` / `SECURE_TLSv12` - TLS encryption
 * - `SECURE_SSLv3` - SSL encryption
 */

namespace Razy\Module\mailer_demo;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            '/' => 'main',
            'basic' => 'demo/basic',
            'html' => 'demo/html',
            'attachments' => 'demo/attachments',
        ]);
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'Mailer Demo',
                'description' => 'SMTP email sending with attachments',
                'url'         => '/mailer_demo/',
                'category'    => 'Web & API',
                'icon'        => '📧',
                'routes'      => '4 routes: /, basic, html, attachments',
            ];
        });
        
        return true;
    }
};
