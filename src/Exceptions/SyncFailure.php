<?php

namespace WeiJuKeJi\LaravelEkpOrgSync\Exceptions;

use RuntimeException;

class SyncFailure extends RuntimeException
{
    // Only fixed diagnostic codes cross this boundary; remote bodies and credentials never do.
}
