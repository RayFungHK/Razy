<?php
/**
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Closure;
use Exception;
use Throwable;

/**
 * An app class used to isolate a system or routed site, for internal site-to-site communication.
 */
class Application
{
	/**
	 * The storage of the site alias
	 *
	 * @var array
	 */
	private static array $alias = [];
	/**
	 * The storage of the cached sites config
	 *
	 * @var array|null
	 */
	static private ?array $cachedConfig = null;
	/**
	 * The storage of the created Application instances
	 *
	 * @var array
	 */
	private static array $instances = [];
	/**
	 * The primary application instances
	 *
	 * @var Application|null
	 */
	private static ?Application $master = null;
	/**
	 * The storage of the site config
	 *
	 * @var array
	 */
	private static array $multisite = [];
	/**
	 * The registered distributor
	 *
	 * @var array
	 */
	private static array $registeredDist = [];
	/**
	 * The Domain entity
	 *
	 * @var Domain|null
	 */
	private ?Domain $domain = null;
	/**
	 * The unique ID
	 *
	 * @var string
	 */
	private string $guid;
	/**
	 * The Application entity that connected in via API
	 *
	 * @var Application|null
	 */
	private ?Application $peer = null;

	/**
	 * Container constructor.
	 *
	 * @param string $fqdn the well-formatted FQDN string
	 * @param Application|null $peer The Application instance which is connected
	 *
	 * @throws Throwable
	 */
	public function __construct(string $fqdn = '', self $peer = null)
	{
		// If the FQDN is empty, determine Application is called via CLI
		if (!empty($fqdn)) {
			$fqdn = format_fqdn($fqdn);
			if (!is_fqdn($fqdn, true)) {
				throw new Error('Invalid domain format, it should be a string in FQDN format.');
			}
		}

		$this->guid = spl_object_hash($this);

		if (null === self::$master) {
			self::$master = $this;
		} else {
			if (null === $peer || !array_key_exists($peer->getGUID(), self::$instances)) {
				throw new Error('You cannot create multiple root Container in one session.');
			}
			$this->peer = $peer;
		}
		if (!empty($fqdn)) {
			if (($this->domain = $this->matchDomain($fqdn)) === null) {
				throw new Error('No domain is matched.');
			}
		}

		// Add the instance to the list
		self::$instances[$this->guid] = $this;
	}

	/**
	 * Get the Application GUID.
	 *
	 * @return string The GUID of the Application Instance
	 */
	public function getGUID(): string
	{
		return $this->guid;
	}

	/**
	 * Get the Domain instance by given FQDN string.
	 *
	 * @param string $fqdn The well-formatted FQDN string used to match the domain
	 *
	 * @return Domain|null Return the matched Domain instance or return null if no FQDN has matched
	 *
	 * @throws Throwable
	 */
	private function matchDomain(string $fqdn): ?Domain
	{
		[$domain,] = explode(':', $fqdn, 2);

		// Get the path value from the multisite and alias list by the current domain
		if (array_key_exists($fqdn, self::$multisite)) {
			return new Domain($this, $fqdn, '', self::$multisite[$fqdn]);
		}

		if (array_key_exists($domain, self::$multisite)) {
			return new Domain($this, $domain, '', self::$multisite[$domain]);
		}

		if (array_key_exists($fqdn, self::$alias) && isset(self::$multisite[self::$alias[$fqdn]])) {
			return new Domain($this, self::$alias[$fqdn], $fqdn, self::$multisite[self::$alias[$fqdn]]);
		}

		if (array_key_exists($domain, self::$alias) && isset(self::$multisite[self::$alias[$domain]])) {
			return new Domain($this, self::$alias[$domain], $domain, self::$multisite[self::$alias[$domain]]);
		}

		foreach (self::$multisite as $pattern => $path) {
			if (is_fqdn($pattern, true)) {
				// If the FQDN string contains * (wildcard)
				if ('*' !== $pattern && str_contains($pattern, '*')) {
					$wildcard = preg_replace('/\\\\.(*SKIP)(*FAIL)|\*/', '[^.]+', $pattern);
					if (preg_match('/^' . $wildcard . '$/', $fqdn)) {
						return new Domain($this, $pattern, $fqdn, self::$multisite[$fqdn]);
					}
				}
			}
		}

		if (isset(self::$multisite['*'])) {
			// If there is a wildcard domain exists
			return new Domain($this, '*', $fqdn, self::$multisite['*']);
		}

		// Return null if no domain has matched
		return null;
	}

