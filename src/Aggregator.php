<?php

namespace App;

use App\Entity\Domain;
use App\Entity\PageStats;
use App\Entity\ReferrerStats;
use App\Entity\SiteStats;
use DateTimeImmutable;
use Exception;

class Aggregator {

    protected SiteStats $site_stats;
    protected array $page_stats = [];
    protected array $referrer_stats = [];
    protected Domain $domain;

    public function __construct(
        protected Database $db
    ) {
        $this->site_stats = new SiteStats;
    }

    public function run(Domain $domain): void
    {
        $this->domain = $domain;

        $filename = \dirname(__DIR__) . "/var/buffer-{$this->domain->getName()}";
        if (!\is_file($filename)) {
            // buffer file for this domain does not exist, meaning no new data since last aggregation
            // we still create the file, because we use this to validate domain on /collect requests
            \touch($filename);
            return;
        }

        // rename file to something temporary
        $tmp_filename = $filename . '-' . \time();
        $renamed = \rename($filename, $tmp_filename);
        if (!$renamed) throw new Exception("Error renaming buffer file");

        // put empty file into place
        \touch($filename);

        $fh = \fopen($tmp_filename, 'r');
        if (!$fh) throw new Exception("Error opening buffer file for reading");

        // read file line by line
        while (($line = \fgets($fh, 1024)) !== false) {
            $line = \trim($line);

            // skip empty line
            if ($line === '') {
                continue;
            }

            $data = \unserialize($line);
            $this->addData($data);
        }

        // close file & remove it from filesystem
        \fclose($fh);
        \unlink($tmp_filename);

        $this->commit();
    }

    private function addData(array $data): void
    {
        [$path, $new_visitor, $unique_pageview, $referrer_url] = $data;

        // if referrer is on blocklist, ignore entire line
        if ($this->isReferrerUrlOnBlocklist($referrer_url)) {
            return;
        }

        // increment site stats
        $this->site_stats->pageviews++;
        $this->site_stats->visitors += $new_visitor ? 1 : 0;

        // increment page stats
        $this->page_stats[$path] ??= new PageStats;
        $this->page_stats[$path]->pageviews++;
        $this->page_stats[$path]->visitors += $unique_pageview ? 1 : 0;

        // increment referrer stats
        if ($referrer_url !== '') {
            $this->referrer_stats[$referrer_url] ??= new ReferrerStats;
            $this->referrer_stats[$referrer_url]->pageviews++;
            $this->referrer_stats[$referrer_url]->visitors += $unique_pageview ? 1 : 0;
        }
    }

    private function commit(): void
    {
        // return early if no new data came in
        if ($this->site_stats->pageviews === 0) return;

        // TODO: Use configurable timezone here
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $date = $now->format('Y-m-d');

        $this->commitSiteStats($date);
        $this->commitPageStats($date);
        $this->commitReferrerStats($date);
        $this->commitRealtimePageviewCount();
        $this->reset();

        (new SessionCleaner)();
    }

    /**
     * Resets the object properties to their initial state.
     * This protects against calling run() twice on the same class instance, committing data twice.
     */
    private function reset(): void
    {
        $this->site_stats = new SiteStats;
        $this->page_stats = [];
        $this->referrer_stats = [];
    }

    private function commitSiteStats(string $date): void
    {
        // TODO: Abstract away in a different class: SQLiteCommitter
        if ($this->db->getDriverName() === Database::DRIVER_SQLITE) {
            $query = "INSERT INTO koko_analytics_site_stats_{$this->domain->getId()} (date, visitors, pageviews) VALUES (:date, :visitors, :pageviews) ON CONFLICT DO UPDATE SET visitors = visitors + excluded.visitors, pageviews = pageviews + excluded.pageviews";
        } else {
            $query = "INSERT INTO koko_analytics_site_stats_{$this->domain->getId()} (date, visitors, pageviews) VALUES (:date, :visitors, :pageviews) ON DUPLICATE KEY UPDATE visitors = visitors + VALUES(visitors), pageviews = pageviews + VALUES(pageviews)";
        }

        $this->db->prepare($query)->execute([
            'date' => $date,
            'visitors' => $this->site_stats->visitors,
            'pageviews' => $this->site_stats->pageviews,
        ]);
    }

    private function commitPageStats(string $date): void
    {
        if (empty($this->page_stats)) return;

        // insert all page urls
        $values = \array_keys($this->page_stats);
        $placeholders = \rtrim(\str_repeat('(?),', count($values)), ',');
        if ($this->db->getDriverName() === Database::DRIVER_SQLITE) {
            $query = "INSERT OR IGNORE INTO koko_analytics_page_urls_{$this->domain->getId()} (url) VALUES {$placeholders}";
        } else {
            $query = "INSERT IGNORE INTO koko_analytics_page_urls_{$this->domain->getId()} (url) VALUES {$placeholders}";
        }
        $this->db->prepare($query)->execute($values);

        // select and map page url to id
        $placeholders = \rtrim(\str_repeat('?,', count($values)), ',');
        $stmt = $this->db->prepare("SELECT * FROM koko_analytics_page_urls_{$this->domain->getId()} WHERE url IN ({$placeholders})");
        $stmt->execute($values);
        $page_url_ids = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $page_url_ids[$row['url']] = $row['id'];
        }

