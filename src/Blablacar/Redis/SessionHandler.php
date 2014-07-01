<?php

namespace Blablacar\Redis;

use Blablacar\Redis\Exception\LockException;

class SessionHandler implements \SessionHandlerInterface
{
    protected $client;
    protected $prefix;
    protected $ttl;
    protected $spinLockWait;
    protected $lockMaxWait;
    protected $lockKey;
    protected $readOnly;

    /**
     * __construct
     *
     * @param Client $client       A redis client
     * @param string $prefix       The prefix to use for keys (default: "session")
     * @param int    $ttl          A ttl for keys (default: null = no ttl)
     * @param int    $spinLockWait The time to wait in µs before lock try
     * @param int    $lockMaxWait  The maximum time to wait in µs before exiting if no lock
     *
     * @return void
     */
    public function __construct(Client $client, $prefix = 'session', $ttl = null, $spinLockWait = 150000, $lockMaxWait = 30000000, $readOnly = false)
    {
        $this->client       = $client;
        $this->prefix       = $prefix;
        $this->ttl          = $ttl;
        $this->spinLockWait = $spinLockWait;
        $this->lockMaxWait  = $lockMaxWait;
        $this->lockKey      = null;
        $this->isLocked     = false;
        $this->readOnly     = $readOnly;
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->close();
    }

    public function setReadOnly($readOnly)
    {
        $this->readOnly = $readOnly;
    }

    /**
     * {@inheritDoc}
     */
    public function read($sessionId)
    {
        $this->lock($sessionId);

        $key = $this->getSessionKey($sessionId);
        if (false === $data = $this->client->get($key)) {
            $data = '';
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function write($sessionId, $data)
    {
        if ($this->readOnly) {
            return true;
        }

        $this->lock($sessionId);

        if (null === $this->ttl) {
            $return = $this->client->set(
                $this->getSessionKey($sessionId),
                (string) $data
            );
        } else {
            $return = $this->client->setex(
                $this->getSessionKey($sessionId),
                $this->ttl,
                (string) $data
            );
        }

        return $return;
    }

    /**
     * {@inheritDoc}
     */
    public function destroy($sessionId)
    {
        $this->client->del($this->getSessionKey($sessionId));
        $this->close();

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function gc($lifetime)
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function open($savePath, $sessionName)
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function close()
    {
        $this->unlock();

        return true;
    }

    /**
     * lock
     *
     * @param string $sessionId
     *
     * @return boolean
     */
    protected function lock($sessionId)
    {
        if (true === $this->isLocked || $this->readOnly) {
            return true;
        }

        $attempts = $this->lockMaxWait / $this->spinLockWait;
        $this->lockKey = $this->getSessionLockKey($sessionId);
        for ($i = 0; $i < $attempts; $i++) {
            if ($this->client->set($this->lockKey, 1, array('nx', 'ex' => $this->lockMaxWait / 1000000 + 1))) {
                return $this->isLocked = true;
            }
            usleep($this->spinLockWait);
        }

        if ($this->client->get($this->lockKey) && $this->client->ttl($this->lockKey) === -1) {
            throw new LockException(sprintf("Unable to lock session '%s' (lock ttl not set)", $this->lockKey));
        }

        throw new LockException(sprintf(
            "Unable to lock session '%s' (%d attempts, spinLockWait %d µs, total time %d µs)",
            $this->lockKey, $i, $this->spinLockWait, $i*$this->spinLockWait
        ));
    }

    /**
     * unlock
     *
     * @return void
     */
    protected function unlock()
    {
        if (null !== $this->lockKey) {
            $this->client->del($this->lockKey);
            $this->lockKey = null;
        }
    }

    /**
     * getSessionKey
     *
     * @param string $sessionId
     *
     * @return string
     */
    protected function getSessionKey($sessionId)
    {
        return sprintf('%s:%s', $this->prefix, $sessionId);
    }

    /**
     * getSessionLockKey
     *
     * @param string $sessionId
     *
     * @return string
     */
    protected function getSessionLockKey($sessionId)
    {
        return sprintf('%s.lock', $this->getSessionKey($sessionId));
    }
}
