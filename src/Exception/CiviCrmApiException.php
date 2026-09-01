<?php

declare(strict_types=1);

namespace Survos\Civicrm\Exception;

/**
 * A CiviCRM API call that did not do what was asked.
 *
 * Its own class because the interesting failures are not transport failures: CiviCRM returns
 * "Api FakeEntity fakeAction version 4 does not exist" inside an HTTP 200, and a caller that
 * only checks the status code sees an empty result and carries on.
 */
final class CiviCrmApiException extends \RuntimeException
{
    /**
     * Code for a request that never left the building.
     *
     * Distinct from 0 (transport failure) because the two want opposite handling: a connection
     * reset is worth retrying, and parameters that will not encode will not encode next time
     * either.
     */
    public const int NOT_ENCODABLE = -1;

    /**
     * Code for a request that failed below the API: connection reset, DNS, timeout.
     *
     * Its own code rather than 0, because CiviCRM reports plenty of API errors with no
     * `error_code` at all, and 0 cannot mean both "the network broke, try again" and "the API
     * said no, do not".
     */
    public const int TRANSPORT_FAILURE = -2;
}