        // build final upsert query for page stats
        $values = [];
        foreach ($this->page_stats as $url => $stats) {
            \array_push($values, $date, $page_url_ids[$url], $stats->visitors, $stats->pageviews);
        }
        $column_count = 4;
        $placeholders = \rtrim(\str_repeat('?,', $column_count), ',');
        $placeholders = \rtrim(\str_repeat("($placeholders),", \count($values) / $column_count), ',');

        if ($this->db->getDrivername() === Database::DRIVER_SQLITE) {
            $query = "INSERT INTO koko_analytics_page_stats_{$this->domain->getId()} (date, id, visitors, pageviews) VALUES {$placeholders} ON CONFLICT DO UPDATE SET visitors = visitors + excluded.visitors, pageviews = pageviews + excluded.pageviews";
        } else {
            $query = "INSERT INTO koko_analytics_page_stats_{$this->domain->getId()} (date, id, visitors, pageviews) VALUES {$placeholders} ON DUPLICATE KEY UPDATE visitors = visitors + VALUES(visitors), pageviews = pageviews + VALUES(pageviews)";
        }
        $this->db->prepare($query)->execute($values);
    }

    private function commitReferrerStats(string $date): void
    {
        if (empty($this->referrer_stats)) return;

        // insert all page urls
        $values = \array_keys($this->referrer_stats);
        $placeholders = \rtrim(\str_repeat('(?),', \count($values)), ',');
        if ($this->db->getDriverName() === Database::DRIVER_SQLITE) {
            $query = "INSERT OR IGNORE INTO koko_analytics_referrer_urls_{$this->domain->getId()} (url) VALUES {$placeholders}";
        } else {
            $query = "INSERT IGNORE INTO koko_analytics_referrer_urls_{$this->domain->getId()} (url) VALUES {$placeholders}";
        }
        $this->db->prepare($query)->execute($values);

        // select and map page url to id
        $placeholders = \rtrim(\str_repeat('?,', count($values)), ',');
        $stmt = $this->db->prepare("SELECT * FROM koko_analytics_referrer_urls_{$this->domain->getId()} WHERE url IN ({$placeholders})");
        $stmt->execute($values);
        $url_ids = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $url_ids[$row['url']] = $row['id'];
        }

        // build final upsert query for page stats
        $values = [];
        foreach ($this->referrer_stats as $url => $stats) {
            \array_push($values, $date, $url_ids[$url], $stats->visitors, $stats->pageviews);
        }
        $column_count = 4;
        $placeholders = \rtrim(\str_repeat('?,', $column_count), ',');
        $placeholders = \rtrim(\str_repeat("($placeholders),", \count($values) / $column_count), ',');
        if ($this->db->getDrivername() === Database::DRIVER_SQLITE) {
            $query = "INSERT INTO koko_analytics_referrer_stats_{$this->domain->getId()} (date, id, visitors, pageviews) VALUES {$placeholders} ON CONFLICT DO UPDATE SET visitors = visitors + excluded.visitors, pageviews = pageviews + excluded.pageviews";
        } else {
            $query = "INSERT INTO koko_analytics_referrer_stats_{$this->domain->getId()} (date, id, visitors, pageviews) VALUES {$placeholders} ON DUPLICATE KEY UPDATE visitors = visitors + VALUES(visitors), pageviews = pageviews + VALUES(pageviews);";
        }
        $this->db->prepare($query)->execute($values);
    }

    private function commitRealtimePageviewCount(): void
    {
        // insert pageviews since last aggregation run
        $this->db
            ->prepare("INSERT INTO koko_analytics_realtime_count_{$this->domain->getId()} (timestamp, count) VALUES (?, ?)")
            ->execute([(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s') , $this->site_stats->pageviews]);

        // remove pageviews older than 3 hours
        $this->db
            ->prepare("DELETE FROM koko_analytics_realtime_count_{$this->domain->getId()} WHERE timestamp < ?")
            ->execute([ (new \DateTimeImmutable('-3 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')]);
    }

    private function isReferrerUrlOnBlocklist(string $url): bool
    {
        if ($url === '') return false;

        static $blocklist;
        if ($blocklist === null) {
            $blocklist_filename = \dirname(__DIR__) . '/var/blocklist.txt';
            if (\is_file($blocklist_filename)) {
                $blocklist = \file($blocklist_filename, FILE_IGNORE_NEW_LINES);
            }
            $blocklist = $blocklist ?: [];
        }

        foreach ($blocklist as $blocklisted_domain) {
            if (\str_contains($url, $blocklisted_domain)) {
                return true;
            }
        }

        return false;
    }
}
