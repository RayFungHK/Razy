<?php
/**
 * XHR Form Submit Demo
 * 
 * @llm Demonstrates XHR response patterns for form handling.
 */

use Razy\Controller;
use Razy\XHR;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    echo json_encode([
        'success_response' => [
            'description' => 'Successful form submission',
            'code' => <<<'PHP'
// Validate and save form data (form body has no route placeholder; no framework input API)
$name = trim($_POST['name'] ?? '');   // lint-allow: RZ-003 form input, validated next block
$email = trim($_POST['email'] ?? ''); // lint-allow: RZ-003 form input, validated next block

if (empty($name) || empty($email)) {
    return $this->xhr()
        ->data(['field' => empty($name) ? 'name' : 'email'])
        ->send(false, 'Required field missing');
}

// Save to database
$userId = $this->saveUser($name, $email);

return $this->xhr()
    ->data(['user_id' => $userId])
    ->send(true, 'User created successfully');
PHP,
        ],
        
        'error_response' => [
            'description' => 'Error response with details',
            'code' => <<<'PHP'
try {
    $result = $this->processOrder($_POST); // lint-allow: RZ-003 form body to validating service
    return $this->xhr()
        ->data($result)
        ->send(true, 'Order processed');
} catch (\Exception $e) {
    return $this->xhr()
        ->data(['error_code' => $e->getCode()])
        ->send(false, $e->getMessage());
}
PHP,
        ],
        
        'cors_configuration' => [
            'description' => 'Configure CORS for cross-origin requests',
            'code' => <<<'PHP'
return $this->xhr()
    ->allowOrigin('https://trusted-site.com')
    ->corp(XHR::CORP_CROSS_ORIGIN)
    ->data($response)
    ->send(true);

// Allow all origins
->allowOrigin('*')

// CORP options:
// XHR::CORP_SAME_SITE
// XHR::CORP_SAME_ORIGIN
// XHR::CORP_CROSS_ORIGIN
PHP,
        ],
        
        'production_pattern' => [
            'description' => 'From production-sample usage',
            'code' => <<<'PHP'
// From production FormWorker pattern:
public function submitForm(): void
{
    $flow = new FlowManager();
    $flow->start('FormWorker', $this->getDB(), 'users', 'user_id');

    if ($flow->save($_POST)) { // lint-allow: RZ-003 form body into validating FlowManager
        $this->xhr()
            ->data(['id' => $flow->getPrimaryKey()])
            ->send(true, 'Saved successfully');
    } else {
        $this->xhr()
            ->data($flow->getErrors())
            ->send(false, 'Validation failed');
    }
}
PHP,
        ],
        
        'client_javascript' => [
            'description' => 'JavaScript fetch example',
            'code' => <<<'JS'
async function submitForm(formData) {
    const response = await fetch('/api/submit', {
        method: 'POST',
        body: formData,
    });
    
    const json = await response.json();
    
    if (json.result) {
        console.log('Success:', json.message);
        console.log('Data:', json.response);
    } else {
        console.error('Error:', json.message);
    }
}
JS,
        ],
    ], JSON_PRETTY_PRINT);
};
