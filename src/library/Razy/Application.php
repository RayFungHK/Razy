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
	 * @var array
	 */
	private static array $multisite = [];

	/**
	 * @var array
	 */
	private static array $alias = [];

	/**
	 * @var null|Application
	 */
	private static ?Application $master = null;

	/**
	 * @var array
	 */
	private static array $instances = [];

	/**
	 * @var null|Domain
	 */
	private ?Domain $domain = null;

	/**
	 * @var null|Application
	 */
	private ?Application $peer = null;

	/**
	 * @var array
	 */
	private static array $registeredDist = [];

	/**
	 * @var string
	 */
	private string $guid;

	/**
	 * Container constructor.
	 *
	 * @param string           $fqdn the well-formatted FQDN string
	 * @param null|Application $peer The Application instance which is connected
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
	 * @param string   $code
	 * @param Closure $closure
	 *
	 * @return bool
	 * @throws Throwable
	 *
	 */
	public static function ValidatePackage(string $code, Closure $closure): bool
	{
		$code = trim($code);
		if (CLI_MODE) {
			if (self::distributorExists($code)) {
				return (self::createDistributor(self::$registeredDist[$code]))->validatePackage($closure);
			}

			throw new Error('Distributor `' . $code . '` is not found.');
		}

		throw new Error('You can only access unpackAsset method via CLI.');
	}

	/**
	 * Load the site configuration to setup the multisite setting.
	 *
	 * @throws Throwable
	 */
	public static function UpdateSites()
	{
		if (!defined('SYSTEM_ROOT')) {
			throw new Error('SYSTEM_ROOT is not defined, initialize failed.');
		}

		self::$multisite      = [];
		self::$registeredDist = [];
		// Load the site configuration file
		$config = require SYSTEM_ROOT . DIRECTORY_SEPARATOR . 'sites.inc.php';

		// Load extra alias and map to configured domain
		$aliasMapping = [];
		if (is_array($config['alias'])) {
			foreach ($config['alias'] as $alias => $domain) {
				if (is_string($domain)) {
					$domain = format_fqdn($domain);
					$alias  = format_fqdn($alias);
					if (is_fqdn($domain, true) && is_fqdn($alias, true)) {
						$aliasMapping[$domain]   = $aliasMapping[$domain] ?? [];
						$aliasMapping[$domain][] = $alias;
						self::$alias[$alias]     = $domain;
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
						($validate = function ($distPath, $urlPath = '') use (&$validate, $domain, $aliasMapping) {
							if (is_string($distPath)) {
								// Check the distributor config first
								$configFile = append(SITES_FOLDER, $distPath, 'dist.php');
								if (is_file($configFile)) {
									try {
										// Load the distributor config file
										$distConfig = require $configFile;

										// Ensure the config file is in valid format and the code is not empty
										if (is_array($distConfig) && is_string($distConfig['dist'])) {
											$distConfig['dist'] = trim($distConfig['dist']);
											if ($distConfig['dist']) {
												// Distributor should be unique, if the distribution code has been used, throw an error
												if (isset(self::$registeredDist[$distConfig['dist']])) {
													throw new Error($distConfig['dist'] . ' has been declared already, please ensure the distributor code is not duplicated.');
												}

												// Declare an array if the domain is not exists
												self::$multisite[$domain] = self::$multisite[$domain] ?? [];

												self::$multisite[$domain][$urlPath] = $distPath;

												self::$registeredDist[$distConfig['dist']] = [
													'distributor_path' => $distPath,
													'url_path'         => $urlPath,
													'domain'           => $domain,
													'alias'            => $aliasMapping[$domain] ?? [],
												];
											}
										}
									} catch (Exception $e) {
									}
								}
							} elseif (is_array($distPath)) {
								foreach ($distPath as $path => $pathSet) {
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
	 * @return array
	 */
	public static function GetDistributors(): array
	{
		return self::$registeredDist;
	}

	/**
	 * Get the Application instance.
	 *
	 * @return null|Application The Application instance which is connected
	 */
	public function getPeer(): ?self
	{
		return $this->peer;
	}

	/**
	 * Connect to the specified domain under the same Razy file system.
	 *
	 * @param string $fqdn The well-formatted FQDN string
	 *
	 * @throws Throwable
	 *
	 * @return null|API The API instance or return null if the domain is not found
	 */
	public function connect(string $fqdn): ?API
	{
		if (!$this->domain) {
			throw new Error('No domain was matched that allowed to connect.');
		}
		$fqdn                = tidy($fqdn, true, '/');
		[$domain, $urlQuery] = explode('/', $fqdn, 2);

		// If the domain is not matched or other error occurred, return null
		try {
			$app = new self($domain, $this);
		} catch (Exception $e) {
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
	 * @return null|Domain The Domain instance
	 */
	public function getDomain(): ?Domain
	{
		return $this->domain;
	}

	/**
	 * Start query the distributor by the URL query.
	 *
	 * @param string $urlQuery The URL query string
	 * @param bool   $exactly  Match the Distributor path exactly
	 *
	 * @throws Throwable
	 *
	 * @return bool Return true if Distributor is matched
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

		if (WEB_MODE && null === $this->peer) {
			if (!$distributor->matchRoute()) {
				Error::Show404();
			}
		}

		return true;
	}

	/**
	 * @param string $code
	 *
	 * @return bool
	 */
	public static function distributorExists(string $code): bool
	{
		return isset(self::$registeredDist[$code]);
	}

	/**
	 * @param string   $code
	 * @param Closure $closure
	 *
	 * @return int
	 *@throws Throwable
	 *
	 */
	public static function UnpackAsset(string $code, Closure $closure): int
	{
		$code = trim($code);
		if (CLI_MODE) {
			if (self::distributorExists($code)) {
				return (self::createDistributor(self::$registeredDist[$code]))->unpackAllAsset($closure);
			}

			throw new Error('Distributor `' . $code . '` is not found.');
		}

		throw new Error('You can only access unpackAsset method via CLI.');
	}

	/**
	 * @param string $code
	 *
	 * @throws Throwable
	 *
	 * @return array
	 */
	public static function getDistributorModules(string $code): array
	{
		$code = trim($code);
		if (CLI_MODE) {
			if (self::distributorExists($code)) {
				$modules = (self::createDistributor(self::$registeredDist[$code]))->getAllModules();
				$info    = [];
				$status  = [
					0 => 'Disabled',
					1 => 'Initialing',
					2 => 'Enabled',
					3 => 'Loaded',
					4 => 'Disabled',
					5 => 'Failed',
				];
				foreach ($modules as $module) {
					$info[] = [
						$module->getCode(),
						$status[$module->getStatus()],
						$module->getVersion(),
						$module->getAuthor(),
						$module->getAPICode(),
					];
				}

				return $info;
			}

			throw new Error('Distributor `' . $code . '` is not found.');
		}

		throw new Error('You can only access unpackAsset method via CLI.');
	}

	/**
	 * @return null|Application
	 */
	public static function GetMaster(): ?Application
	{
		return self::$master;
	}

	/**
	 * Get the Domain instance by given FQDN string.
	 *
	 * @param string $fqdn The well-formatted FQDN string used to match the domain
	 *
	 * @throws Throwable
	 *
	 * @return null|Domain Return the matched Domain instance or return null if no FQDN has matched
	 */
	private function matchDomain(string $fqdn): ?Domain
	{
		// Get the path value from the multisite and alias list by th current domain
		if (array_key_exists($fqdn, self::$multisite)) {
			return new Domain($this, $fqdn, '', self::$multisite[$fqdn]);
		}

		if (array_key_exists($fqdn, self::$alias) && isset(self::$multisite[self::$alias[$fqdn]])) {
			return new Domain($this, self::$alias[$fqdn], $fqdn, self::$multisite[self::$alias[$fqdn]]);
		}

		foreach (self::$multisite as $pattern => $path) {
			if (is_fqdn($pattern, true)) {
				// If the FQDN string contains * (wildcard)
				if ('*' !== $pattern && false !== strpos($pattern, '*')) {
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
	 * @param array $distInfo
	 *
	 * @throws Throwable
	 *
	 * @return Distributor|mixed
	 */
	private static function createDistributor(array &$distInfo)
	{
		$distInfo['entity'] = $distInfo['entity'] ?? new Distributor($distInfo['distributor_path']);

		return $distInfo['entity'];
	}
}
