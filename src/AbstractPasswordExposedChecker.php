<?php

namespace DivineOmega\PasswordExposed;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Class AbstractPasswordExposedChecker
 *
 * @package DivineOmega\PasswordExposed
 */
abstract class AbstractPasswordExposedChecker implements PasswordExposedCheckerInterface
{

    /** @var int */
    protected const CACHE_EXPIRY_SECONDS = 2592000;

    /**
     * @inheritdoc
     */
    public function passwordExposed(string $password): string
    {
        return $this->passwordExposedByHash($this->getHash($password));
    }

    /**
     * @inheritdoc
     */
    public function passwordExposedByHash(string $hash): string
    {
        $cacheKey = substr($hash, 0, 2) . '_' . substr($hash, 2, 3);

        try {
            $cacheItem = $this->getCache()->getItem($cacheKey);
        } catch (\Exception $e) {
            $cacheItem = null;
        }

        if ($cacheItem !== null && $cacheItem->isHit()) {
            /** @var string $responseBody */
            $responseBody = $cacheItem->get();
        } else {
            try {
                /** @var ResponseInterface $response */
                $response = $this->makeRequest($hash);

                if ($response->getStatusCode() !== 200) {
                    return PasswordExposedCheckerInterface::UNKNOWN;
                }
            } catch (ClientExceptionInterface $e) {
                return PasswordExposedCheckerInterface::UNKNOWN;
            }

            /** @var string $responseBody */
            $responseBody = $response->getBody()->getContents();

            if ($cacheItem !== null) {
                $cacheItem->set($responseBody);
                $cacheItem->expiresAfter(self::CACHE_EXPIRY_SECONDS);
                $this->getCache()->save($cacheItem);
            }
        }

        return $this->getPasswordStatus($hash, $responseBody);
    }

    /**
     * @inheritdoc
     */
    public function isExposed(string $password): ?bool
    {
        return $this->isExposedByHash($this->getHash($password));
    }

    /**
     * @inheritdoc
     */
    public function isExposedByHash(string $hash): ?bool
    {
        $status = $this->passwordExposedByHash($hash);

        if ($status === PasswordExposedCheckerInterface::EXPOSED) {
            return true;
        }

        if ($status === PasswordExposedCheckerInterface::NOT_EXPOSED) {
            return false;
        }

        return null;
    }

    /**
     * @param $hash
     *
     * @return ResponseInterface
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    protected function makeRequest(string $hash): ResponseInterface
    {
        $uri = $this->getUriFactory()->createUri('https://api.pwnedpasswords.com/range/' . substr($hash, 0, 5));
        $request = $this->getRequestFactory()->createRequest('GET', $uri);

        return $this->getClient()->sendRequest($request);
    }

    /**
     * @param $string
     *
     * @return string
     */
    protected function getHash(string $string): string
    {
        return sha1($string);
    }

    /**
     * @param string $hash
     * @param string $responseBody
     *
     * @return string
     */
    protected function getPasswordStatus($hash, $responseBody): string
    {
        $hash = strtoupper($hash);
        $hashSuffix = substr($hash, 5);

        $lines = explode("\r\n", $responseBody);

        foreach ($lines as $line) {
            [$exposedHashSuffix, $occurrences] = explode(':', $line);
            if (hash_equals($hashSuffix, $exposedHashSuffix)) {
                return PasswordStatus::EXPOSED;
            }
        }

        return PasswordStatus::NOT_EXPOSED;
    }

    /**
     * @return ClientInterface
     */
    abstract protected function getClient(): ClientInterface;

    /**
     * @return CacheItemPoolInterface
     */
    abstract protected function getCache(): CacheItemPoolInterface;

    /**
     * @return RequestFactoryInterface
     */
    abstract protected function getRequestFactory(): RequestFactoryInterface;

    /**
     * @return UriFactoryInterface
     */
    abstract protected function getUriFactory(): UriFactoryInterface;
}
