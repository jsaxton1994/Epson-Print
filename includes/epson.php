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
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $payload = [],
        public readonly bool $transport = false,
        public readonly int $curlErrno = 0,
        public readonly bool $connected = true
    ) {
        parent::__construct($message);
    }
}

final class EpsonClient
{
    /** Last print step reached, so a transport failure can be judged safely. */
    public string $lastStage = '';

    /** Diagnostics only: skip the fail-fast circuit to test the connection. */
    public bool $bypassCircuit = false;

    /**
     * Called during slow transfers, so the print worker can prove it is alive
     * while an upload or a status call is still in flight.
     */
    public $onActivity = null;

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
            'epson_capability_photo', 'epson_capability_document',
        ] as $key) {
            set_setting($key, '');
        }
    }

    public function deviceInfo(): array
    {
        // Used for the dashboard's live status: fail fast rather than hold a page.
        return $this->api('GET', '/printing/devices/info', null, 30, 1);
    }

    /**
     * Printer capability, cached: it only changes when the printer is re-linked,
     * and asking Epson on every photo added a slow API call to each sheet.
     */
    public function capability(string $mode = 'photo'): array
    {
        $key    = 'epson_capability_' . preg_replace('/[^a-z0-9_]/', '', strtolower($mode));
        $cached = json_decode((string) setting($key, ''), true);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $fresh = $this->api('GET', '/printing/capability/' . $mode, null, 30, 1);

        if ($fresh !== []) {
            set_setting($key, json_encode($fresh, JSON_UNESCAPED_SLASHES));
        }

        return $fresh;
    }

    /**
     * Create the job and upload the photo without printing it, so the upload can
     * happen while the previous sheet is still on the printer.
     */
    public function preparePhoto(array $order, array $photo): array
    {
        // The photo is sent exactly as it was uploaded: no resizing, padding or
        // recompression, so the print is the customer's own file.
        $bytes = photo_binary($photo);

        if ($bytes === null) {
            throw new EpsonException('A photo for this order is missing from the database.');
        }

        $extension = strtolower(pathinfo((string) ($photo['file_name'] ?? ''), PATHINFO_EXTENSION)) === 'png' ? 'png' : 'jpg';

        $jobName = 'PrintFree-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) $order['reference'])
            . '-P' . max(1, (int) ($photo['position'] ?? 1));
        $jobName = mb_substr($jobName !== '' ? $jobName : 'PrintFree-photo', 0, 64);

        $this->lastStage = 'settings';
        $settings        = $this->photoSettings($order);

        $this->lastStage = 'create';
        $job = $this->api('POST', '/printing/jobs', [
            'jobName'       => $jobName,
            'printMode'     => 'photo',
            'printSettings' => $settings,
        ]);

        if (empty($job['jobId']) || empty($job['uploadUri'])) {
            throw new EpsonException('Epson did not return a print job. Please try again.', 0, $job);
        }

        $this->lastStage = 'upload';
        $this->upload((string) $job['uploadUri'], $bytes, $extension);

        return $job;
    }

    /**
     * Drop a job that was prepared but never started, so unstarted jobs do not
     * pile up at Epson when a worker slice ends mid-print.
     */
    public function cancelJob(string $jobId): void
    {
        if ($jobId === '') {
            return;
        }

        $this->api('DELETE', '/printing/jobs/' . rawurlencode($jobId), null, 20, 1);
    }

    /**
     * Start a job that was created and uploaded earlier.
     */
    public function startPhoto(array $job): void
    {
        $this->lastStage = 'start';

        try {
            $this->api(
                'POST',
                '/printing/jobs/' . rawurlencode((string) ($job['jobId'] ?? '')) . '/print',
                null,
                EPSON_UPLOAD_TIMEOUT,
                2
            );
        } catch (EpsonException $e) {
            // A start command that timed out may still have started the job.
            // Ask Epson before failing the photo (and risking a second sheet).
            try {
                $state = $this->jobState($this->jobStatus((string) $job['jobId']));
            } catch (EpsonException) {
                throw $e;
            }

            if (!in_array($state['status'], ['pending', 'pending_held', 'processing', 'processing_stopped', 'completed'], true)) {
                throw $e;
            }
        }
    }

    public function jobStatus(string $jobId): array
    {
        // Status checks run in loops: fail quickly and retry once so one stalled
        // connection cannot hold the worker for a whole minute.
        return $this->api('GET', '/printing/jobs/' . rawurlencode($jobId), null, 25, 2);
    }

    /**
     * Raw Epson job status and reason, e.g. ['processing_stopped', 'media_empty'].
     *
     * interpretJobStatus() collapses these into an order status; the dashboard
     * needs the reason to tell a jam from out-of-paper or out-of-ink.
     *
     * @return array{status:string,reason:string}
     */
    public function jobState(array $payload): array
    {
        $status = '';
        $reason = '';

        array_walk_recursive($payload, static function ($value, $key) use (&$status, &$reason): void {
            if (!is_string($value)) {
                return;
            }

            $key = strtolower((string) $key);

            if ($status === '' && in_array($key, ['status', 'jobstatus', 'printstatus'], true)) {
                $status = strtolower($value);
            } elseif ($reason === '' && in_array($key, ['status_reason', 'statusreason'], true)) {
                $reason = strtolower($value);
            }
        });

        return ['status' => $status, 'reason' => $reason];
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
        $host = (string) parse_url($url, PHP_URL_HOST);
        $resolve = $this->resolveOptions($host);

        $error     = '';
        $status    = 0;
        $connected = false;

        // Uploads are the slowest call and a one-off dropped connection should
        // not fail the photo, so retry a transport failure a couple of times.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ch = curl_init($url);
            $options = [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => EPSON_UPLOAD_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => EPSON_CONNECT_TIMEOUT,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: ' . $mime,
                    'Content-Length: ' . $size,
                    'Expect:',
                ],
            ];

            if ($resolve !== []) {
                $options[CURLOPT_RESOLVE] = $resolve;
            }

            curl_setopt_array($ch, $options);
            $this->attachActivity($ch);

            $body      = curl_exec($ch);
            $error     = curl_error($ch);
            $errno     = curl_errno($ch);
            $info      = curl_getinfo($ch);
            $timings   = $this->requestTimings($ch);
            $connected = $connected || (float) ($info['pretransfer_time'] ?? 0) > 0;
            $status    = (int) ($info['http_code'] ?? 0);
            curl_close($ch);

            epson_log_request([
                'host'       => (string) parse_url($url, PHP_URL_HOST),
                'path'       => 'upload',
                'ok'         => $body !== false && $error === '' && $status < 400,
                'errno'      => $errno,
                'http'       => $status,
                'connect_ms' => (int) round(1000 * (float) ($info['connect_time'] ?? 0)),
                'total_ms'   => (int) round(1000 * (float) ($info['total_time'] ?? 0)),
                'error'      => mb_substr($error, 0, 160),
            ]);

            if ($body !== false && $error === '' && $status < 400) {
                return;
            }

            if ($status >= 400) {
                throw new EpsonException('Epson rejected the photo upload (HTTP ' . $status . ').', $status);
            }

            $error = ($error !== '' ? $error : 'no response from Epson')
                . ' (cURL ' . $errno . '; ' . $timings . ')';

            // Uploads go to an Epson host too, so the same unreachable-edge
            // problem applies here: try another edge before failing the photo.
            if (self::isConnectFailure((int) $errno, $info)) {
                if ($resolve !== []) {
                    epson_set_edge_ip($host, '');
                    $resolve = [];
                } elseif ($attempt < 2) {
                    $pinned = $this->pinReachableEdge($host);

                    if ($pinned !== '') {
                        $resolve = [$host . ':443:' . $pinned];
                    }
                }
            }

            if ($attempt < 2) {
                sleep(2);
            }
        }

        throw new EpsonException('Could not upload the photo to Epson: ' . $error, 0, [], true, $errno, $connected);
    }

    /**
     * Where a failed request spent its time: a stalled DNS lookup looks very
     * different from a TLS handshake that never finished.
     */
    private function requestTimings(\CurlHandle $ch): string
    {
        $info = curl_getinfo($ch);

        return sprintf(
            'dns %.1fs, connect %.1fs, tls %.1fs, first byte %.1fs, total %.1fs',
            (float) ($info['namelookup_time'] ?? 0),
            (float) ($info['connect_time'] ?? 0),
            (float) ($info['appconnect_time'] ?? 0),
            (float) ($info['starttransfer_time'] ?? 0),
            (float) ($info['total_time'] ?? 0)
        );
    }

    /**
     * CURLOPT_RESOLVE value for a host: the Epson edge remembered for it, if any.
     */
    private function resolveOptions(string $host): array
    {
        $ip = epson_edge_ip($host);

        return $ip === '' ? [] : [$host . ':443:' . $ip];
    }

    /**
     * Epson is served by many CloudFront edges. When the address this server
     * resolved cannot be reached at all, try the known edges and remember the
     * first that answers, so later calls connect straight to a working one.
     */
    private function pinReachableEdge(string $host): string
    {
        $candidates = epson_edge_candidates($host);
        $current    = epson_edge_ip($host);

        foreach ($candidates as $ip) {
            if ($ip === $current) {
                continue;
            }

            $ch = curl_init('https://' . $host . '/');
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_RESOLVE        => [$host . ':443:' . $ip],
            ]);

            curl_exec($ch);
            $errno   = curl_errno($ch);
            $curlErr = curl_error($ch);
            $info    = curl_getinfo($ch);
            curl_close($ch);

            epson_log_request([
                'host'       => $host,
                'path'       => 'edge-probe',
                'ok'         => $errno === 0,
                'errno'      => $errno,
                'http'       => (int) ($info['http_code'] ?? 0),
                'connect_ms' => (int) round(1000 * (float) ($info['connect_time'] ?? 0)),
                'total_ms'   => (int) round(1000 * (float) ($info['total_time'] ?? 0)),
                'error'      => $errno === 0 ? '' : 'edge ' . $ip . ': ' . mb_substr($curlErr, 0, 100),
            ]);

            if ($errno === 0) {
                epson_set_edge_ip($host, $ip);

                return $ip;
            }
        }

        return '';
    }

    /**
     * Beat the worker's liveness during a transfer.
     *
     * Throttled to once every ten seconds, so a long upload is not mistaken for
     * a hung run while a genuinely stalled transfer still goes quiet inside the
     * liveness threshold.
     */
    private function attachActivity($ch): void
    {
        if ($this->onActivity === null) {
            return;
        }

        $last = 0.0;

        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt(
            $ch,
            CURLOPT_XFERINFOFUNCTION,
            function () use (&$last): int {
                $now = microtime(true);

                if ($now - $last >= 10.0) {
                    $last = $now;

                    if ($this->onActivity !== null) {
                        ($this->onActivity)();
                    }
                }

                return 0;
            }
        );
    }

    /**
     * A connection that never opened: the edge address is unreachable, which is
     * different from a slow or rejected request on a working connection.
     */
    private static function isConnectFailure(int $errno, array $info): bool
    {
        return in_array($errno, [6, 7], true)
            || ($errno === 28 && (float) ($info['connect_time'] ?? 0) <= 0.0);
    }

    private function api(string $method, string $path, ?array $body = null, int $timeout = 0, int $attempts = 2): array
    {
        // Fail fast while the endpoint is known to be unreachable, instead of
        // stacking up connection timeouts minute after minute.
        $wait = epson_circuit_seconds();

        if ($wait > 0 && !$this->bypassCircuit) {
            throw new EpsonException(
                'Epson Connect is not answering; retrying in ' . $wait . 's.',
                0,
                [],
                true,
                28,
                false
            );
        }

        $headers = [
            'Authorization: Bearer ' . $this->accessToken(),
            'x-api-key: ' . $this->config['api_key'],
            'Accept: application/json',
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $timeout  = $timeout > 0 ? $timeout : EPSON_TIMEOUT;
        $attempts = max(1, $attempts);
        $url      = EPSON_API_URL . $path;
        $host     = (string) parse_url($url, PHP_URL_HOST);
        $resolve  = $this->resolveOptions($host);
        $error    = '';
        $raw      = false;
        $status   = 0;
        $connected = false;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $ch = curl_init($url);
            $options = [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => EPSON_CONNECT_TIMEOUT,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_HTTPHEADER     => $headers,
            ];

            if ($resolve !== []) {
                $options[CURLOPT_RESOLVE] = $resolve;
            }

            curl_setopt_array($ch, $options);
            $this->attachActivity($ch);

            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
            }

            $raw      = curl_exec($ch);
            $error    = curl_error($ch);
            $errno    = curl_errno($ch);
            $info     = curl_getinfo($ch);
            $timings  = $this->requestTimings($ch);
            $connected = $connected || (float) ($info['pretransfer_time'] ?? 0) > 0;
            $status   = (int) ($info['http_code'] ?? 0);
            curl_close($ch);

            epson_log_request([
                'host'       => (string) parse_url($url, PHP_URL_HOST),
                'path'       => $path,
                'ok'         => $raw !== false && $error === '',
                'errno'      => $errno,
                'http'       => $status,
                'connect_ms' => (int) round(1000 * (float) ($info['connect_time'] ?? 0)),
                'total_ms'   => (int) round(1000 * (float) ($info['total_time'] ?? 0)),
                'error'      => mb_substr($error, 0, 160),
            ]);

            if ($raw !== false && $error === '') {
                break;
            }

            // The edge this server resolved may be unreachable while other Epson
            // edges answer: try a working one before spending more timeouts.
            if (self::isConnectFailure((int) $errno, $info)) {
                if ($resolve !== []) {
                    // The remembered edge stopped answering; go back to DNS.
                    epson_set_edge_ip($host, '');
                    $resolve = [];
                } elseif ($attempt < $attempts) {
                    $pinned = $this->pinReachableEdge($host);

                    if ($pinned !== '') {
                        $resolve  = [$host . ':443:' . $pinned];
                        $attempts = min(4, $attempts + 1);
                    }
                }
            }

            $error = ($error !== '' ? $error : 'no response from Epson')
                . ' (cURL ' . $errno . '; ' . $timings . ')';

            if ($attempt < $attempts) {
                sleep($attempt * 2);
            }
        }

        if ($raw === false || $error !== '') {
            throw new EpsonException('Could not reach Epson Connect: ' . $error, 0, [], true, $errno, $connected);
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
            throw new EpsonException('The printer is not linked yet. Go to Admin → Printer Settings and connect your ET-2926.');
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
                throw new EpsonException('The printer link expired. Reconnect the ET-2926 in Admin → Printer Settings.');
            }

            throw $e;
        }

        $this->storeTokens($token);

        return (string) $token['access_token'];
    }

    private function tokenRequest(array $fields): array
    {
        $host     = (string) parse_url(EPSON_AUTH_URL, PHP_URL_HOST);
        $resolve  = $this->resolveOptions($host);
        $attempts = 2;
        $error    = '';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $ch      = curl_init(EPSON_AUTH_URL . '/auth/token');
            $options = [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($fields),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => EPSON_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => EPSON_CONNECT_TIMEOUT,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Basic ' . base64_encode($this->config['client_id'] . ':' . $this->config['client_secret']),
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ],
            ];

            if ($resolve !== []) {
                $options[CURLOPT_RESOLVE] = $resolve;
            }

            curl_setopt_array($ch, $options);

            $raw     = curl_exec($ch);
        $error   = curl_error($ch);
        $errno   = curl_errno($ch);
        $info    = curl_getinfo($ch);
        $timings = $this->requestTimings($ch);
        $status  = (int) ($info['http_code'] ?? 0);
        curl_close($ch);

        epson_log_request([
            'host'       => (string) parse_url(EPSON_AUTH_URL, PHP_URL_HOST),
            'path'       => 'auth/token',
            'ok'         => $raw !== false && $error === '' && $status < 400,
            'errno'      => $errno,
            'http'       => $status,
            'connect_ms' => (int) round(1000 * (float) ($info['connect_time'] ?? 0)),
            'total_ms'   => (int) round(1000 * (float) ($info['total_time'] ?? 0)),
            'error'      => mb_substr($error, 0, 160),
        ]);

            if ($raw === false || $error !== '') {
                $error = $error !== '' ? $error : 'no response from Epson';

                if (self::isConnectFailure((int) $errno, $info)) {
                    if ($resolve !== []) {
                        epson_set_edge_ip($host, '');
                        $resolve = [];
                        continue;
                    }

                    if ($attempt < $attempts) {
                        $pinned = $this->pinReachableEdge($host);

                        if ($pinned !== '') {
                            $resolve  = [$host . ':443:' . $pinned];
                            $attempts = min(3, $attempts + 1);
                            continue;
                        }
                    }
                }

                throw new EpsonException(
                    'Could not reach Epson authorisation: ' . $error . ' (cURL ' . $errno . '; ' . $timings . ')',
                    0,
                    [],
                    true,
                    $errno
                );
            }

            $payload = json_decode((string) $raw, true);
            $payload = is_array($payload) ? $payload : [];

            if ($status >= 400 || empty($payload['access_token'])) {
                throw new EpsonException($this->errorMessage($payload, $status), $status, $payload);
            }

            return $payload;
        }

        throw new EpsonException('Could not reach Epson authorisation: ' . $error);
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

/**
 * Public-facing printer state: online, credentials saved but not linked, or
 * no Epson credentials at all.
 *
 * @return array{key: string, label: string, footer: string}
 */
function epson_status(?EpsonClient $client = null): array
{
    $client ??= EpsonClient::fromSettings();

    if ($client->isConnected()) {
        return ['key' => 'online', 'label' => 'Online', 'footer' => 'Printer online'];
    }

    if ($client->isConfigured()) {
        return ['key' => 'not_linked', 'label' => 'Not linked', 'footer' => 'Printer not linked'];
    }

    return ['key' => 'not_set_up', 'label' => 'Not set up', 'footer' => 'Printer not set up'];
}
