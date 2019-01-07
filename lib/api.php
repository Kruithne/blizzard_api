<?php
	require_once(__DIR__ . '/util.php');

	define('API_URL', 'https://%s.api.blizzard.com/wow/%%s?locale=en_GB&access_token=%%s');
	define('TOKEN_URL', 'https://%s.battle.net/oauth/token');
	define('ICON_URL', 'https://render-%s.worldofwarcraft.com/icons/%d/%s.jpg');
	define('URL_PARAM', '&%s=%s');
	define('CONFIG_DIRECTORY', __DIR__ . '/../cfg');
	define('API_CONFIG_FILE', CONFIG_DIRECTORY . '/api.conf.json');
	define('REGION_FILE',CONFIG_DIRECTORY . '/regions.json');
	define('CHAR_DATA_DIR', __DIR__ . '/../characters/%s-%s');
	define('ICON_DIR', __DIR__ . '/../icons/%d/');
	define('CHAR_FILE', '%s/%s.json');

	define('ENDPOINT_REALM', 'realm/status');
	define('ENDPOINT_CHARACTER', 'character/%s/%s');
	define('ENDPOINT_SPELL', 'spell/%d');

	define('CACHE_TIME', 86400); // 86400 seconds (24 hours).

	/**
	 * Represents an exception thrown by the API class.
	 * @class APIException
	 */
	class APIException extends Exception {
		/**
		 * APIException constructor.
		 * @param string $message
		 * @param array $params
		 */
		public function __construct($message = '', ...$params) {
			parent::__construct('[API] ' . sprintf($message, ...$params));
		}
	}

	/**
	 * Represents a connection to a regional API.
	 * @class API
	 */
	class API {
		/**
		 * Contains the data for all regions.
		 * @var object
		 */
		private $regionData;

		/**
		 * Contains all available region IDs.
		 * @var string[]
		 */
		private $regions;

		/**
		 * OAuth Client Key
		 * @var string
		 */
		private $clientKey;

		/**
		 * OAuth Client Secret
		 * @var string
		 */
		private $clientSecret;

		/**
		 * Currently selected region ID.
		 * @var string
		 */
		private $selectedRegionID;

		/**
		 * Currently selected region data.
		 * @var object
		 */
		private $selectedRegion;

		/**
		 * URL formatted for the current region.
		 * @var string
		 */
		private $selectedRegionURL;

		/**
		 * OAuth Token URL formatted for the current region.
		 * @var
		 */
		private $selectedRegionTokenURL;

		/**
		 * API constructor.
		 * @throws APIException
		 */
		public function __construct() {
			$this->loadConfig();
			$this->loadRegionData();
		}

		/**
		 * Set the region to use for requests.
		 * @param string $regionID
		 */
		public function setRegion($regionID) {
			$this->selectedRegionID = $regionID;
			$this->selectedRegion = &$this->regionData->$regionID;
			$this->selectedRegionURL = sprintf(API_URL, $regionID);
			$this->selectedRegionTokenURL = sprintf(TOKEN_URL, $regionID);
		}

		/**
		 * Returns data for all regions.
		 * @return object
		 */
		public function getCompleteRegionData() {
			return $this->regionData;
		}

		/**
		 * Return all available region IDs.
		 * @return string[]
		 */
		public function getRegions() {
			return $this->regions;
		}

		/**
		 * Get the represented region ID.
		 * @return string
		 */
		public function getSelectedRegionID() {
			return $this->selectedRegionID;
		}

		/**
		 * Get the represented region name.
		 * @return string
		 */
		public function getRegionName() {
			return $this->selectedRegion->name;
		}

		/**
		 * Obtain the realm list for this region.
		 * @param bool $updateCache If true, cache will be updated from remote API. Defaults to false.
		 * @return mixed
		 */
		public function getRealms($updateCache = false) {
			if ($updateCache) {
				$realmStack = [];
				$realms = $this->requestEndpoint(ENDPOINT_REALM)->realms;

				foreach ($realms as $realm)
					$realmStack[$realm->slug] = $realm->name;

				$this->selectedRegion->realms = $realmStack;
				file_put_json(REGION_FILE, $this->regionData);
			}

			return $this->selectedRegion->realms;
		}

		/**
		 * Obtain character data, subject to caching.
		 * @param string $character
		 * @param string $realm
		 * @return mixed
		 */
		public function getCharacter($character, $realm) {
			$realmDir = sprintf(CHAR_DATA_DIR, $this->getSelectedRegionID(), $realm);
			$characterFile = sprintf(CHAR_FILE, $realmDir, urlencode($character));

			// Check if we have this character cached on disk.
			if (file_exists($characterFile)) {
				$cache = file_get_json($characterFile);

				// If our cache was updated within 24 hours, do not update it.
				if (time() - $cache->cacheTime < CACHE_TIME)
					return $cache->data;
			} else {
				// Create the realm directory if needed.
				if (!file_exists($realmDir))
					mkdir($realmDir);
			}

			$res = $this->requestEndpoint(sprintf(ENDPOINT_CHARACTER, $realm, urlencode($character)), ['fields' => 'professions,reputation']);

			if ($res)
				file_put_json($characterFile, ['cacheTime' => time(), 'data' => $res]);

			return $res;
		}

		/**
		 * Obtain information for a spell from the API.
		 * Data returned from this function is not cached.
		 * @param int $spellID
		 * @return mixed
		 */
		public function getSpell($spellID) {
			return $this->requestEndpoint(sprintf(ENDPOINT_SPELL, $spellID));
		}

		/**
		 * Obtain the path to an icon file by ID.
		 * Returns null if the icon cannot be found.
		 * @param string $iconID
		 * @param int $size Size of the icon, defaults to 36
		 * @param bool $download If true, will download if not cached.
		 * @param string $dir Specify a custom directory to use.
		 * @return null|string
		 */
		public function getIconImagePath($iconID, $size = 36, $download = false, $dir = null) {
			// Ensure the directory for this size exists.
			if ($dir === null)
				$dir = sprintf(ICON_DIR, $size);

			if (!file_exists($dir))
				mkdir($dir);

			// Construct the full path for the icon.
			$path = $dir . $iconID . '.jpg';

			// Use cached icon if available.
			if (file_exists($path))
				return $path;

			if ($download) {
				// Download and save the icon from the region CDN.
				$remote = file_get_contents(sprintf(ICON_URL, $this->getSelectedRegionID(), $size, $iconID));
				if ($remote !== false) {
					file_put_contents($path, $remote);
					return $path;
				}
			}

			return null;
		}

		/**
		 * Performs basic validation on a character name.
		 * @param string $characterName
		 * @return bool
		 */
		public function isValidCharacterName($characterName) {
			if (!is_string($characterName))
				return false;

			$characterNameLength = strlen($characterName);
			if ($characterNameLength < 2 || $characterNameLength > 24)
				return false;

			return true;
		}

		/**
		 * Verifies if a region ID is valid.
		 * @param string $regionTag
		 * @return bool
		 */
		public function isValidRegion($regionTag) {
			if (!is_string($regionTag))
				return false;

			return in_array($regionTag, $this->regions);
		}

		/**
		 * Verifies if a realm is valid for the selected region.
		 * @param string $realm
		 * @return bool
		 */
		public function isValidRealm($realm) {
			if (!is_string($realm))
				return false;

			$realms = $this->getRealms(false);
			return array_key_exists($realm, $realms);
		}

		/**
		 * Request endpoint from the API.
		 * @param string $endpoint
		 * @param array|null $params
		 * @return mixed
		 */
		private function requestEndpoint($endpoint, $params = null) {
			$handle = curl_init();
			curl_setopt($handle, CURLOPT_URL, $this->formatEndpointURL($endpoint, $params));
			curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($handle, CURLOPT_CUSTOMREQUEST, "GET");
			$res = curl_exec($handle);
			curl_close($handle);

			return json_decode($res);
		}

		/**
		 * Request an access token for OAuth
		 * @return mixed
		 * @throws APIException
		 */
		private function requestAccessToken() {
			$handle = curl_init($this->selectedRegionTokenURL);
			$data = ['grant_type' => 'client_credentials', 'client_id' => $this->clientKey, 'client_secret' => $this->clientSecret];
			curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($handle, CURLOPT_POST, true);
			curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($data));
			$resp = json_decode(curl_exec($handle));

			if (!isset($resp->access_token))
				throw new APIException('Unable to obtain OAuth token from API');

			return $resp->access_token;
		}

		/**
		 * Format an endpoint URL for this API.
		 * @param string $endpoint
		 * @param array|null $params
		 * @return string
		 * @throws APIException
		 */
		private function formatEndpointURL($endpoint, $params = null) {
			$token = $this->requestAccessToken();
			$url = sprintf($this->selectedRegionURL, $endpoint, $token);

			if (is_array($params))
				foreach ($params as $key => $value)
					$url .= sprintf(URL_PARAM, $key, urlencode($value));

			return $url;
		}

		/**
		 * Load the configuration data from file.
		 * @throws APIException
		 */
		private function loadConfig() {
			if (!file_exists(API_CONFIG_FILE))
				throw new APIException('Unable to locate API configuration file %s', API_CONFIG_FILE);

			$config = file_get_json(API_CONFIG_FILE);
			if (!isset($config->clientKey))
				throw new APIException('Configuration file does not define API property `clientKey`');

			if (!isset($config->clientSecret))
				throw new APIException('Configuration file does not define API property `clientSecret`');

			$this->clientKey = $config->clientKey;
			$this->clientSecret = $config->clientSecret;
		}

		/**
		 * Load region data from file.
		 * @throws APIException
		 */
		private function loadRegionData() {
			if (!file_exists(REGION_FILE))
				throw new APIException('Unable to locate region data file %s', REGION_FILE);

			// Load region data from file.
			$this->regionData = file_get_json(REGION_FILE);

			// Ensure the data file has at least one region.
			if (count($this->regionData) === 0)
				throw new APIException('Region data file does not define any regions');

			// Prepare a list of all region IDs.
			$this->regions = [];
			foreach ($this->regionData as $regionID => $region)
				array_push($this->regions, $regionID);

			// Default to first region in config.
			$this->setRegion($this->regions[0]);
		}
	}