<?php
declare(strict_types=1);

/**
 * Wallabag Sync — FreshRSS extension.
 *
 * Marks a Wallabag entry as archived (read) via the Wallabag API whenever the
 * corresponding FreshRSS entry is marked read. Detection is done by matching
 * the entry link against the user-configured Wallabag base URL.
 */
final class WallabagSyncExtension extends Minz_Extension {

	private const TOKEN_REFRESH_LEEWAY = 60;

	public function init(): void {
		Minz_View::appendScript($this->getFileUrl('wallabag-sync.js', 'js'));

		if (Minz_Request::isPost() && Minz_Request::paramString('wallabag_sync_action') !== '') {
			$this->dispatchAjax();
		}
	}

	// -------- Configuration UI ------------------------------------------------

	public function handleConfigureAction(): void {
		if (Minz_Request::isPost()) {
			$conf = [
				'wallabag_url' => rtrim(trim(Minz_Request::paramString('wallabag_url')), '/'),
				'client_id' => trim(Minz_Request::paramString('client_id')),
				'client_secret' => trim(Minz_Request::paramString('client_secret')),
				'username' => trim(Minz_Request::paramString('username')),
				'password' => Minz_Request::paramString('password'),
			];

			$existing = $this->getUserConf();
			if ($conf['password'] === '' && isset($existing['password'])) {
				$conf['password'] = $existing['password'];
			}

			$conf['access_token'] = '';
			$conf['refresh_token'] = '';
			$conf['token_expires_at'] = 0;

			$this->setUserConfiguration($conf);
		}
	}

	// -------- AJAX dispatch ---------------------------------------------------

