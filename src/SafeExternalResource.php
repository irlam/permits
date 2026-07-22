<?php
declare(strict_types=1);

namespace Permits;

use RuntimeException;

/**
 * Bounded HTTPS downloader and upload validator used by admin import tools.
 */
final class SafeExternalResource
{
    public const DEFAULT_MAX_BYTES = 8 * 1024 * 1024;
    public const MAX_REDIRECTS = 3;

    /** @var callable(string):array<int,string> */
    private $resolver;

    /** @param null|callable(string):array<int,string> $resolver */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $addresses = [];
            $recordTypes = DNS_A;
            if (defined('DNS_AAAA')) {
                $recordTypes |= DNS_AAAA;
            }

            $records = @dns_get_record($host, $recordTypes);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $address = $record['ip'] ?? ($record['ipv6'] ?? null);
                    if (is_string($address) && $address !== '') {
                        $addresses[] = $address;
                    }
                }
            }

            if ($addresses === []) {
                $ipv4 = @gethostbynamel($host);
                if (is_array($ipv4)) {
                    $addresses = array_merge($addresses, $ipv4);
                }
            }

            return array_values(array_unique($addresses));
        };
    }

    /**
     * @param array<int,string> $allowedExtensions
     * @param array<int,string> $allowedContentTypes
     * @return array{body:string,content_type:string,final_url:string}
     */
    public function fetch(
        string $url,
        array $allowedExtensions,
        array $allowedContentTypes,
        int $maxBytes = self::DEFAULT_MAX_BYTES
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('External downloads are unavailable because the PHP cURL extension is not enabled.');
        }

        $maxBytes = max(1024, min($maxBytes, self::DEFAULT_MAX_BYTES));
        $currentUrl = trim($url);

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $target = $this->validateUrl($currentUrl, $allowedExtensions);
            $body = '';
            $tooLarge = false;
            $headers = [];

            $curl = curl_init($currentUrl);
            if ($curl === false) {
                throw new RuntimeException('The external address could not be opened.');
            }

            $pinnedAddress = str_contains($target['address'], ':')
                ? '[' . $target['address'] . ']'
                : $target['address'];

            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROXY => '',
                CURLOPT_USERAGENT => 'PermitsTemplateImporter/1.0',
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html, application/xhtml+xml, application/pdf, application/vnd.openxmlformats-officedocument.wordprocessingml.document;q=0.9',
                ],
                CURLOPT_RESOLVE => [$target['host'] . ':443:' . $pinnedAddress],
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                    $length = strlen($line);
                    $trimmed = trim($line);
                    if ($trimmed === '' || str_starts_with(strtoupper($trimmed), 'HTTP/')) {
                        return $length;
                    }

                    $separator = strpos($trimmed, ':');
                    if ($separator !== false) {
                        $name = strtolower(trim(substr($trimmed, 0, $separator)));
                        $headers[$name] = trim(substr($trimmed, $separator + 1));
                    }

                    return $length;
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                    if (strlen($body) + strlen($chunk) > $maxBytes) {
                        $tooLarge = true;
                        return 0;
                    }

                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);

            $ok = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $contentType = (string)(curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?: ($headers['content-type'] ?? ''));
            $curlError = curl_error($curl);
            curl_close($curl);

            if ($tooLarge) {
                $limitMb = max(1, (int)ceil($maxBytes / 1024 / 1024));
                throw new RuntimeException('The external file is larger than the ' . $limitMb . ' MB import limit.');
            }
            if ($ok === false) {
                error_log('External template download failed: ' . $curlError);
                throw new RuntimeException('The external address could not be downloaded. Check that it is publicly accessible over HTTPS.');
            }

            if ($status >= 300 && $status < 400) {
                $location = trim((string)($headers['location'] ?? ''));
                if ($location === '') {
                    throw new RuntimeException('The external site returned an invalid redirect.');
                }
                if ($redirects >= self::MAX_REDIRECTS) {
                    throw new RuntimeException('The external site redirected too many times.');
                }

                $currentUrl = self::resolveRedirect($currentUrl, $location);
                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('The external site returned HTTP ' . $status . ' instead of a downloadable template.');
            }
            if ($body === '') {
                throw new RuntimeException('The external site returned an empty file.');
            }

            $contentType = strtolower(trim(explode(';', $contentType, 2)[0]));
            $normalisedAllowedTypes = array_map(static fn(string $type): string => strtolower(trim($type)), $allowedContentTypes);
            if ($contentType === '' || !in_array($contentType, $normalisedAllowedTypes, true)) {
                throw new RuntimeException('That address did not return a supported HTML, PDF, or Word template.');
            }

            self::validateExtensionContentType($target['extension'], $contentType);
            self::validateContentSignature($body, $contentType, $target['extension']);

            return [
                'body' => $body,
                'content_type' => $contentType,
                'final_url' => $currentUrl,
            ];
        }

        throw new RuntimeException('The external site redirected too many times.');
    }

    /**
     * Validate URL syntax, extension, DNS answers and public address status.
     *
     * @param array<int,string> $allowedExtensions
     * @return array{host:string,address:string,extension:string}
     */
    public function validateUrl(string $url, array $allowedExtensions): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            throw new RuntimeException('The external template address is empty or too long.');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException('Use a public HTTPS address for external templates.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Template addresses containing sign-in details are not supported.');
        }
        if (isset($parts['port']) && (int)$parts['port'] !== 443) {
            throw new RuntimeException('External templates must use the standard HTTPS port.');
        }

        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new RuntimeException('Use a named public website, not an IP address.');
        }
        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
            throw new RuntimeException('The external website name is not valid.');
        }

        $path = rawurldecode((string)($parts['path'] ?? ''));
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $normalisedExtensions = array_map(static fn(string $value): string => strtolower(ltrim(trim($value), '.')), $allowedExtensions);
        if ($extension !== '' && !in_array($extension, $normalisedExtensions, true)) {
            throw new RuntimeException('That file type is not supported. Use HTML, PDF, or DOCX.');
        }

        $addresses = ($this->resolver)($host);
        if (!is_array($addresses) || $addresses === []) {
            throw new RuntimeException('The external website could not be found.');
        }

        $publicAddresses = [];
        foreach (array_unique($addresses) as $address) {
            if (!is_string($address) || self::isPublicIp($address) === false) {
                throw new RuntimeException('Private or local network addresses cannot be imported.');
            }
            $publicAddresses[] = $address;
        }

        return [
            'host' => $host,
            'address' => $publicAddresses[0],
            'extension' => $extension,
        ];
    }

    /**
     * @param array<int,string> $allowedExtensions
     * @param array<int,string> $allowedMimeTypes
     * @return array{path:string,name:string,extension:string,mime:string,size:int}
     */
    public static function validateUpload(
        string $temporaryPath,
        string $originalName,
        int $reportedSize,
        array $allowedExtensions,
        array $allowedMimeTypes,
        int $maxBytes = self::DEFAULT_MAX_BYTES
    ): array {
        if (!is_file($temporaryPath) || !is_readable($temporaryPath)) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        $actualSize = filesize($temporaryPath);
        if ($actualSize === false || $actualSize <= 0 || $reportedSize <= 0) {
            throw new RuntimeException('The uploaded file is empty.');
        }
        if ($actualSize > $maxBytes || $reportedSize > $maxBytes) {
            $limitMb = max(1, (int)ceil($maxBytes / 1024 / 1024));
            throw new RuntimeException('Uploaded templates must be ' . $limitMb . ' MB or smaller.');
        }

        $safeName = basename(str_replace('\\', '/', $originalName));
        $extension = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        $normalisedExtensions = array_map(static fn(string $value): string => strtolower(ltrim(trim($value), '.')), $allowedExtensions);
        if ($extension === '' || !in_array($extension, $normalisedExtensions, true)) {
            throw new RuntimeException('Unsupported upload type. Use HTML, PDF, or DOCX.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string)$finfo->file($temporaryPath));
        $normalisedMimeTypes = array_map(static fn(string $value): string => strtolower(trim($value)), $allowedMimeTypes);
        if ($mime === '' || !in_array($mime, $normalisedMimeTypes, true)) {
            throw new RuntimeException('The uploaded file content does not match a supported template type.');
        }

        self::validateExtensionContentType($extension, $mime);
        $body = file_get_contents($temporaryPath);
        if (!is_string($body) || $body === '') {
            throw new RuntimeException('The uploaded file could not be inspected.');
        }
        self::validateContentSignature($body, $mime, $extension);

        return [
            'path' => $temporaryPath,
            'name' => $safeName,
            'extension' => $extension,
            'mime' => $mime,
            'size' => (int)$actualSize,
        ];
    }

    private static function isPublicIp(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private static function validateContentSignature(string $body, string $contentType, string $extension): void
    {
        $prefix = substr($body, 0, 8);
        if ($extension === 'pdf' || $contentType === 'application/pdf') {
            if (!str_starts_with($prefix, '%PDF-')) {
                throw new RuntimeException('The selected PDF does not contain valid PDF data.');
            }
            return;
        }

        $docxTypes = [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ];
        if ($extension === 'docx' || in_array($contentType, $docxTypes, true)) {
            if (
                !str_starts_with($prefix, "PK\x03\x04")
                || !str_contains($body, '[Content_Types].xml')
                || !str_contains($body, 'word/document.xml')
            ) {
                throw new RuntimeException('The selected DOCX does not contain valid Word document data.');
            }
            return;
        }

        $sample = strtolower(ltrim(substr($body, 0, 4096)));
        if (!str_contains($sample, '<html') && !str_contains($sample, '<!doctype') && !str_contains($sample, '<form')) {
            throw new RuntimeException('The selected HTML file does not contain a recognisable web document.');
        }
    }

    private static function validateExtensionContentType(string $extension, string $contentType): void
    {
        if ($extension === '') {
            return;
        }

        $compatibleTypes = [
            'html' => ['text/html', 'text/plain', 'application/xhtml+xml'],
            'htm' => ['text/html', 'text/plain', 'application/xhtml+xml'],
            'pdf' => ['application/pdf'],
            'docx' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
            ],
        ];

        if (!isset($compatibleTypes[$extension]) || !in_array($contentType, $compatibleTypes[$extension], true)) {
            throw new RuntimeException('The file extension and detected content type do not match.');
        }
    }

    private static function resolveRedirect(string $baseUrl, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base['host'])) {
            throw new RuntimeException('The external site returned an invalid redirect.');
        }

        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }

        $authority = 'https://' . $base['host'];
        if (str_starts_with($location, '/')) {
            return $authority . $location;
        }

        $basePath = (string)($base['path'] ?? '/');
        $directory = str_ends_with($basePath, '/') ? $basePath : dirname($basePath) . '/';
        $combined = $directory . $location;
        $segments = [];
        foreach (explode('/', $combined) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $authority . '/' . implode('/', $segments);
    }
}
