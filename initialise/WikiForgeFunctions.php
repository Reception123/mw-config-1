<?php

use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\RemoteWiki;
use Miraheze\ManageWiki\Helpers\ManageWikiSettings;
use Wikimedia\Rdbms\DBConnRef;

class WikiForgeFunctions {

	/** @var string */
	public $dbname;

	/** @var string */
	public $hostname;

	/** @var bool */
	public $missing;

	/** @var string */
	public $server;

	/** @var string */
	public $sitename;

	/** @var string */
	public $version;

	/** @var array */
	public $wikiDBClusters;

	/** @var array */
	public static $disabledExtensions = [];

	private const CACHE_DIRECTORY = '/srv/mediawiki/cache';

	private const DEFAULT_SERVER = 'wikiforge.net';

	private const GLOBAL_DATABASE = 'prodglobal';

	private const MEDIAWIKI_DIRECTORY = '/srv/mediawiki/';

	public const MEDIAWIKI_VERSIONS = [
		'alpha' => '1.41',
		'beta' => '1.40',
		'legacy' => '1.38',
		'legacy-lts' => '1.35',
		'lts' => '1.39',
		'stable' => '1.39',
	];

	public const SUFFIXES = [
		'wiki' => 'wikiforge.net',
	];

	public function __construct() {
		self::setupHooks();
		self::setupSiteConfiguration();

		$this->dbname = self::getCurrentDatabase();
		$this->wikiDBClusters = self::getDatabaseClusters();

		$this->server = self::getServer();
		$this->sitename = self::getSiteName();
		$this->missing = self::isMissing();
		$this->version = self::getMediaWikiVersion();

		$this->hostname = $_SERVER['HTTP_HOST'] ??
			parse_url( $this->server, PHP_URL_HOST ) ?: 'undefined';

		$this->setDatabase();
		$this->setServers();
		$this->setSiteNames();
	}

	/** @var string */
	private static $currentDatabase;

	/**
	 * @return ?array
	 */
	public static function getLocalDatabases(): ?array {
		global $wgLocalDatabases;

		static $databases = null;

		self::$currentDatabase ??= self::getCurrentDatabase();

		// We need the CLI to be able to access 'deleted' wikis
		if ( PHP_SAPI === 'cli' ) {
			$databases ??= array_merge( self::readDbListFile( 'databases' ), self::readDbListFile( 'deleted' ) );
		}

		$databases ??= self::readDbListFile( 'databases' );

		$wgLocalDatabases = $databases;
		return $databases;
	}

	/**
	 * @param string $dblist
	 * @param bool $onlyDBs
	 * @param ?string $database
	 * @param bool $fromServer
	 * @return array|string
	 */
	public static function readDbListFile( string $dblist, bool $onlyDBs = true, ?string $database = null, bool $fromServer = false ) {
		if ( $database && $onlyDBs && !$fromServer ) {
			return $database;
		}

		if ( !file_exists( self::CACHE_DIRECTORY . "/{$dblist}.json" ) ) {
			$databases = [];

			return $databases;
		} else {
			$databasesArray = json_decode( file_get_contents( self::CACHE_DIRECTORY . "/{$dblist}.json" ), true );
		}

		if ( $database ) {
			if ( $fromServer ) {
				$server = $database;
				$database = '';
				foreach ( $databasesArray['combi'] ?? $databasesArray['databases'] as $key => $data ) {
					if ( isset( $data['u'] ) && $data['u'] === $server ) {
						$database = $key;
						break;
					}
				}

				if ( $onlyDBs ) {
					return $database;
				}
			}

			if ( isset( $databasesArray['combi'][$database] ) || isset( $databasesArray['databases'][$database] ) ) {
				return $databasesArray['combi'][$database] ?? $databasesArray['databases'][$database];
			} else {
				return '';
			}
		} else {
			global $wgDatabaseClustersMaintenance;

			$databases = $databasesArray['combi'] ?? $databasesArray['databases'];

			if ( $wgDatabaseClustersMaintenance ) {
				$databases = array_filter( $databases, static function ( $data, $key ) {
					global $wgDBname, $wgCommandLineMode, $wgDatabaseClustersMaintenance;

					if ( $wgDBname && $key === $wgDBname ) {
						if ( !$wgCommandLineMode && in_array( $data['c'], $wgDatabaseClustersMaintenance ) ) {
							require_once '/srv/mediawiki/ErrorPages/databaseMaintenance.php';
						}
					}

					return true;
				}, ARRAY_FILTER_USE_BOTH );
			}
		}

		if ( $onlyDBs ) {
			return array_keys( $databases );
		}

		return $databases;
	}

