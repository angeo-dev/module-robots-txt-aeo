<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Api;

/**
 * Public read-only API for cross-module integration.
 *
 * Implementations expose the *effective* robots.txt the module would emit for
 * a given store, plus the list of enabled bot user-agents and resolved sitemap
 * URLs. Consumer modules (e.g. angeo/module-aeo-audit) can wire to this
 * interface and skip the round-trip HTTP fetch against /robots.txt entirely.
 *
 * Soft-coupling pattern: consumers should check `interface_exists()` on this
 * FQCN before declaring a constructor dependency, so they keep working when
 * Angeo_RobotsTxtAeo is not installed.
 *
 * @api
 * @since 2.0.0
 */
interface RobotsStatusInterface
{
    /**
     * Render the robots.txt content this module would emit for the given store.
     *
     * Equivalent to what a browser would see at <base_url>/robots.txt after
     * the plugin has run. Pure in-process call — no HTTP, no cache write.
     *
     * @param int|null $storeId Null = use current store from StoreManager.
     */
    public function getEffectiveRobotsTxt(?int $storeId = null): string;

    /**
     * The user-agent strings of bots that are enabled and will be written
     * into the managed Angeo block.
     *
     * @return string[] e.g. ["GPTBot", "ClaudeBot", ...]
     */
    public function getEnabledBotUserAgents(?int $storeId = null): array;

    /**
     * The fully-qualified Sitemap: URLs that will be emitted.
     *
     * @return string[]
     */
    public function getEffectiveSitemaps(?int $storeId = null): array;

    /**
     * Whether this module is enabled for the given store scope.
     */
    public function isEnabled(?int $storeId = null): bool;

    /**
     * Effective mode for the given scope: "inject" or "replace".
     */
    public function getMode(?int $storeId = null): string;
}