	/**
	 * @return array
	 */
	public static function GetDistributors(): array
	{
		return self::$registeredDist;
	}

	/**
	 * @param string $dist
	 * @return Distributor|null
	 */
	public static function GetDistributorByName(string $dist): ?Distributor
	{
		return self::$registeredDist[$dist] ?? null;
	}

	/**
	 * Unpack the distributor asset to the shared view.
	 *
	 * @param string $code
	 * @param Closure $closure
	 *
	 * @return int
	 *
	 * @throws Throwable
	 */
	public static function UnpackAsset(string $code, Closure $closure): int
	{
		$code = trim($code);
		if (CLI_MODE) {
			if (self::DistributorExists($code)) {
				return self::CreateDistributor(self::$registeredDist[$code])->unpackAllAsset($closure);
			}

			throw new Error('Distributor `' . $code . '` is not found.');
		}

		throw new Error('You can only access unpackAsset method via CLI.');
	}

	/**
	 * Check if the distributor is existing by given distributor code.
	 *
	 * @param string $code
	 *
	 * @return bool
	 */
	public static function DistributorExists(string $code): bool
	{
		return isset(self::$registeredDist[$code]);
	}

	/**
	 * Create the Distributor entity.
	 *
	 * @param array $distInfo
	 *
	 * @return mixed|Distributor
	 * @throws Throwable
	 */
	private static function CreateDistributor(array &$distInfo): mixed
	{
		$distInfo['entity'] = $distInfo['entity'] ?? new Distributor($distInfo['distributor_path'], $distInfo['distributor_alias']);

		return $distInfo['entity'];
	}

	/**
	 * Load the site configuration to setup the multisite setting.
	 *
	 * @throws Throwable
	 */
	public static function UpdateSites(): void
	{
		if (!defined('SYSTEM_ROOT')) {
			throw new Error('SYSTEM_ROOT is not defined, initialize failed.');
		}

		self::$multisite = [];
		self::$registeredDist = [];

		// Load the site configuration file
		$config = self::$cachedConfig ?: self::LoadSiteConfig();

		// Load extra alias and map to configured domain
		$aliasMapping = [];
		if (is_array($config['alias'])) {
			foreach ($config['alias'] as $alias => $domain) {
				if (is_string($domain)) {
					$domain = format_fqdn($domain);
					$alias = format_fqdn($alias);
					if (is_fqdn($domain, true) && is_fqdn($alias, true)) {
						$aliasMapping[$domain] = $aliasMapping[$domain] ?? [];
						$aliasMapping[$domain][] = $alias;
						self::$alias[$alias] = $domain;
					}
				}
			}
		}

		if (is_array($config['domains'] ?? null)) {
			foreach ($config['domains'] as $domain => $pathSet) {
				$domain = format_fqdn($domain);
				if (is_fqdn($domain, true) && is_array($pathSet)) {
					foreach ($pathSet as $urlPath => $distPath) {
						// Validate the distributor directory has the configuration file
						($validate = function ($distIdentify, $urlPath = '') use (&$validate, $domain, $aliasMapping) {
							[$path, $alias] = explode('@', $distIdentify);
							if (is_string($distIdentify)) {
								// Distributor should be unique, if the distribution code has been used, throw an error
								if (isset(self::$registeredDist[$distIdentify])) {
									throw new Error($distIdentify . ' has been declared already, please ensure the distributor code is not duplicated.');
								}

								// Declare an array if the domain is not existed
								self::$multisite[$domain] = self::$multisite[$domain] ?? [];

								self::$multisite[$domain][$urlPath] = $distIdentify;

								self::$registeredDist[$distIdentify] = [
									'distributor_path' => $path,
									'distributor_alias' => $alias ?: '*',
									'url_path' => $urlPath,
									'domain' => $domain,
									'alias' => $aliasMapping[$domain] ?? [],
								];
							} elseif (is_array($distIdentify)) {
								foreach ($distIdentify as $path => $pathSet) {
									$validate($pathSet, append($urlPath, $path));
								}
							}
						})($distPath, $urlPath);
					}
				}
			}
		}
	}