	public static function setupHooks() {
		global $wgHooks, $wgExtensionFunctions;

		$wgHooks['CreateWikiJsonGenerateDatabaseList'][] = 'WikiForgeFunctions::onGenerateDatabaseLists';
		$wgHooks['ManageWikiCoreAddFormFields'][] = 'WikiForgeFunctions::onManageWikiCoreAddFormFields';
		$wgHooks['ManageWikiCoreFormSubmission'][] = 'WikiForgeFunctions::onManageWikiCoreFormSubmission';
		$wgHooks['MediaWikiServices'][] = 'WikiForgeFunctions::onMediaWikiServices';

		$wgExtensionFunctions[] = 'WikiForgeFunctions::onExtensionFunctions';
	}

	public static function setupSiteConfiguration() {
		global $wgConf;

		$wgConf = new SiteConfiguration();

		$wgConf->suffixes = array_keys( self::SUFFIXES );
		$wgConf->wikis = self::getLocalDatabases();
	}

	/**
	 * @return string
	 */
	public static function getCurrentSuffix(): string {
		return array_flip( self::SUFFIXES )[ self::DEFAULT_SERVER ];
	}

	/**
	 * @param ?string $database
	 * @param bool $deleted
	 * @return array|string
	 */
	public static function getServers( ?string $database = null, bool $deleted = false ) {
		global $wgConf;

		if ( $database !== null ) {
			if ( $wgConf->get( 'wgServer', $database ) ) {
				return $wgConf->get( 'wgServer', $database );
			}

			if ( isset( $wgConf->settings['wgServer'] ) && count( $wgConf->settings['wgServer'] ) > 1 ) {
				return 'https://' . self::DEFAULT_SERVER;
			}
		}

		if ( isset( $wgConf->settings['wgServer'] ) && count( $wgConf->settings['wgServer'] ) > 1 ) {
			return $wgConf->settings['wgServer'];
		}

		$servers = [];

		static $default = null;

		self::$currentDatabase ??= self::getCurrentDatabase();

		$databases = self::readDbListFile( 'databases', false, $database );

		if ( $deleted && $databases ) {
			$databases += self::readDbListFile( 'deleted', false, $database );
		}

		if ( $database !== null ) {
			if ( is_string( $database ) && $database !== 'default' ) {
				foreach ( array_flip( self::SUFFIXES ) as $suffix ) {
					if ( substr( $database, -strlen( $suffix ) ) === $suffix ) {
						return $databases['u'] ?? 'https://' . substr( $database, 0, -strlen( $suffix ) ) . '.' . self::SUFFIXES[$suffix];
					}
				}
			}

			$default ??= 'https://' . self::DEFAULT_SERVER;
			return $default;
		}

		foreach ( $databases as $db => $data ) {
			foreach ( array_flip( self::SUFFIXES ) as $suffix ) {
				if ( substr( $db, -strlen( $suffix ) ) === $suffix ) {
					$servers[$db] = $data['u'] ?? 'https://' . substr( $db, 0, -strlen( $suffix ) ) . '.' . self::SUFFIXES[$suffix];
				}
			}
		}

		$default ??= 'https://' . self::DEFAULT_SERVER;
		$servers['default'] = $default;

		return $servers;
	}

	/**
	 * @return string
	 */
	public static function getCurrentDatabase(): string {
		if ( defined( 'MW_DB' ) ) {
			return MW_DB;
		}

		$hostname = $_SERVER['HTTP_HOST'] ?? 'undefined';

		static $database = null;
		$database ??= self::readDbListFile( 'databases', true, 'https://' . $hostname, true );

		if ( $database ) {
			return $database;
		}

		$explode = explode( '.', $hostname, 2 );

		if ( $explode[0] === 'www' ) {
			$explode = explode( '.', $explode[1], 2 );
		}

		foreach ( self::SUFFIXES as $suffix => $site ) {
			if ( $explode[1] === $site ) {
				return $explode[0] . $suffix;
			}
		}

		return '';
	}

