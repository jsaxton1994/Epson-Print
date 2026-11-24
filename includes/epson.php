<?php
/**
 * Epson Connect API v2 client — photo printing.
 *
 * Flow (from the Epson v2 documentation):
 *   1. Admin authorises the printer once via auth.epsonconnect.com (OAuth2).
 *      We keep the refresh token; device tokens are refreshed automatically.
 *   2. Create a print job   POST /printing/jobs   (printMode: photo)
 *   3. Upload the photo     POST <uploadUri>&File=1.jpg
 *   4. Execute the job      POST /printing/jobs/{jobId}/print
 */

declare(strict_types=1);

final class EpsonException extends RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 0, public readonly array $payload = [])
    {
        parent::__construct($message);
    }
}

final class EpsonClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function fromSettings(): self
    {
        return new self([
            'client_id'     => (string) setting('epson_client_id', ''),
            'client_secret' => (string) setting('epson_client_secret', ''),
            'api_key'       => (string) setting('epson_api_key', ''),
            'redirect_uri'  => (string) setting('epson_redirect_uri', ''),
            'refresh_token' => (string) setting('epson_refresh_token', ''),
            'access_token'  => (string) setting('epson_access_token', ''),
            'token_expires' => (string) setting('epson_token_expires', ''),
            'device_name'   => (string) setting('epson_device_name', ''),
            'device_serial' => (string) setting('epson_device_serial', ''),
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->config['client_id'] !== ''
            && $this->config['client_secret'] !== ''
            && $this->config['api_key'] !== '';
    }

    public function isConnected(): bool
    {
        return $this->isConfigured() && $this->config['refresh_token'] !== '';
    }

    public function deviceName(): string
    {
        return $this->config['device_name'] !== '' ? $this->config['device_name'] : EPSON_PRINTER_MODEL;
    }

    public function redirectUri(): string
    {
        return $this->config['redirect_uri'] !== ''
            ? $this->config['redirect_uri']
            : absolute_url('admin/epson-callback.php');
    }

    public function authorizeUrl(string $state): string
    {
        return EPSON_AUTH_URL . '/auth/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->config['client_id'],
            'redirect_uri'  => $this->redirectUri(),
            'scope'         => 'device',
            'state'         => $state,
        ]);
    }

    /**
     * Exchange the one-time authorisation code for a refresh token.
     */
    public function exchangeCode(string $code): array
    {
        $token = $this->tokenRequest([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri(),
            'client_id'     => $this->config['client_id'],
        ]);

        $this->storeTokens($token);
        set_setting('epson_connected_at', now());

        // Pull the printer name/serial so the dashboard can show it.
        try {
            $device = $this->deviceInfo();
            if (!empty($device['productName'])) {
                set_setting('epson_device_name', (string) $device['productName']);
            }
            if (!empty($device['serialNumber'])) {
                set_setting('epson_device_serial', (string) $device['serialNumber']);
            }
        } catch (EpsonException) {
            // The token is valid even if this lookup fails.
        }

        return $token;
    }

    public function disconnect(): void
    {
        foreach ([
            'epson_refresh_token', 'epson_access_token', 'epson_token_expires',
            'epson_device_name', 'epson_device_serial', 'epson_connected_at',
        ] as $key) {
            set_setting($key, '');
        }
    }

    public function deviceInfo(): array
    {
        return $this->api('GET', '/printing/devices/info');
    }

    public function capability(string $mode = 'photo'): array
    {
        return $this->api('GET', '/printing/capability/' . $mode);
    }

    /**
     * Create one print job for one photo, upload the image and start printing.
     *
     * Epson uploads a single file per job (`File=1.jpg`), so an order with
     * several photos becomes one job per photo.
     *
     * @param array{reference:string,paper_size:string,color_mode:string,print_quality:string,borderless:int} $order
     * @param array{file_name:string,position:int} $photo
     * @return array{jobId:string,uploadUri:string}
     */
    public function printPhoto(array $order, array $photo): array
    {
        $bytes = photo_binary($photo);
        if ($bytes === null) {
            throw new EpsonException('A photo for this order is missing from the database.');
        }

        $extension = strtolower(pathinfo((string) ($photo['file_name'] ?? ''), PATHINFO_EXTENSION));
        $extension = $extension === 'png' ? 'png' : 'jpg';

        $jobName = 'PrintFree-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) $order['reference'])
            . '-P' . max(1, (int) ($photo['position'] ?? 1));
        $jobName = mb_substr($jobName !== '' ? $jobName : 'PrintFree-photo', 0, 64);

        $job = $this->api('POST', '/printing/jobs', [
            'jobName'       => $jobName,
            'printMode'     => 'photo',
            'printSettings' => $this->photoSettings($order),
        ]);

        if (empty($job['jobId']) || empty($job['uploadUri'])) {
            throw new EpsonException('Epson did not return a print job. Please try again.', 0, $job);
        }

        $this->upload((string) $job['uploadUri'], $bytes, $extension);
        $this->api('POST', '/printing/jobs/' . rawurlencode((string) $job['jobId']) . '/print');

        return $job;
    }

    public function jobStatus(string $jobId): array
    {
        return $this->api('GET', '/printing/jobs/' . rawurlencode($jobId));
    }

    /**
     * Translate an Epson job response into an order status.
     *
     * @return array{status:string,detail:string}
     */
    public function interpretJobStatus(array $payload): array
    {
        $status = '';
        array_walk_recursive($payload, static function ($value, $key) use (&$status): void {
            if ($status === '' && is_string($value) && in_array(strtolower($key), ['status', 'jobstatus', 'printstatus'], true)) {
                $status = strtolower($value);
            }
        });

        $detail = 'Printer status: ' . ($status !== '' ? $status : 'unknown');

        return match (true) {
            in_array($status, ['completed', 'finished', 'printed', 'success', 'done'], true) => ['status' => 'completed', 'detail' => $detail],
            in_array($status, ['cancelled', 'canceled'], true) => ['status' => 'cancelled', 'detail' => $detail],
            in_array($status, ['error', 'error_occurred', 'failed', 'aborted', 'expired'], true) => ['status' => 'failed', 'detail' => $detail],
            in_array($status, ['media_empty', 'media_jam', 'marker_supply_empty', 'stopped_other'], true) => ['status' => 'printing', 'detail' => ucfirst(str_replace('_', ' ', $status)) . ' — check the printer'],
            default => ['status' => 'printing', 'detail' => $detail],
        };
    }

    /* ---------------------------------------------------------------------
     |  Internals
     * ------------------------------------------------------------------ */

    /**
     * Pick a paper size, paper type, borderless flag and quality that the
     * printer actually reports for photos.
     *
     * Epson's v2 appendix has no literal 4x6 code, so 4x6 asks for ps_4x6
     * first and falls back to the identical 102 × 153 mm sheet (ps_kg).
     */
    private function photoSettings(array $order): array
    {
        $requested  = (string) $order['paper_size'];
        $candidates = match ($requested) {
            '4x6' => ['ps_4x6', 'ps_kg', 'ps_l'],
            '5x7' => ['ps_2l', 'ps_5x8'],
            default => ['ps_a4'],
        };

        $paperSize  = $candidates[0];
        $paperType  = 'pt_photopaper';
        $borderless = !empty($order['borderless']);
        $quality    = in_array($order['print_quality'], ['draft', 'normal', 'high'], true) ? (string) $order['print_quality'] : 'high';

        try {
            $capability = $this->capability('photo');
        } catch (EpsonException) {
            $capability = [];
        }

        $sizes = is_array($capability['paperSizes'] ?? null) ? $capability['paperSizes'] : [];

        if ($sizes !== []) {
            $match = null;

            foreach ($candidates as $candidate) {
                foreach ($sizes as $paper) {
                    if (is_array($paper) && ($paper['paperSize'] ?? '') === $candidate) {
                        $match     = $paper;
                        $paperSize = $candidate;
                        break 2;
                    }
                }
            }

            if ($match === null) {
                throw new EpsonException(
                    'The printer does not report a ' . paper_size_label($requested)
                    . ' paper size. Load that photo paper in the ET-2926, or choose another size.'
                );
            }

            $types  = is_array($match['paperTypes'] ?? null) ? $match['paperTypes'] : [];
            $chosen = null;

            foreach ($types as $type) {
                if (is_array($type) && ($type['paperType'] ?? '') === 'pt_photopaper') {
                    $chosen = $type;
                    break;
                }
            }

            if ($chosen === null && $types !== []) {
                $chosen = $types[0];
            }

            if (is_array($chosen)) {
                $paperType = (string) ($chosen['paperType'] ?? $paperType);

                if ($borderless && empty($chosen['borderless'])) {
                    $borderless = false;
                }

                $qualities = is_array($chosen['printQualities'] ?? null) ? $chosen['printQualities'] : [];
                if ($qualities !== [] && !in_array($quality, $qualities, true)) {
                    $quality = in_array('normal', $qualities, true) ? 'normal' : (string) $qualities[0];
                }
            }
        }

        return [
            'paperSize'    => $paperSize,
            'paperType'    => $paperType,
            'borderless'   => $borderless,
            'printQuality' => $quality,
            'paperSource'  => 'rear',
            'colorMode'    => $order['color_mode'] === 'mono' ? 'mono' : 'color',
        ];
    }

    /**
     * Upload the photo bytes taken from the database to Epson's upload URI.
     */
    private function upload(string $uploadUri, string $data, string $extension): void
    {
        $url  = $uploadUri . (str_contains($uploadUri, '?') ? '&' : '?') . 'File=1.' . $extension;
        $size = strlen($data);
        $mime = $extension === 'png' ? 'image/png' : 'image/jpeg';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => EPSON_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: ' . $mime,
                'Content-Length: ' . $size,
                'Expect:',
            ],
        ]);

        $body   = curl_exec($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new EpsonException('Could not upload the photo to Epson: ' . $error);
        }
        if ($status >= 400) {
            throw new EpsonException('Epson rejected the photo upload (HTTP ' . $status . ').', $status);
        }
    }

    private function api(string $method, string $path, ?array $body = null): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->accessToken(),
            'x-api-key: ' . $this->config['api_key'],
            'Accept: application/json',
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init(EPSON_API_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => EPSON_TIMEOUT,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }

        $raw    = curl_exec($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            throw new EpsonException('Could not reach Epson Connect: ' . $error);
        }

        $payload = json_decode((string) $raw, true);
        $payload = is_array($payload) ? $payload : [];

        if ($status >= 400) {
            throw new EpsonException($this->errorMessage($payload, $status), $status, $payload);
        }

        return $payload;
    }

    private function accessToken(): string
    {
        $token   = $this->config['access_token'];
        $expires = (int) $this->config['token_expires'];

        if ($token !== '' && $expires > time() + 60) {
            return $token;
        }

        return $this->refreshAccessToken();
    }

    private function refreshAccessToken(): string
    {
        if ($this->config['refresh_token'] === '') {
            throw new EpsonException('The printer is not linked yet. Go to Settings → Epson Connect and connect your ET-2926.');
        }

        try {
            $token = $this->tokenRequest([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $this->config['refresh_token'],
            ]);
        } catch (EpsonException $e) {
            if ($e->statusCode === 400 || $e->statusCode === 401) {
                set_setting('epson_refresh_token', '');
                set_setting('epson_access_token', '');
                set_setting('epson_token_expires', '');
                set_setting('epson_connected_at', '');
                throw new EpsonException('The printer link expired. Reconnect the ET-2926 in Settings → Epson Connect.');
            }

            throw $e;
        }

        $this->storeTokens($token);

        return (string) $token['access_token'];
    }

    private function tokenRequest(array $fields): array
    {
        $ch = curl_init(EPSON_AUTH_URL . '/auth/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => EPSON_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($this->config['client_id'] . ':' . $this->config['client_secret']),
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);

        $raw    = curl_exec($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            throw new EpsonException('Could not reach Epson authorisation: ' . $error);
        }

        $payload = json_decode((string) $raw, true);
        $payload = is_array($payload) ? $payload : [];

        if ($status >= 400 || empty($payload['access_token'])) {
            throw new EpsonException($this->errorMessage($payload, $status), $status, $payload);
        }

        return $payload;
    }

    private function storeTokens(array $token): void
    {
        $expiresIn = max(300, (int) ($token['expires_in'] ?? 3600));
        $expiresAt = time() + $expiresIn - 60;

        set_setting('epson_access_token', (string) $token['access_token']);
        set_setting('epson_token_expires', (string) $expiresAt);

        if (!empty($token['refresh_token'])) {
            set_setting('epson_refresh_token', (string) $token['refresh_token']);
            $this->config['refresh_token'] = (string) $token['refresh_token'];
        }

        $this->config['access_token']  = (string) $token['access_token'];
        $this->config['token_expires'] = (string) $expiresAt;
    }

    private function errorMessage(array $payload, int $status): string
    {
        $candidates = [
            $payload['message'] ?? null,
            $payload['error_description'] ?? null,
            $payload['error'] ?? null,
            is_array($payload['errors'] ?? null) ? json_encode($payload['errors']) : ($payload['errors'] ?? null),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return 'Epson: ' . mb_substr(trim($candidate), 0, 240);
            }
        }

        return 'Epson Connect returned HTTP ' . $status . '.';
    }
}

function epson_configured(): bool
{
    return EpsonClient::fromSettings()->isConfigured();
}

function epson_connected(): bool
{
    return EpsonClient::fromSettings()->isConnected();
}
