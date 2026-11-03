<?php
declare(strict_types=1);

namespace Mlbd\Scraper;

/** Base exception for every scraper failure. */
class ScraperError extends \RuntimeException
{
}

/** The site answered with a Cloudflare challenge. */
class CloudflareBlocked extends ScraperError
{
}

/** No usable stream or download could be extracted from a page. */
class NoStreamFound extends ScraperError
{
    public string $pageUrl;
    /** @var array<string, mixed> */
    public array $debug;

    /**
     * @param array<string, mixed> $debug
     */
    public function __construct(string $pageUrl, array $debug = [])
    {
        parent::__construct($pageUrl);
        $this->pageUrl = $pageUrl;
        $this->debug = $debug;
    }
}