	public function setDatabase() {
		global $wgConf, $wgDBname;

		$wgConf->settings['wgDBname'][$this->dbname] = $this->dbname;
		$wgDBname = $this->dbname;
	}

	/**
	 * @return ?array
	 */
	public static function getDatabaseClusters(): ?array {
		static $allDatabases = null;
		static $deletedDatabases = null;

		$allDatabases ??= self::readDbListFile( 'databases', false );
		$deletedDatabases ??= self::readDbListFile( 'deleted', false );

		$databases = array_merge( $allDatabases, $deletedDatabases );

		$clusters = array_column( $databases, 'c' );

		return array_combine( array_keys( $databases ), $clusters );
	}

	/**
	 * @return string
	 */
	public static function getServer(): string {
		self::$currentDatabase ??= self::getCurrentDatabase();

		return self::getServers( self::$currentDatabase ?: 'default' );
	}

	public function setServers() {
		global $wgConf, $wgServer;

		$wgConf->settings['wgServer'] = self::getServers( null, true );
		$wgServer = $this->server;
	}

	public function setSiteNames() {
		global $wgConf, $wgSitename;

		$wgConf->settings['wgSitename'] = self::getSiteNames();
		$wgSitename = $this->sitename;
	}

	/**
	 * @return array
	 */
	public static function getSiteNames(): array {
		static $allDatabases = null;
		static $deletedDatabases = null;

		$allDatabases ??= self::readDbListFile( 'databases', false );
		$deletedDatabases ??= self::readDbListFile( 'deleted', false );

		$databases = array_merge( $allDatabases, $deletedDatabases );

		$siteNameColumn = array_column( $databases, 's' );

		$siteNames = array_combine( array_keys( $databases ), $siteNameColumn );
		$siteNames['default'] = 'No sitename set.';

		return $siteNames;
	}

	/**
	 * @return string
	 */
	public static function getSiteName(): string {
		self::$currentDatabase ??= self::getCurrentDatabase();

		return self::getSiteNames()[ self::$currentDatabase ] ?? self::getSiteNames()['default'];
	}

	/**
	 * @return string
	 */
	public static function getDefaultMediaWikiVersion(): string {
		return php_uname( 'n' ) === 'test1.wikiforge.net' ? 'beta' : 'stable';
	}

	/**
	 * @param ?string $database
	 * @return string
	 */
	public static function getMediaWikiVersion( ?string $database = null ): string {
		if ( getenv( 'WIKIFORGE_WIKI_VERSION' ) ) {
			return getenv( 'WIKIFORGE_WIKI_VERSION' );
		}

		if ( $database ) {
			$mwVersion = self::readDbListFile( 'databases', false, $database )['v'] ?? null;
			return $mwVersion ?? self::MEDIAWIKI_VERSIONS[self::getDefaultMediaWikiVersion()];
		}

		static $version = null;

		if ( PHP_SAPI === 'cli' ) {
			$version ??= explode( '/', $_SERVER['SCRIPT_NAME'] )[3] ?? null;
			if ( !in_array( $version, self::MEDIAWIKI_VERSIONS ) ) {
				$version = null;
			}
		}

		self::$currentDatabase ??= self::getCurrentDatabase();
		$version ??= self::readDbListFile( 'databases', false, self::$currentDatabase )['v'] ?? null;

		return $version ?? self::MEDIAWIKI_VERSIONS[self::getDefaultMediaWikiVersion()];
	}

	/**
	 * @param string $file
	 * @return string
	 */
	public static function getMediaWiki( string $file ): string {
		global $IP;

		$IP = self::MEDIAWIKI_DIRECTORY . self::getMediaWikiVersion();

		chdir( $IP );
		putenv( "MW_INSTALL_PATH=$IP" );

		return $IP . '/' . $file;
	}

	/**
	 * @return bool
	 */
	public static function isMissing(): bool {
		global $wgConf;

		self::$currentDatabase ??= self::getCurrentDatabase();

		return !in_array( self::$currentDatabase, $wgConf->wikis );
	}

