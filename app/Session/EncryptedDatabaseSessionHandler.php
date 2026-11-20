<?php

namespace App\Session;

use App\Support\SensitiveLookup;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Crypt;

class EncryptedDatabaseSessionHandler extends DatabaseSessionHandler
{
    public function __construct(ConnectionInterface $connection, string $table, int $minutes, ?Container $container = null)
    {
        parent::__construct($connection, $table, $minutes, $container);
    }

    public function read($sessionId): string|false
    {
        $this->setExists(false);
        $data = parent::read($this->storageKey($sessionId));

        if ($data === '' || $data === false) {
            return $data;
        }

        try {
            return Crypt::decryptString($data);
        } catch (DecryptException) {
            return $data;
        }
    }

    public function write($sessionId, $data): bool
    {
        $storageKey = $this->storageKey($sessionId);

        if (! $this->exists) {
            parent::read($storageKey);
        }

        $payload = $this->getDefaultPayload(Crypt::encryptString($data));

        if (! $this->exists) {
            $this->performInsert($storageKey, $payload);
        } else {
            $this->performUpdate($storageKey, $payload);
        }

        return $this->exists = true;
    }

    public function destroy($sessionId): bool
    {
        return parent::destroy($this->storageKey($sessionId));
    }

    protected function getDefaultPayload($data)
    {
        $payload = parent::getDefaultPayload($data);

        foreach (['ip_address', 'user_agent'] as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = Crypt::encryptString((string) $payload[$field]);
            }
        }

        return $payload;
    }

    private function storageKey(string $sessionId): string
    {
        return SensitiveLookup::hash('session_id', $sessionId)
            ?? throw new \LogicException('A session ID hash could not be generated.');
    }
}
