<?php
/**
 * Unit tests for Razy\Exception\* classes.
 *
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Contract\Container\ContainerExceptionInterface;
use Razy\Contract\Container\NotFoundExceptionInterface;
use Razy\Exception\CacheException;
use Razy\Exception\ConfigurationException;
use Razy\Exception\ConnectionException;
use Razy\Exception\ContainerException;
use Razy\Exception\ContainerNotFoundException;
use Razy\Exception\DatabaseException;
use Razy\Exception\FileException;
use Razy\Exception\FTPException;
use Razy\Exception\HttpException;
use Razy\Exception\MailerException;
use Razy\Exception\ModuleConfigException;
use Razy\Exception\ModuleException;
use Razy\Exception\ModuleLoadException;
use Razy\Exception\NetworkException;
use Razy\Exception\NotFoundException;
use Razy\Exception\OAuthException;
use Razy\Exception\PipelineException;
use Razy\Exception\QueryException;
use Razy\Exception\RedirectException;
use Razy\Exception\RoutingException;
use Razy\Exception\SSHException;
use Razy\Exception\TemplateException;
use RuntimeException;

#[CoversClass(HttpException::class)]
#[CoversClass(NotFoundException::class)]
#[CoversClass(RedirectException::class)]
#[CoversClass(ConfigurationException::class)]
#[CoversClass(DatabaseException::class)]
#[CoversClass(ConnectionException::class)]
#[CoversClass(QueryException::class)]
#[CoversClass(TemplateException::class)]
#[CoversClass(ModuleException::class)]
#[CoversClass(ModuleLoadException::class)]
#[CoversClass(ModuleConfigException::class)]
#[CoversClass(CacheException::class)]
#[CoversClass(NetworkException::class)]
#[CoversClass(FTPException::class)]
#[CoversClass(SSHException::class)]
#[CoversClass(ContainerException::class)]
#[CoversClass(ContainerNotFoundException::class)]
#[CoversClass(FileException::class)]
#[CoversClass(MailerException::class)]
#[CoversClass(OAuthException::class)]
#[CoversClass(PipelineException::class)]
#[CoversClass(RoutingException::class)]
class ExceptionTest extends TestCase
{
    // ── HttpException ──────────────────────────────────────

    public function testHttpExceptionDefaults(): void
    {
        $e = new HttpException();
        $this->assertSame(400, $e->getStatusCode());
        $this->assertSame(400, $e->getCode());
        $this->assertSame('', $e->getMessage());
        $this->assertNull($e->getPrevious());
    }

    public function testHttpExceptionCustomValues(): void
    {
        $prev = new \RuntimeException('inner');
        $e = new HttpException(503, 'Service Unavailable', $prev);
        $this->assertSame(503, $e->getStatusCode());
        $this->assertSame('Service Unavailable', $e->getMessage());
        $this->assertSame($prev, $e->getPrevious());
    }

    public function testHttpExceptionExtendsRuntimeException(): void
    {
        $e = new HttpException();
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testHttpExceptionIsThrowable(): void
    {
        $this->expectException(HttpException::class);
        throw new HttpException(500, 'Internal Server Error');
    }

    // ── NotFoundException ──────────────────────────────────

    public function testNotFoundExceptionDefaults(): void
    {
        $e = new NotFoundException();
        $this->assertSame(404, $e->getStatusCode());
        $this->assertSame('The requested URL was not found on this server.', $e->getMessage());
    }

    public function testNotFoundExceptionCustomMessage(): void
    {
        $e = new NotFoundException('Custom 404 message');
        $this->assertSame(404, $e->getStatusCode());
        $this->assertSame('Custom 404 message', $e->getMessage());
    }

    public function testNotFoundExceptionExtendsHttpException(): void
    {
        $e = new NotFoundException();
        $this->assertInstanceOf(HttpException::class, $e);
    }

    public function testNotFoundExceptionIsCatchableAsHttpException(): void
    {
        $caught = false;
        try {
            throw new NotFoundException();
        } catch (HttpException $e) {
            $caught = true;
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertTrue($caught);
    }

    // ── RedirectException ──────────────────────────────────

    public function testRedirectExceptionDefaults(): void
    {
        $e = new RedirectException('https://example.com');
        $this->assertSame('https://example.com', $e->getUrl());
        $this->assertSame(301, $e->getRedirectCode());
        $this->assertSame(301, $e->getStatusCode());
        $this->assertStringContainsString('https://example.com', $e->getMessage());
    }

    public function testRedirectExceptionCustomCode(): void
    {
        $e = new RedirectException('/login', 302);
        $this->assertSame('/login', $e->getUrl());
        $this->assertSame(302, $e->getRedirectCode());
        $this->assertSame(302, $e->getStatusCode());
    }

    public function testRedirectExceptionExtendsHttpException(): void
    {
        $e = new RedirectException('/');
        $this->assertInstanceOf(HttpException::class, $e);
    }

    public function testRedirectExceptionIsCatchableAsHttpException(): void
    {
        $caught = false;
        try {
            throw new RedirectException('/target', 307);
        } catch (HttpException $e) {
            $caught = true;
            $this->assertInstanceOf(RedirectException::class, $e);
        }
        $this->assertTrue($caught);
    }

    public function testRedirectExceptionPreservesUrl(): void
    {
        $url = 'https://example.com/path?query=1&other=2#frag';
        $e = new RedirectException($url);
        $this->assertSame($url, $e->getUrl());
    }

    // ── ConfigurationException ─────────────────────────────

    public function testConfigurationExceptionDefaults(): void
    {
        $e = new ConfigurationException();
        $this->assertSame('Failed to load site configuration.', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertNull($e->getPrevious());
    }

    public function testConfigurationExceptionCustomMessage(): void
    {
        $e = new ConfigurationException('Custom config error');
        $this->assertSame('Custom config error', $e->getMessage());
    }

    public function testConfigurationExceptionWithPreviousException(): void
    {
        $prev = new \RuntimeException('file not found');
        $e = new ConfigurationException('Config failed', $prev);
        $this->assertSame($prev, $e->getPrevious());
        $this->assertSame('Config failed', $e->getMessage());
    }

    public function testConfigurationExceptionExtendsRuntimeException(): void
    {
        $e = new ConfigurationException();
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testConfigurationExceptionIsNotHttpException(): void
    {
        $e = new ConfigurationException();
        $this->assertNotInstanceOf(HttpException::class, $e);
    }

    // ── DatabaseException ──────────────────────────────────

    public function testDatabaseExceptionDefaults(): void
    {
        $e = new DatabaseException();
        $this->assertSame('A database error occurred.', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertNull($e->getPrevious());
    }

    public function testDatabaseExceptionCustomMessage(): void
    {
        $e = new DatabaseException('Connection pool exhausted');
        $this->assertSame('Connection pool exhausted', $e->getMessage());
    }

    public function testDatabaseExceptionExtendsRuntimeException(): void
    {
        $e = new DatabaseException();
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    // ── ConnectionException ─────────────────────────────────

    public function testConnectionExceptionDefaults(): void
    {
        $e = new ConnectionException();
        $this->assertSame('Database connection failed.', $e->getMessage());
    }

    public function testConnectionExceptionExtendsDatabaseException(): void
    {
        $e = new ConnectionException('Driver not found');
        $this->assertInstanceOf(DatabaseException::class, $e);
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testConnectionExceptionIsCatchableAsDatabaseException(): void
    {
        $caught = false;
        try {
            throw new ConnectionException('No host');
        } catch (DatabaseException $e) {
            $caught = true;
            $this->assertInstanceOf(ConnectionException::class, $e);
        }
        $this->assertTrue($caught);
    }

    // ── QueryException ──────────────────────────────────────

    public function testQueryExceptionDefaults(): void
    {
        $e = new QueryException();
        $this->assertSame('Query execution failed.', $e->getMessage());
    }

    public function testQueryExceptionExtendsDatabaseException(): void
    {
        $e = new QueryException('Syntax error near SELECT');
        $this->assertInstanceOf(DatabaseException::class, $e);
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testQueryExceptionIsCatchableAsDatabaseException(): void
    {
        $caught = false;
        try {
            throw new QueryException('Invalid SQL');
        } catch (DatabaseException $e) {
            $caught = true;
            $this->assertInstanceOf(QueryException::class, $e);
        }
        $this->assertTrue($caught);
    }

    public function testQueryExceptionWithPrevious(): void
    {
        $pdo = new \PDOException('SQLSTATE[42000]');
        $e = new QueryException('Query failed', 42000, $pdo);
        $this->assertSame($pdo, $e->getPrevious());
        $this->assertSame(42000, $e->getCode());
    }

    // ── TemplateException ───────────────────────────────────

    public function testTemplateExceptionDefaults(): void
    {
        $e = new TemplateException();
        $this->assertSame('A template error occurred.', $e->getMessage());
    }

    public function testTemplateExceptionCustomMessage(): void
    {
        $e = new TemplateException('Block "header" not found');
        $this->assertSame('Block "header" not found', $e->getMessage());
    }

    public function testTemplateExceptionExtendsRuntimeException(): void
    {
        $e = new TemplateException();
        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertNotInstanceOf(DatabaseException::class, $e);
    }

    // ── ModuleException ─────────────────────────────────────

    public function testModuleExceptionDefaults(): void
    {
        $e = new ModuleException();
        $this->assertSame('A module error occurred.', $e->getMessage());
    }

    public function testModuleExceptionExtendsRuntimeException(): void
    {
        $e = new ModuleException();
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    // ── ModuleLoadException ─────────────────────────────────

    public function testModuleLoadExceptionDefaults(): void
    {
        $e = new ModuleLoadException();
        $this->assertSame('Module failed to load.', $e->getMessage());
    }

    public function testModuleLoadExceptionExtendsModuleException(): void
    {
        $e = new ModuleLoadException('Controller not found');
        $this->assertInstanceOf(ModuleException::class, $e);
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testModuleLoadExceptionIsCatchableAsModuleException(): void
    {
        $caught = false;
        try {
            throw new ModuleLoadException('Missing prerequisite');
        } catch (ModuleException $e) {
            $caught = true;
            $this->assertInstanceOf(ModuleLoadException::class, $e);
        }
        $this->assertTrue($caught);
    }

    // ── ModuleConfigException ───────────────────────────────

    public function testModuleConfigExceptionDefaults(): void
    {
        $e = new ModuleConfigException();
        $this->assertSame('Invalid module configuration.', $e->getMessage());
    }

    public function testModuleConfigExceptionExtendsModuleException(): void
    {
        $e = new ModuleConfigException('Invalid module code format');
        $this->assertInstanceOf(ModuleException::class, $e);
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testModuleConfigExceptionIsCatchableAsModuleException(): void
    {
        $caught = false;
        try {
            throw new ModuleConfigException('Bad package.php');
        } catch (ModuleException $e) {
            $caught = true;
            $this->assertInstanceOf(ModuleConfigException::class, $e);
        }
        $this->assertTrue($caught);
    }

    // ── CacheException ──────────────────────────────────────

    public function testCacheExceptionDefaults(): void
    {
        $e = new CacheException();
        $this->assertSame('Cache operation failed.', $e->getMessage());
    }

    public function testCacheExceptionExtendsRuntimeException(): void
    {
        $e = new CacheException('Write failed');
        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertNotInstanceOf(DatabaseException::class, $e);
    }

    // ── NetworkException ────────────────────────────────────

    public function testNetworkExceptionDefaults(): void
    {
        $e = new NetworkException();
        $this->assertSame('Network operation failed.', $e->getMessage());
    }

    public function testNetworkExceptionExtendsRuntimeException(): void
    {
        $e = new NetworkException();
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    // ── FTPException ────────────────────────────────────────

    public function testFTPExceptionDefaults(): void
    {
        $e = new FTPException();
        $this->assertSame('FTP operation failed.', $e->getMessage());
    }

    public function testFTPExceptionExtendsNetworkException(): void
    {
        $e = new FTPException('Login failed');
        $this->assertInstanceOf(NetworkException::class, $e);
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testFTPExceptionIsCatchableAsNetworkException(): void
    {
        $caught = false;
        try {
            throw new FTPException('Connection refused');
        } catch (NetworkException $e) {
            $caught = true;
            $this->assertInstanceOf(FTPException::class, $e);
        }
        $this->assertTrue($caught);
    }

    // ── SSHException ────────────────────────────────────────

    public function testSSHExceptionDefaults(): void
    {
        $e = new SSHException();
        $this->assertSame('SSH/SFTP operation failed.', $e->getMessage());
    }

    public function testSSHExceptionExtendsNetworkException(): void
    {
        $e = new SSHException('Auth failed');
        $this->assertInstanceOf(NetworkException::class, $e);
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testSSHExceptionIsCatchableAsNetworkException(): void
    {
        $caught = false;
        try {
            throw new SSHException('Key rejected');
        } catch (NetworkException $e) {
            $caught = true;
            $this->assertInstanceOf(SSHException::class, $e);
        }
        $this->assertTrue($caught);
    }

    // ── ContainerException ──────────────────────────────────

    public function testContainerExceptionDefaults(): void
    {
        $e = new ContainerException();
        $this->assertSame('', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertNull($e->getPrevious());
    }

    public function testContainerExceptionCustomMessage(): void
    {
        $e = new ContainerException('Service not resolvable');
        $this->assertSame('Service not resolvable', $e->getMessage());
    }

    public function testContainerExceptionWithPrevious(): void
    {
        $prev = new \RuntimeException('inner');
        $e = new ContainerException('Container error', 42, $prev);
        $this->assertSame($prev, $e->getPrevious());
        $this->assertSame(42, $e->getCode());
    }

    public function testContainerExceptionExtendsRuntimeException(): void
    {
        $e = new ContainerException();
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testContainerExceptionImplementsPsrInterface(): void
    {
        $e = new ContainerException();
        $this->assertInstanceOf(ContainerExceptionInterface::class, $e);
    }

    // ── ContainerNotFoundException ──────────────────────────

    public function testContainerNotFoundExceptionDefaults(): void
    {
        $e = new ContainerNotFoundException();
        $this->assertSame('', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertNull($e->getPrevious());
    }

    public function testContainerNotFoundExceptionCustomMessage(): void
    {
        $e = new ContainerNotFoundException('Entry "foo" not found');
        $this->assertSame('Entry "foo" not found', $e->getMessage());
    }

    public function testContainerNotFoundExceptionWithPrevious(): void
    {
        $prev = new \RuntimeException('lookup failed');
        $e = new ContainerNotFoundException('Not found', 0, $prev);
        $this->assertSame($prev, $e->getPrevious());
    }

    public function testContainerNotFoundExceptionExtendsContainerException(): void
    {
        $e = new ContainerNotFoundException();
        $this->assertInstanceOf(ContainerException::class, $e);
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testContainerNotFoundExceptionImplementsPsrInterfaces(): void
    {
        $e = new ContainerNotFoundException();
        $this->assertInstanceOf(NotFoundExceptionInterface::class, $e);
        $this->assertInstanceOf(ContainerExceptionInterface::class, $e);
    }

    public function testContainerNotFoundExceptionIsCatchableAsContainerException(): void
    {
        $caught = false;
        try {
            throw new ContainerNotFoundException('missing');
        } catch (ContainerException $e) {
            $caught = true;
            $this->assertInstanceOf(ContainerNotFoundException::class, $e);
        }
        $this->assertTrue($caught);
    }

    // ── FileException ───────────────────────────────────────

    public function testFileExceptionDefaults(): void
    {
        $e = new FileException();
        $this->assertSame('File operation failed.', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertNull($e->getPrevious());
    }

    public function testFileExceptionCustomMessage(): void
    {
        $e = new FileException('File not readable');
        $this->assertSame('File not readable', $e->getMessage());
    }

    public function testFileExceptionWithPrevious(): void
    {
        $prev = new \RuntimeException('permission denied');
        $e = new FileException('Write failed', 13, $prev);
        $this->assertSame($prev, $e->getPrevious());
        $this->assertSame(13, $e->getCode());
    }

    public function testFileExceptionExtendsRuntimeException(): void
    {
        $e = new FileException();
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testFileExceptionIsNotDatabaseOrNetworkException(): void
    {
        $e = new FileException();
        $this->assertNotInstanceOf(DatabaseException::class, $e);
        $this->assertNotInstanceOf(NetworkException::class, $e);
    }

    // ── MailerException ─────────────────────────────────────

    public function testMailerExceptionDefaults(): void
    {
        $e = new MailerException();
        $this->assertSame('Mail operation failed.', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertNull($e->getPrevious());
    }

    public function testMailerExceptionCustomMessage(): void
    {
        $e = new MailerException('SMTP connection refused');
        $this->assertSame('SMTP connection refused', $e->getMessage());
    }

    public function testMailerExceptionWithPrevious(): void
    {
        $prev = new \RuntimeException('socket timeout');
        $e = new MailerException('Send failed', 110, $prev);
        $this->assertSame($prev, $e->getPrevious());
        $this->assertSame(110, $e->getCode());
    }

    public function testMailerExceptionExtendsNetworkException(): void
    {
        $e = new MailerException();
        $this->assertInstanceOf(NetworkException::class, $e);
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testMailerExceptionIsCatchableAsNetworkException(): void
    {
        $caught = false;
        try {
            throw new MailerException('Delivery failed');
        } catch (NetworkException $e) {
            $caught = true;
            $this->assertInstanceOf(MailerException::class, $e);
        }
        $this->assertTrue($caught);
    }

    // ── OAuthException ──────────────────────────────────────

    public function testOAuthExceptionDefaults(): void
    {
        $e = new OAuthException();
        $this->assertSame('OAuth operation failed.', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertNull($e->getPrevious());
    }

    public function testOAuthExceptionCustomMessage(): void
    {
        $e = new OAuthException('Token exchange failed');
        $this->assertSame('Token exchange failed', $e->getMessage());
    }

    public function testOAuthExceptionWithPrevious(): void
    {
        $prev = new \RuntimeException('HTTP 401');
        $e = new OAuthException('Unauthorized', 401, $prev);
        $this->assertSame($prev, $e->getPrevious());
        $this->assertSame(401, $e->getCode());
    }

    public function testOAuthExceptionExtendsRuntimeException(): void
    {
        $e = new OAuthException();
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testOAuthExceptionIsNotNetworkException(): void
    {
        $e = new OAuthException();
        $this->assertNotInstanceOf(NetworkException::class, $e);
        $this->assertNotInstanceOf(DatabaseException::class, $e);
    }

    // ── PipelineException ───────────────────────────────────

    public function testPipelineExceptionDefaults(): void
    {
        $e = new PipelineException();
        $this->assertSame('Pipeline operation failed.', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertNull($e->getPrevious());
    }

    public function testPipelineExceptionCustomMessage(): void
    {
        $e = new PipelineException('Plugin not found');
        $this->assertSame('Plugin not found', $e->getMessage());
    }

    public function testPipelineExceptionWithPrevious(): void
    {
        $prev = new \RuntimeException('action error');
        $e = new PipelineException('Action creation failed', 0, $prev);
        $this->assertSame($prev, $e->getPrevious());
    }

    public function testPipelineExceptionExtendsRuntimeException(): void
    {
        $e = new PipelineException();
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testPipelineExceptionIsNotModuleOrNetworkException(): void
    {
        $e = new PipelineException();
        $this->assertNotInstanceOf(ModuleException::class, $e);
        $this->assertNotInstanceOf(NetworkException::class, $e);
    }

    // ── RoutingException ────────────────────────────────────

    public function testRoutingExceptionDefaults(): void
    {
        $e = new RoutingException();
        $this->assertSame('Routing operation failed.', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertNull($e->getPrevious());
    }

    public function testRoutingExceptionCustomMessage(): void
    {
        $e = new RoutingException('Invalid route format');
        $this->assertSame('Invalid route format', $e->getMessage());
    }

    public function testRoutingExceptionWithPrevious(): void
    {
        $prev = new \RuntimeException('regex error');
        $e = new RoutingException('Shadow route conflict', 0, $prev);
        $this->assertSame($prev, $e->getPrevious());
    }

    public function testRoutingExceptionExtendsRuntimeException(): void
    {
        $e = new RoutingException();
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testRoutingExceptionIsNotHttpException(): void
    {
        $e = new RoutingException();
        $this->assertNotInstanceOf(HttpException::class, $e);
        $this->assertNotInstanceOf(ModuleException::class, $e);
    }

    // ── Cross-type catching ────────────────────────────────

    public function testMultipleExceptionCatchOrder(): void
    {
        // Simulate the main.php catch pattern: NotFoundException|RedirectException before Throwable
        $exceptions = [
            new NotFoundException(),
            new RedirectException('/url'),
            new ConfigurationException(),
            new HttpException(500),
        ];

        foreach ($exceptions as $ex) {
            $type = match (true) {
                $ex instanceof NotFoundException => 'notfound',
                $ex instanceof RedirectException => 'redirect',
                $ex instanceof ConfigurationException => 'config',
                $ex instanceof HttpException => 'http',
                default => 'unknown',
            };

            if ($ex instanceof NotFoundException) {
                $this->assertSame('notfound', $type);
            } elseif ($ex instanceof RedirectException) {
                $this->assertSame('redirect', $type);
            } elseif ($ex instanceof ConfigurationException) {
                $this->assertSame('config', $type);
            } elseif ($ex instanceof HttpException) {
                $this->assertSame('http', $type);
            }
        }
    }

    public function testSemanticExceptionHierarchyIsolation(): void
    {
        // Verify that different exception branches don't cross-catch
        $db = new DatabaseException();
        $tpl = new TemplateException();
        $mod = new ModuleException();
        $net = new NetworkException();
        $cache = new CacheException();
        $file = new FileException();
        $oauth = new OAuthException();
        $pipeline = new PipelineException();
        $routing = new RoutingException();
        $container = new ContainerException();

        // Each should be RuntimeException but NOT any other branch
        $this->assertInstanceOf(RuntimeException::class, $db);
        $this->assertInstanceOf(RuntimeException::class, $tpl);
        $this->assertInstanceOf(RuntimeException::class, $mod);
        $this->assertInstanceOf(RuntimeException::class, $net);
        $this->assertInstanceOf(RuntimeException::class, $cache);
        $this->assertInstanceOf(RuntimeException::class, $file);
        $this->assertInstanceOf(RuntimeException::class, $oauth);
        $this->assertInstanceOf(RuntimeException::class, $pipeline);
        $this->assertInstanceOf(RuntimeException::class, $routing);
        $this->assertInstanceOf(RuntimeException::class, $container);

        $this->assertNotInstanceOf(DatabaseException::class, $tpl);
        $this->assertNotInstanceOf(DatabaseException::class, $mod);
        $this->assertNotInstanceOf(DatabaseException::class, $net);
        $this->assertNotInstanceOf(DatabaseException::class, $file);
        $this->assertNotInstanceOf(DatabaseException::class, $oauth);
        $this->assertNotInstanceOf(DatabaseException::class, $pipeline);
        $this->assertNotInstanceOf(DatabaseException::class, $routing);
        $this->assertNotInstanceOf(DatabaseException::class, $container);
        $this->assertNotInstanceOf(TemplateException::class, $db);
        $this->assertNotInstanceOf(TemplateException::class, $mod);
        $this->assertNotInstanceOf(ModuleException::class, $db);
        $this->assertNotInstanceOf(NetworkException::class, $db);
        $this->assertNotInstanceOf(CacheException::class, $db);
        $this->assertNotInstanceOf(FileException::class, $db);
        $this->assertNotInstanceOf(OAuthException::class, $db);
        $this->assertNotInstanceOf(PipelineException::class, $db);
        $this->assertNotInstanceOf(RoutingException::class, $db);
        $this->assertNotInstanceOf(ContainerException::class, $db);
    }

    public function testFullHierarchyCatchChain(): void
    {
        // ConnectionException → DatabaseException → RuntimeException
        $e = new ConnectionException('test');
        $this->assertInstanceOf(ConnectionException::class, $e);
        $this->assertInstanceOf(DatabaseException::class, $e);
        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertInstanceOf(\Throwable::class, $e);

        // SSHException → NetworkException → RuntimeException
        $e2 = new SSHException('test');
        $this->assertInstanceOf(SSHException::class, $e2);
        $this->assertInstanceOf(NetworkException::class, $e2);
        $this->assertInstanceOf(RuntimeException::class, $e2);

        // ModuleLoadException → ModuleException → RuntimeException
        $e3 = new ModuleLoadException('test');
        $this->assertInstanceOf(ModuleLoadException::class, $e3);
        $this->assertInstanceOf(ModuleException::class, $e3);
        $this->assertInstanceOf(RuntimeException::class, $e3);

        // ContainerNotFoundException → ContainerException → RuntimeException
        $e4 = new ContainerNotFoundException('test');
        $this->assertInstanceOf(ContainerNotFoundException::class, $e4);
        $this->assertInstanceOf(ContainerException::class, $e4);
        $this->assertInstanceOf(RuntimeException::class, $e4);
        $this->assertInstanceOf(ContainerExceptionInterface::class, $e4);
        $this->assertInstanceOf(NotFoundExceptionInterface::class, $e4);

        // MailerException → NetworkException → RuntimeException
        $e5 = new MailerException('test');
        $this->assertInstanceOf(MailerException::class, $e5);
        $this->assertInstanceOf(NetworkException::class, $e5);
        $this->assertInstanceOf(RuntimeException::class, $e5);
    }
}