	/**
	 * @return array
	 */
	public static function getCacheArray(): array {
		self::$currentDatabase ??= self::getCurrentDatabase();

		// If we don't have a cache file, let us exit here
		if ( !file_exists( self::CACHE_DIRECTORY . '/' . self::$currentDatabase . '.json' ) ) {
			return [];
		}

		return (array)json_decode( file_get_contents(
			self::CACHE_DIRECTORY . '/' . self::$currentDatabase . '.json'
		), true );
	}

	/** @var array */
	private static $activeExtensions;

	/**
	 * @return array
	 */
	public static function getConfigGlobals(): array {
		global $wgDBname, $wgConf;

		// Try configuration cache
		$confCacheFileName = "config-$wgDBname.json";

		// To-Do: merge ManageWiki cache with main config cache,
		// to automatically update when ManageWiki is updated
		$confActualMtime = max(
			// When config files are updated
			filemtime( __DIR__ . '/../LocalSettings.php' ),
			filemtime( __DIR__ . '/../ManageWikiExtensions.php' ),
			filemtime( __DIR__ . '/../ManageWikiNamespaces.php' ),
			filemtime( __DIR__ . '/../ManageWikiSettings.php' ),

			// When MediaWiki is upgraded
			filemtime( MW_INSTALL_PATH . '/includes/Defines.php' ),

			// When ManageWiki is changed
			@filemtime( self::CACHE_DIRECTORY . '/' . $wgDBname . '.json' )
		);

		static $globals = null;
		$globals ??= self::readFromCache(
			self::CACHE_DIRECTORY . '/' . $confCacheFileName,
			$confActualMtime
		);

		if ( !$globals ) {
			$wgConf->settings = array_merge(
				$wgConf->settings,
				self::getManageWikiConfigCache()
			);

			self::$activeExtensions ??= self::getActiveExtensions();

			$globals = self::getConfigForCaching();

			$confCacheObject = [ 'mtime' => $confActualMtime, 'globals' => $globals, 'extensions' => self::$activeExtensions ];

			$minTime = $confActualMtime + intval( ini_get( 'opcache.revalidate_freq' ) );
			if ( time() > $minTime ) {
				self::writeToCache(
					$confCacheFileName, $confCacheObject
				);
			}
		}

		return $globals;
	}

	/**
	 * @return array
	 */
	public static function getConfigForCaching(): array {
		global $wgDBname, $wgConf;

		$wikiTags = [];

		static $cacheArray = null;
		$cacheArray ??= self::getCacheArray();

		$wikiTags[] = self::getMediaWikiVersion();
		foreach ( $cacheArray['states'] ?? [] as $state => $value ) {
			if ( $value !== 'exempt' && (bool)$value ) {
				$wikiTags[] = $state;
			}
		}

		self::$activeExtensions ??= self::getActiveExtensions();
		$wikiTags = array_merge( preg_filter( '/^/', 'ext-',
				str_replace( ' ', '', self::$activeExtensions )
			), $wikiTags
		);

		list( $site, $lang ) = $wgConf->siteFromDB( $wgDBname );
		$dbSuffix = self::getCurrentSuffix();

		$confParams = [
			'lang' => $lang,
			'site' => $site,
		];

		$globals = $wgConf->getAll( $wgDBname, $dbSuffix, $confParams, $wikiTags );

		return $globals;
	}