	private function dispatchAjax(): void {
		header('Content-Type: application/json; charset=utf-8');

		if (!FreshRSS_Auth::hasAccess()) {
			$this->respond(['ok' => false, 'error' => 'not_authenticated'], 401);
		}

		$expectedToken = (string)Minz_Session::param('csrf', '');
		$givenToken = Minz_Request::paramString('_csrf');
		if ($expectedToken === '' || !hash_equals($expectedToken, $givenToken)) {
			$this->respond(['ok' => false, 'error' => 'csrf_invalid'], 403);
		}

		$action = Minz_Request::paramString('wallabag_sync_action');

		try {
			switch ($action) {
				case 'mark_read':
					$entryId = Minz_Request::paramInt('entry_id');
					if ($entryId <= 0) {
						$this->respond(['ok' => false, 'error' => 'missing_entry_id'], 400);
					}
					$result = $this->markEntryArchived($entryId);
					$this->respond($result, $result['ok'] ? 200 : 502);
					break;

				case 'config':
					$conf = $this->getUserConf();
					$this->respond([
						'ok' => true,
						'wallabag_url' => (string)($conf['wallabag_url'] ?? ''),
						'configured' => $this->isConfigured($conf),
					]);
					break;

				default:
					$this->respond(['ok' => false, 'error' => 'unknown_action'], 400);
			}
		} catch (Throwable $e) {
			Minz_Log::warning('WallabagSync: ' . $e->getMessage());
			$this->respond(['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function respond(array $payload, int $status = 200): void {
		http_response_code($status);
		echo json_encode($payload);
		exit;
	}

	// -------- Wallabag API ----------------------------------------------------

	/**
	 * @return array{ok:bool, error?:string, message?:string}
	 */
	private function markEntryArchived(int $entryId): array {
		$conf = $this->getUserConf();
		if (!$this->isConfigured($conf)) {
			return ['ok' => false, 'error' => 'not_configured'];
		}

		$token = $this->getValidAccessToken($conf);
		if ($token === null) {
			return ['ok' => false, 'error' => 'token_unavailable'];
		}

		$url = $conf['wallabag_url'] . '/api/entries/' . $entryId . '.json';
		[$status, $body] = $this->httpRequest('PATCH', $url, [
			'Authorization: Bearer ' . $token,
			'Content-Type: application/json',
		], json_encode(['archive' => 1]));

		if ($status === 401) {
			// Token may have been revoked since cache write — clear and retry once.
			$this->clearTokens($conf);
			$token = $this->getValidAccessToken($this->getUserConf());
			if ($token === null) {
				return ['ok' => false, 'error' => 'reauth_failed'];
			}
			[$status, $body] = $this->httpRequest('PATCH', $url, [
				'Authorization: Bearer ' . $token,
				'Content-Type: application/json',
			], json_encode(['archive' => 1]));
		}

		if ($status >= 200 && $status < 300) {
			return ['ok' => true];
		}

		return ['ok' => false, 'error' => 'wallabag_http_' . $status, 'message' => substr((string)$body, 0, 500)];
	}

	/**
	 * @param array<string,mixed> $conf
	 */
	private function getValidAccessToken(array $conf): ?string {
		$now = time();
		$expiresAt = (int)($conf['token_expires_at'] ?? 0);
		$accessToken = (string)($conf['access_token'] ?? '');

		if ($accessToken !== '' && $expiresAt > $now + self::TOKEN_REFRESH_LEEWAY) {
			return $accessToken;
		}

		$refreshToken = (string)($conf['refresh_token'] ?? '');
		if ($refreshToken !== '') {
			$token = $this->requestToken($conf, [
				'grant_type' => 'refresh_token',
				'client_id' => $conf['client_id'],
				'client_secret' => $conf['client_secret'],
				'refresh_token' => $refreshToken,
			]);
			if ($token !== null) {
				return $token;
			}
		}

		return $this->requestToken($conf, [
			'grant_type' => 'password',
			'client_id' => $conf['client_id'],
			'client_secret' => $conf['client_secret'],
			'username' => $conf['username'],
			'password' => $conf['password'],
		]);
	}

	/**
	 * @param array<string,mixed> $conf
	 * @param array<string,string> $params
	 */
	private function requestToken(array $conf, array $params): ?string {
		$url = $conf['wallabag_url'] . '/oauth/v2/token';
		[$status, $body] = $this->httpRequest('POST', $url, [
			'Content-Type: application/json',
		], json_encode($params));

		if ($status < 200 || $status >= 300 || !is_string($body)) {
			Minz_Log::warning('WallabagSync: token request failed status=' . $status);
			return null;
		}

		$decoded = json_decode($body, true);
		if (!is_array($decoded) || !isset($decoded['access_token'])) {
			return null;
		}

		$conf['access_token'] = (string)$decoded['access_token'];
		$conf['refresh_token'] = (string)($decoded['refresh_token'] ?? '');
		$conf['token_expires_at'] = time() + (int)($decoded['expires_in'] ?? 3600);
		$this->setUserConfiguration($conf);

		return $conf['access_token'];
	}

	/**
	 * @param array<string,mixed> $conf
	 */
	private function clearTokens(array $conf): void {
		$conf['access_token'] = '';
		$conf['refresh_token'] = '';
		$conf['token_expires_at'] = 0;
		$this->setUserConfiguration($conf);
	}

	/**
	 * @param array<int,string> $headers
	 * @return array{0:int, 1:string|false}
	 */
	private function httpRequest(string $method, string $url, array $headers, ?string $body): array {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}

		$response = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		return [$status, $response];
	}

	// -------- Helpers ---------------------------------------------------------

	/**
	 * @return array<string,mixed>
	 */
	private function getUserConf(): array {
		$conf = $this->getUserConfiguration();
		return is_array($conf) ? $conf : [];
	}

	/**
	 * @param array<string,mixed> $conf
	 */
	private function isConfigured(array $conf): bool {
		foreach (['wallabag_url', 'client_id', 'client_secret', 'username', 'password'] as $key) {
			if (empty($conf[$key])) {
				return false;
			}
		}
		return true;
	}
}
