<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Razy\Controller;
use Razy\Validation\FormRequest;
use Razy\Validation\Rule\Email;
use Razy\Validation\Rule\MinLength;
use Razy\Validation\Rule\Required;

/**
 * FORMREQUEST-RAIL M1 — the Controller::validated() door.
 *
 * The one-call contract (Q1) and the two named envelopes (Q4): pass hands the
 * handler validated data; every failure answers itself and returns null, so a
 * handler that only checks null can never touch rejected input.
 *
 * Every test answers (sets a status line), and PHP 8.5 warns when a LATER
 * status-set finds the process-level status line already set — a fact of the
 * shared CLI process, never of production (one request = one fresh state in
 * fpm/FrankenPHP). PHPUnit's own remedy: run these responders in separate
 * processes, each with pristine header state (the 8.5 matrix taught us this,
 * 2026-09).
 */
#[CoversMethod(Controller::class, 'validated')]
#[RunTestsInSeparateProcesses]
final class FormRequestDoorTest extends TestCase
{
    private Controller $controller;

    protected function setUp(): void
    {
        $_POST = [];
        $_GET = [];
        $this->controller = new Controller(null);
    }

    protected function tearDown(): void
    {
        // Emptied, never unset — these are superglobals the framework reads;
        // deleting them from the symbol table breaks later requests in prod-
        // shaped code (and later tests in this file).
        $_POST = [];
        $_GET = [];
        unset($_SERVER['CONTENT_TYPE']);
    }

    public function testPassHandsTheHandlerValidatedData(): void
    {
        $_POST = ['name' => 'Alice', 'email' => 'alice@example.com', 'junk' => 'ignored'];

        $data = $this->controller->validated(FRD_UserRequest::class);

        $this->assertIsArray($data);
        $this->assertSame('Alice', $data['name']);
        $this->assertSame('alice@example.com', $data['email']);
        // validated() is the RULES' surface only — junk stays out.
        $this->assertArrayNotHasKey('junk', $data);
    }

    public function testInvalidPayloadAnswers422EnvelopeAndNeverReachesTheHandler(): void
    {
        $_POST = ['name' => '', 'email' => 'nope'];

        [$envelope, $handlerSaw] = $this->runDoor(FRD_UserRequest::class);

        $this->assertNull($handlerSaw, 'answered-and-died: the handler never continues');
        $this->assertSame('validation-failed', $envelope['error']);
        $this->assertArrayHasKey('name', $envelope['errors']);
        $this->assertArrayHasKey('email', $envelope['errors']);
        $this->assertStringContainsString('resend', $envelope['fix']);
    }

    public function testDeniedRequestAnswers403AsItsOwnVerdict(): void
    {
        // Valid payload, denied actor: Q4's split means the answer is 403
        // forbidden, NEVER a validation bag — the two verdicts cannot conflate
        // through the door even though hand-rolled passes() ANDs them.
        $_POST = ['name' => 'Alice', 'email' => 'alice@example.com'];

        [$envelope, $handlerSaw] = $this->runDoor(FRD_DeniedRequest::class);

        $this->assertNull($handlerSaw);
        $this->assertSame('forbidden', $envelope['error']);
        $this->assertStringContainsString('FRD_DeniedRequest', $envelope['fix']);
        $this->assertArrayNotHasKey('errors', $envelope);
    }

    public function testJsonContentTypeSwitchesTheSource(): void
    {
        // Differential proof without a real request body: POST data is present,
        // but with a JSON Content-Type the door must read php://input (empty
        // under CLI) — so the valid-looking POST is invisible and 422 answers.
        $_POST = ['name' => 'Alice', 'email' => 'alice@example.com'];
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        [$envelope] = $this->runDoor(FRD_UserRequest::class);

        $this->assertSame('validation-failed', $envelope['error']);
    }

    public function testMessagesOverrideFlowsThroughTheDoor(): void
    {
        // M0's wiring end-to-end at the door: the custom text must be what the
        // client actually sees in the envelope.
        $_POST = ['name' => 'Al'];

        [$envelope] = $this->runDoor(FRD_MessagedRequest::class);

        $this->assertSame(['Please give a longer name.'], $envelope['errors']['name']);
    }

    /**
     * Run the door with ob capture; returns [decoded envelope|null, what the
     * handler variable would hold]. A FAIL answer ends dispatch via the
     * HttpException control-flow (XHR::output) — caught HERE, it is exactly
     * what "the handler never continues" means on the wire.
     *
     * @param class-string<FormRequest> $requestClass
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function runDoor(string $requestClass): array
    {
        $handlerHolds = 'NOT-SET';

        \ob_start();

        try {
            $handlerHolds = $this->controller->validated($requestClass);
        } catch (\Razy\Exception\HttpException) {
            $handlerHolds = null; // answered-and-died: dispatch ends here
        }

        $body = (string) \ob_get_clean();

        return [\json_decode($body, true), $handlerHolds === 'NOT-SET' ? null : $handlerHolds];
    }
}

/** @internal */
class FRD_UserRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'name' => [new Required()],
            'email' => [new Required(), new Email()],
        ];
    }
}

/** @internal */
class FRD_DeniedRequest extends FormRequest
{
    protected function rules(): array
    {
        return ['name' => [new Required()]];
    }

    protected function authorize(): bool
    {
        return false;
    }
}

/** @internal */
class FRD_MessagedRequest extends FormRequest
{
    protected function rules(): array
    {
        return ['name' => [new MinLength(5)]];
    }

    protected function messages(): array
    {
        return ['name.minLength' => 'Please give a longer name.'];
    }
}