	/**
	 * @param string $cacheShard
	 * @param array $configObject
	 */
	public static function writeToCache( string $cacheShard, array $configObject ) {
		@mkdir( self::CACHE_DIRECTORY );
		$tmpFile = tempnam( '/tmp/', $cacheShard );

		$cacheObject = json_encode(
			$configObject,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) . "\n";

		if ( $tmpFile ) {
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				trigger_error( 'Config cache failure: Encoding failed', E_USER_ERROR );
			} else {
				if ( file_put_contents( $tmpFile, $cacheObject ) ) {
					if ( rename( $tmpFile, self::CACHE_DIRECTORY . '/' . $cacheShard ) ) {
						return;
					}
				}
			}

			unlink( $tmpFile );
		}
	}

	/**
	 * @param string $confCacheFile
	 * @param string $confActualMtime
	 * @param string $type
	 * @return ?array
	 */
	public static function readFromCache(
		string $confCacheFile,
		string $confActualMtime,
		string $type = 'globals'
	): ?array {
		$cacheRecord = @file_get_contents( $confCacheFile );

		if ( $cacheRecord !== false ) {
			$cacheObject = json_decode( $cacheRecord, true );

			if ( json_last_error() === JSON_ERROR_NONE ) {
				if ( ( $cacheObject['mtime'] ?? null ) == $confActualMtime ) {
					return $cacheObject[$type] ?? null;
				}
			} else {
				trigger_error( 'Config cache failure: Decoding failed', E_USER_ERROR );
			}
		}

		return null;
	}

	/**
	 * @return array
	 */
	public static function getManageWikiConfigCache(): array {
		static $cacheArray = null;
		$cacheArray ??= self::getCacheArray();

		if ( !$cacheArray ) {
			return [];
		}

		$settings = [];

		// Assign language code
		$settings['wgLanguageCode']['default'] = $cacheArray['core']['wgLanguageCode'];

		// Assign states
		$settings['cwPrivate']['default'] = (bool)$cacheArray['states']['private'];

		// Assign settings
		if ( isset( $cacheArray['settings'] ) ) {
			foreach ( $cacheArray['settings'] as $var => $val ) {
				$settings[$var]['default'] = $val;
			}
		}

		// Handle namespaces
		if ( isset( $cacheArray['namespaces'] ) ) {
			foreach ( $cacheArray['namespaces'] as $name => $ns ) {
				$settings['wgExtraNamespaces']['default'][(int)$ns['id']] = $name;
				$settings['wgNamespacesToBeSearchedDefault']['default'][(int)$ns['id']] = $ns['searchable'];
				$settings['wgNamespacesWithSubpages']['default'][(int)$ns['id']] = $ns['subpages'];
				$settings['wgNamespaceContentModels']['default'][(int)$ns['id']] = $ns['contentmodel'];

				if ( $ns['content'] ) {
					$settings['wgContentNamespaces']['default'][] = (int)$ns['id'];
				}

				if ( $ns['protection'] ) {
					$settings['wgNamespaceProtection']['default'][(int)$ns['id']] = [ $ns['protection'] ];
				}

				foreach ( (array)$ns['aliases'] as $alias ) {
					$settings['wgNamespaceAliases']['default'][$alias] = (int)$ns['id'];
				}
			}
		}

		// Handle Permissions
		if ( isset( $cacheArray['permissions'] ) ) {
			foreach ( $cacheArray['permissions'] as $group => $perm ) {
				foreach ( (array)$perm['permissions'] as $id => $right ) {
					$settings['wgGroupPermissions']['default'][$group][$right] = true;
				}

				foreach ( (array)$perm['addgroups'] as $name ) {
					$settings['wgAddGroups']['default'][$group][] = $name;
				}

				foreach ( (array)$perm['removegroups'] as $name ) {
					$settings['wgRemoveGroups']['default'][$group][] = $name;
				}

				foreach ( (array)$perm['addself'] as $name ) {
					$settings['wgGroupsAddToSelf']['default'][$group][] = $name;
				}

				foreach ( (array)$perm['removeself'] as $name ) {
					$settings['wgGroupsRemoveFromSelf']['default'][$group][] = $name;
				}

				if ( $perm['autopromote'] !== null ) {
					$onceId = array_search( 'once', $perm['autopromote'] );

					if ( !is_bool( $onceId ) ) {
						unset( $perm['autopromote'][$onceId] );
						$promoteVar = 'wgAutopromoteOnce';
					} else {
						$promoteVar = 'wgAutopromote';
					}

					$settings[$promoteVar]['default'][$group] = $perm['autopromote'];
				}
			}
		}

		return $settings;
	}

	/**
	 * @param string $setting
	 * @param string $wiki
	 * @return mixed
	 */
	public function getSettingValue( string $setting, string $wiki = 'default' ) {
		global $wgConf;

		static $cacheArray = null;
		$cacheArray ??= self::getCacheArray();

		if ( !$cacheArray ) {
			return $wgConf->get( $setting, $wiki );
		}

		return $cacheArray['settings'][$setting] ?? $wgConf->get( $setting, $wiki );
	}

	/**
	 * @return array
	 */
	public static function getActiveExtensions(): array {
		global $wgDBname;

		$confCacheFileName = "config-$wgDBname.json";

		// To-Do: merge ManageWiki cache with main config cache,
		// to automatically update when ManageWiki is updated
		$confActualMtime = max(
			// When config files are updated
			filemtime( __DIR__ . '/../LocalSettings.php' ),
			filemtime( __DIR__ . '/../ManageWikiExtensions.php' ),

			// When MediaWiki is upgraded
			filemtime( MW_INSTALL_PATH . '/includes/Defines.php' ),

			// When ManageWiki is changed
			@filemtime( self::CACHE_DIRECTORY . '/' . $wgDBname . '.json' )
		);

		static $extensions = null;
		$extensions ??= self::readFromCache(
			self::CACHE_DIRECTORY . '/' . $confCacheFileName,
			$confActualMtime,
			'extensions'
		);

		if ( $extensions ) {
			return $extensions;
		}

		static $cacheArray = null;
		$cacheArray ??= self::getCacheArray();

		if ( !$cacheArray ) {
			return [];
		}

		global $wgManageWikiExtensions;

		$allExtensions = array_filter( array_combine(
			array_column( $wgManageWikiExtensions, 'name' ),
			array_keys( $wgManageWikiExtensions )
		) );

		$enabledExtensions = array_keys(
			array_diff( $allExtensions, static::$disabledExtensions )
		);

		return array_keys( array_intersect_key(
			$allExtensions,
			array_intersect(
				array_flip( $cacheArray['extensions'] ?? [] ),
				array_flip( $enabledExtensions )
			)
		) );
	}

	/**
	 * @param string $extension
	 * @return bool
	 */
	public function isExtensionActive( string $extension ): bool {
		self::$activeExtensions ??= self::getActiveExtensions();
		return in_array( $extension, self::$activeExtensions );
	}

	/**
	 * @param string ...$extensions
	 * @return bool
	 */
	public function isAnyOfExtensionsActive( string ...$extensions ): bool {
		self::$activeExtensions ??= self::getActiveExtensions();
		return count( array_intersect( $extensions, self::$activeExtensions ) ) > 0;
	}

	/**
	 * @param string ...$extensions
	 * @return bool
	 */
	public function isAllOfExtensionsActive( string ...$extensions ): bool {
		self::$activeExtensions ??= self::getActiveExtensions();
		return count( array_intersect( $extensions, self::$activeExtensions ) ) === count( $extensions );
	}

	public function loadExtensions() {
		global $wgDBname;

		if ( !file_exists( self::CACHE_DIRECTORY . '/' . $wgDBname . '.json' ) ) {
			return;
		}

		if ( !file_exists( self::CACHE_DIRECTORY . '/' . $this->version . '/extension-list.json' ) ) {
			if ( !is_dir( self::CACHE_DIRECTORY . '/' . $this->version ) ) {
				// Create directory since it doesn't exist
				mkdir( self::CACHE_DIRECTORY . '/' . $this->version );
			}

			$queue = array_fill_keys( array_merge(
					glob( self::MEDIAWIKI_DIRECTORY . $this->version . '/extensions/*/extension*.json' ),
					glob( self::MEDIAWIKI_DIRECTORY . $this->version . '/skins/*/skin.json' )
				),
			true );

			$processor = new ExtensionProcessor();

			foreach ( $queue as $path => $mtime ) {
				$json = file_get_contents( $path );
				$info = json_decode( $json, true );
				$version = $info['manifest_version'];

				$processor->extractInfo( $path, $info, $version );
			}

			$data = $processor->getExtractedInfo();

			$list = array_column( $data['credits'], 'path', 'name' );

			file_put_contents( self::CACHE_DIRECTORY . '/' . $this->version . '/extension-list.json', json_encode( $list ), LOCK_EX );
		} else {
			$list = json_decode( file_get_contents( self::CACHE_DIRECTORY . '/' . $this->version . '/extension-list.json' ), true );
		}

		self::$activeExtensions ??= self::getActiveExtensions();
		foreach ( self::$activeExtensions as $name ) {
			$path = $list[ $name ] ?? false;

			$pathInfo = pathinfo( $path )['extension'] ?? false;
			if ( $path && $pathInfo === 'json' ) {
				ExtensionRegistry::getInstance()->queue( $path );
			}
		}
	}

	/**
	 * @param string $globalDatabase
	 * @param ?string $version
	 * @return array
	 */
	private static function getCombiList( string $globalDatabase, ?string $version = null ): array {
		$dbr = self::getDatabaseConnection( $globalDatabase );
		$wikiVersion = $version ? [ 'wiki_version' => $version ] : [];
		$allWikis = $dbr->newSelectQueryBuilder()
			->table( 'cw_wikis' )
			->fields( [
				'wiki_dbcluster',
				'wiki_dbname',
				'wiki_url',
				'wiki_sitename',
				'wiki_version',
			] )
			->where( [ 'wiki_deleted' => 0 ] + $wikiVersion )
			->caller( __METHOD__ )
			->fetchResultSet();

		$combiList = [];
		foreach ( $allWikis as $wiki ) {
			$combiList[$wiki->wiki_dbname] = [
				's' => $wiki->wiki_sitename,
				'c' => $wiki->wiki_dbcluster,
				'v' => ( $wiki->wiki_version ?? null ) ?: self::MEDIAWIKI_VERSIONS[self::getDefaultMediaWikiVersion()],
			];

			if ( $wiki->wiki_url !== null ) {
				$combiList[$wiki->wiki_dbname]['u'] = $wiki->wiki_url;
			}
		}

		return $combiList;
	}

	/**
	 * @param string $globalDatabase
	 * @return array
	 */
	private static function getDeletedList( string $globalDatabase ): array {
		$dbr = self::getDatabaseConnection( $globalDatabase );
		$deletedWikis = $dbr->newSelectQueryBuilder()
			->table( 'cw_wikis' )
			->fields( [
				'wiki_dbcluster',
				'wiki_dbname',
				'wiki_sitename',
			] )
			->where( [ 'wiki_deleted' => 1 ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$deletedList = [];
		foreach ( $deletedWikis as $wiki ) {
			$deletedList[$wiki->wiki_dbname] = [
				's' => $wiki->wiki_sitename,
				'c' => $wiki->wiki_dbcluster,
			];
		}

		return $deletedList;
	}

	/**
	 * @param string $databaseName
	 * @return DBConnRef
	 */
	private static function getDatabaseConnection( string $databaseName ): DBConnRef {
		return MediaWikiServices::getInstance()
			->getDBLoadBalancerFactory()
			->getMainLB( $databaseName )
			->getMaintenanceConnectionRef( DB_REPLICA, [], $databaseName );
	}

	/**
	 * @param array &$databaseLists
	 */
	public static function onGenerateDatabaseLists( array &$databaseLists ) {
		$databaseLists = [
			'databases' => [
				'combi' => self::getCombiList(
					self::GLOBAL_DATABASE
				),
			],
			'deleted' => [
				'deleted' => 'databases',
				'databases' => self::getDeletedList(
					self::GLOBAL_DATABASE
				),
			],
		];

		foreach ( self::MEDIAWIKI_VERSIONS as $name => $version ) {
			$databaseLists += [
				$name . '-wikis' => [
					'combi' => self::getCombiList(
						self::GLOBAL_DATABASE,
						$version
					),
				],
			];
		}
	}

	/**
	 * @param bool $ceMW
	 * @param IContextSource $context
	 * @param string $dbName
	 * @param array &$formDescriptor
	 */
	public static function onManageWikiCoreAddFormFields( $ceMW, $context, $dbName, &$formDescriptor ) {
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();

		$mwVersion = self::getMediaWikiVersion( $dbName );
		$versions = array_unique( array_filter( self::MEDIAWIKI_VERSIONS, static function ( $version ) use ( $mwVersion ): bool {
			return $mwVersion === $version || is_dir( self::MEDIAWIKI_DIRECTORY . $version );
		} ) );

		asort( $versions );

		$mwSettings = new ManageWikiSettings( $dbName );
		$setList = $mwSettings->list();
		$formDescriptor['article-path'] = [
			'label-message' => 'wikiforge-label-managewiki-article-path',
			'type' => 'select',
			'options-messages' => [
				'wikiforge-label-managewiki-article-path-wiki' => '/wiki/$1',
				'wikiforge-label-managewiki-article-path-root' => '/$1',
			],
			'default' => $setList['wgArticlePath'] ?? '/wiki/$1',
			'disabled' => !$ceMW,
			'cssclass' => 'managewiki-infuse',
			'section' => 'main',
		];

		$formDescriptor['mainpage-is-domain-root'] = [
			'label-message' => 'wikiforge-label-managewiki-mainpage-is-domain-root',
			'type' => 'check',
			'default' => $setList['wgMainPageIsDomainRoot'] ?? false,
			'disabled' => !$ceMW,
			'cssclass' => 'managewiki-infuse',
			'section' => 'main',
		];

		$formDescriptor['mediawiki-version'] = [
			'label-message' => 'wikiforge-label-managewiki-mediawiki-version',
			'type' => 'select',
			'options' => array_combine( $versions, $versions ),
			'default' => $mwVersion,
			'disabled' => !$permissionManager->userHasRight( $context->getUser(), 'managewiki-restricted' ),
			'cssclass' => 'managewiki-infuse',
			'section' => 'main',
		];

		$wiki = new RemoteWiki( $dbName );
		if ( ( $setList['wgWikiDiscoverExclude'] ?? false ) || $wiki->isPrivate() ) {
			unset( $formDescriptor['category'], $formDescriptor['description'] );
		}
	}

	/**
	 * @param IContextSource $context
	 * @param string $dbName
	 * @param DBConnRef $dbw
	 * @param array $formData
	 * @param RemoteWiki &$wiki
	 */
	public static function onManageWikiCoreFormSubmission( $context, $dbName, $dbw, $formData, &$wiki ) {
		$version = self::getMediaWikiVersion( $dbName );
		if ( $formData['mediawiki-version'] !== $version && is_dir( self::MEDIAWIKI_DIRECTORY . $formData['mediawiki-version'] ) ) {
			$wiki->newRows['wiki_version'] = $formData['mediawiki-version'];
			$wiki->changes['mediawiki-version'] = [
				'old' => $version,
				'new' => $formData['mediawiki-version']
			];
		}

		$mwSettings = new ManageWikiSettings( $dbName );

		$articlePath = $mwSettings->list()['wgArticlePath'] ?? '';
		if ( $formData['article-path'] !== $articlePath ) {
			$mwSettings->modify( [ 'wgArticlePath' => $formData['article-path'] ] );
			$mwSettings->commit();
			$wiki->changes['article-path'] = [
				'old' => $articlePath,
				'new' => $formData['article-path']
			];
		}

		$mainPageIsDomainRoot = $mwSettings->list()['wgMainPageIsDomainRoot'] ?? false;
		if ( $formData['mainpage-is-domain-root'] !== $mainPageIsDomainRoot ) {
			$mwSettings->modify( [ 'wgMainPageIsDomainRoot' => $formData['mainpage-is-domain-root'] ] );
			$mwSettings->commit();
			$wiki->changes['mainpage-is-domain-root'] = [
				'old' => $mainPageIsDomainRoot,
				'new' => $formData['mainpage-is-domain-root']
			];
		}
	}

	public static function onMediaWikiServices() {
		if ( isset( $GLOBALS['globals'] ) ) {
			foreach ( $GLOBALS['globals'] as $global => $value ) {
				if ( !isset( $GLOBALS['wgConf']->settings["+$global"] ) ) {
					$GLOBALS[$global] = $value;
				}
			}

			// Don't need a global here
			unset( $GLOBALS['globals'] );
		}
	}

	public static function onExtensionFunctions() {
		global $wgFileBackends, $wgDBname, $wgAWSBucketName;
		$wgFileBackends['s3']['containerPaths']["$wgDBname-avatars"] = "$wgAWSBucketName/$wgDBname/avatars";
		$wgFileBackends['s3']['containerPaths']["$wgDBname-awards"] = "$wgAWSBucketName/$wgDBname/awards";
		$wgFileBackends['s3']['containerPaths']["$wgDBname-dumps-backup"] = "$wgAWSBucketName/$wgDBname/dumps";
		$wgFileBackends['s3']['containerPaths']["$wgDBname-local-transcoded"] = "$wgAWSBucketName/$wgDBname/transcoded";
		$wgFileBackends['s3']['containerPaths']["$wgDBname-score-render"] = "$wgAWSBucketName/$wgDBname/score";
		$wgFileBackends['s3']['containerPaths']["$wgDBname-timeline-render"] = "$wgAWSBucketName/$wgDBname/timeline";
	}
}