	/**
	 * @param string $code
	 * @param Closure $closure
	 *
	 * @return bool
	 *
	 * @throws Throwable
	 */
	public static function ValidatePackage(string $code, Closure $closure): bool
	{
		$code = trim($code);
		if (CLI_MODE) {
			if (self::DistributorExists($code)) {
				return self::CreateDistributor(self::$registeredDist[$code])->validatePackage($closure);
			}

			throw new Error('Distributor `' . $code . '` is not found.');
		}

		throw new Error('You can only access unpackAsset method via CLI.');
	}

	/**
	 * @param array $config
	 *
	 * @return bool
	 * @throws Throwable
	 */
	static public function WriteSiteConfig(array $config = []): bool
	{
		if (CLI_MODE) {
			$configFilePath = append(defined('RAZY_PATH') ? RAZY_PATH : SYSTEM_ROOT, 'sites.inc.php');

			// Write the config file
			$source = Template::LoadFile('phar://./' . PHAR_FILE . '/asset/setup/sites.inc.php.tpl');
			$root = $source->getRoot();

			foreach ($config['domains'] as $domainName => $sites) {
				$domainBlock = $root->newBlock('domain')->assign('domain', $domainName);
				foreach ($sites as $path => $code) {
					$domainBlock->newBlock('site')->assign([
						'path' => $path,
						'dist_code' => $code,
					]);
				}
			}

			foreach ($config['alias'] as $alias => $domain) {
				if (is_string($domain)) {
					$domain = trim($domain);
					if ($domain) {
						$root->newBlock('alias')->assign([
							'alias' => $alias,
							'domain' => $domain,
						]);
					}
				}
			}

			try {
				$file = fopen($configFilePath, 'w');
				if (!$file) {
					throw new Exception('Can\'t create lock file!');
				}

				if (flock($file, LOCK_EX)) {
					ftruncate($file, 0);
					fwrite($file, $source->output());
					fflush($file);
					flock($file, LOCK_UN);
				}

				self::$cachedConfig = $config;
				return true;
			} catch (Exception) {
				return false;
			}
		}

		throw new Error('You can only access this method via CLI.');
	}

	/**
	 * Update the rewrite.
	 *
	 * @return bool
	 * @throws Error
	 * @throws Throwable
	 */
	static public function UpdateRewriteRules(): bool
	{
		if (CLI_MODE) {
			$source = Template::LoadFile('phar://./' . PHAR_FILE . '/asset/setup/htaccess.tpl');
			$rootBlock = $source->getRoot();
			foreach (self::GetDistributors() as $info) {
				$domain = preg_quote($info['domain']);
				if (!preg_match('/:\d+$/', $domain)) {
					$domain .= '(:\d+)?';
				}
				try {
					$distributor = new Distributor($info['distributor_path'], $info['distributor_alias']);
					$modules = $distributor->getAllModules();
					foreach ($modules as $module) {
						$moduleInfo = $module->getModuleInfo();
						if (is_dir(append($moduleInfo->getPath(), 'webassets'))) {
							$rootBlock->newBlock('rewrite')->assign([
								'domain' => $domain,
								'dist_path' => ltrim(tidy(append($moduleInfo->getContainerPath(true), '$1', 'webassets', '$2')), '/'),
								'route_path' => ($info['url_path'] === '/') ? '' : ltrim($info['url_path'] . '/', '/'),
								'mapping' => $moduleInfo->getClassName(),
							]);
						}
					}
				} catch (Exception $e) {
					echo $e->getMessage();
					return false;
				}
			}
			file_put_contents(append(defined('RAZY_PATH') ? RAZY_PATH : SYSTEM_ROOT, '.htaccess'), $source->output());
			return true;
		}

		throw new Error('You can only allowed to access this method via CLI.');
	}

