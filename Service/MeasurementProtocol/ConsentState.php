<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

enum ConsentState: string
{
    /** The visitor accepted tracking cookies for this website. */
    case Granted = 'granted';

    /** A browser request without accepted tracking consent. */
    case Declined = 'declined';

    /** No browser context to read consent from (admin-created orders, gateway webhooks, ...). */
    case Unknown = 'unknown';
}
