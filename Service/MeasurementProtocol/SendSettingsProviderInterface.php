<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

interface SendSettingsProviderInterface
{
    /**
     * Credentials and delivery settings for a store view, read at send time (secrets are never stored in the queue).
     * Returns null when server-side tracking is disabled or not fully configured for the store.
     */
    public function getSettings(int $storeId): ?SendSettings;
}
