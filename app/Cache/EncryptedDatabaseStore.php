<?php

namespace App\Cache;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class EncryptedDatabaseStore extends DatabaseStore
{
    protected function serialize($value)
    {
        return Crypt::encryptString(parent::serialize($value));
    }

    protected function unserialize($value)
    {
        try {
            $value = Crypt::decryptString($value);
        } catch (DecryptException) {
            // Read cache entries written before database-cache encryption was enabled.
        }

        return parent::unserialize($value);
    }
}