	/**
	 * @return array[]
	 * @throws Error
	 */
	static public function LoadSiteConfig(): array
	{
		$configFilePath = append(defined('RAZY_PATH') ? RAZY_PATH : SYSTEM_ROOT, 'sites.inc.php');

		// Load default config setting
		$config = [
			'domains' => [],
			'alias' => [],
		];

		// Import the config file setting
		try {
			if (is_file($configFilePath)) {
				$config = require $configFilePath;
			}
		} catch (Exception $e) {
			echo $e;

			exit;
		}

		// Domains config fixing & initialize
		if (!is_array($config['domains'] ?? null)) {
			$config['domains'] = [];
		}

		if (!is_array($config['alias'] ?? null)) {
			$config['alias'] = [];
		}

		self::$cachedConfig = $config;
		return $config;
	}

	/**
	 * Get the distributor's module information by given distributor code.
	 *
	 * @param string $code
	 *
	 * @return array
	 *
	 * @throws Throwable
	 */
	public static function GetDistributorModules(string $code): array
	{
		$code = trim($code);
		if (CLI_MODE) {
			if (self::DistributorExists($code)) {
				$modules = self::CreateDistributor(self::$registeredDist[$code])->getAllModules();
				$info = [];
				$status = [
					0 => 'Disabled',
					1 => 'Initialing',
					2 => 'Enabled',
					3 => 'Waiting Validation',
					4 => 'Preloading',
					5 => 'Loaded',
					-1 => 'Unloaded',
					-2 => 'Failed',
				];
				foreach ($modules as $module) {
					$info[] = [
						$module->getModuleInfo()->getCode(),
						$status[$module->getStatus()],
						$module->getModuleInfo()->getVersion(),
						$module->getModuleInfo()->getAuthor(),
						$module->getModuleInfo()->getAPICode(),
					];
				}

				return $info;
			}

			throw new Error('Distributor `' . $code . '` is not found.');
		}

		throw new Error('You can only access unpackAsset method via CLI.');
	}

	/**
	 * Connect to the specified domain under the same Razy file system.
	 *
	 * @param string $fqdn The well-formatted FQDN string
	 *
	 * @return API|null The API instance or return null if the domain is not found
	 *
	 * @throws Throwable
	 */
	public function connect(string $fqdn): ?API
	{
		if (!$this->domain) {
			throw new Error('No domain was matched that allowed to connect.');
		}
		$fqdn = tidy($fqdn, true, '/');
		[$domain, $urlQuery] = explode('/', $fqdn, 2);

		// If the domain is not matched or another error occurred, return null
		try {
			$app = new self($domain, $this);
		} catch (Exception) {
			return null;
		}

		$internalSite = $app->getDomain();
		if (null !== $internalSite && !$app->query($urlQuery, true)) {
			throw new Error('No distribution was found.');
		}

		return $app->domain->getAPI();
	}

	/**
	 * Get the Domain instance.
	 *
	 * @return Domain|null The Domain instance
	 */
	public function getDomain(): ?Domain
	{
		return $this->domain;
	}

	/**
	 * Start to query the distributor by the URL query.
	 *
	 * @param string $urlQuery The URL query string
	 * @param bool $exactly Match the Distributor path exactly
	 *
	 * @return bool Return true if Distributor is matched
	 *
	 * @throws Throwable
	 */
	public function query(string $urlQuery, bool $exactly = false): bool
	{
		if (!$this->domain) {
			throw new Error('No domain was matched that no query is allowed.');
		}
		$distributor = $this->domain->matchQuery($urlQuery, $exactly);
		if (null === $distributor) {
			return false;
		}

		if (null === $this->peer) {
			if (!$distributor->matchRoute()) {
				Error::Show404();
			}
		}

		return true;
	}

	/**
	 * Get the Application instance.
	 *
	 * @return Application|null The Application instance which is connected
	 */
	public function getPeer(): ?self
	{
		return $this->peer;
	}
}
